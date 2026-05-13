<?php
/**
 * Helpers for emitting WP-style deprecation notices with a consistent
 * SWH version-tag format.
 *
 * Thin wrappers around WordPress core's apply_filters_deprecated() and
 * do_action_deprecated() (available since WP 4.6) so v4.0+ deprecations
 * are mechanical and share a single version-tag convention (`SWH x.y`).
 *
 * No existing hooks are deprecated in v3.7.0 — this file only lands the
 * tools. See docs/developer/deprecations.md for the deprecation policy.
 *
 * @package Simple_WP_Helpdesk
 * @since 3.7.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Apply a deprecated filter with a consistent message format.
 *
 * Thin wrapper around WP core's apply_filters_deprecated() that standardises
 * the version tag as "SWH x.y" and the default replacement message.
 *
 * @since 3.7.0
 *
 * @param non-empty-string $hook        Deprecated filter name.
 * @param array<mixed>     $args        Arguments to pass to the filter.
 * @param string           $version     SWH version in which the filter was deprecated, e.g. '3.7'.
 * @param string           $replacement Optional. Name of the replacement filter.
 * @param string           $message     Optional. Custom deprecation message.
 * @return mixed Filtered value (the first element of $args after running the filter chain).
 */
function swh_apply_deprecated_filter( $hook, $args, $version, $replacement = '', $message = '' ) {
	$msg = '' !== $message ? $message : sprintf( 'Use %s instead.', '' !== $replacement ? $replacement : 'the documented replacement' );
	return apply_filters_deprecated( $hook, $args, "SWH $version", $replacement, $msg );
}

/**
 * Fire a deprecated action with a consistent message format.
 *
 * Thin wrapper around WP core's do_action_deprecated() that standardises
 * the version tag as "SWH x.y" and the default replacement message.
 *
 * @since 3.7.0
 *
 * @param non-empty-string $hook        Deprecated action name.
 * @param array<mixed>     $args        Arguments passed to the action.
 * @param string           $version     SWH version in which the action was deprecated, e.g. '3.7'.
 * @param string           $replacement Optional. Name of the replacement action.
 * @param string           $message     Optional. Custom deprecation message.
 * @return void
 */
function swh_do_deprecated_action( $hook, $args, $version, $replacement = '', $message = '' ) {
	$msg = '' !== $message ? $message : sprintf( 'Use %s instead.', '' !== $replacement ? $replacement : 'the documented replacement' );
	do_action_deprecated( $hook, $args, "SWH $version", $replacement, $msg );
}
