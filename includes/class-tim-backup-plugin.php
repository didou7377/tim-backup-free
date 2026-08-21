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
	 * Persistent restore job service.
	 *
	 * @var TIM_Backup_Restore_Job_Service
	 */
	private TIM_Backup_Restore_Job_Service $restore_jobs;

	/**
	 * Shared traffic-drain lock held for the current request.
	 *
	 * @var resource|null
	 */
	private $traffic_lock = null;

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
		$this->restore_jobs = new TIM_Backup_Restore_Job_Service( $this->storage, $this->backups, $this->restore );
	}

	/**
	 * Registers runtime hooks.
	 *
	 * @return void
	 */
	public function init(): void {
		$this->guard_restore_traffic();
		add_action( 'plugins_loaded', array( $this, 'load_textdomain' ), -2000 );
		add_action( 'plugins_loaded', array( $this, 'guard_restore_traffic' ), -1500 );
		add_action( 'plugins_loaded', array( $this, 'enforce_restore_maintenance' ), -1000 );
		add_action( 'admin_post_tim_backup_create', array( $this, 'handle_create' ) );
		add_action( 'admin_post_tim_backup_delete', array( $this, 'handle_delete' ) );
		add_action( 'admin_post_tim_backup_restore', array( $this, 'handle_restore' ) );
		add_action( 'admin_post_tim_backup_download', array( $this, 'handle_download' ) );
		add_action( 'wp_ajax_tim_backup_restore_start', array( $this, 'handle_restore_start' ) );
		add_action( 'wp_ajax_tim_backup_restore_advance', array( $this, 'handle_restore_advance' ) );
		add_action( 'wp_ajax_tim_backup_restore_status', array( $this, 'handle_restore_status' ) );
		add_action( 'wp_ajax_tim_backup_restore_cancel', array( $this, 'handle_restore_cancel' ) );
		add_action( self::CRON_HOOK, array( $this, 'run_scheduled_backup' ) );

		if ( is_admin() ) {
			$admin = new TIM_Backup_Admin( $this->storage, $this->restore_jobs );
			$admin->init();
		}
	}

	/**
	 * Loads bundled translations.
	 *
	 * @return void
	 */
	public function load_textdomain(): void {
		load_plugin_textdomain( 'tim-backup-free', false, dirname( plugin_basename( TIM_BACKUP_FILE ) ) . '/languages' );
	}

	/**
	 * Holds a shared lock for normal requests so restore can drain them safely.
	 *
	 * @return void
	 */
	public function guard_restore_traffic(): void {
		if (
			is_resource( $this->traffic_lock )
			|| ( defined( 'WP_CLI' ) && WP_CLI )
			|| ! is_dir( $this->storage->directory() )
		) {
			return;
		}

		$lock = $this->storage->acquire_lock( 'restore-traffic', true );

		if ( is_wp_error( $lock ) ) {
			return;
		}

		$this->traffic_lock = $lock;
	}

	/**
	 * Releases the current restore request before it requests the exclusive drain.
	 *
	 * @return void
	 */
	private function release_restore_traffic_lock(): void {
		if ( is_resource( $this->traffic_lock ) ) {
			$this->storage->release_lock( 'restore-traffic', $this->traffic_lock );
			$this->traffic_lock = null;
		}
	}

	/**
	 * Blocks normal traffic while a verified database snapshot is being restored.
	 *
	 * Restore AJAX, login and the administrator's restore page remain reachable
	 * so an interrupted job can be completed or cancelled.
	 *
	 * @return void
	 */
	public function enforce_restore_maintenance(): void {
		if ( ! $this->restore_jobs->is_maintenance_active() ) {
			return;
		}

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			return;
		}

		if ( $this->is_restore_ajax_request() ) {
			return;
		}

		$script_name = isset( $_SERVER['SCRIPT_NAME'] ) ? sanitize_text_field( wp_unslash( $_SERVER['SCRIPT_NAME'] ) ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotValidated -- Used only for a basename comparison.

		if ( 'wp-login.php' === basename( $script_name ) && $this->restore_jobs->allows_recovery_login() ) {
			return;
		}

		$page   = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only recovery route.
		$view   = isset( $_GET['view'] ) ? sanitize_key( wp_unslash( $_GET['view'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only recovery route.
		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) : '';

		if (
			is_admin()
			&& 'GET' === $method
			&& 'tim-backup-free' === $page
			&& 'restore' === $view
			&& current_user_can( 'manage_options' )
		) {
			return;
		}

		$this->send_restore_maintenance_response();
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
				esc_html__( 'TIM Backup activation failed', 'tim-backup-free' ),
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

		$type   = isset( $_POST['backup_type'] ) ? sanitize_key( wp_unslash( $_POST['backup_type'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- authorize() verifies this action's nonce first.
		$result = $this->can_operate();

		if ( ! is_wp_error( $result ) ) {
			$result = $this->backups->create( $type );
		}

		if ( is_wp_error( $result ) ) {
			$this->send_failure_email( $result );
			$this->set_notice( 'error', $result->get_error_message() );
		} else {
			$this->set_notice( 'success', __( 'The backup was created and verified successfully.', 'tim-backup-free' ) );
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

		$id           = isset( $_POST['backup_id'] ) ? sanitize_text_field( wp_unslash( $_POST['backup_id'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- authorize() verifies this action's nonce first.
		$confirmation = isset( $_POST['confirm_delete'] ) ? sanitize_text_field( wp_unslash( $_POST['confirm_delete'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- authorize() verifies this action's nonce first.

		if ( '' === $id || ! hash_equals( $id, $confirmation ) ) {
			$this->set_notice( 'error', __( 'The deletion was not confirmed.', 'tim-backup-free' ) );
			$this->redirect( 'backups' );
		}

		$result = $this->can_operate();

		if ( ! is_wp_error( $result ) ) {
			$result = $this->storage->ensure_directory();
		}

		$lock = is_wp_error( $result ) ? $result : $this->storage->acquire_lock( 'operation' );

		if ( is_wp_error( $lock ) ) {
			$result = $lock;
		} else {
			$result = $this->storage->delete( $id );
			$this->storage->release_lock( 'operation', $lock );
		}

		if ( is_wp_error( $result ) ) {
			$this->set_notice( 'error', $result->get_error_message() );
		} else {
			$this->set_notice( 'success', __( 'The backup was deleted.', 'tim-backup-free' ) );
		}

		$this->redirect( 'backups' );
	}

	/**
	 * Redirects legacy restore forms to the guided assistant.
	 *
	 * @return void
	 */
	public function handle_restore(): void {
		$this->authorize( 'tim_backup_restore' );

		$id  = isset( $_POST['backup_id'] ) ? sanitize_text_field( wp_unslash( $_POST['backup_id'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- authorize() verifies this action's nonce first.
		$url = add_query_arg(
			array(
				'page'      => 'tim-backup-free',
				'view'      => 'restore',
				'backup_id' => $id,
			),
			admin_url( 'admin.php' )
		);

		wp_safe_redirect( $url );
		exit;
	}

	/**
	 * Starts a guided restore job.
	 *
	 * @return void
	 */
	public function handle_restore_start(): void {
		$this->authorize_restore_ajax();
		$this->release_restore_traffic_lock();
		$id     = isset( $_POST['backup_id'] ) ? sanitize_text_field( wp_unslash( $_POST['backup_id'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- AJAX nonce is verified first.
		$result = $this->can_operate();

		if ( ! is_wp_error( $result ) ) {
			$result = $this->restore_jobs->start( $id, get_current_user_id() );
		}

		$this->send_restore_response( $result );
	}

	/**
	 * Advances a guided restore job.
	 *
	 * @return void
	 */
	public function handle_restore_advance(): void {
		$this->authorize_restore_ajax();
		$this->release_restore_traffic_lock();
		$this->send_restore_response( $this->restore_jobs->advance() );
	}

	/**
	 * Returns guided restore status.
	 *
	 * @return void
	 */
	public function handle_restore_status(): void {
		$this->authorize_restore_ajax();
		$this->release_restore_traffic_lock();
		$this->send_restore_response( $this->restore_jobs->status() );
	}

	/**
	 * Cancels a guided restore before activation.
	 *
	 * @return void
	 */
	public function handle_restore_cancel(): void {
		$this->authorize_restore_ajax();
		$this->release_restore_traffic_lock();
		$this->send_restore_response( $this->restore_jobs->cancel() );
	}

	/**
	 * Streams an authenticated managed archive.
	 *
	 * @return void
	 */
	public function handle_download(): void {
		$this->authorize( 'tim_backup_download' );

		$id      = isset( $_POST['backup_id'] ) ? sanitize_text_field( wp_unslash( $_POST['backup_id'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- authorize() verifies this action's nonce first.
		$ready   = $this->storage->ensure_directory();
		$lock    = is_wp_error( $ready ) ? $ready : $this->storage->acquire_lock( 'operation', true );
		$backup  = is_wp_error( $lock ) ? $lock : $this->storage->find( $id );
		$path    = $this->storage->archive_path( $id );
		$checked = is_wp_error( $lock ) || is_wp_error( $path ) ? $lock : $this->backups->verify( $path );

		if ( is_wp_error( $backup ) || is_wp_error( $path ) || is_wp_error( $checked ) || ! is_readable( $path ) ) {
			if ( ! is_wp_error( $lock ) ) {
				$this->storage->release_lock( 'operation', $lock );
			}

			$this->set_notice( 'error', __( 'The requested backup could not be downloaded.', 'tim-backup-free' ) );
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
	 * Whether the current request is one of the protected restore AJAX routes.
	 *
	 * @return bool
	 */
	private function is_restore_ajax_request(): bool {
		if ( ! wp_doing_ajax() ) {
			return false;
		}

		$action = isset( $_REQUEST['action'] ) ? sanitize_key( wp_unslash( $_REQUEST['action'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Routing only; handlers verify action-specific nonces.
		$nonce  = isset( $_REQUEST['nonce'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['nonce'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Verified immediately below for early routing.

		return (
			in_array(
				$action,
				array(
					'tim_backup_restore_start',
					'tim_backup_restore_advance',
					'tim_backup_restore_status',
					'tim_backup_restore_cancel',
				),
				true
			)
			&& false !== wp_verify_nonce( $nonce, 'tim_backup_restore_job' )
			&& current_user_can( 'manage_options' )
		);
	}

	/**
	 * Sends the maintenance response used while traffic is drained or blocked.
	 *
	 * @return void
	 */
	private function send_restore_maintenance_response(): void {
		status_header( 503 );
		nocache_headers();
		header( 'Retry-After: 60' );
		wp_die(
			esc_html__( 'TIM Backup is restoring the database. Please try again shortly.', 'tim-backup-free' ),
			esc_html__( 'Database restore in progress', 'tim-backup-free' ),
			array( 'response' => 503 )
		);
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
				__( 'TIM Backup Free 0.2.0 does not support WordPress Multisite.', 'tim-backup-free' )
			);
		}

		if ( $this->restore_jobs->is_active() ) {
			return new WP_Error(
				'tim_backup_restore_job_active',
				__( 'A database restore is in progress. Other backup changes are temporarily unavailable.', 'tim-backup-free' )
			);
		}

		return true;
	}

	/**
	 * Verifies capability and nonce for restore AJAX requests.
	 *
	 * @return void
	 */
	private function authorize_restore_ajax(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error(
				array( 'message' => __( 'You are not allowed to restore backups.', 'tim-backup-free' ) ),
				403
			);
		}

		check_ajax_referer( 'tim_backup_restore_job', 'nonce' );
	}

	/**
	 * Sends one normalized restore AJAX response.
	 *
	 * @param array<string, mixed>|WP_Error $result Result.
	 * @return void
	 */
	private function send_restore_response( $result ): void {
		if ( is_wp_error( $result ) ) {
			wp_send_json_error(
				array( 'message' => $result->get_error_message() ),
				409
			);
		}

		wp_send_json_success( $result );
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
				esc_html__( 'You are not allowed to manage backups.', 'tim-backup-free' ),
				esc_html__( 'Access denied', 'tim-backup-free' ),
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
					'page' => 'tim-backup-free',
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
			__( '[%s] TIM Backup failed', 'tim-backup-free' ),
			wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES )
		);
		$message = sprintf(
			/* translators: 1: Site URL, 2: Error message. */
			__( "TIM Backup could not create a backup for %1\$s.\n\nReason: %2\$s\n\nPlease sign in to WordPress and review TIM Backup.", 'tim-backup-free' ),
			home_url( '/' ),
			$error->get_error_message()
		);

		wp_mail( $recipient, $subject, $message );
	}
}
