<?php
/**
 * Accessible tabbed administration interface.
 *
 * @package TIM_Backup
 */

defined( 'ABSPATH' ) || exit;

/**
 * Renders the single TIM Backup admin menu.
 */
final class TIM_Backup_Admin {

	/**
	 * Storage service.
	 *
	 * @var TIM_Backup_Storage
	 */
	private TIM_Backup_Storage $storage;

	/**
	 * Restore jobs.
	 *
	 * @var TIM_Backup_Restore_Job_Service
	 */
	private TIM_Backup_Restore_Job_Service $restore_jobs;

	/**
	 * Admin page hook suffix.
	 *
	 * @var string
	 */
	private string $page_hook = '';

	/**
	 * Constructor.
	 *
	 * @param TIM_Backup_Storage             $storage Storage service.
	 * @param TIM_Backup_Restore_Job_Service $restore_jobs Restore jobs.
	 */
	public function __construct( TIM_Backup_Storage $storage, TIM_Backup_Restore_Job_Service $restore_jobs ) {
		$this->storage      = $storage;
		$this->restore_jobs = $restore_jobs;
	}

	/**
	 * Registers admin hooks.
	 *
	 * @return void
	 */
	public function init(): void {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
	}

	/**
	 * Registers one top-level sidebar item.
	 *
	 * @return void
	 */
	public function register_menu(): void {
		$this->page_hook = add_menu_page(
			__( 'TIM Backup', 'tim-backup' ),
			__( 'TIM Backup', 'tim-backup' ),
			'manage_options',
			'tim-backup',
			array( $this, 'render_page' ),
			'dashicons-database-export',
			81
		);
	}

