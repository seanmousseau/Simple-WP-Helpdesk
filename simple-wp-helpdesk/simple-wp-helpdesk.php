<?php
/**
 * Plugin Name: Simple WP Helpdesk
 * Description: A comprehensive helpdesk system with auto-close, custom templates, multi-file attachments, internal notes, anti-spam, deep uninstallation cleanup, and GitHub auto-updates.
 * Version: 2.5.0
 * Requires at least: 5.3
 * Requires PHP: 7.4
 * Text Domain: simple-wp-helpdesk
 * Author: Sean Mousseau
 * Author URI: https://github.com/seanmousseau/Simple-WP-Helpdesk
 *
 * @package Simple_WP_Helpdesk
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'SWH_VERSION', '2.5.0' );
define( 'SWH_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'SWH_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'SWH_PLUGIN_FILE', __FILE__ );

// Bundled brand assets (served locally — no CDN dependency).
define( 'SWH_ICON_1X', SWH_PLUGIN_URL . 'assets/icon-128x128.png' );
define( 'SWH_ICON_2X', SWH_PLUGIN_URL . 'assets/icon-256x256.png' );
define( 'SWH_MENU_ICON', SWH_PLUGIN_URL . 'assets/favicon-32.png' );

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

// Inject icons into the PUC plugin info response (View Details modal / plugins_api).
add_filter( 'puc_request_info_result-simple-wp-helpdesk', 'swh_add_plugin_icons' );
/**
 * Add CDN-hosted icons to the plugin info returned by the update checker.
 *
 * @param \YahnisElsts\PluginUpdateChecker\v5p6\Plugin\PluginInfo|null $info Plugin info object from PUC.
 * @return \YahnisElsts\PluginUpdateChecker\v5p6\Plugin\PluginInfo|null
 *
 * @phpstan-param \YahnisElsts\PluginUpdateChecker\v5p6\Plugin\PluginInfo|null $info
 * @phpstan-return \YahnisElsts\PluginUpdateChecker\v5p6\Plugin\PluginInfo|null
 */
function swh_add_plugin_icons( $info ) {
	if ( $info ) {
		$info->icons                   = array(
			'1x' => SWH_ICON_1X,
			'2x' => SWH_ICON_2X,
		);
		$info->sections['description'] = swh_plugin_description_html();
	}
	return $info;
}

/**
 * Returns the HTML description shown in the WordPress "View Details" plugin modal.
 *
 * PUC does not reliably parse the readme.txt == Description == section from a GitHub
 * release ZIP, so we inject it directly via the puc_request_info_result filter.
 *
 * @return string
 */
