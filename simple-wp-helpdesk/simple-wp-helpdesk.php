<?php
/**
 * Plugin Name: Simple WP Helpdesk
 * Description: A comprehensive helpdesk system with auto-close, custom templates, multi-file attachments, internal notes, anti-spam, deep uninstallation cleanup, and GitHub auto-updates.
 * Version: 2.4.1
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

define( 'SWH_VERSION', '2.4.1' );
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
	return '<p>Simple WP Helpdesk is a full-featured helpdesk and ticketing system built entirely on WordPress core data structures. No custom database tables, no external services, no subscriptions — your data stays on your server.</p>
<p><strong>Key Features:</strong></p>
<ul>
<li><strong>Tickets as Custom Post Types</strong> — all data lives in standard WP tables; no schema migrations or DB cleanup needed on uninstall</li>
<li><strong>Frontend submission form</strong> — <code>[submit_ticket]</code> shortcode with configurable priority, status, and lookup form visibility</li>
<li><strong>Secure token-based client portal</strong> — clients view conversation history, reply, upload attachments, and close or reopen their ticket</li>
<li><strong>My Tickets dashboard</strong> — portal without a token shows a ticket table for logged-in users or the lookup form for guests</li>
<li><strong>Standalone portal shortcode</strong> — optional <code>[helpdesk_portal]</code> for a dedicated support hub page</li>
<li><strong>Canned responses</strong> — save reply templates in Settings and insert them from within the ticket editor</li>
<li><strong>14 customizable email templates</strong> — HTML and plain-text, with dynamic placeholders and <code>{if key}&hellip;{endif key}</code> conditional blocks</li>
<li><strong>Multi-file uploads</strong> — XHR progress bar on submission, configurable size/count limits, files served via a secure proxy endpoint</li>
<li><strong>Technician role</strong> — optional restriction so technicians only see tickets assigned to them</li>
<li><strong>Bulk status changes</strong> — update multiple tickets at once directly from the ticket list</li>
<li><strong>CSAT satisfaction prompt</strong> — 1&ndash;5 star rating shown to clients after closing a ticket, stored as post meta</li>
<li><strong>Background automation</strong> — auto-close resolved tickets and scheduled data retention with cron locking</li>
<li><strong>Anti-spam</strong> — honeypot (zero config), Google reCAPTCHA v2, and Cloudflare Turnstile on all public forms</li>
<li><strong>CDN/proxy-aware rate limiting</strong> — persistent via <code>wp_options</code>, survives cache flushes</li>
<li><strong>Token expiration</strong> — configurable TTL with auto-rotation for portal links</li>
<li><strong>Tabbed settings panel</strong> — 7 tabs: General, Assignment &amp; Routing, Email Templates, Messages, Anti-Spam, Canned Responses, Tools</li>
<li><strong>GDPR tools</strong> — per-email data purge, retention policies, and thorough uninstall cleanup</li>
<li><strong>Internationalization</strong> — i18n ready with full text-domain support</li>
<li><strong>GitHub auto-updater</strong> — new releases delivered directly to the WordPress dashboard via plugin-update-checker</li>
</ul>';
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
