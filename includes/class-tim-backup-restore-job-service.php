<?php
/**
 * Persistent, resumable database restore jobs.
 *
 * @package TIM_Backup
 */

defined( 'ABSPATH' ) || exit;

/**
 * Orchestrates a restore across bounded authenticated requests.
 */
final class TIM_Backup_Restore_Job_Service {

	/**
	 * Journal filename below protected storage.
	 */
	private const JOURNAL_FILE = 'restore-job.json';

	/**
	 * Database-independent journal key filename.
	 */
	private const JOURNAL_KEY_FILE = 'restore-journal.key';

	/**
	 * Filesystem maintenance marker filename.
	 */
	private const MAINTENANCE_FILE = 'restore-maintenance.json';

	/**
	 * Maximum uncompressed database payload per independently verified entry.
	 */
	private const MAX_DATABASE_ENTRY_BYTES = 4 * 1024 * 1024;

	/**
	 * Ordered execution phases.
	 */
	private const PHASES = array(
		'verify',
		'safety',
		'extract',
		'prepare',
		'import',
		'swap',
		'cleanup',
		'complete',
	);

	/**
	 * Storage service.
	 *
	 * @var TIM_Backup_Storage
	 */
	private TIM_Backup_Storage $storage;

	/**
	 * Backup service.
	 *
	 * @var TIM_Backup_Backup_Service
	 */
	private TIM_Backup_Backup_Service $backups;

	/**
	 * Restore service.
	 *
	 * @var TIM_Backup_Restore_Service
	 */
	private TIM_Backup_Restore_Service $restore;

	/**
	 * Constructor.
	 *
	 * @param TIM_Backup_Storage         $storage Storage service.
	 * @param TIM_Backup_Backup_Service  $backups Backup service.
	 * @param TIM_Backup_Restore_Service $restore Restore service.
	 */
	public function __construct(
		TIM_Backup_Storage $storage,
		TIM_Backup_Backup_Service $backups,
		TIM_Backup_Restore_Service $restore
	) {
		$this->storage = $storage;
		$this->backups = $backups;
		$this->restore = $restore;
	}

	/**
	 * Starts one restore job.
	 *
	 * @param string $backup_id Managed backup identifier.
	 * @param int    $user_id Initiating administrator.
	 * @return array<string, mixed>|WP_Error
	 */
	public function start( string $backup_id, int $user_id ) {
		$ready = $this->storage->ensure_directory();

		if ( is_wp_error( $ready ) ) {
			return $ready;
		}

		$job_lock = $this->storage->acquire_lock( 'restore-job' );

		if ( is_wp_error( $job_lock ) ) {
			return $job_lock;
		}

		$current = $this->load();

		if ( is_wp_error( $current ) ) {
			$this->storage->release_lock( 'restore-job', $job_lock );
			return $current;
		}

		if (
			is_array( $current )
			&& in_array( (string) ( $current['status'] ?? '' ), array( 'active', 'error' ), true )
		) {
			$this->storage->release_lock( 'restore-job', $job_lock );
			return new WP_Error(
				'tim_backup_restore_job_active',
				__( 'Another database restore is already in progress.', 'tim-backup-free' )
			);
		}

		$backup = $this->storage->find( $backup_id );

		if ( is_wp_error( $backup ) ) {
			$this->storage->release_lock( 'restore-job', $job_lock );
			return $backup;
		}

		$job_id = bin2hex( random_bytes( 16 ) );
		$job    = array(
			'job_id'         => $job_id,
			'backup_id'      => $backup_id,
			'backup_type'    => (string) ( $backup['type'] ?? 'database' ),
			'backup_created' => (int) ( $backup['created_at'] ?? 0 ),
			'user_id'        => $user_id,
			'status'         => 'active',
			'phase'          => 'verify',
			'created_at'     => time(),
			'updated_at'     => time(),
			'entries'        => array(),
			'extract_index'  => 0,
			'backup_index'   => array(),
			'restore_state'  => array(),
			'error'          => '',
		);

		$this->remove_workspace_for_job( $job_id );

		$saved = $this->save( $job );
		$this->storage->release_lock( 'restore-job', $job_lock );

		return is_wp_error( $saved ) ? $saved : $this->public_status( $job );
	}

