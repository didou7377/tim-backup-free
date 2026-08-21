<?php
/**
 * Backup creation and verification.
 *
 * @package TIM_Backup
 */

defined( 'ABSPATH' ) || exit;

/**
 * Creates signed local backup archives.
 */
final class TIM_Backup_Backup_Service {

	/**
	 * Archive format version.
	 */
	private const FORMAT_VERSION = 1;

	/**
	 * Rows exported per database query.
	 */
	private const DATABASE_BATCH_SIZE = 500;

	/**
	 * Storage service.
	 *
	 * @var TIM_Backup_Storage
	 */
	private TIM_Backup_Storage $storage;

	/**
	 * Constructor.
	 *
	 * @param TIM_Backup_Storage $storage Storage service.
	 */
	public function __construct( TIM_Backup_Storage $storage ) {
		$this->storage = $storage;
	}

	/**
	 * Creates a full or database-only backup.
	 *
	 * @param string $type Backup type: full or database.
	 * @param string $protected_id Backup that retention must keep.
	 * @return array<string, mixed>|WP_Error
	 */
	public function create( string $type, string $protected_id = '' ) {
		if ( ! in_array( $type, array( 'full', 'database' ), true ) ) {
			return new WP_Error(
				'tim_backup_invalid_type',
				__( 'The requested backup type is invalid.', 'tim-backup' )
			);
		}

		if ( ! class_exists( 'ZipArchive' ) ) {
			return new WP_Error(
				'tim_backup_zip_unavailable',
				__( 'The PHP ZIP extension is required to create backups.', 'tim-backup' )
			);
		}

		$storage_ready = $this->storage->ensure_directory();

		if ( is_wp_error( $storage_ready ) ) {
			return $storage_ready;
		}

		$lock = $this->storage->acquire_lock( 'operation' );

		if ( is_wp_error( $lock ) ) {
			return $lock;
		}

		$started      = microtime( true );
		$id           = $this->storage->create_id();
		$archive_path = $this->storage->archive_path( $id );

		if ( is_wp_error( $archive_path ) ) {
			$this->storage->release_lock( 'operation', $lock );
			return $archive_path;
		}

		$partial_path = $archive_path . '.partial';
		$temp_path    = $this->storage->directory() . DIRECTORY_SEPARATOR . 'tmp-' . $id;
		$result       = $this->create_archive( $partial_path, $temp_path, $type, $id );

		if ( ! is_wp_error( $result ) ) {
			$verification = $this->verify( $partial_path );

			if ( is_wp_error( $verification ) ) {
				$result = $verification;
			}
		}

		if ( ! is_wp_error( $result ) && ! rename( $partial_path, $archive_path ) ) {
			$result = new WP_Error(
				'tim_backup_archive_finalize_failed',
				__( 'The completed backup archive could not be finalized.', 'tim-backup' )
			);
		}

		$this->remove_directory( $temp_path );

		if ( is_wp_error( $result ) ) {
			if ( is_file( $partial_path ) ) {
				wp_delete_file( $partial_path );
			}

			$this->storage->release_lock( 'operation', $lock );
			return $result;
		}

		$archive_hash = hash_file( 'sha256', $archive_path );

		if ( false === $archive_hash ) {
			wp_delete_file( $archive_path );
			$this->storage->release_lock( 'operation', $lock );
			return new WP_Error(
				'tim_backup_archive_hash_failed',
				__( 'The completed backup archive could not be hashed.', 'tim-backup' )
			);
		}

		$metadata = array(
			'id'           => $id,
			'type'         => $type,
			'created_at'   => time(),
			'duration'     => round( microtime( true ) - $started, 2 ),
			'size'         => (int) filesize( $archive_path ),
			'archive_hash' => $archive_hash,
			'verified'     => true,
		);

		$registered = $this->storage->register( $metadata, $protected_id );
		$this->storage->release_lock( 'operation', $lock );

		if ( is_wp_error( $registered ) ) {
			if ( is_file( $archive_path ) ) {
				wp_delete_file( $archive_path );
			}

			return $registered;
		}

		return $metadata;
	}

