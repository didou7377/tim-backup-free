<?php
/**
 * Destructive MySQL/MariaDB database restore integration test.
 *
 * Run only in an ephemeral WordPress installation with:
 * wp eval-file wp-content/plugins/tim-backup/tests/mysql-restore.php
 *
 * @package TIM_Backup
 */

defined( 'ABSPATH' ) || exit;

$storage = new TIM_Backup_Storage();
$backups = new TIM_Backup_Backup_Service( $storage );
$restore = new TIM_Backup_Restore_Service( $storage, $backups );
$jobs    = new TIM_Backup_Restore_Job_Service( $storage, $backups, $restore );

update_option( 'tim_backup_restore_probe', 'before-backup', false );
$chunk_table = $GLOBALS['wpdb']->prefix . 'tim_chunk_probe';
$GLOBALS['wpdb']->query( "DROP TABLE IF EXISTS `{$chunk_table}`" );
$GLOBALS['wpdb']->query( "CREATE TABLE `{$chunk_table}` (id BIGINT UNSIGNED NOT NULL, payload LONGBLOB NOT NULL, PRIMARY KEY (id)) ENGINE=InnoDB" );

for ( $row_id = 1; $row_id <= 6; ++$row_id ) {
	$inserted = $GLOBALS['wpdb']->insert(
		$chunk_table,
		array(
			'id'      => $row_id,
			'payload' => str_repeat( chr( 64 + $row_id ), 800 * 1024 ),
		)
	);

	if ( false === $inserted ) {
		throw new RuntimeException( 'Chunk fixture could not be created.' );
	}
}

$backup = $backups->create( 'database' );

if ( is_wp_error( $backup ) ) {
	throw new RuntimeException( 'Database backup failed: ' . $backup->get_error_message() );
}

$archive_path = $storage->archive_path( (string) $backup['id'] );
$manifest     = is_wp_error( $archive_path ) ? $archive_path : $backups->verify( $archive_path );

if ( is_wp_error( $manifest ) ) {
	throw new RuntimeException( 'Chunked database backup could not be verified.' );
}

$chunk_prefix = 'tim-backup/database/data/' . hash( 'sha256', $chunk_table ) . '-';
$chunk_count  = count(
	array_filter(
		array_keys( (array) $manifest['entries'] ),
		static function ( string $entry ) use ( $chunk_prefix ): bool {
			return str_starts_with( $entry, $chunk_prefix );
		}
	)
);

if ( $chunk_count < 2 ) {
	throw new RuntimeException( 'Large database table was not split into bounded chunks.' );
}

update_option( 'tim_backup_restore_probe', 'after-backup', false );
$GLOBALS['wpdb']->query( "TRUNCATE TABLE `{$chunk_table}`" );

if ( 'after-backup' !== get_option( 'tim_backup_restore_probe' ) ) {
	throw new RuntimeException( 'Restore probe setup failed.' );
}

$result = $jobs->start( (string) $backup['id'], 1 );

if ( is_wp_error( $result ) ) {
	throw new RuntimeException( 'Restore job could not start: ' . $result->get_error_message() );
}

for ( $attempt = 0; $attempt < 1000 && 'active' === (string) $result['status']; ++$attempt ) {
	// Recreate every service to prove that progress survives independent requests.
	$storage = new TIM_Backup_Storage();
	$backups = new TIM_Backup_Backup_Service( $storage );
	$restore = new TIM_Backup_Restore_Service( $storage, $backups );
	$jobs    = new TIM_Backup_Restore_Job_Service( $storage, $backups, $restore );
	$result  = $jobs->advance();

	if ( is_wp_error( $result ) ) {
		throw new RuntimeException( 'Database restore failed: ' . $result->get_error_message() );
	}
}

if ( 'completed' !== (string) $result['status'] ) {
	throw new RuntimeException( 'Database restore job did not complete: ' . (string) ( $result['error'] ?? 'attempt limit reached' ) );
}

if ( 'before-backup' !== get_option( 'tim_backup_restore_probe' ) ) {
	throw new RuntimeException( 'Database restore did not restore the probe value.' );
}

if ( 6 !== (int) $GLOBALS['wpdb']->get_var( "SELECT COUNT(*) FROM `{$chunk_table}`" ) ) {
	throw new RuntimeException( 'Database restore did not restore all chunked rows.' );
}

foreach ( $storage->all() as $managed_backup ) {
	$deleted = $storage->delete( (string) $managed_backup['id'] );

	if ( is_wp_error( $deleted ) ) {
		throw new RuntimeException( 'Integration backup cleanup failed.' );
	}
}

$GLOBALS['wpdb']->query( "DROP TABLE IF EXISTS `{$chunk_table}`" );
WP_CLI::success( 'TIM Backup resumable MySQL restore passed.' );
