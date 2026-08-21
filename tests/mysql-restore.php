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

update_option( 'tim_backup_restore_probe', 'before-backup', false );

$backup = $backups->create( 'database' );

if ( is_wp_error( $backup ) ) {
	throw new RuntimeException( 'Database backup failed: ' . $backup->get_error_message() );
}

update_option( 'tim_backup_restore_probe', 'after-backup', false );

if ( 'after-backup' !== get_option( 'tim_backup_restore_probe' ) ) {
	throw new RuntimeException( 'Restore probe setup failed.' );
}

$result = $restore->restore( (string) $backup['id'] );

if ( is_wp_error( $result ) ) {
	throw new RuntimeException( 'Database restore failed: ' . $result->get_error_message() );
}

if ( 'before-backup' !== get_option( 'tim_backup_restore_probe' ) ) {
	throw new RuntimeException( 'Database restore did not restore the probe value.' );
}

$deleted = $storage->delete( (string) $backup['id'] );

if ( is_wp_error( $deleted ) ) {
	throw new RuntimeException( 'Integration backup cleanup failed.' );
}

WP_CLI::success( 'TIM Backup MySQL restore passed.' );