	/**
	 * Verifies archive structure, signature and payload hashes.
	 *
	 * @param string $archive_path Absolute archive path.
	 * @return array<string, mixed>|WP_Error
	 */
	public function verify( string $archive_path ) {
		if ( ! is_file( $archive_path ) ) {
			return new WP_Error(
				'tim_backup_archive_missing',
				__( 'The backup archive does not exist.', 'tim-backup' )
			);
		}

		$zip = new ZipArchive();

		if ( true !== $zip->open( $archive_path, ZipArchive::RDONLY ) ) {
			return new WP_Error(
				'tim_backup_archive_unreadable',
				__( 'The backup archive is not a readable ZIP file.', 'tim-backup' )
			);
		}

		$manifest_json = $zip->getFromName( 'tim-backup/manifest.json' );

		if ( false === $manifest_json ) {
			$zip->close();
			return new WP_Error(
				'tim_backup_manifest_missing',
				__( 'The backup manifest is missing.', 'tim-backup' )
			);
		}

		$manifest = json_decode( $manifest_json, true );

		if (
			! is_array( $manifest )
			|| self::FORMAT_VERSION !== (int) ( $manifest['format_version'] ?? 0 )
			|| empty( $manifest['signature'] )
			|| ! is_array( $manifest['entries'] ?? null )
		) {
			$zip->close();
			return new WP_Error(
				'tim_backup_manifest_invalid',
				__( 'The backup manifest is invalid or unsupported.', 'tim-backup' )
			);
		}

		$signature = (string) $manifest['signature'];
		unset( $manifest['signature'] );
		$expected_signature = hash_hmac( 'sha256', (string) wp_json_encode( $manifest ), $this->signing_key() );

		if ( ! hash_equals( $expected_signature, $signature ) ) {
			$zip->close();
			return new WP_Error(
				'tim_backup_signature_invalid',
				__( 'The backup signature is invalid. The archive may have been changed.', 'tim-backup' )
			);
		}

		if ( ! hash_equals( (string) $manifest['site_hash'], hash( 'sha256', home_url( '/' ) ) ) ) {
			$zip->close();
			return new WP_Error(
				'tim_backup_wrong_site',
				__( 'This backup belongs to a different WordPress site.', 'tim-backup' )
			);
		}

		$allowed_entries = array_fill_keys( array_keys( $manifest['entries'] ), true );
		$allowed_entries['tim-backup/manifest.json'] = true;
		$seen_entries   = array();
		$number_of_files = $zip->numFiles; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- ZipArchive exposes this public property.

		for ( $index = 0; $index < $number_of_files; ++$index ) {
			$entry               = $zip->getNameIndex( $index );
			$operating_system    = 0;
			$external_attributes = 0;
			$attributes_read     = $zip->getExternalAttributesIndex( $index, $operating_system, $external_attributes );
			$file_type           = ( $external_attributes >> 16 ) & 0170000;

			if (
				false === $entry
				|| false === $attributes_read
				|| isset( $seen_entries[ $entry ] )
				|| ! isset( $allowed_entries[ $entry ] )
				|| ! $this->is_safe_archive_entry( $entry )
				|| str_ends_with( $entry, '/' )
				|| ( ZipArchive::OPSYS_UNIX === $operating_system && 0 !== $file_type && 0100000 !== $file_type )
			) {
				$zip->close();
				return new WP_Error(
					'tim_backup_archive_contents_invalid',
					__( 'The backup contains an unexpected, duplicate, or unsafe archive entry.', 'tim-backup' )
				);
			}

			$seen_entries[ $entry ] = true;
		}

		if ( count( $seen_entries ) !== count( $allowed_entries ) ) {
			$zip->close();
			return new WP_Error(
				'tim_backup_archive_contents_incomplete',
				__( 'The backup archive contents do not match its signed manifest.', 'tim-backup' )
			);
		}

		foreach ( $manifest['entries'] as $entry => $expected_hash ) {
			if ( ! $this->is_safe_archive_entry( (string) $entry ) ) {
				$zip->close();
				return new WP_Error(
					'tim_backup_entry_invalid',
					__( 'The backup contains an unsafe archive path.', 'tim-backup' )
				);
			}

			$stream = $zip->getStream( (string) $entry );

			if ( false === $stream ) {
				$zip->close();
				return new WP_Error(
					'tim_backup_entry_missing',
					__( 'A file recorded by the backup manifest is missing.', 'tim-backup' )
				);
			}

			$context = hash_init( 'sha256' );
			hash_update_stream( $context, $stream );
			fclose( $stream ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- ZIP streams require explicit closing.

			if ( ! hash_equals( (string) $expected_hash, hash_final( $context ) ) ) {
				$zip->close();
				return new WP_Error(
					'tim_backup_hash_invalid',
					__( 'A backup file failed its integrity check.', 'tim-backup' )
				);
			}
		}

		$zip->close();
		$manifest['signature'] = $signature;

		return $manifest;
	}

	/**
	 * Builds the ZIP payload and signed manifest.
	 *
	 * @param string $archive_path Partial archive path.
	 * @param string $temp_path Temporary working directory.
	 * @param string $type Backup type.
	 * @param string $id Backup identifier.
	 * @return true|WP_Error
	 */
	private function create_archive( string $archive_path, string $temp_path, string $type, string $id ) {
		if ( ! wp_mkdir_p( $temp_path ) ) {
			return new WP_Error(
				'tim_backup_temp_create_failed',
				__( 'TIM Backup could not create a temporary working directory.', 'tim-backup' )
			);
		}

		$zip = new ZipArchive();

		if ( true !== $zip->open( $archive_path, ZipArchive::CREATE | ZipArchive::OVERWRITE ) ) {
			return new WP_Error(
				'tim_backup_archive_create_failed',
				__( 'TIM Backup could not create the backup archive.', 'tim-backup' )
			);
		}

		$entries = array();
		$result  = $this->add_database( $zip, $temp_path, $entries );

		if ( ! is_wp_error( $result ) && 'full' === $type ) {
			$result = $this->add_site_files( $zip, $entries );
		}

		if ( is_wp_error( $result ) ) {
			$zip->close();
			return $result;
		}

		$manifest = array(
			'format_version' => self::FORMAT_VERSION,
			'plugin_version' => TIM_BACKUP_VERSION,
			'backup_id'      => $id,
			'type'           => $type,
			'created_at'     => gmdate( 'c' ),
			'site_hash'      => hash( 'sha256', home_url( '/' ) ),
			'wordpress'      => get_bloginfo( 'version' ),
			'php'            => PHP_VERSION,
			'entries'        => $entries,
		);

		$manifest['signature'] = hash_hmac(
			'sha256',
			(string) wp_json_encode( $manifest ),
			$this->signing_key()
		);

		if ( ! $zip->addFromString( 'tim-backup/manifest.json', (string) wp_json_encode( $manifest, JSON_PRETTY_PRINT ) ) ) {
			$zip->close();
			return new WP_Error(
				'tim_backup_manifest_write_failed',
				__( 'TIM Backup could not write the archive manifest.', 'tim-backup' )
			);
		}

		if ( ! $zip->close() ) {
			return new WP_Error(
				'tim_backup_archive_close_failed',
				__( 'TIM Backup could not finish writing the backup archive.', 'tim-backup' )
			);
		}

		return true;
	}

	/**
	 * Exports all current-site database tables to schema JSON and JSON Lines data.
	 *
	 * @param ZipArchive            $zip ZIP archive.
	 * @param string                $temp_path Temporary directory.
	 * @param array<string, string> $entries Manifest entries.
	 * @return true|WP_Error
	 */
	private function add_database( ZipArchive $zip, string $temp_path, array &$entries ) {
		global $wpdb;

		$is_sqlite = $this->is_sqlite_database();

		if (
			! $is_sqlite
			&& false === $wpdb->query( 'SET TRANSACTION ISOLATION LEVEL REPEATABLE READ' ) // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Static transaction statement.
		) {
			return new WP_Error(
				'tim_backup_database_isolation_failed',
				__( 'The database could not start a repeatable-read backup transaction.', 'tim-backup' )
			);
		}

		$transaction_sql     = $is_sqlite ? 'START TRANSACTION' : 'START TRANSACTION WITH CONSISTENT SNAPSHOT';
		$transaction_started = false !== $wpdb->query( $transaction_sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Statement is selected from two static values.

		if ( ! $transaction_started ) {
			return new WP_Error(
				'tim_backup_database_transaction_failed',
				__( 'The database could not start a consistent backup transaction.', 'tim-backup' )
			);
		}

		$fail                = static function ( WP_Error $error ) use ( $wpdb, &$transaction_started ): WP_Error {
			if ( $transaction_started ) {
				$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Static transaction statement.
				$transaction_started = false;
			}

			return $error;
		};
		$like   = $wpdb->esc_like( $wpdb->prefix ) . '%';
		$tables = $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $like ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- The wildcard value is prepared.

		if ( ! is_array( $tables ) || empty( $tables ) ) {
			return $fail(
				new WP_Error(
					'tim_backup_database_tables_missing',
					__( 'No WordPress database tables were found.', 'tim-backup' )
				)
			);
		}

		$schema = array();

		foreach ( $tables as $table ) {
			$table = (string) $table;

			if ( ! $this->is_safe_table_name( $table ) ) {
				return $fail(
					new WP_Error(
						'tim_backup_table_name_invalid',
						__( 'A database table has an unsafe name and cannot be backed up.', 'tim-backup' )
					)
				);
			}

			$create_row = $wpdb->get_row( 'SHOW CREATE TABLE `' . esc_sql( $table ) . '`', ARRAY_N ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Identifier is strictly validated.

			if ( ! is_array( $create_row ) || empty( $create_row[1] ) ) {
				return $fail(
					new WP_Error(
						'tim_backup_schema_export_failed',
						__( 'A database table schema could not be exported.', 'tim-backup' )
					)
				);
			}

			if ( 1 !== preg_match( '/\ACREATE TABLE\s+`' . preg_quote( $table, '/' ) . '`/i', (string) $create_row[1] ) ) {
				return $fail(
					new WP_Error(
						'tim_backup_database_object_unsupported',
						__( 'A prefixed database object is not a base table and cannot be backed up by this version.', 'tim-backup' )
					)
				);
			}

			if ( ! $is_sqlite ) {
				$status = $wpdb->get_row(
					$wpdb->prepare( 'SHOW TABLE STATUS LIKE %s', $wpdb->esc_like( $table ) ), // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- The table pattern is prepared.
					ARRAY_A
				);

				if ( ! is_array( $status ) || 'INNODB' !== strtoupper( (string) ( $status['Engine'] ?? '' ) ) ) {
					return $fail(
						new WP_Error(
							'tim_backup_database_engine_unsupported',
							__( 'All backed-up tables must use InnoDB for a consistent snapshot.', 'tim-backup' )
						)
					);
				}

				$primary_rows = $wpdb->get_results(
					'SHOW INDEX FROM `' . esc_sql( $table ) . "` WHERE Key_name = 'PRIMARY'", // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Identifier is strictly validated; key name is static.
					ARRAY_A
				);

				if ( ! is_array( $primary_rows ) || empty( $primary_rows ) ) {
					return $fail(
						new WP_Error(
							'tim_backup_database_primary_key_missing',
							__( 'Every backed-up table must have a primary key for deterministic export.', 'tim-backup' )
						)
					);
				}

				usort(
					$primary_rows,
					static function ( array $left, array $right ): int {
						return (int) $left['Seq_in_index'] <=> (int) $right['Seq_in_index'];
					}
				);

				$primary_columns = array();

				foreach ( $primary_rows as $primary_row ) {
					$column = (string) ( $primary_row['Column_name'] ?? '' );

					if ( 1 !== preg_match( '/\A[A-Za-z0-9_$]+\z/', $column ) ) {
						return $fail(
							new WP_Error(
								'tim_backup_database_primary_key_invalid',
								__( 'A primary-key column has an unsafe name.', 'tim-backup' )
							)
						);
					}

					$primary_columns[] = '`' . esc_sql( $column ) . '`';
				}

				$order_by = ' ORDER BY ' . implode( ', ', $primary_columns );
			} else {
				$order_by = ' ORDER BY rowid';
			}

			$schema[ $table ] = (string) $create_row[1];
			$temp_file       = $temp_path . DIRECTORY_SEPARATOR . hash( 'sha256', $table ) . '.jsonl';
			$handle          = @fopen( $temp_file, 'wb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen,WordPress.PHP.NoSilencedErrors.Discouraged -- Streaming prevents memory exhaustion; failure is handled below.

			if ( false === $handle ) {
				return $fail(
					new WP_Error(
						'tim_backup_database_file_failed',
						__( 'A temporary database export file could not be created.', 'tim-backup' )
					)
				);
			}

			$offset = 0;

			do {
				$query = $wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Identifiers are strictly validated before interpolation.
					'SELECT * FROM `' . esc_sql( $table ) . '`' . $order_by . ' LIMIT %d OFFSET %d', // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Identifiers are strictly validated.
					self::DATABASE_BATCH_SIZE,
					$offset
				);
				$rows  = $wpdb->get_results( $query, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Query is prepared above.

				if ( ! is_array( $rows ) ) {
					fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
					return $fail(
						new WP_Error(
							'tim_backup_database_export_failed',
							__( 'Database rows could not be exported.', 'tim-backup' )
						)
					);
				}

				foreach ( $rows as $row ) {
					$encoded = array();

					foreach ( $row as $column => $value ) {
						$encoded[ $column ] = null === $value ? null : base64_encode( (string) $value ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Binary-safe database transport, not code obfuscation.
					}

					if ( false === fwrite( $handle, (string) wp_json_encode( $encoded ) . "\n" ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
						fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
						return $fail(
							new WP_Error(
								'tim_backup_database_export_write_failed',
								__( 'Database export data could not be written.', 'tim-backup' )
							)
						);
					}
				}

				$row_count = count( $rows );
				$offset   += self::DATABASE_BATCH_SIZE;
			} while ( $row_count === self::DATABASE_BATCH_SIZE );

			fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose

			$entry = 'tim-backup/database/data/' . hash( 'sha256', $table ) . '.jsonl';

			if ( ! $zip->addFile( $temp_file, $entry ) ) {
				return $fail(
					new WP_Error(
						'tim_backup_database_archive_failed',
						__( 'Database export data could not be added to the archive.', 'tim-backup' )
					)
				);
			}

			$hash = hash_file( 'sha256', $temp_file );

			if ( false === $hash ) {
				return $fail(
					new WP_Error(
						'tim_backup_database_hash_failed',
						__( 'Database export data could not be hashed.', 'tim-backup' )
					)
				);
			}

			$entries[ $entry ] = $hash;
		}

		$schema_json  = (string) wp_json_encode( $schema, JSON_PRETTY_PRINT );
		$schema_entry = 'tim-backup/database/schema.json';

		if ( ! $zip->addFromString( $schema_entry, $schema_json ) ) {
			return $fail(
				new WP_Error(
					'tim_backup_schema_archive_failed',
					__( 'The database schema could not be added to the archive.', 'tim-backup' )
				)
			);
		}

		$entries[ $schema_entry ] = hash( 'sha256', $schema_json );

		if ( $transaction_started && false === $wpdb->query( 'COMMIT' ) ) { // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Static transaction statement.
			return $fail(
				new WP_Error(
					'tim_backup_database_commit_failed',
					__( 'The consistent database snapshot could not be completed.', 'tim-backup' )
				)
			);
		}

		return true;
	}

	/**
	 * Adds regular files below ABSPATH without following symbolic links.
	 *
	 * @param ZipArchive            $zip ZIP archive.
	 * @param array<string, string> $entries Manifest entries.
	 * @return true|WP_Error
	 */
	private function add_site_files( ZipArchive $zip, array &$entries ) {
		$root         = realpath( ABSPATH );
		$storage_root = realpath( $this->storage->directory() );

		if ( false === $root || false === $storage_root ) {
			return new WP_Error(
				'tim_backup_site_root_invalid',
				__( 'The WordPress or backup storage path could not be resolved.', 'tim-backup' )
			);
		}

		try {
			$iterator = new RecursiveIteratorIterator(
				new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS ),
				RecursiveIteratorIterator::LEAVES_ONLY,
				RecursiveIteratorIterator::CATCH_GET_CHILD
			);

			foreach ( $iterator as $file ) {
				if ( ! $file instanceof SplFileInfo || $file->isLink() || ! $file->isFile() ) {
					continue;
				}

				$real_path = $file->getRealPath();

				if ( false === $real_path || ! str_starts_with( $real_path, trailingslashit( $root ) ) ) {
					continue;
				}

				if ( str_starts_with( $real_path, trailingslashit( $storage_root ) ) ) {
					continue;
				}

				$relative = ltrim( substr( $real_path, strlen( $root ) ), DIRECTORY_SEPARATOR );
				$relative = str_replace( DIRECTORY_SEPARATOR, '/', $relative );

				if ( $this->is_mandatory_file_exclusion( $relative ) ) {
					continue;
				}

				$entry    = 'tim-backup/files/' . $relative;

				if ( ! $this->is_safe_archive_entry( $entry ) || ! $zip->addFile( $real_path, $entry ) ) {
					return new WP_Error(
						'tim_backup_file_archive_failed',
						__( 'A WordPress file could not be added to the backup archive.', 'tim-backup' )
					);
				}

				$hash = hash_file( 'sha256', $real_path );

				if ( false === $hash ) {
					return new WP_Error(
						'tim_backup_file_hash_failed',
						__( 'A WordPress file could not be hashed.', 'tim-backup' )
					);
				}

				$entries[ $entry ] = $hash;
			}
		} catch ( UnexpectedValueException $exception ) {
			return new WP_Error(
				'tim_backup_file_read_failed',
				__( 'A WordPress directory could not be read during backup.', 'tim-backup' )
			);
		}

		return true;
	}

	/**
	 * Returns a key bound to the current WordPress installation.
	 *
	 * @return string
	 */
	private function signing_key(): string {
		return hash_hmac( 'sha256', 'tim-backup-manifest-key-v1', wp_salt( 'auth' ), true );
	}

	/**
	 * Validates archive entry paths.
	 *
	 * @param string $entry Entry path.
	 * @return bool
	 */
	private function is_safe_archive_entry( string $entry ): bool {
		return (
			str_starts_with( $entry, 'tim-backup/' )
			&& ! str_contains( $entry, '../' )
			&& ! str_contains( $entry, '..\\' )
			&& ! str_starts_with( $entry, '/' )
			&& ! str_contains( $entry, "\0" )
		);
	}

	/**
	 * Excludes secrets and version-control internals from every full backup.
	 *
	 * These are security exclusions, not user-configurable source filters.
	 *
	 * @param string $relative Relative WordPress path.
	 * @return bool
	 */
	private function is_mandatory_file_exclusion( string $relative ): bool {
		$segments = explode( '/', $relative );
		$basename = (string) end( $segments );

		return (
			'wp-config.php' === $relative
			|| '.env' === $basename
			|| str_starts_with( $basename, '.env.' )
			|| in_array( '.git', $segments, true )
			|| in_array( '.svn', $segments, true )
		);
	}

	/**
	 * Detects the SQLite compatibility layer used by WordPress Playground tests.
	 *
	 * Production WordPress support targets MySQL and MariaDB.
	 *
	 * @return bool
	 */
	private function is_sqlite_database(): bool {
		global $wpdb;

		if ( defined( 'SQLITE_DB' ) ) {
			return true;
		}

		if ( str_contains( strtolower( get_class( $wpdb ) ), 'sqlite' ) ) {
			return true;
		}

		if ( method_exists( $wpdb, 'db_server_info' ) ) {
			return str_contains( strtolower( (string) $wpdb->db_server_info() ), 'sqlite' );
		}

		return false;
	}

	/**
	 * Validates a database table identifier and current prefix.
	 *
	 * @param string $table Table name.
	 * @return bool
	 */
	private function is_safe_table_name( string $table ): bool {
		global $wpdb;

		return (
			str_starts_with( $table, $wpdb->prefix )
			&& 1 === preg_match( '/\A[A-Za-z0-9_$]+\z/', $table )
		);
	}

	/**
	 * Removes a plugin-created temporary directory.
	 *
	 * @param string $directory Directory path.
	 * @return void
	 */
	private function remove_directory( string $directory ): void {
		if ( ! is_dir( $directory ) || ! str_starts_with( $directory, $this->storage->directory() . DIRECTORY_SEPARATOR . 'tmp-' ) ) {
			return;
		}

		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $directory, FilesystemIterator::SKIP_DOTS ),
			RecursiveIteratorIterator::CHILD_FIRST
		);

		foreach ( $iterator as $item ) {
			if ( $item->isDir() ) {
				rmdir( $item->getPathname() ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
			} else {
				wp_delete_file( $item->getPathname() );
			}
		}

		rmdir( $directory ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
	}
}
