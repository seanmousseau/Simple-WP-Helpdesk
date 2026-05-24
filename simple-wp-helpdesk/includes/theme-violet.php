<?php
/**
 * Violet theme enqueue + body class gate (v4.0.0 #355).
 *
 * Loads simple-wp-helpdesk/assets/dist/theme-violet.css and adds the
 * .swh-theme-violet body class only when swh_settings_general.use_violet_theme
 * is true. No-op otherwise.
 *
 * @package Simple_WP_Helpdesk
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * True if the operator has opted in to the violet theme.
 */
function swh_violet_is_enabled(): bool {
	return (bool) swh_get_option( 'general', 'use_violet_theme', false );
}

/**
 * True when the current admin page belongs to the helpdesk plugin.
 *
 * Used to scope violet-theme hooks so unrelated admin screens and frontend pages
 * are not affected (prevents style bleed and extra payload on non-helpdesk pages).
 *
 * @return bool
 */
function swh_violet_is_helpdesk_screen(): bool {
	if ( is_admin() ) {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( $screen && 'helpdesk_ticket' === $screen->post_type ) {
			return true;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only screen guard.
		$page = isset( $_GET['page'] ) && is_string( $_GET['page'] ) ? sanitize_key( $_GET['page'] ) : '';
		if ( '' !== $page && 0 === strpos( $page, 'swh-' ) ) {
			return true;
		}
		return false;
	}
	// Frontend: only load on pages that use helpdesk shortcodes.
	global $post;
	if ( $post instanceof WP_Post ) {
		$content = $post->post_content;
		if (
			has_shortcode( $content, 'helpdesk_portal' ) ||
			has_shortcode( $content, 'submit_ticket' )
		) {
			return true;
		}
	}
	return false;
}

/**
 * Enqueue the violet stylesheet on helpdesk admin pages and portal shortcode
 * pages only. Scoped to avoid style bleed on unrelated screens.
 *
 * Hooked to both `admin_enqueue_scripts` and `wp_enqueue_scripts`.
 */
function swh_violet_enqueue(): void {
	if ( ! swh_violet_is_enabled() ) {
		return;
	}
	if ( ! swh_violet_is_helpdesk_screen() ) {
		return;
	}

	$asset_file = SWH_PLUGIN_DIR . 'assets/dist/theme-violet.asset.php';
	$deps       = array( 'swh-shared' );
	$version    = SWH_VERSION;
	if ( file_exists( $asset_file ) ) {
		// @phpstan-ignore-next-line include.fileNotFound
		$asset = include $asset_file; // phpcs:ignore WordPressVIPMinimum.Files.IncludingFile.UsingVariable
		if ( is_array( $asset ) ) {
			if ( isset( $asset['dependencies'] ) && is_array( $asset['dependencies'] ) ) {
				$deps = array_merge( $deps, array_values( array_filter( $asset['dependencies'], 'is_string' ) ) );
			}
			if ( isset( $asset['version'] ) && is_string( $asset['version'] ) ) {
				$version = $asset['version'];
			}
		}
	}

	wp_enqueue_style(
		'swh-theme-violet',
		SWH_PLUGIN_URL . 'assets/dist/theme-violet.css',
		$deps,
		$version
	);
}
add_action( 'admin_enqueue_scripts', 'swh_violet_enqueue', 20 );
add_action( 'wp_enqueue_scripts', 'swh_violet_enqueue', 20 );

/**
 * Append `swh-theme-violet` to admin/body classes when enabled.
 *
 * @param string|string[] $classes Existing body classes.
 * @return string|string[]
 */
function swh_violet_filter_body_class( $classes ) {
	if ( ! swh_violet_is_enabled() ) {
		return $classes;
	}
	if ( ! swh_violet_is_helpdesk_screen() ) {
		return $classes;
	}

	// Admin filter passes a string; frontend passes an array.
	if ( is_array( $classes ) ) {
		$classes[] = 'swh-theme-violet';
		return $classes;
	}
	return trim( $classes . ' swh-theme-violet' );
}
add_filter( 'admin_body_class', 'swh_violet_filter_body_class' );
add_filter( 'body_class', 'swh_violet_filter_body_class' );

/**
 * Preload geist-400.woff2 to avoid FOUT on the first admin/frontend paint.
 */
function swh_violet_preload_font(): void {
	if ( ! swh_violet_is_enabled() ) {
		return;
	}
	if ( ! swh_violet_is_helpdesk_screen() ) {
		return;
	}
	printf(
		'<link rel="preload" href="%s" as="font" type="font/woff2" crossorigin>',
		esc_url( SWH_PLUGIN_URL . 'assets/fonts/geist-400.woff2' )
	);
}
add_action( 'admin_head', 'swh_violet_preload_font' );
add_action( 'wp_head', 'swh_violet_preload_font' );