	/**
	 * Advances a job by one bounded unit of work.
	 *
	 * @return array<string, mixed>|WP_Error
	 */
	public function advance() {
		$job_lock = $this->storage->acquire_lock( 'restore-job' );

		if ( is_wp_error( $job_lock ) ) {
			return $job_lock;
		}

		$job = $this->load();

		if ( is_wp_error( $job ) ) {
			$this->storage->release_lock( 'restore-job', $job_lock );
			return $job;
		}

		if ( ! is_array( $job ) ) {
			$this->storage->release_lock( 'restore-job', $job_lock );
			return new WP_Error(
				'tim_backup_restore_job_missing',
				__( 'No database restore job is available.', 'tim-backup-free' )
			);
		}

		if ( 'active' !== (string) ( $job['status'] ?? '' ) ) {
			$state = isset( $job['restore_state'] ) && is_array( $job['restore_state'] ) ? $job['restore_state'] : array();

			if ( 'error' === (string) ( $job['status'] ?? '' ) && ( ! empty( $state['swapped'] ) || 'cleanup' === (string) ( $job['phase'] ?? '' ) ) ) {
				$job['status'] = 'active';
				$job['error']  = '';
			} else {
				$this->storage->release_lock( 'restore-job', $job_lock );
				return $this->public_status( $job );
			}
		}

		$phase  = (string) ( $job['phase'] ?? '' );
		$result = null;

		if ( 'safety' === $phase ) {
			$result = $this->create_safety_backup( $job );
		} else {
			$lock = $this->storage->acquire_lock( 'operation' );

			if ( is_wp_error( $lock ) ) {
				$this->storage->release_lock( 'restore-job', $job_lock );
				return $lock;
			}

			switch ( $phase ) {
				case 'verify':
					$result = $this->verify_archive( $job );
					break;
				case 'extract':
					$result = $this->extract_next_entry( $job );
					break;
				case 'prepare':
					$result = $this->prepare_next_table( $job );
					break;
				case 'import':
					$result = $this->import_next_batch( $job );
					break;
				case 'swap':
					$result = $this->activate_tables( $job );
					break;
				case 'cleanup':
					$result = $this->finish_job( $job );
					break;
				default:
					$result = new WP_Error(
						'tim_backup_restore_phase_invalid',
						__( 'The database restore has an invalid saved phase.', 'tim-backup-free' )
					);
			}

			$this->storage->release_lock( 'operation', $lock );
		}

		if ( is_wp_error( $result ) ) {
			$job['status']     = 'error';
			$job['error']      = $result->get_error_message();
			$job['updated_at'] = time();
			$this->save( $job );
			$this->storage->release_lock( 'restore-job', $job_lock );

			return $this->public_status( $job );
		}

		$job               = $result;
		$job['updated_at'] = time();
		$saved             = $this->save( $job );
		$this->storage->release_lock( 'restore-job', $job_lock );

		return is_wp_error( $saved ) ? $saved : $this->public_status( $job );
	}

	/**
	 * Returns the current public status.
	 *
	 * @return array<string, mixed>|WP_Error
	 */
	public function status() {
		$job = $this->load();

		if ( is_wp_error( $job ) ) {
			return $job;
		}

		if ( ! is_array( $job ) ) {
			return new WP_Error(
				'tim_backup_restore_job_missing',
				__( 'No database restore job is available.', 'tim-backup-free' )
			);
		}

		return $this->public_status( $job );
	}

	/**
	 * Cancels a restore before its atomic table activation.
	 *
	 * @return array<string, mixed>|WP_Error
	 */
	public function cancel() {
		$job_lock = $this->storage->acquire_lock( 'restore-job' );

		if ( is_wp_error( $job_lock ) ) {
			return $job_lock;
		}

		$job = $this->load();

		if ( is_wp_error( $job ) ) {
			$this->storage->release_lock( 'restore-job', $job_lock );
			return $job;
		}

		if ( ! is_array( $job ) ) {
			$this->storage->release_lock( 'restore-job', $job_lock );
			return new WP_Error(
				'tim_backup_restore_job_missing',
				__( 'No database restore job is available.', 'tim-backup-free' )
			);
		}

		$state = isset( $job['restore_state'] ) && is_array( $job['restore_state'] ) ? $job['restore_state'] : array();

		$phase  = (string) ( $job['phase'] ?? '' );
		$status = (string) ( $job['status'] ?? '' );

		if (
			! empty( $state['swapped'] )
			|| in_array( $phase, array( 'cleanup', 'complete' ), true )
			|| ( 'swap' === $phase && 'error' !== $status )
		) {
			$this->storage->release_lock( 'restore-job', $job_lock );
			return new WP_Error(
				'tim_backup_restore_cancel_too_late',
				__( 'The restore can no longer be cancelled because database activation has started.', 'tim-backup-free' )
			);
		}

		$lock = $this->storage->acquire_lock( 'operation' );

		if ( is_wp_error( $lock ) ) {
			$this->storage->release_lock( 'restore-job', $job_lock );
			return $lock;
		}

		if ( ! empty( $state ) ) {
			$cleaned = $this->restore->cleanup_staged_tables( $state, false );

			if ( is_wp_error( $cleaned ) ) {
				$this->storage->release_lock( 'operation', $lock );
				$this->storage->release_lock( 'restore-job', $job_lock );
				return $cleaned;
			}
		}

		$this->remove_workspace( $job );
		$maintenance = $this->disable_maintenance();

		if ( is_wp_error( $maintenance ) ) {
			$this->storage->release_lock( 'operation', $lock );
			$this->storage->release_lock( 'restore-job', $job_lock );
			return $maintenance;
		}

		$this->storage->release_lock( 'operation', $lock );

		$job['status']     = 'cancelled';
		$job['updated_at'] = time();
		$job['error']      = '';
		$saved             = $this->save( $job );
		$this->storage->release_lock( 'restore-job', $job_lock );

		return is_wp_error( $saved ) ? $saved : $this->public_status( $job );
	}

