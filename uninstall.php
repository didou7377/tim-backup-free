<?php
/**
 * TIM Backup uninstall routine.
 *
 * Backup archives and their index are deliberately retained to prevent data loss.
 *
 * @package TIM_Backup
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

wp_clear_scheduled_hook( 'tim_backup_weekly_event' );

global $wpdb;

$like = $wpdb->esc_like( '_transient_tim_backup_notice_' ) . '%';

$wpdb->query(
	$wpdb->prepare(
		"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
		$like
	)
);
