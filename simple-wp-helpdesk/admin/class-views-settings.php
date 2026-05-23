<?php
declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Settings → Helpdesk → Views admin page (v4.0.0 #352).
 *
 * Mounts a React tree for admins to manage global saved views.
 */
function swh_views_settings_register(): void {
	add_action( 'admin_menu', 'swh_views_settings_add_menu', 100 );
	add_action( 'admin_enqueue_scripts', 'swh_views_settings_enqueue' );
}
add_action( 'plugins_loaded', 'swh_views_settings_register', 10 );

function swh_views_settings_add_menu(): void {
	add_submenu_page(
		'edit.php?post_type=helpdesk_ticket',
		__( 'Saved Views', 'simple-wp-helpdesk' ),
		__( 'Saved Views', 'simple-wp-helpdesk' ),
		'manage_options',
		'swh-views',
		'swh_views_settings_render'
	);
}

function swh_views_settings_render(): void {
	echo '<div class="wrap"><div id="swh-views-settings-root"></div></div>';
}

function swh_views_settings_enqueue( string $hook ): void {
	if ( strpos( $hook, 'swh-views' ) === false ) {
		return;
	}
	$asset_file = SWH_PLUGIN_DIR . 'assets/dist/views-settings.asset.php';
	if ( ! file_exists( $asset_file ) ) {
		return;
	}
	// @phpstan-ignore-next-line include.fileNotFound
	$asset = include $asset_file; // phpcs:ignore WordPressVIPMinimum.Files.IncludingFile.UsingVariable

	wp_enqueue_script(
		'swh-views-settings',
		SWH_PLUGIN_URL . 'assets/dist/views-settings.js',
		swh_filter_known_script_deps( isset( $asset['dependencies'] ) && is_array( $asset['dependencies'] ) ? $asset['dependencies'] : array() ),
		$asset['version'] ?? SWH_VERSION,
		true
	);
	wp_enqueue_style(
		'swh-views-settings',
		SWH_PLUGIN_URL . 'assets/dist/views-settings.css',
		array( 'wp-components' ),
		$asset['version'] ?? SWH_VERSION
	);

	wp_localize_script(
		'swh-views-settings',
		'swhViewsSettings',
		array(
			'restUrl'   => esc_url_raw( rest_url( 'swh/v1/' ) ),
			// restRoot is the WP REST API root (e.g. /wp-json/); passed to
			// createRootURLMiddleware so the JS does not need to know the namespace.
			'restRoot'  => esc_url_raw( rest_url() ),
			'restNonce' => wp_create_nonce( 'wp_rest' ),
		)
	);
}
