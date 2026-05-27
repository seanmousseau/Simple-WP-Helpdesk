<?php
declare(strict_types=1);

namespace SWH\Admin;

use WP_Post;

use function add_action;
use function add_meta_box;
use function esc_attr__;
use function esc_html;
use function esc_html__;
use function get_date_from_gmt;
use function get_post_meta;
use function wp_date;

/**
 * "API Access" meta box on the `helpdesk_ticket` editor.
 *
 * Renders the entries written by {@see ApiAccessLog::record()} as a read-only
 * table on the ticket editor. Hidden entirely when the toggle is off —
 * operators who don't opt in never see the box.
 *
 * Phase 9 of the v4.1.0 plan (#369).
 *
 * @since 4.1.0
 */
final class ApiAccessLogMetaBox {

	/** Stable meta box id (used by WP for screen options + CSS hooks). */
	public const ID = 'swh_api_access_log';

	/**
	 * Wire `register_meta_box()` into the `add_meta_boxes` action.
	 */
	public static function register(): void {
		add_action( 'add_meta_boxes', array( self::class, 'register_meta_box' ) );
	}

	/**
	 * `add_meta_boxes` callback — registers the box only when the toggle is on.
	 *
	 * Side context + low priority keeps it out of the way of the primary
	 * Ticket Details meta box.
	 */
	public static function register_meta_box(): void {
		if ( ! ApiAccessLog::is_enabled() ) {
			return;
		}
		add_meta_box(
			self::ID,
			esc_html__( 'API Access', 'simple-wp-helpdesk' ),
			array( self::class, 'render' ),
			'helpdesk_ticket',
			'side',
			'low'
		);
	}

	/**
	 * Render the meta box body.
	 *
	 * @param WP_Post $post Current ticket post.
	 */
	public static function render( $post ): void {
		if ( ! $post instanceof WP_Post ) {
			return;
		}
		$raw     = get_post_meta( (int) $post->ID, ApiAccessLog::META_KEY, true );
		$entries = is_array( $raw ) ? array_values( $raw ) : array();

		if ( empty( $entries ) ) {
			echo '<p class="description">' . esc_html__( 'No API requests recorded for this ticket.', 'simple-wp-helpdesk' ) . '</p>';
			return;
		}

		// Newest first.
		$entries = array_reverse( $entries );

		echo '<table class="widefat striped swh-api-access-log" aria-label="' . esc_attr__( 'API access log for this ticket', 'simple-wp-helpdesk' ) . '">';
		echo '<thead><tr>';
		echo '<th scope="col">' . esc_html__( 'Date', 'simple-wp-helpdesk' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Key', 'simple-wp-helpdesk' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Action', 'simple-wp-helpdesk' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Route', 'simple-wp-helpdesk' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $entries as $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}
			$ts        = isset( $entry['ts'] ) && is_numeric( $entry['ts'] ) ? (int) $entry['ts'] : 0;
			$key_label = isset( $entry['key_label'] ) && is_string( $entry['key_label'] ) ? $entry['key_label'] : '';
			$key_id    = isset( $entry['key_id'] ) && is_string( $entry['key_id'] ) ? $entry['key_id'] : '';
			$action    = isset( $entry['action'] ) && is_string( $entry['action'] ) ? $entry['action'] : '';
			$route     = isset( $entry['route'] ) && is_string( $entry['route'] ) ? $entry['route'] : '';

			$display_label = '' !== $key_label ? $key_label : $key_id;
			$display_date  = self::format_date( $ts );

			echo '<tr>';
			echo '<td>' . esc_html( $display_date ) . '</td>';
			echo '<td>' . esc_html( $display_label ) . '</td>';
			echo '<td>' . esc_html( $action ) . '</td>';
			echo '<td><code>' . esc_html( $route ) . '</code></td>';
			echo '</tr>';
		}

		echo '</tbody></table>';
	}

	/**
	 * Format a unix timestamp in the site's timezone. Falls back to a
	 * predictable ISO-8601 string when WP's date helpers are unavailable
	 * (e.g. in unit tests).
	 */
	private static function format_date( int $ts ): string {
		if ( $ts <= 0 ) {
			return '';
		}
		if ( function_exists( 'wp_date' ) ) {
			$formatted = wp_date( 'Y-m-d H:i', $ts );
			if ( is_string( $formatted ) && '' !== $formatted ) {
				return $formatted;
			}
		}
		if ( function_exists( 'get_date_from_gmt' ) ) {
			$formatted = get_date_from_gmt( gmdate( 'Y-m-d H:i:s', $ts ), 'Y-m-d H:i' );
			if ( is_string( $formatted ) && '' !== $formatted ) {
				return $formatted;
			}
		}
		return gmdate( 'Y-m-d H:i', $ts );
	}
}