	/**
	 * Whether a restore currently blocks other state-changing operations.
	 *
	 * @return bool
	 */
	public function is_active(): bool {
		$job = $this->load();

		return is_wp_error( $job ) || ( is_array( $job ) && 'active' === (string) ( $job['status'] ?? '' ) );
	}

	/**
	 * Whether normal site traffic must be paused for database consistency.
	 *
	 * @return bool
	 */
	public function is_maintenance_active(): bool {
		return is_file( $this->maintenance_path() );
	}

	/**
	 * Whether login may be exposed to recover cleanup after database activation.
	 *
	 * @return bool
	 */
	public function allows_recovery_login(): bool {
		$job = $this->load();

		if ( ! is_array( $job ) ) {
			return false;
		}

		$state = isset( $job['restore_state'] ) && is_array( $job['restore_state'] ) ? $job['restore_state'] : array();

		return ! empty( $state['swapped'] ) || 'cleanup' === (string) ( $job['phase'] ?? '' );
	}

	/**
	 * Verifies the complete archive and records only signed database entries.
	 *
	 * @param array<string, mixed> $job Job.
	 * @return array<string, mixed>|WP_Error
	 */
	private function verify_archive( array $job ) {
		$path = $this->storage->archive_path( (string) $job['backup_id'] );

		if ( is_wp_error( $path ) ) {
			return $path;
		}

		// Payloads are verified while they are copied into the private workspace.
		// This phase validates signature and complete archive structure only.
		$manifest = $this->backups->verify( $path, false );

		if ( is_wp_error( $manifest ) ) {
			return $manifest;
		}

		$entries = array();

		foreach ( (array) ( $manifest['entries'] ?? array() ) as $entry => $hash ) {
			$entry = (string) $entry;

			if (
				'tim-backup/database/schema.json' === $entry
				|| 1 === preg_match( '/\Atim-backup\/database\/data\/[a-f0-9]{64}(?:-[0-9]{6})?\.jsonl\z/', $entry )
			) {
				$entries[ $entry ] = (string) $hash;
			} elseif ( str_starts_with( $entry, 'tim-backup/database/' ) ) {
				return new WP_Error(
					'tim_backup_restore_database_entry_invalid',
					__( 'The verified backup contains an unexpected database entry.', 'tim-backup-free' )
				);
			}
		}

		if ( ! isset( $entries['tim-backup/database/schema.json'] ) || count( $entries ) < 2 ) {
			return new WP_Error(
				'tim_backup_restore_database_missing',
				__( 'The verified backup does not contain a restorable database.', 'tim-backup-free' )
			);
		}

		$job['entries']       = $entries;
		$job['extract_index'] = 0;
		$maintenance          = $this->enable_maintenance( $job );

		if ( is_wp_error( $maintenance ) ) {
			return $maintenance;
		}

		$job['safety_backup_id'] = $this->storage->create_id();
		$job['phase']         = 'safety';

		return $job;
	}

	/**
	 * Creates a database-only safety backup.
	 *
	 * @param array<string, mixed> $job Job.
	 * @return array<string, mixed>|WP_Error
	 */
	private function create_safety_backup( array $job ) {
		$traffic_lock = $this->storage->acquire_lock( 'restore-traffic' );

		if ( is_wp_error( $traffic_lock ) ) {
			// The maintenance marker already prevents new traffic. Keep waiting
			// until requests that started before the marker release shared locks.
			return $job;
		}

		$safety_id = (string) ( $job['safety_backup_id'] ?? '' );
		$safety    = $this->backups->create( 'database', (string) $job['backup_id'], $safety_id );
		$this->storage->release_lock( 'restore-traffic', $traffic_lock );

		if ( is_wp_error( $safety ) ) {
			return new WP_Error(
				'tim_backup_restore_safety_failed',
				sprintf(
					/* translators: %s: Backup error message. */
					__( 'Restore cancelled because the database safety backup failed: %s', 'tim-backup-free' ),
					$safety->get_error_message()
				)
			);
		}

		$job['safety_backup_id'] = (string) $safety['id'];
		$job['backup_index']     = $this->storage->all();
		$job['phase']            = 'extract';

		return $job;
	}

