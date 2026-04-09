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

// Plugin icon CDN URLs.
define( 'SWH_ICON_1X',   'https://media.seanmousseau.com/file/seanmousseau/assets/logos/swh/icon-128x128.png' );
define( 'SWH_ICON_2X',   'https://media.seanmousseau.com/file/seanmousseau/assets/logos/swh/icon-256x256.png' );
define( 'SWH_MENU_ICON', 'https://media.seanmousseau.com/file/seanmousseau/assets/logos/swh/favicon-32.png' );

// Inject icons into the PUC plugin info response (View Details modal / plugins_api).
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
			'1x' => SWH_ICON_1X,
			'2x' => SWH_ICON_2X,
		);
	}
	return $info;
}

// Inject icons into the update transient so they appear on the Plugins list screen.
// PUC hooks site_transient_update_plugins at priority 10 (on read) to inject its entry.
// We run at priority 11 so our icons are added after PUC has populated the entry.
add_filter( 'site_transient_update_plugins', 'swh_inject_plugin_icons_into_update_transient', 11 );
/**
 * Ensure icon URLs are present in the update transient for both updated and current states.
 *
 * @param object $transient The update_plugins site transient.
 * @return object
 */
function swh_inject_plugin_icons_into_update_transient( $transient ) {
	if ( empty( $transient->checked ) ) {
		return $transient;
	}
	$plugin_file = plugin_basename( SWH_PLUGIN_FILE );
	$icons       = array(
		'1x' => SWH_ICON_1X,
		'2x' => SWH_ICON_2X,
	);
	foreach ( array( 'response', 'no_update' ) as $key ) {
		if ( isset( $transient->{$key}[ $plugin_file ] ) ) {
			$transient->{$key}[ $plugin_file ]->icons = $icons;
		}
	}
	return $transient;
}
