<?php
/**
 * Validated backup restoration.
 *
 * @package TIM_Backup
 */

defined( 'ABSPATH' ) || exit;

/**
 * Restores archives produced by TIM Backup.
 */
final class TIM_Backup_Restore_Service {

	/**
	 * Storage service.
	 *
	 * @var TIM_Backup_Storage
	 */
	private TIM_Backup_Storage $storage;

	/**
	 * Backup and verification service.
	 *
	 * @var TIM_Backup_Backup_Service
	 */
	private TIM_Backup_Backup_Service $backups;

	/**
	 * Constructor.
	 *
	 * @param TIM_Backup_Storage        $storage Storage service.
	 * @param TIM_Backup_Backup_Service $backups Backup service.
	 */
	public function __construct( TIM_Backup_Storage $storage, TIM_Backup_Backup_Service $backups ) {
		$this->storage = $storage;
		$this->backups = $backups;
	}

	/**
	 * Creates persistent state for a staged database restore.
	 *
	 * @param array<string, string> $schema Signed database schema.
	 * @return array<string, mixed>|WP_Error
	 */
	public function create_staged_state( array $schema ) {
		global $wpdb;

		if ( empty( $schema ) ) {
			return new WP_Error(
				'tim_backup_restore_schema_invalid',
				__( 'The database schema in the backup is invalid.', 'tim-backup-free' )
			);
		}

		if ( strlen( $wpdb->prefix ) > 30 ) {
			return new WP_Error(
				'tim_backup_restore_prefix_too_long',
				__( 'The database prefix is too long for a safe staged restore.', 'tim-backup-free' )
			);
		}

		$token     = substr( bin2hex( random_bytes( 8 ) ), 0, 10 );
		$table_map = array();

		foreach ( $schema as $table => $create_sql ) {
			$table      = (string) $table;
			$create_sql = (string) $create_sql;

			if (
				! $this->is_safe_table_name( $table )
				|| str_contains( strtoupper( $create_sql ), 'FOREIGN KEY' )
			) {
				return new WP_Error(
					'tim_backup_restore_schema_unsupported',
					__( 'A database table schema cannot be restored safely by this version.', 'tim-backup-free' )
				);
			}

			$hash      = substr( hash( 'sha256', $table ), 0, 10 );
			$new_table = substr( $wpdb->prefix . 'tim_new_' . $token . '_' . $hash, 0, 64 );
			$old_table = substr( $wpdb->prefix . 'tim_old_' . $token . '_' . $hash, 0, 64 );

			if ( ! $this->is_safe_table_name( $new_table ) || ! $this->is_safe_table_name( $old_table ) ) {
				return new WP_Error(
					'tim_backup_restore_table_invalid',
					__( 'A temporary database table name is invalid.', 'tim-backup-free' )
				);
			}

			$table_map[ $table ] = array(
				'new' => $new_table,
				'old' => $old_table,
			);
		}

		return array(
			'table_map'     => $table_map,
			'tables'        => array_keys( $table_map ),
			'prepare_index' => 0,
			'import_index'  => 0,
			'import_chunk_index' => 0,
			'import_offset' => 0,
			'rows_imported' => 0,
			'swapped'       => false,
		);
	}