	/**
	 * Extracts and re-hashes one signed database entry.
	 *
	 * @param array<string, mixed> $job Job.
	 * @return array<string, mixed>|WP_Error
	 */
	private function extract_next_entry( array $job ) {
		$entries = isset( $job['entries'] ) && is_array( $job['entries'] ) ? $job['entries'] : array();
		$names   = array_keys( $entries );
		$index   = (int) ( $job['extract_index'] ?? 0 );

		if ( $index >= count( $names ) ) {
			$job['phase'] = 'prepare';
			return $job;
		}

		$workspace = $this->ensure_workspace( $job );

		if ( is_wp_error( $workspace ) ) {
			return $workspace;
		}

		$entry        = (string) $names[ $index ];
		$archive_path = $this->storage->archive_path( (string) $job['backup_id'] );

		if ( is_wp_error( $archive_path ) ) {
			return $archive_path;
		}

		$zip = new ZipArchive();

		if ( true !== $zip->open( $archive_path, ZipArchive::RDONLY ) ) {
			return new WP_Error(
				'tim_backup_restore_open_failed',
				__( 'The verified backup archive could not be opened for restore.', 'tim-backup-free' )
			);
		}

		$entry_stat = $zip->statName( $entry );

		if (
			! is_array( $entry_stat )
			|| (int) ( $entry_stat['size'] ?? -1 ) < 0
			|| (int) $entry_stat['size'] > self::MAX_DATABASE_ENTRY_BYTES
		) {
			$zip->close();
			return new WP_Error(
				'tim_backup_restore_database_chunk_invalid',
				__( 'A database backup chunk is too large for a resumable restore.', 'tim-backup-free' )
			);
		}

		$source = $zip->getStream( $entry );

		if ( false === $source ) {
			$zip->close();
			return new WP_Error(
				'tim_backup_restore_data_missing',
				__( 'Database table data is missing from the backup.', 'tim-backup-free' )
			);
		}

		$destination = $this->entry_path( $workspace, $entry );
		$partial     = $destination . '.partial';
		$target_dir  = dirname( $destination );

		if ( is_file( $destination ) ) {
			$existing_hash = hash_file( 'sha256', $destination );

			if ( is_string( $existing_hash ) && hash_equals( (string) $entries[ $entry ], $existing_hash ) ) {
				fclose( $source ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
				$zip->close();
				$job['extract_index'] = $index + 1;

				if ( $job['extract_index'] >= count( $names ) ) {
					$job['phase'] = 'prepare';
				}

				return $job;
			}

			wp_delete_file( $destination );
		}

		if ( ! is_dir( $target_dir ) && ! wp_mkdir_p( $target_dir ) ) {
			fclose( $source ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			$zip->close();
			return new WP_Error(
				'tim_backup_restore_workspace_failed',
				__( 'The private restore workspace could not be created.', 'tim-backup-free' )
			);
		}

		$target = @fopen( $partial, 'wb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen,WordPress.PHP.NoSilencedErrors.Discouraged -- Private bounded extraction; failure is handled.

		if ( false === $target ) {
			fclose( $source ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			$zip->close();
			return new WP_Error(
				'tim_backup_restore_extract_failed',
				__( 'A verified database file could not be prepared for restore.', 'tim-backup-free' )
			);
		}

		$context = hash_init( 'sha256' );

		while ( ! feof( $source ) ) {
			$chunk = fread( $source, 1024 * 1024 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread -- Streams a private ZIP entry in bounded chunks.

			if ( false === $chunk || ( '' === $chunk && ! feof( $source ) ) ) {
				fclose( $source ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
				fclose( $target ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
				$zip->close();
				wp_delete_file( $partial );
				return new WP_Error(
					'tim_backup_restore_extract_failed',
					__( 'A verified database file could not be prepared for restore.', 'tim-backup-free' )
				);
			}

			hash_update( $context, $chunk );
			$bytes_written = fwrite( $target, $chunk ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Writes only to protected restore workspace.

			if ( false === $bytes_written || strlen( $chunk ) !== $bytes_written ) {
				fclose( $source ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
				fclose( $target ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
				$zip->close();
				wp_delete_file( $partial );
				return new WP_Error(
					'tim_backup_restore_extract_failed',
					__( 'A verified database file could not be prepared for restore.', 'tim-backup-free' )
				);
			}
		}

		fclose( $source ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		fclose( $target ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		$zip->close();

		if ( ! hash_equals( (string) $entries[ $entry ], hash_final( $context ) ) ) {
			wp_delete_file( $partial );
			return new WP_Error(
				'tim_backup_hash_invalid',
				__( 'A backup file failed its integrity check.', 'tim-backup-free' )
			);
		}

		if ( ! rename( $partial, $destination ) ) {
			wp_delete_file( $partial );
			return new WP_Error(
				'tim_backup_restore_extract_failed',
				__( 'A verified database file could not be prepared for restore.', 'tim-backup-free' )
			);
		}

		$job['extract_index'] = $index + 1;

		if ( $job['extract_index'] >= count( $names ) ) {
			$job['phase'] = 'prepare';
		}

		return $job;
	}

	/**
	 * Prepares one staging table.
	 *
	 * @param array<string, mixed> $job Job.
	 * @return array<string, mixed>|WP_Error
	 */
	private function prepare_next_table( array $job ) {
		$schema = $this->read_schema( $job );

		if ( is_wp_error( $schema ) ) {
			return $schema;
		}

		$state = isset( $job['restore_state'] ) && is_array( $job['restore_state'] ) ? $job['restore_state'] : array();

		if ( empty( $state ) ) {
			$state = $this->restore->create_staged_state( $schema );

			if ( is_wp_error( $state ) ) {
				return $state;
			}

			$data_entries = $this->database_data_entries( $job, $schema );

			if ( is_wp_error( $data_entries ) ) {
				return $data_entries;
			}

			$state['data_entries']       = $data_entries;
			$state['import_chunk_index'] = 0;
			$job['restore_state']        = $state;

			// Persist the complete token and table map before creating the first
			// staging table so a crash cannot leave an unreferenced table.
			return $job;
		}

		$state = $this->restore->prepare_next_table( $schema, $state );

		if ( is_wp_error( $state ) ) {
			return $state;
		}

		$job['restore_state'] = $state;

		if ( (int) $state['prepare_index'] >= count( (array) $state['tables'] ) ) {
			$job['phase'] = 'import';
		}

		return $job;
	}

	/**
	 * Imports one bounded row batch.
	 *
	 * @param array<string, mixed> $job Job.
	 * @return array<string, mixed>|WP_Error
	 */
	private function import_next_batch( array $job ) {
		$state  = isset( $job['restore_state'] ) && is_array( $job['restore_state'] ) ? $job['restore_state'] : array();
		$tables = isset( $state['tables'] ) && is_array( $state['tables'] ) ? $state['tables'] : array();
		$index  = (int) ( $state['import_index'] ?? 0 );

		if ( $index >= count( $tables ) ) {
			$job['phase'] = 'swap';
			return $job;
		}

		$workspace = $this->workspace_path( $job );

		if ( is_wp_error( $workspace ) ) {
			return $workspace;
		}

		$table        = (string) $tables[ $index ];
		$data_entries = isset( $state['data_entries'] ) && is_array( $state['data_entries'] ) ? $state['data_entries'] : array();
		$table_files  = isset( $data_entries[ $table ] ) && is_array( $data_entries[ $table ] ) ? $data_entries[ $table ] : array();
		$chunk_index  = (int) ( $state['import_chunk_index'] ?? 0 );
		$entry        = (string) ( $table_files[ $chunk_index ] ?? '' );

		if ( '' === $entry ) {
			return new WP_Error(
				'tim_backup_restore_database_incomplete',
				__( 'The verified database files do not match the database schema.', 'tim-backup-free' )
			);
		}

		$data_path = $workspace . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . basename( $entry );
		$state     = $this->restore->import_next_batch( $data_path, $state, 500, 5.0, count( $table_files ) - 1 === $chunk_index );

		if ( is_wp_error( $state ) ) {
			return $state;
		}

		$job['restore_state'] = $state;

		if ( (int) $state['import_index'] >= count( $tables ) ) {
			$job['phase'] = 'swap';
		}

		return $job;
	}

	/**
	 * Performs the idempotent atomic activation.
	 *
	 * @param array<string, mixed> $job Job.
	 * @return array<string, mixed>|WP_Error
	 */
	private function activate_tables( array $job ) {
		$state = isset( $job['restore_state'] ) && is_array( $job['restore_state'] ) ? $job['restore_state'] : array();
		$state = $this->restore->activate_staged_tables( $state );

		if ( is_wp_error( $state ) ) {
			return $state;
		}

		$job['restore_state'] = $state;
		$job['phase']         = 'cleanup';
		$job['updated_at']    = time();
		$checkpoint           = $this->save( $job );

		if ( is_wp_error( $checkpoint ) ) {
			$job['status'] = 'error';
			$job['error']  = $checkpoint->get_error_message();
			return $job;
		}

		// Finish in the authenticated swap request because restoring wp_users and
		// wp_options can invalidate the current administrator session afterwards.
		$finished = $this->finish_job( $job );

		if ( is_wp_error( $finished ) ) {
			$job['status'] = 'error';
			$job['error']  = $finished->get_error_message();
			$this->save( $job );

			return $job;
		}

		return $finished;
	}

	/**
	 * Preserves plugin state and removes old tables and workspace.
	 *
	 * @param array<string, mixed> $job Job.
	 * @return array<string, mixed>|WP_Error
	 */
	private function finish_job( array $job ) {
		$state = isset( $job['restore_state'] ) && is_array( $job['restore_state'] ) ? $job['restore_state'] : array();
		$index = isset( $job['backup_index'] ) && is_array( $job['backup_index'] ) ? $job['backup_index'] : array();
		$saved = $this->storage->save_index( $index );

		if ( is_wp_error( $saved ) ) {
			return $saved;
		}

		$cleaned = $this->restore->cleanup_staged_tables( $state, true );

		if ( is_wp_error( $cleaned ) ) {
			return $cleaned;
		}

		wp_cache_flush();
		flush_rewrite_rules( false );
		$this->remove_workspace( $job );
		$maintenance = $this->disable_maintenance();

		if ( is_wp_error( $maintenance ) ) {
			return $maintenance;
		}

		$job['phase']  = 'complete';
		$job['status'] = 'completed';
		$job['error']  = '';

		return $job;
	}

	/**
	 * Reads the verified extracted schema.
	 *
	 * @param array<string, mixed> $job Job.
	 * @return array<string, string>|WP_Error
	 */
	private function read_schema( array $job ) {
		$workspace = $this->workspace_path( $job );

		if ( is_wp_error( $workspace ) ) {
			return $workspace;
		}

		$path = $workspace . DIRECTORY_SEPARATOR . 'schema.json';
		$json = is_file( $path ) ? file_get_contents( $path ) : false; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reads a private verified local file.
		$data = false === $json ? null : json_decode( $json, true );

		if ( ! is_array( $data ) || empty( $data ) ) {
			return new WP_Error(
				'tim_backup_restore_schema_invalid',
				__( 'The database schema in the backup is invalid.', 'tim-backup-free' )
			);
		}

		$data_entries = $this->database_data_entries( $job, $data );

		if ( is_wp_error( $data_entries ) ) {
			return $data_entries;
		}

		return $data;
	}

	/**
	 * Maps all contiguous signed chunks to their schema table.
	 *
	 * Legacy single-file entries remain readable when they satisfy the current
	 * bounded-entry size limit.
	 *
	 * @param array<string, mixed>  $job Job.
	 * @param array<string, string> $schema Verified schema.
	 * @return array<string, array<int, string>>|WP_Error
	 */
	private function database_data_entries( array $job, array $schema ) {
		$entries       = isset( $job['entries'] ) && is_array( $job['entries'] ) ? $job['entries'] : array();
		$mapped        = array();
		$matched_count = 0;

		foreach ( array_keys( $schema ) as $table ) {
			$table      = (string) $table;
			$table_hash = hash( 'sha256', $table );
			$pattern    = '/\Atim-backup\/database\/data\/' . preg_quote( $table_hash, '/' ) . '(?:-([0-9]{6}))?\.jsonl\z/';
			$chunks     = array();

			foreach ( array_keys( $entries ) as $entry ) {
				if ( 1 !== preg_match( $pattern, (string) $entry, $matches ) ) {
					continue;
				}

				$chunk_index = isset( $matches[1] ) && '' !== $matches[1] ? (int) $matches[1] : 0;

				if ( isset( $chunks[ $chunk_index ] ) ) {
					return new WP_Error(
						'tim_backup_restore_database_incomplete',
						__( 'The verified database files do not match the database schema.', 'tim-backup-free' )
					);
				}

				$chunks[ $chunk_index ] = (string) $entry;
				++$matched_count;
			}

			ksort( $chunks, SORT_NUMERIC );

			if ( empty( $chunks ) || array_keys( $chunks ) !== range( 0, count( $chunks ) - 1 ) ) {
				return new WP_Error(
					'tim_backup_restore_database_incomplete',
					__( 'The verified database files do not match the database schema.', 'tim-backup-free' )
				);
			}

			$mapped[ $table ] = array_values( $chunks );
		}

		if ( count( $entries ) !== $matched_count + 1 ) {
			return new WP_Error(
				'tim_backup_restore_database_incomplete',
				__( 'The verified database files do not match the database schema.', 'tim-backup-free' )
			);
		}

		return $mapped;
	}

	/**
	 * Maps a signed database entry to a private fixed-form path.
	 *
	 * @param string $workspace Workspace.
	 * @param string $entry Signed entry.
	 * @return string
	 */
	private function entry_path( string $workspace, string $entry ): string {
		if ( 'tim-backup/database/schema.json' === $entry ) {
			return $workspace . DIRECTORY_SEPARATOR . 'schema.json';
		}

		$filename = basename( $entry );

		return $workspace . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . $filename;
	}

	/**
	 * Creates and returns a private job workspace.
	 *
	 * @param array<string, mixed> $job Job.
	 * @return string|WP_Error
	 */
	private function ensure_workspace( array $job ) {
		$workspace = $this->workspace_path( $job );

		if ( is_wp_error( $workspace ) ) {
			return $workspace;
		}

		if ( ! is_dir( $workspace ) && ! wp_mkdir_p( $workspace ) ) {
			return new WP_Error(
				'tim_backup_restore_workspace_failed',
				__( 'The private restore workspace could not be created.', 'tim-backup-free' )
			);
		}

		return $workspace;
	}

	/**
	 * Returns a validated workspace path.
	 *
	 * @param array<string, mixed> $job Job.
	 * @return string|WP_Error
	 */
	private function workspace_path( array $job ) {
		$job_id = (string) ( $job['job_id'] ?? '' );

		if ( 1 !== preg_match( '/\A[a-f0-9]{32}\z/', $job_id ) ) {
			return new WP_Error(
				'tim_backup_restore_job_invalid',
				__( 'The saved database restore job is invalid.', 'tim-backup-free' )
			);
		}

		return $this->storage->directory() . DIRECTORY_SEPARATOR . 'restore-job-' . $job_id;
	}

	/**
	 * Deletes one private workspace recursively.
	 *
	 * @param array<string, mixed> $job Job.
	 * @return void
	 */
	private function remove_workspace( array $job ): void {
		$workspace = $this->workspace_path( $job );

		if ( is_wp_error( $workspace ) ) {
			return;
		}

		$this->remove_workspace_for_job( basename( $workspace ) );
	}

	/**
	 * Deletes a workspace using a validated job ID or full fixed basename.
	 *
	 * @param string $job_id Job ID or workspace basename.
	 * @return void
	 */
	private function remove_workspace_for_job( string $job_id ): void {
		$basename = str_starts_with( $job_id, 'restore-job-' ) ? $job_id : 'restore-job-' . $job_id;

		if ( 1 !== preg_match( '/\Arestore-job-[a-f0-9]{32}\z/', $basename ) ) {
			return;
		}

		$directory = $this->storage->directory() . DIRECTORY_SEPARATOR . $basename;

		if ( ! is_dir( $directory ) ) {
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

	/**
	 * Loads and authenticates the journal.
	 *
	 * @return array<string, mixed>|WP_Error|null
	 */
	private function load() {
		$path = $this->journal_path();

		if ( ! is_file( $path ) ) {
			return null;
		}

		$json = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reads a protected local journal.
		$job  = false === $json ? null : json_decode( $json, true );

		if ( ! is_array( $job ) || empty( $job['signature'] ) ) {
			return new WP_Error(
				'tim_backup_restore_journal_invalid',
				__( 'The saved database restore progress failed its integrity check.', 'tim-backup-free' )
			);
		}

		$key = $this->journal_key();

		if ( is_wp_error( $key ) ) {
			return $key;
		}

		$signature = (string) $job['signature'];
		unset( $job['signature'] );
		$expected = hash_hmac( 'sha256', (string) wp_json_encode( $job ), $key );

		if ( ! hash_equals( $expected, $signature ) ) {
			return new WP_Error(
				'tim_backup_restore_journal_invalid',
				__( 'The saved database restore progress failed its integrity check.', 'tim-backup-free' )
			);
		}

		return $job;
	}

	/**
	 * Atomically writes an authenticated journal.
	 *
	 * @param array<string, mixed> $job Job.
	 * @return true|WP_Error
	 */
	private function save( array $job ) {
		$key = $this->journal_key();

		if ( is_wp_error( $key ) ) {
			return $key;
		}

		$job['signature'] = hash_hmac( 'sha256', (string) wp_json_encode( $job ), $key );
		$json             = wp_json_encode( $job, JSON_PRETTY_PRINT );

		if ( false === $json ) {
			return new WP_Error(
				'tim_backup_restore_journal_failed',
				__( 'The database restore progress could not be saved.', 'tim-backup-free' )
			);
		}

		$path    = $this->journal_path();
		$partial = $path . '.partial';

		if ( false === file_put_contents( $partial, $json, LOCK_EX ) || ! rename( $partial, $path ) ) {
			wp_delete_file( $partial );
			return new WP_Error(
				'tim_backup_restore_journal_failed',
				__( 'The database restore progress could not be saved.', 'tim-backup-free' )
			);
		}

		return true;
	}

	/**
	 * Returns a browser-safe job representation.
	 *
	 * @param array<string, mixed> $job Job.
	 * @return array<string, mixed>
	 */
	private function public_status( array $job ): array {
		$phase        = (string) ( $job['phase'] ?? 'verify' );
		$current      = array_search( $phase, self::PHASES, true );
		$current      = false === $current ? 0 : (int) $current;
		$status       = (string) ( $job['status'] ?? 'error' );
		$state        = isset( $job['restore_state'] ) && is_array( $job['restore_state'] ) ? $job['restore_state'] : array();
		$phase_labels = array(
			'verify'  => __( 'Verify backup archive', 'tim-backup-free' ),
			'safety'  => __( 'Create current database safety backup', 'tim-backup-free' ),
			'extract' => __( 'Prepare verified database files', 'tim-backup-free' ),
			'prepare' => __( 'Create temporary database tables', 'tim-backup-free' ),
			'import'  => __( 'Restore database data', 'tim-backup-free' ),
			'swap'    => __( 'Activate restored database atomically', 'tim-backup-free' ),
			'cleanup' => __( 'Clean up and refresh WordPress', 'tim-backup-free' ),
			'complete' => __( 'Restore complete', 'tim-backup-free' ),
		);
		$steps = array();

		foreach ( self::PHASES as $index => $step_phase ) {
			$step_status = $index < $current ? 'done' : ( $index === $current ? 'active' : 'waiting' );

			if ( 'completed' === $status ) {
				$step_status = 'done';
			} elseif ( 'error' === $status && $index === $current ) {
				$step_status = 'error';
			} elseif ( 'cancelled' === $status ) {
				$step_status = $index < $current ? 'done' : 'waiting';
			}

			$steps[] = array(
				'id'     => $step_phase,
				'label'  => $phase_labels[ $step_phase ],
				'status' => $step_status,
			);
		}

		return array(
			'jobId'          => (string) ( $job['job_id'] ?? '' ),
			'backupId'       => (string) ( $job['backup_id'] ?? '' ),
			'status'         => $status,
			'phase'          => $phase,
			'steps'          => $steps,
			'error'          => (string) ( $job['error'] ?? '' ),
			'message'        => 'completed' === $status
				? __( 'The database was restored successfully. The database safety backup was retained.', 'tim-backup-free' )
				: '',
			'rowsImported'   => (int) ( $state['rows_imported'] ?? 0 ),
			'tableCurrent'   => min( (int) ( $state['import_index'] ?? 0 ) + 1, count( (array) ( $state['tables'] ?? array() ) ) ),
			'tableTotal'     => count( (array) ( $state['tables'] ?? array() ) ),
			'canCancel'      => (
				in_array( $status, array( 'active', 'error' ), true )
				&& empty( $state['swapped'] )
				&& ! in_array( $phase, array( 'cleanup', 'complete' ), true )
				&& ( 'swap' !== $phase || 'error' === $status )
			),
			'canRetry'       => 'error' === $status && ( ! empty( $state['swapped'] ) || 'cleanup' === $phase ),
			'safetyBackupId' => (string) ( $job['safety_backup_id'] ?? '' ),
		);
	}

	/**
	 * Enables filesystem-backed maintenance mode before the safety snapshot.
	 *
	 * @param array<string, mixed> $job Job.
	 * @return true|WP_Error
	 */
	private function enable_maintenance( array $job ) {
		$path = $this->maintenance_path();

		if ( is_file( $path ) ) {
			return true;
		}

		$partial = $path . '.partial';
		$content = wp_json_encode(
			array(
				'job_id'     => (string) ( $job['job_id'] ?? '' ),
				'created_at' => time(),
			)
		);

		if (
			false === $content
			|| false === file_put_contents( $partial, $content, LOCK_EX )
			|| ! rename( $partial, $path )
		) {
			wp_delete_file( $partial );
			return new WP_Error(
				'tim_backup_restore_maintenance_failed',
				__( 'TIM Backup could not enable protected restore maintenance mode.', 'tim-backup-free' )
			);
		}

		return true;
	}

	/**
	 * Disables filesystem-backed maintenance mode after cleanup or cancellation.
	 *
	 * @return true|WP_Error
	 */
	private function disable_maintenance() {
		$path = $this->maintenance_path();

		if ( is_file( $path ) ) {
			wp_delete_file( $path );
		}

		if ( is_file( $path ) ) {
			return new WP_Error(
				'tim_backup_restore_maintenance_cleanup_failed',
				__( 'TIM Backup could not disable restore maintenance mode.', 'tim-backup-free' )
			);
		}

		return true;
	}

	/**
	 * Maintenance marker path.
	 *
	 * @return string
	 */
	private function maintenance_path(): string {
		return $this->storage->directory() . DIRECTORY_SEPARATOR . self::MAINTENANCE_FILE;
	}

	/**
	 * Journal path.
	 *
	 * @return string
	 */
	private function journal_path(): string {
		return $this->storage->directory() . DIRECTORY_SEPARATOR . self::JOURNAL_FILE;
	}

	/**
	 * Returns a random database-independent journal authentication key.
	 *
	 * @return string|WP_Error
	 */
	private function journal_key() {
		$path = $this->storage->directory() . DIRECTORY_SEPARATOR . self::JOURNAL_KEY_FILE;

		if ( is_file( $path ) ) {
			$key = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reads a protected local secret.

			if ( is_string( $key ) && 32 === strlen( $key ) ) {
				return $key;
			}

			return new WP_Error(
				'tim_backup_restore_key_invalid',
				__( 'The protected restore journal key is invalid.', 'tim-backup-free' )
			);
		}

		$key     = random_bytes( 32 );
		$partial = $path . '.partial';

		if (
			32 !== file_put_contents( $partial, $key, LOCK_EX )
			|| ! chmod( $partial, 0600 ) // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- Protects a database-independent local secret.
			|| ! rename( $partial, $path )
		) {
			wp_delete_file( $partial );
			return new WP_Error(
				'tim_backup_restore_key_failed',
				__( 'The protected restore journal key could not be created.', 'tim-backup-free' )
			);
		}

		return $key;
	}
}
