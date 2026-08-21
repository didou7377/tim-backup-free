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
				__( 'The verified backup archive could not be opened for restore.', 'tim-backup' )
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
				__( 'The database schema in the backup is invalid.', 'tim-backup' )
			);
		}

		if ( strlen( $wpdb->prefix ) > 30 ) {
			return new WP_Error(
				'tim_backup_restore_prefix_too_long',
				__( 'The database prefix is too long for a safe staged restore.', 'tim-backup' )
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
					__( 'A database table schema cannot be restored safely by this version.', 'tim-backup' )
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
					__( 'A temporary database table name is invalid.', 'tim-backup' )
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
					__( 'A database CREATE statement did not match its table.', 'tim-backup' )
				);
			}

			$wpdb->query( 'DROP TABLE IF EXISTS `' . esc_sql( $new_table ) . '`' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Identifier is strictly validated.

			if ( false === $wpdb->query( $staged_sql ) ) { // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Signed and strictly validated CREATE TABLE statement.
				$this->drop_temporary_tables( $new_tables );
				$wpdb->query( 'SET FOREIGN_KEY_CHECKS=1' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				return new WP_Error(
					'tim_backup_restore_create_failed',
					__( 'A temporary database table could not be created.', 'tim-backup' )
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
					__( 'Database table data is missing from the backup.', 'tim-backup' )
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
						__( 'A database row in the backup is invalid.', 'tim-backup' )
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
							__( 'A database column name in the backup is invalid.', 'tim-backup' )
						);
					}

					$decoded = null === $value ? null : base64_decode( (string) $value, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Decodes binary-safe database transport, not executable code.

					if ( null !== $value && false === $decoded ) {
						fclose( $data_stream ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
						$this->drop_temporary_tables( $new_tables );
						$wpdb->query( 'SET FOREIGN_KEY_CHECKS=1' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
						return new WP_Error(
							'tim_backup_restore_value_invalid',
							__( 'A database value in the backup is invalid.', 'tim-backup' )
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
						__( 'A database row could not be restored.', 'tim-backup' )
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
				__( 'The restored database tables could not be activated.', 'tim-backup' )
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
