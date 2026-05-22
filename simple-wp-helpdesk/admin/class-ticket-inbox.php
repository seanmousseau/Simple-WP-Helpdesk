<?php
/**
 * Admin Tickets inbox page mount + script enqueue (v4.0.0 #349).
 *
 * @package Simple_WP_Helpdesk
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Register the inbox screen on `current_screen` once the existing CPT menu
 * is mounted. Replaces the default WP_List_Table page for `helpdesk_ticket`.
 */
function swh_inbox_register(): void {
	add_action( 'admin_enqueue_scripts', 'swh_inbox_enqueue' );
	add_action( 'admin_menu', 'swh_inbox_replace_submenu', 99 );
}
add_action( 'plugins_loaded', 'swh_inbox_register', 10 );

/**
 * Enqueue the built React entry on the inbox screen only.
 *
 * @param string $hook The current admin page hook suffix.
 */
function swh_inbox_enqueue( string $hook ): void {
	if ( 'edit.php' !== $hook ) {
		return;
	}
	$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
	if ( ! $screen || 'edit-helpdesk_ticket' !== $screen->id ) {
		return;
	}

	$asset_file = SWH_PLUGIN_DIR . 'assets/dist/admin-inbox.asset.php';
	if ( ! file_exists( $asset_file ) ) {
		return; // Build not present — degrade silently to WP default list.
	}
	$asset = require $asset_file;

	wp_enqueue_script(
		'swh-admin-inbox',
		SWH_PLUGIN_URL . 'assets/dist/admin-inbox.js',
		$asset['dependencies'] ?? array(),
		$asset['version'] ?? SWH_VERSION,
		true
	);
	wp_enqueue_style(
		'swh-admin-inbox',
		SWH_PLUGIN_URL . 'assets/dist/admin-inbox.css',
		array( 'wp-components' ),
		$asset['version'] ?? SWH_VERSION
	);

	wp_localize_script(
		'swh-admin-inbox',
		'swhInbox',
		array(
			'restUrl'       => esc_url_raw( rest_url( 'swh/v1/' ) ),
			'restNonce'     => wp_create_nonce( 'wp_rest' ),
			'currentUserId' => get_current_user_id(),
		)
	);
}

/**
 * Replace the default CPT submenu with a custom callback that renders
 * the React mount point. Keeps the menu slug so deep links still work.
 */
function swh_inbox_replace_submenu(): void {
	remove_submenu_page( 'edit.php?post_type=helpdesk_ticket', 'edit.php?post_type=helpdesk_ticket' );
	add_submenu_page(
		'edit.php?post_type=helpdesk_ticket',
		__( 'Tickets', 'simple-wp-helpdesk' ),
		__( 'All Tickets', 'simple-wp-helpdesk' ),
		'edit_posts',
		'edit.php?post_type=helpdesk_ticket',
		'swh_inbox_render_root'
	);
}

/**
 * Renders the React mount div + a fallback noscript message.
 */
function swh_inbox_render_root(): void {
	echo '<div class="wrap" style="margin:0;padding:0;">';
	echo '<div id="swh-inbox-root"></div>';
	echo '<noscript><p>' . esc_html__( 'JavaScript is required for the new Tickets inbox. Please enable JavaScript.', 'simple-wp-helpdesk' ) . '</p></noscript>';
	echo '</div>';
}
