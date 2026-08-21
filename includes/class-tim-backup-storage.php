<?php
/**
 * Protected local backup storage.
 *
 * @package TIM_Backup
 */

defined( 'ABSPATH' ) || exit;

/**
 * Manages plugin-owned backup files and metadata.
 */
final class TIM_Backup_Storage {

	/**
	 * Maximum number of locally managed backups.
	 */
	private const RETENTION_LIMIT = 3;

	/**
	 * Metadata option name.
	 */
	private const INDEX_OPTION = 'tim_backup_index';

	/**
	 * Storage directory.
	 *
	 * @var string
	 */
	private string $directory;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$custom_directory = defined( 'TIM_BACKUP_STORAGE_DIR' ) ? constant( 'TIM_BACKUP_STORAGE_DIR' ) : '';

		if ( is_string( $custom_directory ) && '' !== $custom_directory ) {
			$this->directory = untrailingslashit( $custom_directory );
			return;
		}

		$document_root = $this->document_root();
		$reference     = false !== $document_root ? $document_root : realpath( ABSPATH );
		$base          = false !== $reference ? dirname( $reference ) : dirname( untrailingslashit( ABSPATH ) );
		$site_id       = substr( hash( 'sha256', home_url( '/' ) ), 0, 12 );

		$this->directory = trailingslashit( $base ) . 'tim-backup-storage-' . $site_id;
	}

	/**
	 * Creates protected storage when needed.
	 *
	 * @return true|WP_Error
	 */
	public function ensure_directory() {
		if ( ! $this->is_outside_document_root() ) {
			return new WP_Error(
				'tim_backup_storage_public',
				__( 'The backup directory must be outside the public document root. Define TIM_BACKUP_STORAGE_DIR with a private writable path.', 'tim-backup-free' )
			);
		}

		if ( ! is_dir( $this->directory ) && ! wp_mkdir_p( $this->directory ) ) {
			return new WP_Error(
				'tim_backup_storage_create_failed',
				__( 'TIM Backup could not create private storage outside the document root. Define TIM_BACKUP_STORAGE_DIR with a writable private path.', 'tim-backup-free' )
			);
		}

		$protection_files = array(
			'index.php'  => "<?php\n// Silence is golden.\n",
			'.htaccess'  => "Require all denied\nDeny from all\n",
			'web.config' => "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<configuration><system.webServer><authorization><remove users=\"*\" roles=\"\" verbs=\"\"/><add accessType=\"Deny\" users=\"*\"/></authorization></system.webServer></configuration>\n",
		);

		foreach ( $protection_files as $filename => $contents ) {
			$path = $this->directory . DIRECTORY_SEPARATOR . $filename;

			if ( ! file_exists( $path ) && false === file_put_contents( $path, $contents, LOCK_EX ) ) {
				return new WP_Error(
					'tim_backup_storage_protection_failed',
					__( 'TIM Backup could not protect its storage directory.', 'tim-backup-free' )
				);
			}
		}

		return true;
	}

	/**
	 * Returns the private storage directory.
	 *
	 * @return string
	 */
	public function directory(): string {
		return $this->directory;
	}

	/**
	 * Confirms that archives cannot be addressed below the public web root.
	 *
	 * @return bool
	 */
	private function is_outside_document_root(): bool {
		$document_root = $this->document_root();
		$public_root   = false !== $document_root ? $document_root : realpath( ABSPATH );

		if ( false === $public_root ) {
			return false;
		}

		$resolved_storage = realpath( $this->directory );

		if ( false === $resolved_storage ) {
			$resolved_parent = realpath( dirname( $this->directory ) );

			if ( false === $resolved_parent ) {
				return false;
			}

			$resolved_storage = trailingslashit( $resolved_parent ) . basename( $this->directory );
		}

		$storage = wp_normalize_path( $resolved_storage );
		$public  = untrailingslashit( wp_normalize_path( $public_root ) );

		return $storage !== $public && ! str_starts_with( $storage, $public . '/' );
	}

	/**
	 * Resolves the web server's public document root.
	 *
	 * @return string|false
	 */
	private function document_root() {
		$document_root = filter_input( INPUT_SERVER, 'DOCUMENT_ROOT', FILTER_UNSAFE_RAW );

		if ( ! is_string( $document_root ) || '' === $document_root ) {
			return false;
		}

		return realpath( $document_root );
	}

	/**
	 * Creates a cryptographically random backup identity.
	 *
	 * @return string
	 */
	public function create_id(): string {
		return bin2hex( random_bytes( 16 ) );
	}

	/**
	 * Builds an archive path from a trusted-format identifier.
	 *
	 * @param string $id Backup identifier.
	 * @return string|WP_Error
	 */
	public function archive_path( string $id ) {
		if ( 1 !== preg_match( '/\A[a-f0-9]{32}\z/', $id ) ) {
			return new WP_Error(
				'tim_backup_invalid_id',
				__( 'The backup identifier is invalid.', 'tim-backup-free' )
			);
		}

		return $this->directory . DIRECTORY_SEPARATOR . 'tim-backup-' . $id . '.zip';
	}

	/**
	 * Returns indexed backups, newest first.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function all(): array {
		$items = get_option( self::INDEX_OPTION, array() );

		if ( ! is_array( $items ) ) {
			return array();
		}

		$valid = array();

		foreach ( $items as $item ) {
			if ( ! is_array( $item ) || empty( $item['id'] ) ) {
				continue;
			}

			$path = $this->archive_path( (string) $item['id'] );

			if ( is_wp_error( $path ) || ! is_file( $path ) ) {
				continue;
			}

			$current_size    = (int) filesize( $path );
			$item['verified'] = ! empty( $item['verified'] ) && (int) ( $item['size'] ?? -1 ) === $current_size;
			$item['size']     = $current_size;
			$valid[]          = $item;
		}

		usort(
			$valid,
			static function ( array $left, array $right ): int {
				return (int) $right['created_at'] <=> (int) $left['created_at'];
			}
		);

		if ( $valid !== $items ) {
			update_option( self::INDEX_OPTION, $valid, false );
		}

		return $valid;
	}

	/**
	 * Returns normal managed backups without rollback artifacts.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function backups(): array {
		return array_values(
			array_filter(
				$this->all(),
				static function ( array $item ): bool {
					return 'rollback' !== (string) ( $item['purpose'] ?? 'backup' );
				}
			)
		);
	}

	/**
	 * Returns the newest managed rollback artifact.
	 *
	 * @return array<string, mixed>|null
	 */
	public function rollback(): ?array {
		foreach ( $this->all() as $item ) {
			if ( 'rollback' === (string) ( $item['purpose'] ?? 'backup' ) ) {
				return $item;
			}
		}

		return null;
	}

	/**
	 * Updates an indexed archive purpose without changing its file.
	 *
	 * @param string $id Archive identifier.
	 * @param string $purpose Archive purpose.
	 * @return true|WP_Error
	 */
	public function set_purpose( string $id, string $purpose ) {
		if ( ! in_array( $purpose, array( 'backup', 'rollback' ), true ) ) {
			return new WP_Error(
				'tim_backup_invalid_purpose',
				__( 'The requested archive purpose is invalid.', 'tim-backup-free' )
			);
		}

		$items = $this->all();
		$found = false;

		foreach ( $items as &$item ) {
			if ( hash_equals( (string) ( $item['id'] ?? '' ), $id ) ) {
				$item['purpose'] = $purpose;
				$found           = true;
				break;
			}
		}
		unset( $item );

		if ( ! $found ) {
			return new WP_Error(
				'tim_backup_not_found',
				__( 'The requested backup does not exist.', 'tim-backup-free' )
			);
		}

		return $this->save_index( $items );
	}

	/**
	 * Finds one indexed backup.
	 *
	 * @param string $id Backup identifier.
	 * @return array<string, mixed>|WP_Error
	 */
	public function find( string $id ) {
		foreach ( $this->all() as $item ) {
			if ( hash_equals( (string) $item['id'], $id ) ) {
				return $item;
			}
		}

		return new WP_Error(
			'tim_backup_not_found',
			__( 'The requested backup does not exist.', 'tim-backup-free' )
		);
	}

	/**
	 * Registers a completed backup and applies retention.
	 *
	 * @param array<string, mixed> $metadata Backup metadata.
	 * @param string               $protected_id Backup that retention must keep.
	 * @return true|WP_Error
	 */
	public function register( array $metadata, string $protected_id = '' ) {
		if ( empty( $metadata['id'] ) || is_wp_error( $this->archive_path( (string) $metadata['id'] ) ) ) {
			return new WP_Error(
				'tim_backup_invalid_metadata',
				__( 'The backup metadata is invalid.', 'tim-backup-free' )
			);
		}

		$items   = $this->all();
		$items[] = $metadata;

		usort(
			$items,
			static function ( array $left, array $right ): int {
				return (int) $right['created_at'] <=> (int) $left['created_at'];
			}
		);

		$expired_items = array();
		$item_count    = count( $items );
		$backup_count  = count(
			array_filter(
				$items,
				static function ( array $item ): bool {
					return 'rollback' !== (string) ( $item['purpose'] ?? 'backup' );
				}
			)
		);

		while ( $backup_count > self::RETENTION_LIMIT ) {
			$expired_index = null;

			for ( $index = $item_count - 1; $index >= 0; --$index ) {
				if (
					'rollback' !== (string) ( $items[ $index ]['purpose'] ?? 'backup' )
					&& ( '' === $protected_id || ! hash_equals( (string) $items[ $index ]['id'], $protected_id ) )
				) {
					$expired_index = $index;
					break;
				}
			}

			if ( null === $expired_index ) {
				return new WP_Error(
					'tim_backup_retention_failed',
					__( 'No backup could be selected for local rotation.', 'tim-backup-free' )
				);
			}

			$expired_items[] = $items[ $expired_index ];
			array_splice( $items, $expired_index, 1 );
			--$item_count;
			--$backup_count;
		}

		update_option( self::INDEX_OPTION, $items, false );
		wp_cache_delete( self::INDEX_OPTION, 'options' );
		wp_cache_delete( 'alloptions', 'options' );

		$stored_items = get_option( self::INDEX_OPTION, array() );
		$expected_ids = wp_list_pluck( $items, 'id' );
		$stored_ids   = is_array( $stored_items ) ? wp_list_pluck( $stored_items, 'id' ) : array();

		if ( $expected_ids !== $stored_ids ) {
			return new WP_Error(
				'tim_backup_index_update_failed',
				__( 'The backup index could not be updated.', 'tim-backup-free' )
			);
		}

		foreach ( $expired_items as $expired ) {
			if ( is_array( $expired ) && ! empty( $expired['id'] ) ) {
				$path = $this->archive_path( (string) $expired['id'] );

				if ( ! is_wp_error( $path ) && is_file( $path ) ) {
					wp_delete_file( $path );
				}
			}
		}

		return true;
	}

	/**
	 * Restores an index captured before database replacement.
	 *
	 * @param array<int, array<string, mixed>> $items Backup metadata.
	 * @return true|WP_Error
	 */
	public function save_index( array $items ) {
		$valid = array();

		foreach ( $items as $item ) {
			if ( ! is_array( $item ) || empty( $item['id'] ) ) {
				continue;
			}

			$path = $this->archive_path( (string) $item['id'] );

			if ( is_wp_error( $path ) || ! is_file( $path ) ) {
				continue;
			}

			$valid[] = $item;
		}

		wp_cache_delete( self::INDEX_OPTION, 'options' );
		wp_cache_delete( 'alloptions', 'options' );
		update_option( self::INDEX_OPTION, $valid, false );
		wp_cache_delete( self::INDEX_OPTION, 'options' );
		wp_cache_delete( 'alloptions', 'options' );

		$stored_items = get_option( self::INDEX_OPTION, array() );
		$expected_ids = wp_list_pluck( $valid, 'id' );
		$stored_ids   = is_array( $stored_items ) ? wp_list_pluck( $stored_items, 'id' ) : array();

		if ( $expected_ids !== $stored_ids ) {
			return new WP_Error(
				'tim_backup_index_restore_failed',
				__( 'The backup index could not be preserved after restore.', 'tim-backup-free' )
			);
		}

		return true;
	}

	/**
	 * Deletes one managed backup.
	 *
	 * @param string $id Backup identifier.
	 * @return true|WP_Error
	 */
	public function delete( string $id ) {
		$backup = $this->find( $id );

		if ( is_wp_error( $backup ) ) {
			return $backup;
		}

		$path = $this->archive_path( $id );

		if ( is_wp_error( $path ) ) {
			return $path;
		}

		if ( is_file( $path ) && ! wp_delete_file( $path ) ) {
			return new WP_Error(
				'tim_backup_delete_failed',
				__( 'The backup archive could not be deleted.', 'tim-backup-free' )
			);
		}

		$items = array_values(
			array_filter(
				$this->all(),
				static function ( array $item ) use ( $id ): bool {
					return ! hash_equals( (string) $item['id'], $id );
				}
			)
		);

		update_option( self::INDEX_OPTION, $items, false );

		return true;
	}

	/**
	 * Acquires an atomic operation lock.
	 *
	 * @param string $operation Operation name.
	 * @param bool   $shared Whether a shared read lock is sufficient.
	 * @return resource|WP_Error
	 */
	public function acquire_lock( string $operation, bool $shared = false ) {
		$operation = sanitize_key( $operation );
		$path      = $this->directory . DIRECTORY_SEPARATOR . $operation . '.lock';
		$handle    = @fopen( $path, 'c+' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen,WordPress.PHP.NoSilencedErrors.Discouraged -- flock needs a persistent handle; failure is handled below.

		if ( false === $handle || ! flock( $handle, ( $shared ? LOCK_SH : LOCK_EX ) | LOCK_NB ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_flock -- OS-level locking prevents stale-lock races.
			if ( is_resource( $handle ) ) {
				fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			}

			return new WP_Error(
				'tim_backup_operation_locked',
				__( 'Another TIM Backup operation is already running.', 'tim-backup-free' )
			);
		}

		if ( ! $shared ) {
			ftruncate( $handle, 0 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_ftruncate -- Writes only lock diagnostics.
			rewind( $handle );
			fwrite( $handle, (string) getmypid() . ':' . (string) time() ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Writes only lock diagnostics.
			fflush( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fflush -- Flushes only lock diagnostics.
		}

		return $handle;
	}

	/**
	 * Releases an operation lock.
	 *
	 * @param string   $operation Operation name.
	 * @param resource $handle Lock handle.
	 * @return void
	 */
	public function release_lock( string $operation, $handle ): void {
		unset( $operation );

		if ( is_resource( $handle ) ) {
			flock( $handle, LOCK_UN ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_flock -- Paired with acquire_lock().
			fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Paired with the atomic lock handle.
		}
	}
}
