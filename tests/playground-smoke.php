<?php
/**
 * Runtime smoke test executed inside WordPress Playground.
 *
 * @package TIM_Backup
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'TIM_Backup_Plugin' ) ) {
	throw new RuntimeException( 'TIM Backup did not load.' );
}

if ( ! wp_next_scheduled( 'tim_backup_weekly_event' ) ) {
	throw new RuntimeException( 'Weekly backup was not scheduled.' );
}

unload_textdomain( 'tim-backup-free' );

if (
	! load_textdomain( 'tim-backup-free', TIM_BACKUP_DIR . 'languages/tim-backup-free-de_DE.mo' )
	|| 'Übersicht' !== __( 'Overview', 'tim-backup-free' )
) {
	throw new RuntimeException( 'Bundled German translation did not load.' );
}

$storage = new TIM_Backup_Storage();
$service = new TIM_Backup_Backup_Service( $storage );
$created = array();

$ready = $storage->ensure_directory();

if ( is_wp_error( $ready ) ) {
	throw new RuntimeException( 'Private storage is unavailable.' );
}

$exclusive = $storage->acquire_lock( 'operation' );

if ( is_wp_error( $exclusive ) ) {
	throw new RuntimeException( 'Exclusive operation lock could not be acquired.' );
}

$storage->release_lock( 'operation', $exclusive );
$shared_one = $storage->acquire_lock( 'operation', true );
$shared_two = $storage->acquire_lock( 'operation', true );

if ( is_wp_error( $shared_one ) || is_wp_error( $shared_two ) ) {
	throw new RuntimeException( 'Shared operation locks could not be acquired.' );
}

$storage->release_lock( 'operation', $shared_two );
$storage->release_lock( 'operation', $shared_one );

for ( $index = 0; $index < 3; ++$index ) {
	$result = $service->create( 'database' );

	if ( is_wp_error( $result ) ) {
		throw new RuntimeException( 'Database backup failed: ' . esc_html( $result->get_error_message() ) );
	}

	$created[] = $result;
}

$protected_id = (string) $created[0]['id'];
$rotated      = $service->create( 'database', $protected_id );

if ( is_wp_error( $rotated ) ) {
	throw new RuntimeException( 'Protected rotation failed: ' . esc_html( $rotated->get_error_message() ) );
}

$backups = $storage->all();

if ( 3 !== count( $backups ) || is_wp_error( $storage->find( $protected_id ) ) ) {
	throw new RuntimeException( 'Protected three-backup rotation is invalid.' );
}

$tampered      = array_shift( $backups );
$tampered_path = $storage->archive_path( (string) $tampered['id'] );

if ( is_wp_error( $tampered_path ) ) {
	throw new RuntimeException( 'Tamper-test archive path is invalid.' );
}

$zip = new ZipArchive();

if ( true !== $zip->open( $tampered_path ) ) {
	throw new RuntimeException( 'Tamper-test archive could not be opened.' );
}

$zip->addFromString( 'tim-backup/unexpected.txt', 'tampered' );
$zip->close();

if ( ! is_wp_error( $service->verify( $tampered_path ) ) ) {
	throw new RuntimeException( 'Unexpected archive content was not rejected.' );
}

$deleted = $storage->delete( (string) $tampered['id'] );

if ( is_wp_error( $deleted ) ) {
	throw new RuntimeException( 'Tamper-test archive cleanup failed.' );
}

$restore      = new TIM_Backup_Restore_Service( $storage, $service );
$restore_jobs = new TIM_Backup_Restore_Job_Service( $storage, $service, $restore );
$job          = $restore_jobs->start( (string) $backups[0]['id'], 1 );

if ( is_wp_error( $job ) || 'active' !== (string) $job['status'] ) {
	throw new RuntimeException( 'Persistent restore job could not start.' );
}

$restore_jobs = new TIM_Backup_Restore_Job_Service( $storage, $service, $restore );
$job          = $restore_jobs->status();

if ( is_wp_error( $job ) || 'verify' !== (string) $job['phase'] ) {
	throw new RuntimeException( 'Persistent restore job could not be resumed.' );
}

$journal_path = $storage->directory() . DIRECTORY_SEPARATOR . 'restore-job.json';
$journal_json = file_get_contents( $journal_path );
$journal_data = false === $journal_json ? null : json_decode( $journal_json, true );

if ( ! is_array( $journal_data ) ) {
	throw new RuntimeException( 'Restore journal could not be read for integrity testing.' );
}

$journal_data['backup_id'] = str_repeat( '0', 32 );
file_put_contents( $journal_path, (string) wp_json_encode( $journal_data ) );
$restore_jobs = new TIM_Backup_Restore_Job_Service( $storage, $service, $restore );

if ( ! is_wp_error( $restore_jobs->status() ) ) {
	throw new RuntimeException( 'Tampered restore journal was not rejected.' );
}

file_put_contents( $journal_path, (string) $journal_json );
$restore_jobs = new TIM_Backup_Restore_Job_Service( $storage, $service, $restore );

for ( $attempt = 0; $attempt < 100 && 'active' === (string) $job['status'] && 'prepare' !== (string) $job['phase']; ++$attempt ) {
	$job = $restore_jobs->advance();

	if ( is_wp_error( $job ) ) {
		throw new RuntimeException( 'Restore preparation failed: ' . esc_html( $job->get_error_message() ) );
	}
}

if ( 'prepare' !== (string) $job['phase'] ) {
	throw new RuntimeException( 'Restore preparation did not reach staged table creation.' );
}

if ( ! $restore_jobs->is_maintenance_active() ) {
	throw new RuntimeException( 'Restore maintenance marker was not enabled.' );
}

$count_before_retry = count( $storage->all() );
$safety_retry       = $service->create( 'database', (string) $job['backupId'], (string) $job['safetyBackupId'] );

if ( is_wp_error( $safety_retry ) || $count_before_retry !== count( $storage->all() ) ) {
	throw new RuntimeException( 'Safety backup creation is not idempotent.' );
}

$job = $restore_jobs->cancel();

if ( is_wp_error( $job ) || 'cancelled' !== (string) $job['status'] ) {
	throw new RuntimeException( 'Restore job cancellation failed.' );
}

if ( $restore_jobs->is_maintenance_active() ) {
	throw new RuntimeException( 'Restore maintenance marker was not removed after cancellation.' );
}

wp_set_current_user( 1 );
$_GET['view']      = 'restore';
$_GET['backup_id'] = (string) $backups[0]['id'];
$admin             = new TIM_Backup_Admin( $storage, $restore_jobs );
ob_start();
$admin->render_page();
$restore_markup = (string) ob_get_clean();
unset( $_GET['view'], $_GET['backup_id'] );

if (
	! str_contains( $restore_markup, 'data-tim-restore-assistant' )
	|| ! str_contains( $restore_markup, 'data-tim-restore-steps' )
	|| ! str_contains( $restore_markup, 'value="' . (string) $backups[0]['id'] . '"' )
) {
	throw new RuntimeException( 'Guided restore interface did not render correctly.' );
}

$backups = $storage->all();

foreach ( $backups as $backup ) {
	$path         = $storage->archive_path( (string) $backup['id'] );
	$verification = is_wp_error( $path ) ? $path : $service->verify( $path );

	if ( is_wp_error( $verification ) ) {
		throw new RuntimeException( 'Backup verification failed: ' . esc_html( $verification->get_error_message() ) );
	}

	$deleted = $storage->delete( (string) $backup['id'] );

	if ( is_wp_error( $deleted ) ) {
		throw new RuntimeException( 'Backup cleanup failed: ' . esc_html( $deleted->get_error_message() ) );
	}
}

echo "TIM_BACKUP_SMOKE_OK\n";
