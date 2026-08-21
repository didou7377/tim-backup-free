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

unload_textdomain( 'tim-backup' );

if (
	! load_textdomain( 'tim-backup', TIM_BACKUP_DIR . 'languages/tim-backup-de_DE.mo' )
	|| 'Übersicht' !== __( 'Overview', 'tim-backup' )
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