function swh_plugin_description_html() {
	$features = array(
		array( __( 'Tickets as Custom Post Types', 'simple-wp-helpdesk' ), __( 'all data lives in standard WP tables; no schema migrations or DB cleanup needed on uninstall', 'simple-wp-helpdesk' ) ),
		/* translators: %s: [submit_ticket] shortcode name wrapped in <code> tags */
		array( __( 'Frontend submission form', 'simple-wp-helpdesk' ), sprintf( __( '%s shortcode with configurable priority, status, and lookup form visibility', 'simple-wp-helpdesk' ), '<code>[submit_ticket]</code>' ) ),
		array( __( 'Secure token-based client portal', 'simple-wp-helpdesk' ), __( 'clients view conversation history, reply, upload attachments, and close or reopen their ticket', 'simple-wp-helpdesk' ) ),
		array( __( 'My Tickets dashboard', 'simple-wp-helpdesk' ), __( 'portal without a token shows a ticket table for logged-in users or the lookup form for guests', 'simple-wp-helpdesk' ) ),
		/* translators: %s: [helpdesk_portal] shortcode name wrapped in <code> tags */
		array( __( 'Standalone portal shortcode', 'simple-wp-helpdesk' ), sprintf( __( 'optional %s for a dedicated support hub page', 'simple-wp-helpdesk' ), '<code>[helpdesk_portal]</code>' ) ),
		array( __( 'Canned responses', 'simple-wp-helpdesk' ), __( 'save reply templates in Settings and insert them from within the ticket editor', 'simple-wp-helpdesk' ) ),
		/* translators: %s: {if key}…{endif key} template conditional syntax wrapped in <code> tags */
		array( __( '14 customizable email templates', 'simple-wp-helpdesk' ), sprintf( __( 'HTML and plain-text, with dynamic placeholders and %s conditional blocks', 'simple-wp-helpdesk' ), '<code>{if key}&hellip;{endif key}</code>' ) ),
		array( __( 'Multi-file uploads', 'simple-wp-helpdesk' ), __( 'XHR progress bar on submission, configurable size/count limits, files served via a secure proxy endpoint', 'simple-wp-helpdesk' ) ),
		array( __( 'Technician role', 'simple-wp-helpdesk' ), __( 'optional restriction so technicians only see tickets assigned to them', 'simple-wp-helpdesk' ) ),
		array( __( 'Bulk status changes', 'simple-wp-helpdesk' ), __( 'update multiple tickets at once directly from the ticket list', 'simple-wp-helpdesk' ) ),
		array( __( 'CSAT satisfaction prompt', 'simple-wp-helpdesk' ), __( '1&#x2013;5 star rating shown to clients after closing a ticket, stored as post meta', 'simple-wp-helpdesk' ) ),
		array( __( 'Background automation', 'simple-wp-helpdesk' ), __( 'auto-close resolved tickets and scheduled data retention with cron locking', 'simple-wp-helpdesk' ) ),
		array( __( 'Anti-spam', 'simple-wp-helpdesk' ), __( 'honeypot (zero config), Google reCAPTCHA v2/Enterprise, and Cloudflare Turnstile on all public forms', 'simple-wp-helpdesk' ) ),
		/* translators: %s: wp_options WordPress table name wrapped in <code> tags */
		array( __( 'CDN/proxy-aware rate limiting', 'simple-wp-helpdesk' ), sprintf( __( 'persistent via %s, survives cache flushes', 'simple-wp-helpdesk' ), '<code>wp_options</code>' ) ),
		array( __( 'Token expiration', 'simple-wp-helpdesk' ), __( 'configurable TTL with auto-rotation for portal links', 'simple-wp-helpdesk' ) ),
		array( __( 'Tabbed settings panel', 'simple-wp-helpdesk' ), __( '7 tabs: General, Assignment &amp; Routing, Email Templates, Messages, Anti-Spam, Canned Responses, Tools', 'simple-wp-helpdesk' ) ),
		array( __( 'GDPR tools', 'simple-wp-helpdesk' ), __( 'per-email data purge, retention policies, and thorough uninstall cleanup', 'simple-wp-helpdesk' ) ),
		array( __( 'Internationalization', 'simple-wp-helpdesk' ), __( 'i18n ready with full text-domain support', 'simple-wp-helpdesk' ) ),
		array( __( 'GitHub auto-updater', 'simple-wp-helpdesk' ), __( 'new releases delivered directly to the WordPress dashboard via plugin-update-checker', 'simple-wp-helpdesk' ) ),
	);
	$html  = '<p>' . esc_html__( 'Simple WP Helpdesk is a full-featured helpdesk and ticketing system built entirely on WordPress core data structures. No custom database tables, no external services, no subscriptions — your data stays on your server.', 'simple-wp-helpdesk' ) . '</p>';
	$html .= '<p><strong>' . esc_html__( 'Key Features:', 'simple-wp-helpdesk' ) . '</strong></p><ul>';
	foreach ( $features as $feature ) {
		$html .= '<li><strong>' . esc_html( $feature[0] ) . '</strong> &mdash; ' . wp_kses( $feature[1], array( 'code' => array() ) ) . '</li>';
	}
	$html .= '</ul>';
	return $html;
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
