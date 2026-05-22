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
 * Register the inbox screen. The React mount is injected via `in_admin_header`
 * because WordPress core renders edit.php?post_type=helpdesk_ticket via its
 * own template; we can't replace that with a submenu callback at the same
 * slug. Instead we inject our React root + a style block that hides the
 * default WP_Posts_List_Table chrome.
 */
function swh_inbox_register(): void {
	add_action( 'admin_enqueue_scripts', 'swh_inbox_enqueue' );
	add_action( 'in_admin_header', 'swh_inbox_inject_root', 99 );
}
add_action( 'plugins_loaded', 'swh_inbox_register', 10 );

/**
 * Inject the React mount on the CPT list screen, BEFORE WP renders the
 * default `<div class="wrap">…WP_List_Table…</div>`. A style block hides
 * any subsequent `.wrap` so operators see only the new UI.
 */
function swh_inbox_inject_root(): void {
	$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
	if ( ! $screen || 'edit-helpdesk_ticket' !== $screen->id ) {
		return;
	}
	// Allow ?legacy=1 to fall back to the WP_List_Table view (operators with
	// custom plugins that hook into the legacy list table can still use it).
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only opt-out flag, not a state change.
	if ( isset( $_GET['legacy'] ) && '1' === $_GET['legacy'] ) {
		return;
	}

	echo '<div class="wrap" id="swh-inbox-mount-wrap" style="margin:0;padding:0;"><div id="swh-inbox-root"></div>';
	echo '<noscript><p>' . esc_html__( 'JavaScript is required for the Tickets inbox. Please enable JavaScript or visit ?legacy=1 for the classic list view.', 'simple-wp-helpdesk' ) . '</p></noscript>';
	echo '</div>';
	echo '<style id="swh-inbox-hide-default">body.post-type-helpdesk_ticket #wpbody-content > .wrap:not(#swh-inbox-mount-wrap){display:none !important;}</style>';
}

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
	$deps       = array();
	$version    = SWH_VERSION;

	if ( file_exists( $asset_file ) ) {
		// @phpstan-ignore-next-line include.fileNotFound
		$asset = include $asset_file; // phpcs:ignore WordPressVIPMinimum.Files.IncludingFile.UsingVariable
		if ( is_array( $asset ) ) {
			if ( isset( $asset['dependencies'] ) && is_array( $asset['dependencies'] ) ) {
				$deps = array_values( array_filter( $asset['dependencies'], 'is_string' ) );
			}
			if ( isset( $asset['version'] ) && is_string( $asset['version'] ) ) {
				$version = $asset['version'];
			}
		}
	}

	wp_enqueue_script(
		'swh-admin-inbox',
		SWH_PLUGIN_URL . 'assets/dist/admin-inbox.js',
		$deps,
		$version,
		true
	);
	wp_enqueue_style(
		'swh-admin-inbox',
		SWH_PLUGIN_URL . 'assets/dist/admin-inbox.css',
		array( 'wp-components' ),
		$version
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
