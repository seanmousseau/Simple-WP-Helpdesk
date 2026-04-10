<?php
/**
 * Plugin Name: Simple WP Helpdesk
 * Description: A comprehensive helpdesk system with auto-close, custom templates, multi-file attachments, internal notes, anti-spam, deep uninstallation cleanup, and GitHub auto-updates.
 * Version: 2.1.0
 * Requires at least: 5.3
 * Requires PHP: 7.4
 * Text Domain: simple-wp-helpdesk
 * Author: SM WP Plugins
 *
 * @package Simple_WP_Helpdesk
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'SWH_VERSION', '2.1.0' );
define( 'SWH_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'SWH_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'SWH_PLUGIN_FILE', __FILE__ );

// CDN-hosted brand assets.
define( 'SWH_CDN_BASE', 'https://media.seanmousseau.com/file/seanmousseau/assets/logos/swh' );
define( 'SWH_ICON_1X', SWH_CDN_BASE . '/icon-128x128.png' );
define( 'SWH_ICON_2X', SWH_CDN_BASE . '/icon-256x256.png' );
define( 'SWH_MENU_ICON', SWH_ICON_1X );

// Core includes (always loaded).
require_once SWH_PLUGIN_DIR . 'includes/helpers.php';
require_once SWH_PLUGIN_DIR . 'includes/class-installer.php';
require_once SWH_PLUGIN_DIR . 'includes/class-email.php';
require_once SWH_PLUGIN_DIR . 'includes/class-ticket.php';
require_once SWH_PLUGIN_DIR . 'includes/class-cron.php';

// Admin includes (only in admin context).
if ( is_admin() ) {
	require_once SWH_PLUGIN_DIR . 'admin/class-settings.php';
	require_once SWH_PLUGIN_DIR . 'admin/class-ticket-editor.php';
	require_once SWH_PLUGIN_DIR . 'admin/class-ticket-list.php';
}

// Frontend includes.
require_once SWH_PLUGIN_DIR . 'frontend/class-shortcode.php';
require_once SWH_PLUGIN_DIR . 'frontend/class-portal.php';

// Lifecycle hooks (must reference main plugin __FILE__).
/** Runs on plugin activation: schedules cron events, registers CPT, seeds defaults. */
register_activation_hook( __FILE__, 'swh_activate' );
/** Runs on plugin deactivation: clears scheduled cron events. */
register_deactivation_hook( __FILE__, 'swh_deactivate' );
/** Runs on plugin uninstall: deletes all options, meta, and uploaded files when opted in. */
register_uninstall_hook( __FILE__, 'swh_uninstall' );

// GitHub updater (via plugin-update-checker library).
require_once SWH_PLUGIN_DIR . 'vendor/plugin-update-checker/load-v5p6.php';

use YahnisElsts\PluginUpdateChecker\v5p6\PucFactory;

$swh_update_checker = PucFactory::buildUpdateChecker(
	'https://github.com/seanmousseau/Simple-WP-Helpdesk/',
	__FILE__,
	'simple-wp-helpdesk'
);
$swh_update_checker->setBranch( 'main' );
$swh_update_checker->getVcsApi()->enableReleaseAssets();

// Inject plugin icons into the update checker's plugin info response.
add_filter( 'puc_request_info_result-simple-wp-helpdesk', 'swh_add_plugin_icons' );
/**
 * Add CDN-hosted icons to the plugin info returned by the update checker.
 *
 * @param object|null $info Plugin info object from PUC.
 * @return object|null
 */
function swh_add_plugin_icons( $info ) {
	if ( $info ) {
		$info->icons = array(
			'1x' => 'https://media.seanmousseau.com/file/seanmousseau/assets/logos/swh/icon-128x128.png',
			'2x' => 'https://media.seanmousseau.com/file/seanmousseau/assets/logos/swh/icon-256x256.png',
		);
	}
	return $info;
}
