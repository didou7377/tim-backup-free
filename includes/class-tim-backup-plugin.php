<?php
/**
 * Plugin lifecycle and request handling.
 *
 * @package TIM_Backup
 */

defined( 'ABSPATH' ) || exit;

/**
 * Main plugin coordinator.
 */
final class TIM_Backup_Plugin {

	/**
	 * Weekly cron hook.
	 */
	private const CRON_HOOK = 'tim_backup_weekly_event';

	/**
	 * Singleton instance.
	 *
	 * @var TIM_Backup_Plugin|null
	 */
	private static ?TIM_Backup_Plugin $instance = null;

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
	 * Returns the singleton.
	 *
	 * @return TIM_Backup_Plugin
	 */
	public static function instance(): TIM_Backup_Plugin {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Constructor.
	 */
	private function __construct() {
		$this->storage = new TIM_Backup_Storage();
		$this->backups = new TIM_Backup_Backup_Service( $this->storage );
		$this->restore = new TIM_Backup_Restore_Service( $this->storage, $this->backups );
	}

	/**
	 * Registers runtime hooks.
	 *
	 * @return void
	 */
	public function init(): void {
		add_action( 'init', array( $this, 'load_textdomain' ) );
		add_action( 'admin_post_tim_backup_create', array( $this, 'handle_create' ) );
		add_action( 'admin_post_tim_backup_delete', array( $this, 'handle_delete' ) );
		add_action( 'admin_post_tim_backup_restore', array( $this, 'handle_restore' ) );
		add_action( 'admin_post_tim_backup_download', array( $this, 'handle_download' ) );
		add_action( self::CRON_HOOK, array( $this, 'run_scheduled_backup' ) );

		if ( is_admin() ) {
			$admin = new TIM_Backup_Admin( $this->storage );
			$admin->init();
		}
	}

	/**
	 * Loads bundled translations.
	 *
	 * @return void
	 */
	public function load_textdomain(): void {
		load_plugin_textdomain( 'tim-backup', false, dirname( plugin_basename( TIM_BACKUP_FILE ) ) . '/languages' );
	}

	/**
	 * Activation callback.
	 *
	 * @return void
	 */
	public static function activate(): void {
		$storage = new TIM_Backup_Storage();
		$result  = $storage->ensure_directory();

		if ( is_wp_error( $result ) ) {
			deactivate_plugins( plugin_basename( TIM_BACKUP_FILE ) );
			wp_die(
				esc_html( $result->get_error_message() ),
				esc_html__( 'TIM Backup activation failed', 'tim-backup' ),
				array( 'back_link' => true )
			);
		}

		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + DAY_IN_SECONDS, 'weekly', self::CRON_HOOK );
		}
	}

	/**
	 * Deactivation callback.
	 *
	 * @return void
	 */
	public static function deactivate(): void {
		wp_clear_scheduled_hook( self::CRON_HOOK );
	}

	/**
	 * Handles manual backup creation.
	 *
	 * @return void
	 */
	public function handle_create(): void {
		$this->authorize( 'tim_backup_create' );

		$type   = isset( $_POST['backup_type'] ) ? sanitize_key( wp_unslash( $_POST['backup_type'] ) ) : '';
		$result = $this->can_operate();

		if ( ! is_wp_error( $result ) ) {
			$result = $this->backups->create( $type );
		}

		if ( is_wp_error( $result ) ) {
			$this->send_failure_email( $result );
			$this->set_notice( 'error', $result->get_error_message() );
		} else {
			$this->set_notice( 'success', __( 'The backup was created and verified successfully.', 'tim-backup' ) );
		}

		$this->redirect( 'backups' );
	}

	/**
	 * Handles backup deletion.
	 *
	 * @return void
	 */
	public function handle_delete(): void {
		$this->authorize( 'tim_backup_delete' );

		$id           = isset( $_POST['backup_id'] ) ? sanitize_text_field( wp_unslash( $_POST['backup_id'] ) ) : '';
		$confirmation = isset( $_POST['confirm_delete'] ) ? sanitize_text_field( wp_unslash( $_POST['confirm_delete'] ) ) : '';

		if ( '' === $id || ! hash_equals( $id, $confirmation ) ) {
			$this->set_notice( 'error', __( 'The deletion was not confirmed.', 'tim-backup' ) );
			$this->redirect( 'backups' );
		}

		$result = $this->storage->ensure_directory();
		$lock   = is_wp_error( $result ) ? $result : $this->storage->acquire_lock( 'operation' );

		if ( is_wp_error( $lock ) ) {
			$result = $lock;
		} else {
			$result = $this->storage->delete( $id );
			$this->storage->release_lock( 'operation', $lock );
		}

		if ( is_wp_error( $result ) ) {
			$this->set_notice( 'error', $result->get_error_message() );
		} else {
			$this->set_notice( 'success', __( 'The backup was deleted.', 'tim-backup' ) );
		}

		$this->redirect( 'backups' );
	}

	/**
	 * Handles explicitly confirmed restoration.
	 *
	 * @return void
	 */
	public function handle_restore(): void {
		$this->authorize( 'tim_backup_restore' );

		$id           = isset( $_POST['backup_id'] ) ? sanitize_text_field( wp_unslash( $_POST['backup_id'] ) ) : '';
		$confirmation = isset( $_POST['confirm_restore'] ) ? sanitize_text_field( wp_unslash( $_POST['confirm_restore'] ) ) : '';

		if ( '' === $id || ! hash_equals( $id, $confirmation ) ) {
			$this->set_notice( 'error', __( 'The restore was not confirmed.', 'tim-backup' ) );
			$this->redirect( 'backups' );
		}

		$result = $this->can_operate();

		if ( ! is_wp_error( $result ) ) {
			$selected_backup = $this->storage->find( $id );

			if ( is_wp_error( $selected_backup ) ) {
				$result = $selected_backup;
			} else {
				$safety_backup = $this->backups->create( 'full', $id );
			}

			if ( isset( $safety_backup ) && is_wp_error( $safety_backup ) ) {
				$this->send_failure_email( $safety_backup );
				$result = new WP_Error(
					'tim_backup_restore_safety_failed',
					sprintf(
						/* translators: %s: Backup error message. */
						__( 'Restore cancelled because the safety backup failed: %s', 'tim-backup' ),
						$safety_backup->get_error_message()
					)
				);
			} elseif ( isset( $safety_backup ) ) {
				$result = $this->restore->restore( $id );
			}
		}

		if ( is_wp_error( $result ) ) {
			$this->set_notice( 'error', $result->get_error_message() );
		} else {
			$this->set_notice( 'success', __( 'The database was restored successfully. A full safety backup of the previous state was retained.', 'tim-backup' ) );
		}

		$this->redirect( 'backups' );
	}

	/**
	 * Streams an authenticated managed archive.
	 *
	 * @return void
	 */
	public function handle_download(): void {
		$this->authorize( 'tim_backup_download' );

		$id      = isset( $_POST['backup_id'] ) ? sanitize_text_field( wp_unslash( $_POST['backup_id'] ) ) : '';
		$ready   = $this->storage->ensure_directory();
		$lock    = is_wp_error( $ready ) ? $ready : $this->storage->acquire_lock( 'operation', true );
		$backup  = is_wp_error( $lock ) ? $lock : $this->storage->find( $id );
		$path    = $this->storage->archive_path( $id );
		$checked = is_wp_error( $lock ) || is_wp_error( $path ) ? $lock : $this->backups->verify( $path );

		if ( is_wp_error( $backup ) || is_wp_error( $path ) || is_wp_error( $checked ) || ! is_readable( $path ) ) {
			if ( ! is_wp_error( $lock ) ) {
				$this->storage->release_lock( 'operation', $lock );
			}

			$this->set_notice( 'error', __( 'The requested backup could not be downloaded.', 'tim-backup' ) );
			$this->redirect( 'backups' );
		}

		nocache_headers();
		header( 'Content-Type: application/zip' );
		header( 'Content-Disposition: attachment; filename="tim-backup-' . gmdate( 'Y-m-d-His', (int) $backup['created_at'] ) . '.zip"' );
		header( 'Content-Length: ' . (string) filesize( $path ) );
		header( 'X-Content-Type-Options: nosniff' );

		readfile( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile -- Authenticated streaming avoids exposing a public backup URL.
		$this->storage->release_lock( 'operation', $lock );
		exit;
	}

	/**
	 * Runs the fixed weekly full backup.
	 *
	 * @return void
	 */
	public function run_scheduled_backup(): void {
		$allowed = $this->can_operate();
		$result  = is_wp_error( $allowed ) ? $allowed : $this->backups->create( 'full' );

		if ( is_wp_error( $result ) ) {
			$this->send_failure_email( $result );
		}
	}

	/**
	 * Rejects unsupported multisite operation.
	 *
	 * @return true|WP_Error
	 */
	private function can_operate() {
		if ( is_multisite() ) {
			return new WP_Error(
				'tim_backup_multisite_unsupported',
				__( 'TIM Backup Free 0.1.0 does not support WordPress Multisite.', 'tim-backup' )
			);
		}

		return true;
	}

	/**
	 * Verifies capability and request nonce.
	 *
	 * @param string $action Nonce action.
	 * @return void
	 */
	private function authorize( string $action ): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die(
				esc_html__( 'You are not allowed to manage backups.', 'tim-backup' ),
				esc_html__( 'Access denied', 'tim-backup' ),
				array( 'response' => 403 )
			);
		}

		check_admin_referer( $action );
	}

	/**
	 * Stores a one-time notice for the current administrator.
	 *
	 * @param string $type Notice type.
	 * @param string $message Notice message.
	 * @return void
	 */
	private function set_notice( string $type, string $message ): void {
		set_transient(
			'tim_backup_notice_' . get_current_user_id(),
			array(
				'type'    => in_array( $type, array( 'success', 'error', 'warning', 'info' ), true ) ? $type : 'info',
				'message' => $message,
			),
			MINUTE_IN_SECONDS
		);
	}

	/**
	 * Redirects to a plugin tab.
	 *
	 * @param string $tab Tab key.
	 * @return never
	 */
	private function redirect( string $tab ): void {
		wp_safe_redirect(
			add_query_arg(
				array(
					'page' => 'tim-backup',
					'tab'  => sanitize_key( $tab ),
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Sends the required failure-only notification.
	 *
	 * @param WP_Error $error Backup error.
	 * @return void
	 */
	private function send_failure_email( WP_Error $error ): void {
		$recipient = sanitize_email( (string) get_option( 'admin_email' ) );

		if ( ! is_email( $recipient ) ) {
			return;
		}

		$subject = sprintf(
			/* translators: %s: Site name. */
			__( '[%s] TIM Backup failed', 'tim-backup' ),
			wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES )
		);
		$message = sprintf(
			/* translators: 1: Site URL, 2: Error message. */
			__( "TIM Backup could not create a backup for %1\$s.\n\nReason: %2\$s\n\nPlease sign in to WordPress and review TIM Backup.", 'tim-backup' ),
			home_url( '/' ),
			$error->get_error_message()
		);

		wp_mail( $recipient, $subject, $message );
	}
}
