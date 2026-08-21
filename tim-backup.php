<?php
/**
 * Plugin Name:       TIM Backup
 * Plugin URI:        https://github.com/didou7377/tim-backup-free
 * Description:       Create, verify, download, and manage secure local WordPress backups.
 * Version:           0.1.0
 * Requires at least: 6.5
 * Requires PHP:      8.1
 * Author:            TIM Plugins
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       tim-backup
 * Domain Path:       /languages
 *
 * @package TIM_Backup
 */

defined( 'ABSPATH' ) || exit;

define( 'TIM_BACKUP_VERSION', '0.1.0' );
define( 'TIM_BACKUP_FILE', __FILE__ );
define( 'TIM_BACKUP_DIR', plugin_dir_path( __FILE__ ) );
define( 'TIM_BACKUP_URL', plugin_dir_url( __FILE__ ) );

require_once TIM_BACKUP_DIR . 'includes/class-tim-backup-storage.php';
require_once TIM_BACKUP_DIR . 'includes/class-tim-backup-backup-service.php';
require_once TIM_BACKUP_DIR . 'includes/class-tim-backup-restore-service.php';
require_once TIM_BACKUP_DIR . 'includes/class-tim-backup-admin.php';
require_once TIM_BACKUP_DIR . 'includes/class-tim-backup-plugin.php';

register_activation_hook( __FILE__, array( 'TIM_Backup_Plugin', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'TIM_Backup_Plugin', 'deactivate' ) );

TIM_Backup_Plugin::instance()->init();