	/**
	 * Creates one empty staging table.
	 *
	 * Repeating this method before import is safe because the staging table is
	 * recreated from scratch.
	 *
	 * @param array<string, string> $schema Signed database schema.
	 * @param array<string, mixed>  $state Persistent restore state.
	 * @return array<string, mixed>|WP_Error
	 */
	public function prepare_next_table( array $schema, array $state ) {
		global $wpdb;

		$tables = isset( $state['tables'] ) && is_array( $state['tables'] ) ? $state['tables'] : array();
		$index  = (int) ( $state['prepare_index'] ?? 0 );

		if ( $index >= count( $tables ) ) {
			return $state;
		}

		$table      = (string) $tables[ $index ];
		$table_map  = isset( $state['table_map'] ) && is_array( $state['table_map'] ) ? $state['table_map'] : array();
		$names      = isset( $table_map[ $table ] ) && is_array( $table_map[ $table ] ) ? $table_map[ $table ] : array();
		$new_table  = (string) ( $names['new'] ?? '' );
		$create_sql = (string) ( $schema[ $table ] ?? '' );

		if ( ! $this->is_safe_table_name( $table ) || ! $this->is_safe_table_name( $new_table ) ) {
			return new WP_Error(
				'tim_backup_restore_table_invalid',
				__( 'A temporary database table name is invalid.', 'tim-backup-free' )
			);
		}

		$replacement = 'CREATE TABLE `' . $new_table . '`';
		$pattern     = '/\ACREATE TABLE\s+`' . preg_quote( $table, '/' ) . '`/i';
		$staged_sql  = preg_replace( $pattern, $replacement, $create_sql, 1, $count );

		if ( 1 !== $count || ! is_string( $staged_sql ) ) {
			return new WP_Error(
				'tim_backup_restore_create_invalid',
				__( 'A database CREATE statement did not match its table.', 'tim-backup-free' )
			);
		}

		$wpdb->query( 'DROP TABLE IF EXISTS `' . esc_sql( $new_table ) . '`' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Identifier is strictly validated.

		if ( false === $wpdb->query( $staged_sql ) ) { // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Signed and strictly validated CREATE TABLE statement.
			return new WP_Error(
				'tim_backup_restore_create_failed',
				__( 'A temporary database table could not be created.', 'tim-backup-free' )
			);
		}

		$state['prepare_index'] = $index + 1;

		return $state;
	}

	/**
	 * Imports a bounded number of rows into the current staging table.
	 *
	 * REPLACE makes retrying a batch safe if a request ends after database writes
	 * but before its updated byte offset reaches the journal.
	 *
	 * @param string               $data_path Verified JSON Lines file.
	 * @param array<string, mixed> $state Persistent restore state.
	 * @param int                  $row_limit Maximum rows per request.
	 * @param float                $time_limit Maximum processing seconds.
	 * @param bool                 $last_chunk Whether this is the table's final chunk.
	 * @return array<string, mixed>|WP_Error
	 */
	public function import_next_batch(
		string $data_path,
		array $state,
		int $row_limit = 500,
		float $time_limit = 5.0,
		bool $last_chunk = true
	) {
		global $wpdb;

		$tables = isset( $state['tables'] ) && is_array( $state['tables'] ) ? $state['tables'] : array();
		$index  = (int) ( $state['import_index'] ?? 0 );

		if ( $index >= count( $tables ) ) {
			return $state;
		}

		$table     = (string) $tables[ $index ];
		$table_map = isset( $state['table_map'] ) && is_array( $state['table_map'] ) ? $state['table_map'] : array();
		$names     = isset( $table_map[ $table ] ) && is_array( $table_map[ $table ] ) ? $table_map[ $table ] : array();
		$new_table = (string) ( $names['new'] ?? '' );

		if ( ! $this->is_safe_table_name( $new_table ) || ! is_file( $data_path ) ) {
			return new WP_Error(
				'tim_backup_restore_data_missing',
				__( 'Database table data is missing from the backup.', 'tim-backup-free' )
			);
		}

		$handle = @fopen( $data_path, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen,WordPress.PHP.NoSilencedErrors.Discouraged -- Private verified stream; failure is handled.

		if ( false === $handle ) {
			return new WP_Error(
				'tim_backup_restore_data_missing',
				__( 'Database table data is missing from the backup.', 'tim-backup-free' )
			);
		}

		$offset = max( 0, (int) ( $state['import_offset'] ?? 0 ) );

		if ( 0 !== fseek( $handle, $offset ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fseek -- Resume requires an exact private-file offset.
			fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			return new WP_Error(
				'tim_backup_restore_resume_failed',
				__( 'The database restore could not resume from its saved position.', 'tim-backup-free' )
			);
		}

		$started = microtime( true );
		$rows    = 0;

		while ( $rows < $row_limit && microtime( true ) - $started < $time_limit ) {
			$line = fgets( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fgets

			if ( false === $line ) {
				if ( $last_chunk ) {
					$state['import_index']       = $index + 1;
					$state['import_chunk_index'] = 0;
				} else {
					$state['import_chunk_index'] = (int) ( $state['import_chunk_index'] ?? 0 ) + 1;
				}

				$state['import_offset'] = 0;
				$state['rows_imported'] = (int) ( $state['rows_imported'] ?? 0 ) + $rows;
				fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
				return $state;
			}

			$decoded_row = $this->decode_row( $line );

			if ( is_wp_error( $decoded_row ) ) {
				fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
				return $decoded_row;
			}

			if ( false === $wpdb->replace( $new_table, $decoded_row ) ) {
				fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
				return new WP_Error(
					'tim_backup_restore_insert_failed',
					__( 'A database row could not be restored.', 'tim-backup-free' )
				);
			}

			++$rows;
		}

		$position = ftell( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_ftell -- Persists the exact resume cursor.
		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose

		if ( false === $position ) {
			return new WP_Error(
				'tim_backup_restore_resume_failed',
				__( 'The database restore could not save its current position.', 'tim-backup-free' )
			);
		}

		$state['import_offset'] = $position;
		$state['rows_imported'] = (int) ( $state['rows_imported'] ?? 0 ) + $rows;

		return $state;
	}

	/**
	 * Atomically activates all staged tables.
	 *
	 * @param array<string, mixed> $state Persistent restore state.
	 * @return array<string, mixed>|WP_Error
	 */
	public function activate_staged_tables( array $state ) {
		global $wpdb;

		$table_map   = isset( $state['table_map'] ) && is_array( $state['table_map'] ) ? $state['table_map'] : array();
		$already_done = true;
		$ready        = true;

		foreach ( $table_map as $table => $names ) {
			$table       = (string) $table;
			$new_table   = (string) ( $names['new'] ?? '' );
			$old_table   = (string) ( $names['old'] ?? '' );
			$live_exists = $this->table_exists( $table );
			$new_exists  = $this->table_exists( $new_table );
			$old_exists  = $this->table_exists( $old_table );

			$already_done = $already_done && $live_exists && ! $new_exists && $old_exists;
			$ready        = $ready && $live_exists && $new_exists && ! $old_exists;
		}

		if ( $already_done ) {
			$state['swapped'] = true;
			return $state;
		}

		if ( ! $ready || empty( $table_map ) ) {
			return new WP_Error(
				'tim_backup_restore_swap_state_invalid',
				__( 'The staged database tables are not in a safe state for activation.', 'tim-backup-free' )
			);
		}

		$renames = array();

		foreach ( $table_map as $table => $names ) {
			$renames[] = '`' . esc_sql( (string) $table ) . '` TO `' . esc_sql( (string) $names['old'] ) . '`';
			$renames[] = '`' . esc_sql( (string) $names['new'] ) . '` TO `' . esc_sql( (string) $table ) . '`';
		}

		if ( false === $wpdb->query( 'RENAME TABLE ' . implode( ', ', $renames ) ) ) { // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Every identifier was strictly validated when state was created.
			return new WP_Error(
				'tim_backup_restore_swap_failed',
				__( 'The restored database tables could not be activated.', 'tim-backup-free' )
			);
		}

		$state['swapped'] = true;

		return $state;
	}

	/**
	 * Removes old or incomplete staging tables.
	 *
	 * @param array<string, mixed> $state Persistent restore state.
	 * @param bool                 $after_swap Whether old live tables should be removed.
	 * @return true|WP_Error
	 */
	public function cleanup_staged_tables( array $state, bool $after_swap ) {
		global $wpdb;

		$table_map = isset( $state['table_map'] ) && is_array( $state['table_map'] ) ? $state['table_map'] : array();

		foreach ( $table_map as $names ) {
			$table = $after_swap ? (string) ( $names['old'] ?? '' ) : (string) ( $names['new'] ?? '' );

			if ( $this->is_safe_table_name( $table ) ) {
				if ( false === $wpdb->query( 'DROP TABLE IF EXISTS `' . esc_sql( $table ) . '`' ) ) { // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Identifier is strictly validated.
					return new WP_Error(
						'tim_backup_restore_cleanup_failed',
						__( 'A temporary database table could not be removed safely.', 'tim-backup-free' )
					);
				}
			}
		}

		return true;
	}

	/**
	 * Restores a managed backup.
	 *
	 * Database tables are prepared under temporary names and swapped only after
	 * every row has been imported successfully.
	 *
	 * @param string $id Backup identifier.
	 * @return true|WP_Error
	 */
	public function restore( string $id ) {
		$backup = $this->storage->find( $id );

		if ( is_wp_error( $backup ) ) {
			return $backup;
		}

		$current_index = $this->storage->all();
		$archive_path = $this->storage->archive_path( $id );

		if ( is_wp_error( $archive_path ) ) {
			return $archive_path;
		}

		$manifest = $this->backups->verify( $archive_path );

		if ( is_wp_error( $manifest ) ) {
			return $manifest;
		}

		$lock = $this->storage->acquire_lock( 'operation' );

		if ( is_wp_error( $lock ) ) {
			return $lock;
		}

		$zip = new ZipArchive();

		if ( true !== $zip->open( $archive_path, ZipArchive::RDONLY ) ) {
			$this->storage->release_lock( 'operation', $lock );
			return new WP_Error(
				'tim_backup_restore_open_failed',
				__( 'The verified backup archive could not be opened for restore.', 'tim-backup-free' )
			);
		}

		$result = $this->restore_database( $zip );

		$zip->close();
		$this->storage->release_lock( 'operation', $lock );

		if ( ! is_wp_error( $result ) ) {
			$result = $this->storage->save_index( $current_index );
			wp_cache_flush();
			flush_rewrite_rules( false );
		}

		return $result;
	}

	/**
	 * Restores database tables by staging and atomically swapping them.
	 *
	 * @param ZipArchive $zip Open archive.
	 * @return true|WP_Error
	 */
	private function restore_database( ZipArchive $zip ) {
		global $wpdb;

		$schema_json = $zip->getFromName( 'tim-backup/database/schema.json' );
		$schema      = false === $schema_json ? null : json_decode( $schema_json, true );

		if ( ! is_array( $schema ) || empty( $schema ) ) {
			return new WP_Error(
				'tim_backup_restore_schema_invalid',
				__( 'The database schema in the backup is invalid.', 'tim-backup-free' )
			);
		}

		if ( strlen( $wpdb->prefix ) > 30 ) {
			return new WP_Error(
				'tim_backup_restore_prefix_too_long',
				__( 'The database prefix is too long for a safe staged restore.', 'tim-backup-free' )
			);
		}

		$token      = substr( bin2hex( random_bytes( 8 ) ), 0, 10 );
		$table_map  = array();
		$new_tables = array();

		$wpdb->query( 'SET FOREIGN_KEY_CHECKS=0' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Static session setting.

		foreach ( $schema as $table => $create_sql ) {
			$table      = (string) $table;
			$create_sql = (string) $create_sql;

			if ( ! $this->is_safe_table_name( $table ) || str_contains( strtoupper( $create_sql ), 'FOREIGN KEY' ) ) {
				$this->drop_temporary_tables( $new_tables );
				$wpdb->query( 'SET FOREIGN_KEY_CHECKS=1' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				return new WP_Error(
					'tim_backup_restore_schema_unsupported',
					__( 'A database table schema cannot be restored safely by this version.', 'tim-backup-free' )
				);
			}

			$hash      = substr( hash( 'sha256', $table ), 0, 10 );
			$new_table = substr( $wpdb->prefix . 'tim_new_' . $token . '_' . $hash, 0, 64 );
			$old_table = substr( $wpdb->prefix . 'tim_old_' . $token . '_' . $hash, 0, 64 );

			if ( ! $this->is_safe_table_name( $new_table ) || ! $this->is_safe_table_name( $old_table ) ) {
				$this->drop_temporary_tables( $new_tables );
				$wpdb->query( 'SET FOREIGN_KEY_CHECKS=1' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				return new WP_Error(
					'tim_backup_restore_table_invalid',
					__( 'A temporary database table name is invalid.', 'tim-backup-free' )
				);
			}

			$replacement = 'CREATE TABLE `' . $new_table . '`';
			$pattern     = '/\ACREATE TABLE\s+`' . preg_quote( $table, '/' ) . '`/i';
			$staged_sql  = preg_replace( $pattern, $replacement, $create_sql, 1, $count );

			if ( 1 !== $count || ! is_string( $staged_sql ) ) {
				$this->drop_temporary_tables( $new_tables );
				$wpdb->query( 'SET FOREIGN_KEY_CHECKS=1' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				return new WP_Error(
					'tim_backup_restore_create_invalid',
					__( 'A database CREATE statement did not match its table.', 'tim-backup-free' )
				);
			}

			$wpdb->query( 'DROP TABLE IF EXISTS `' . esc_sql( $new_table ) . '`' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Identifier is strictly validated.

			if ( false === $wpdb->query( $staged_sql ) ) { // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Signed and strictly validated CREATE TABLE statement.
				$this->drop_temporary_tables( $new_tables );
				$wpdb->query( 'SET FOREIGN_KEY_CHECKS=1' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				return new WP_Error(
					'tim_backup_restore_create_failed',
					__( 'A temporary database table could not be created.', 'tim-backup-free' )
				);
			}

			$new_tables[]       = $new_table;
			$table_map[ $table ] = array(
				'new' => $new_table,
				'old' => $old_table,
			);

			$data_entry = 'tim-backup/database/data/' . hash( 'sha256', $table ) . '.jsonl';
			$data_stream = $zip->getStream( $data_entry );

			if ( false === $data_stream ) {
				$this->drop_temporary_tables( $new_tables );
				$wpdb->query( 'SET FOREIGN_KEY_CHECKS=1' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				return new WP_Error(
					'tim_backup_restore_data_missing',
					__( 'Database table data is missing from the backup.', 'tim-backup-free' )
				);
			}

			while ( true ) {
				$line = fgets( $data_stream ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fgets

				if ( false === $line ) {
					break;
				}

				$encoded = json_decode( trim( $line ), true );

				if ( ! is_array( $encoded ) ) {
					fclose( $data_stream ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
					$this->drop_temporary_tables( $new_tables );
					$wpdb->query( 'SET FOREIGN_KEY_CHECKS=1' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
					return new WP_Error(
						'tim_backup_restore_row_invalid',
						__( 'A database row in the backup is invalid.', 'tim-backup-free' )
					);
				}

				$row = array();

				foreach ( $encoded as $column => $value ) {
					if ( 1 !== preg_match( '/\A[A-Za-z0-9_$]+\z/', (string) $column ) ) {
						fclose( $data_stream ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
						$this->drop_temporary_tables( $new_tables );
						$wpdb->query( 'SET FOREIGN_KEY_CHECKS=1' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
						return new WP_Error(
							'tim_backup_restore_column_invalid',
							__( 'A database column name in the backup is invalid.', 'tim-backup-free' )
						);
					}

					$decoded = null === $value ? null : base64_decode( (string) $value, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Decodes binary-safe database transport, not executable code.

					if ( null !== $value && false === $decoded ) {
						fclose( $data_stream ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
						$this->drop_temporary_tables( $new_tables );
						$wpdb->query( 'SET FOREIGN_KEY_CHECKS=1' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
						return new WP_Error(
							'tim_backup_restore_value_invalid',
							__( 'A database value in the backup is invalid.', 'tim-backup-free' )
						);
					}

					$row[ (string) $column ] = $decoded;
				}

				if ( false === $wpdb->insert( $new_table, $row ) ) {
					fclose( $data_stream ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
					$this->drop_temporary_tables( $new_tables );
					$wpdb->query( 'SET FOREIGN_KEY_CHECKS=1' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
					return new WP_Error(
						'tim_backup_restore_insert_failed',
						__( 'A database row could not be restored.', 'tim-backup-free' )
					);
				}
			}

			fclose( $data_stream ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		}

		$renames = array();

		foreach ( $table_map as $table => $names ) {
			$wpdb->query( 'DROP TABLE IF EXISTS `' . esc_sql( $names['old'] ) . '`' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Identifier is strictly validated.
			$renames[] = '`' . esc_sql( $table ) . '` TO `' . esc_sql( $names['old'] ) . '`';
			$renames[] = '`' . esc_sql( $names['new'] ) . '` TO `' . esc_sql( $table ) . '`';
		}

		if ( false === $wpdb->query( 'RENAME TABLE ' . implode( ', ', $renames ) ) ) { // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Every identifier is strictly validated.
			$this->drop_temporary_tables( $new_tables );
			$wpdb->query( 'SET FOREIGN_KEY_CHECKS=1' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			return new WP_Error(
				'tim_backup_restore_swap_failed',
				__( 'The restored database tables could not be activated.', 'tim-backup-free' )
			);
		}

		foreach ( $table_map as $names ) {
			$wpdb->query( 'DROP TABLE IF EXISTS `' . esc_sql( $names['old'] ) . '`' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Identifier is strictly validated.
		}

		$wpdb->query( 'SET FOREIGN_KEY_CHECKS=1' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		return true;
	}

	/**
	 * Drops staged tables after a failed restore.
	 *
	 * @param array<int, string> $tables Table names.
	 * @return void
	 */
	private function drop_temporary_tables( array $tables ): void {
		global $wpdb;

		foreach ( $tables as $table ) {
			if ( $this->is_safe_table_name( $table ) ) {
				$wpdb->query( 'DROP TABLE IF EXISTS `' . esc_sql( $table ) . '`' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Identifier is strictly validated.
			}
		}
	}

	/**
	 * Decodes and validates one database row from the signed export.
	 *
	 * @param string $line JSON Lines row.
	 * @return array<string, string|null>|WP_Error
	 */
	private function decode_row( string $line ) {
		$encoded = json_decode( trim( $line ), true );

		if ( ! is_array( $encoded ) ) {
			return new WP_Error(
				'tim_backup_restore_row_invalid',
				__( 'A database row in the backup is invalid.', 'tim-backup-free' )
			);
		}

		$row = array();

		foreach ( $encoded as $column => $value ) {
			if ( 1 !== preg_match( '/\A[A-Za-z0-9_$]+\z/', (string) $column ) ) {
				return new WP_Error(
					'tim_backup_restore_column_invalid',
					__( 'A database column name in the backup is invalid.', 'tim-backup-free' )
				);
			}

			$decoded = null === $value ? null : base64_decode( (string) $value, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Decodes binary-safe database transport, not executable code.

			if ( null !== $value && false === $decoded ) {
				return new WP_Error(
					'tim_backup_restore_value_invalid',
					__( 'A database value in the backup is invalid.', 'tim-backup-free' )
				);
			}

			$row[ (string) $column ] = $decoded;
		}

		return $row;
	}

	/**
	 * Checks an already validated table identifier.
	 *
	 * @param string $table Table name.
	 * @return bool
	 */
	private function table_exists( string $table ): bool {
		global $wpdb;

		if ( ! $this->is_safe_table_name( $table ) ) {
			return false;
		}

		$found = $wpdb->get_var(
			$wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Value is prepared.
		);

		return is_string( $found ) && hash_equals( $table, $found );
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
}