	/**
	 * Loads assets only on the plugin page.
	 *
	 * @param string $hook Current admin hook.
	 * @return void
	 */
	public function enqueue_assets( string $hook ): void {
		if ( $this->page_hook !== $hook ) {
			return;
		}

		wp_enqueue_style(
			'tim-backup-admin',
			TIM_BACKUP_URL . 'assets/css/admin.css',
			array(),
			TIM_BACKUP_VERSION
		);

		wp_enqueue_script(
			'tim-backup-restore',
			TIM_BACKUP_URL . 'assets/js/admin-restore.js',
			array(),
			TIM_BACKUP_VERSION,
			true
		);

		wp_localize_script(
			'tim-backup-restore',
			'timBackupRestore',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'tim_backup_restore_job' ),
				'actions' => array(
					'start'   => 'tim_backup_restore_start',
					'advance' => 'tim_backup_restore_advance',
					'status'  => 'tim_backup_restore_status',
					'cancel'  => 'tim_backup_restore_cancel',
				),
				'text'    => array(
					'requestFailed' => __( 'The restore request failed. You can safely reopen this page to continue.', 'tim-backup' ),
					'cancelConfirm' => __( 'Cancel this restore and remove all prepared temporary database tables?', 'tim-backup' ),
					'working'       => __( 'Restore in progress. Do not close this page unless necessary.', 'tim-backup' ),
					'rows'          => __( 'rows', 'tim-backup' ),
				),
			)
		);
	}

	/**
	 * Renders the tabbed application shell.
	 *
	 * @return void
	 */
	public function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$tabs = array(
			'overview' => __( 'Overview', 'tim-backup' ),
			'backups'  => __( 'Backups', 'tim-backup' ),
			'system'   => __( 'System', 'tim-backup' ),
		);
		$tab  = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'overview'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only tab navigation.
		$view = isset( $_GET['view'] ) ? sanitize_key( wp_unslash( $_GET['view'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only view navigation.

		if ( ! isset( $tabs[ $tab ] ) ) {
			$tab = 'overview';
		}

		?>
		<div class="wrap tim-backup">
			<header class="tim-backup__header">
				<div>
					<p class="tim-backup__eyebrow"><?php esc_html_e( 'TIM Plugin Series', 'tim-backup' ); ?></p>
					<h1><?php esc_html_e( 'TIM Backup', 'tim-backup' ); ?></h1>
					<p><?php esc_html_e( 'Secure local backups with verification before you need them.', 'tim-backup' ); ?></p>
				</div>
				<span class="tim-backup__version">
					<?php
					printf(
						/* translators: %s: Plugin version. */
						esc_html__( 'Version %s', 'tim-backup' ),
						esc_html( TIM_BACKUP_VERSION )
					);
					?>
				</span>
			</header>

			<?php if ( 'restore' === $view ) : ?>
				<?php $this->render_restore_assistant(); ?>
			</div>
				<?php
				return;
			endif;
			?>

			<nav class="nav-tab-wrapper tim-backup__tabs" aria-label="<?php esc_attr_e( 'TIM Backup sections', 'tim-backup' ); ?>">
				<?php foreach ( $tabs as $key => $label ) : ?>
					<?php
					$tab_url = add_query_arg(
						array(
							'page' => 'tim-backup',
							'tab'  => $key,
						),
						admin_url( 'admin.php' )
					);
					?>
					<a
						class="<?php echo esc_attr( 'nav-tab ' . ( $tab === $key ? 'nav-tab-active' : '' ) ); ?>"
						href="<?php echo esc_url( $tab_url ); ?>"
						<?php if ( $tab === $key ) : ?>
							aria-current="page"
						<?php endif; ?>
					>
						<?php echo esc_html( $label ); ?>
					</a>
				<?php endforeach; ?>
			</nav>

			<?php $this->render_notice(); ?>

			<main class="tim-backup__content">
				<?php
				if ( 'backups' === $tab ) {
					$this->render_backups();
				} elseif ( 'system' === $tab ) {
					$this->render_system();
				} else {
					$this->render_overview();
				}
				?>
			</main>
		</div>
		<?php
	}

	/**
	 * Renders the dedicated guided database restore view.
	 *
	 * @return void
	 */
	private function render_restore_assistant(): void {
		$backups    = $this->storage->all();
		$selected   = isset( $_GET['backup_id'] ) ? sanitize_text_field( wp_unslash( $_GET['backup_id'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only preselection.
		$backups_url = add_query_arg(
			array(
				'page' => 'tim-backup',
				'tab'  => 'backups',
			),
			admin_url( 'admin.php' )
		);
		?>
		<div class="tim-restore-assistant" data-tim-restore-assistant>
			<div class="tim-restore-assistant__topbar">
				<a href="<?php echo esc_url( $backups_url ); ?>" class="button">
					<span class="dashicons dashicons-arrow-left-alt2" aria-hidden="true"></span>
					<?php esc_html_e( 'Back to backup management', 'tim-backup' ); ?>
				</a>
			</div>

			<section class="tim-card tim-card--wide tim-restore-assistant__intro">
				<p class="tim-card__label"><?php esc_html_e( 'Guided restore', 'tim-backup' ); ?></p>
				<h2><?php esc_html_e( 'Restore a database safely', 'tim-backup' ); ?></h2>
				<p><?php esc_html_e( 'TIM Backup verifies the selected archive, creates a current database safety backup, prepares temporary tables, and activates them only after every row was imported successfully.', 'tim-backup' ); ?></p>
			</section>

			<?php if ( empty( $backups ) ) : ?>
				<section class="tim-card tim-card--wide">
					<h2><?php esc_html_e( 'No backup is available', 'tim-backup' ); ?></h2>
					<p><?php esc_html_e( 'Create a database or full backup before opening the restore assistant.', 'tim-backup' ); ?></p>
				</section>
			<?php else : ?>
				<div class="tim-restore-assistant__layout">
					<section class="tim-card tim-restore-assistant__selection" data-tim-restore-selection>
						<h2><?php esc_html_e( '1. Select backup', 'tim-backup' ); ?></h2>
						<label for="tim-restore-backup"><strong><?php esc_html_e( 'Backup archive', 'tim-backup' ); ?></strong></label>
						<select id="tim-restore-backup" data-tim-restore-backup>
							<?php foreach ( $backups as $backup ) : ?>
								<?php
								$id   = (string) $backup['id'];
								$type = 'full' === (string) $backup['type'] ? __( 'Full backup', 'tim-backup' ) : __( 'Database backup', 'tim-backup' );
								$text = sprintf(
									/* translators: 1: Backup type, 2: Date, 3: Size. */
									__( '%1$s — %2$s — %3$s', 'tim-backup' ),
									$type,
									wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $backup['created_at'] ),
									size_format( (int) $backup['size'], 1 )
								);
								?>
								<option value="<?php echo esc_attr( $id ); ?>" <?php selected( $selected, $id ); ?>>
									<?php echo esc_html( $text ); ?>
								</option>
							<?php endforeach; ?>
						</select>

						<div class="tim-restore-assistant__warning">
							<span class="dashicons dashicons-warning" aria-hidden="true"></span>
							<p><strong><?php esc_html_e( 'Current database data will be replaced.', 'tim-backup' ); ?></strong><br><?php esc_html_e( 'Website files are not restored. A database safety backup is created first.', 'tim-backup' ); ?></p>
						</div>

						<label class="tim-restore-assistant__confirm">
							<input type="checkbox" data-tim-restore-confirm>
							<?php esc_html_e( 'I understand and want to start the guided database restore.', 'tim-backup' ); ?>
						</label>

						<button type="button" class="button button-primary button-hero" data-tim-restore-start disabled>
							<?php esc_html_e( 'Start database restore', 'tim-backup' ); ?>
						</button>
					</section>

					<section class="tim-card tim-restore-assistant__progress" data-tim-restore-progress aria-live="polite">
						<h2><?php esc_html_e( '2. Restore progress', 'tim-backup' ); ?></h2>
						<ol class="tim-restore-steps" data-tim-restore-steps>
							<?php
							$steps = array(
								__( 'Verify backup archive', 'tim-backup' ),
								__( 'Create current database safety backup', 'tim-backup' ),
								__( 'Prepare verified database files', 'tim-backup' ),
								__( 'Create temporary database tables', 'tim-backup' ),
								__( 'Restore database data', 'tim-backup' ),
								__( 'Activate restored database atomically', 'tim-backup' ),
								__( 'Clean up and refresh WordPress', 'tim-backup' ),
								__( 'Restore complete', 'tim-backup' ),
							);

							foreach ( $steps as $step ) :
								?>
								<li class="tim-restore-step is-waiting">
									<span class="tim-restore-step__icon dashicons dashicons-marker" aria-hidden="true"></span>
									<span><?php echo esc_html( $step ); ?></span>
								</li>
							<?php endforeach; ?>
						</ol>
						<p class="tim-restore-assistant__detail" data-tim-restore-detail><?php esc_html_e( 'Select a backup to begin.', 'tim-backup' ); ?></p>
						<div class="tim-restore-assistant__error" data-tim-restore-error hidden></div>
						<button type="button" class="button button-primary" data-tim-restore-retry hidden>
							<?php esc_html_e( 'Retry final cleanup', 'tim-backup' ); ?>
						</button>
						<button type="button" class="button button-link-delete" data-tim-restore-cancel hidden>
							<?php esc_html_e( 'Cancel restore', 'tim-backup' ); ?>
						</button>
					</section>
				</div>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Renders the overview.
	 *
	 * @return void
	 */
	private function render_overview(): void {
		$backups    = $this->storage->all();
		$latest     = $backups[0] ?? null;
		$next       = wp_next_scheduled( 'tim_backup_weekly_event' );
		$backups_url = add_query_arg(
			array(
				'page' => 'tim-backup',
				'tab'  => 'backups',
			),
			admin_url( 'admin.php' )
		);
		?>
		<div class="tim-backup__grid">
			<section class="tim-card tim-card--accent">
				<p class="tim-card__label"><?php esc_html_e( 'Backup status', 'tim-backup' ); ?></p>
				<?php if ( is_array( $latest ) ) : ?>
					<h2><?php esc_html_e( 'Protected locally', 'tim-backup' ); ?></h2>
					<p>
						<?php
						printf(
							/* translators: %s: Human-readable backup date. */
							esc_html__( 'Latest verified backup: %s', 'tim-backup' ),
							esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $latest['created_at'] ) )
						);
						?>
					</p>
				<?php else : ?>
					<h2><?php esc_html_e( 'Create your first backup', 'tim-backup' ); ?></h2>
					<p><?php esc_html_e( 'No managed backup is available yet.', 'tim-backup' ); ?></p>
				<?php endif; ?>
				<a class="button button-primary button-hero" href="<?php echo esc_url( $backups_url ); ?>">
					<?php esc_html_e( 'Manage backups', 'tim-backup' ); ?>
				</a>
			</section>

			<section class="tim-card">
				<p class="tim-card__label"><?php esc_html_e( 'Weekly automation', 'tim-backup' ); ?></p>
				<h2><?php esc_html_e( 'Full site backup', 'tim-backup' ); ?></h2>
				<p>
					<?php
					if ( $next ) {
						printf(
							/* translators: %s: Date of next scheduled backup. */
							esc_html__( 'Next run: %s', 'tim-backup' ),
							esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $next ) )
						);
					} else {
						esc_html_e( 'The weekly event is not currently scheduled.', 'tim-backup' );
					}
					?>
				</p>
				<p class="description"><?php esc_html_e( 'WordPress Cron runs when the site receives traffic.', 'tim-backup' ); ?></p>
			</section>

			<section class="tim-card">
				<p class="tim-card__label"><?php esc_html_e( 'Local retention', 'tim-backup' ); ?></p>
				<h2>
					<?php
					printf(
						/* translators: 1: Current backup count, 2: Maximum backup count. */
						esc_html__( '%1$d of %2$d backups', 'tim-backup' ),
						count( $backups ),
						3
					);
					?>
				</h2>
				<p><?php esc_html_e( 'After a successful fourth backup, the oldest managed archive is removed.', 'tim-backup' ); ?></p>
			</section>
		</div>

		<section class="tim-card tim-card--wide">
			<h2><?php esc_html_e( 'Security by default', 'tim-backup' ); ?></h2>
			<ul class="tim-check-list">
				<li><?php esc_html_e( 'Archives use random names and protected local storage.', 'tim-backup' ); ?></li>
				<li><?php esc_html_e( 'Every payload file is checked with SHA-256.', 'tim-backup' ); ?></li>
				<li><?php esc_html_e( 'A site-bound signature is verified before restore.', 'tim-backup' ); ?></li>
				<li><?php esc_html_e( 'Downloads and changes require administrator permission and request verification.', 'tim-backup' ); ?></li>
			</ul>
		</section>
		<?php
	}

	/**
	 * Renders backup creation and management.
	 *
	 * @return void
	 */
	private function render_backups(): void {
		$backups = $this->storage->all();
		?>
		<div class="tim-backup__grid tim-backup__grid--two">
			<?php $this->render_create_card( 'full' ); ?>
			<?php $this->render_create_card( 'database' ); ?>
		</div>

		<section class="tim-card tim-card--wide">
			<div class="tim-card__heading">
				<div>
					<p class="tim-card__label"><?php esc_html_e( 'Protected archives', 'tim-backup' ); ?></p>
					<h2><?php esc_html_e( 'Local backups', 'tim-backup' ); ?></h2>
				</div>
				<span><?php esc_html_e( 'Maximum: 3', 'tim-backup' ); ?></span>
			</div>

			<?php if ( empty( $backups ) ) : ?>
				<div class="tim-empty-state">
					<span class="dashicons dashicons-database-add" aria-hidden="true"></span>
					<h3><?php esc_html_e( 'No backups yet', 'tim-backup' ); ?></h3>
					<p><?php esc_html_e( 'Create a full or database backup above.', 'tim-backup' ); ?></p>
				</div>
			<?php else : ?>
				<div class="tim-backup-list">
					<?php foreach ( $backups as $backup ) : ?>
						<?php $this->render_backup_item( $backup ); ?>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>
		</section>
		<?php
	}

	/**
	 * Renders one backup creation card.
	 *
	 * @param string $type Backup type.
	 * @return void
	 */
	private function render_create_card( string $type ): void {
		$is_full = 'full' === $type;
		?>
		<section class="tim-card">
			<span class="<?php echo esc_attr( 'dashicons ' . ( $is_full ? 'dashicons-admin-site-alt3' : 'dashicons-database' ) ); ?>" aria-hidden="true"></span>
			<h2><?php echo esc_html( $is_full ? __( 'Full backup', 'tim-backup' ) : __( 'Database backup', 'tim-backup' ) ); ?></h2>
			<p>
				<?php
				echo esc_html(
					$is_full
						? __( 'Back up the database and regular files below the WordPress root.', 'tim-backup' )
						: __( 'Back up all current-site database tables, including WooCommerce HPOS tables.', 'tim-backup' )
				);
				?>
			</p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="tim_backup_create">
				<input type="hidden" name="backup_type" value="<?php echo esc_attr( $type ); ?>">
				<?php wp_nonce_field( 'tim_backup_create' ); ?>
				<button type="submit" class="button button-primary">
					<?php esc_html_e( 'Create and verify', 'tim-backup' ); ?>
				</button>
			</form>
		</section>
		<?php
	}

	/**
	 * Renders a managed backup and its authenticated actions.
	 *
	 * @param array<string, mixed> $backup Backup metadata.
	 * @return void
	 */
	private function render_backup_item( array $backup ): void {
		$id      = (string) $backup['id'];
		$is_full = 'full' === (string) $backup['type'];
		$type    = $is_full ? __( 'Full backup', 'tim-backup' ) : __( 'Database backup', 'tim-backup' );
		$restore_url = add_query_arg(
			array(
				'page'      => 'tim-backup',
				'view'      => 'restore',
				'backup_id' => $id,
			),
			admin_url( 'admin.php' )
		);
		?>
		<article class="tim-backup-item">
			<div class="tim-backup-item__summary">
				<span class="tim-status-dot" aria-hidden="true"></span>
				<div>
					<h3><?php echo esc_html( $type ); ?></h3>
					<p>
						<?php
						echo esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $backup['created_at'] ) );
						echo ' · ';
						echo esc_html( size_format( (int) $backup['size'], 1 ) );
						echo ' · ';
						printf(
							/* translators: %s: Backup duration in seconds. */
							esc_html__( '%s seconds', 'tim-backup' ),
							esc_html( (string) $backup['duration'] )
						);
						?>
					</p>
				</div>
				<span class="tim-badge">
					<?php
					echo esc_html(
						! empty( $backup['verified'] )
							? __( 'Verified at creation', 'tim-backup' )
							: __( 'Archive changed', 'tim-backup' )
					);
					?>
				</span>
			</div>

			<div class="tim-backup-item__actions">
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="tim_backup_download">
					<input type="hidden" name="backup_id" value="<?php echo esc_attr( $id ); ?>">
					<?php wp_nonce_field( 'tim_backup_download' ); ?>
					<button type="submit" class="button"><?php esc_html_e( 'Download', 'tim-backup' ); ?></button>
				</form>

				<a class="button" href="<?php echo esc_url( $restore_url ); ?>">
					<?php echo esc_html( $is_full ? __( 'Restore database only', 'tim-backup' ) : __( 'Restore database', 'tim-backup' ) ); ?>
				</a>

				<details class="tim-restore tim-delete">
					<summary class="button button-link-delete"><?php esc_html_e( 'Delete', 'tim-backup' ); ?></summary>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<input type="hidden" name="action" value="tim_backup_delete">
						<input type="hidden" name="backup_id" value="<?php echo esc_attr( $id ); ?>">
						<?php wp_nonce_field( 'tim_backup_delete' ); ?>
						<p><strong><?php esc_html_e( 'Permanently delete this backup?', 'tim-backup' ); ?></strong></p>
						<label>
							<input type="checkbox" name="confirm_delete" value="<?php echo esc_attr( $id ); ?>" required>
							<?php esc_html_e( 'I understand that this archive cannot be recovered.', 'tim-backup' ); ?>
						</label>
						<button type="submit" class="button button-link-delete"><?php esc_html_e( 'Delete permanently', 'tim-backup' ); ?></button>
					</form>
				</details>
			</div>
		</article>
		<?php
	}

	/**
	 * Renders system requirements and privacy facts.
	 *
	 * @return void
	 */
	private function render_system(): void {
		$storage_ready = $this->storage->ensure_directory();
		$checks        = array(
			__( 'PHP 8.1 or newer', 'tim-backup' )       => version_compare( PHP_VERSION, '8.1', '>=' ),
			__( 'PHP ZIP extension', 'tim-backup' )      => class_exists( 'ZipArchive' ),
			__( 'Writable backup storage', 'tim-backup' ) => ! is_wp_error( $storage_ready ) && is_writable( $this->storage->directory() ),
			__( 'Single-site WordPress', 'tim-backup' )  => ! is_multisite(),
		);
		?>
		<div class="tim-backup__grid tim-backup__grid--two">
			<section class="tim-card">
				<p class="tim-card__label"><?php esc_html_e( 'Environment', 'tim-backup' ); ?></p>
				<h2><?php esc_html_e( 'System checks', 'tim-backup' ); ?></h2>
				<ul class="tim-system-list">
					<?php foreach ( $checks as $label => $passed ) : ?>
						<li>
							<span class="<?php echo esc_attr( 'dashicons ' . ( $passed ? 'dashicons-yes-alt' : 'dashicons-warning' ) ); ?>" aria-hidden="true"></span>
							<span><?php echo esc_html( $label ); ?></span>
							<strong><?php echo esc_html( $passed ? __( 'Ready', 'tim-backup' ) : __( 'Action needed', 'tim-backup' ) ); ?></strong>
						</li>
					<?php endforeach; ?>
				</ul>
			</section>

			<section class="tim-card">
				<p class="tim-card__label"><?php esc_html_e( 'Privacy', 'tim-backup' ); ?></p>
				<h2><?php esc_html_e( 'Local by design', 'tim-backup' ); ?></h2>
				<p><?php esc_html_e( 'TIM Backup Free does not create an account, track usage, or contact an external service.', 'tim-backup' ); ?></p>
				<p><?php esc_html_e( 'Backup archives remain on this WordPress server until an administrator downloads or deletes them.', 'tim-backup' ); ?></p>
			</section>
		</div>
		<?php
	}

	/**
	 * Displays and consumes the current user's one-time notice.
	 *
	 * @return void
	 */
	private function render_notice(): void {
		$key    = 'tim_backup_notice_' . get_current_user_id();
		$notice = get_transient( $key );
		delete_transient( $key );

		if ( ! is_array( $notice ) || empty( $notice['message'] ) ) {
			return;
		}

		$type = in_array( $notice['type'] ?? '', array( 'success', 'error', 'warning', 'info' ), true )
			? (string) $notice['type']
			: 'info';
		?>
		<div class="notice notice-<?php echo esc_attr( $type ); ?> is-dismissible tim-backup__notice">
			<p><?php echo esc_html( (string) $notice['message'] ); ?></p>
		</div>
		<?php
	}
}
