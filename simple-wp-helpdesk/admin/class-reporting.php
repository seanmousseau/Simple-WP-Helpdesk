<?php
/**
 * Reporting dashboard: AJAX data endpoints for ticket metrics and trend charts.
 *
 * @package Simple_WP_Helpdesk
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'wp_ajax_swh_report_data', 'swh_ajax_report_data' );
/**
 * AJAX handler that returns reporting data as JSON.
 *
 * Accepts a `type` parameter: 'status_breakdown', 'avg_resolution_time',
 * 'weekly_trend', 'first_response_time', or 'kpi'. Results are cached in a 1-hour transient.
 *
 * @since 3.0.0
 * @return void
 */
function swh_ajax_report_data() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( array( 'message' => __( 'Permission denied.', 'simple-wp-helpdesk' ) ), 403 );
	}
	check_ajax_referer( 'swh_report_data', 'nonce' );
	// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized by sanitize_key().
	$type = isset( $_POST['type'] ) && is_string( $_POST['type'] ) ? sanitize_key( $_POST['type'] ) : '';

	$transient_key = 'swh_report_' . $type;
	$cached        = get_transient( $transient_key );
	if ( false !== $cached && is_array( $cached ) ) {
		wp_send_json_success( $cached );
	}

	switch ( $type ) {
		case 'status_breakdown':
			$data = swh_report_status_breakdown();
			break;
		case 'avg_resolution_time':
			$data = swh_report_avg_resolution_time();
			break;
		case 'weekly_trend':
			$data = swh_report_weekly_trend();
			break;
		case 'first_response_time':
			$data = swh_report_first_response_time();
			break;
		case 'kpi':
			$data = swh_report_kpi_data();
			break;
		default:
			wp_send_json_error( array( 'message' => __( 'Unknown report type.', 'simple-wp-helpdesk' ) ) );
	}

	set_transient( $transient_key, $data, HOUR_IN_SECONDS );
	wp_send_json_success( $data );
}

/**
 * Returns ticket counts grouped by status.
 *
 * @since 3.0.0
 * @return array<string, int> Map of status label => count.
 */
function swh_report_status_breakdown() {
	$statuses = swh_get_statuses();
	$result   = array();
	foreach ( $statuses as $status ) {
		// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
		$count             = (int) ( new WP_Query(
			array(
				'post_type'      => 'helpdesk_ticket',
				'post_status'    => 'any',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'meta_query'     => array(
					array(
						'key'   => '_ticket_status',
						'value' => $status,
					),
				),
			)
		) )->found_posts;
		$result[ $status ] = $count;
	}
	return $result;
}

/**
 * Returns the average ticket resolution time in seconds for tickets closed in the last 30 days.
 *
 * @since 3.0.0
 * @return array{avg_seconds: int, count: int}
 */
function swh_report_avg_resolution_time() {
	global $wpdb;
	$defs            = swh_get_defaults();
	$closed_status   = get_option( 'swh_closed_status', $defs['swh_closed_status'] );
	$resolved_status = get_option( 'swh_resolved_status', $defs['swh_resolved_status'] );
	$since           = gmdate( 'Y-m-d H:i:s', time() - ( 30 * DAY_IN_SECONDS ) );

	$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->prepare(
			"SELECT p.post_date_gmt, m.meta_value AS resolved_ts
			FROM {$wpdb->posts} p
			INNER JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = '_resolved_timestamp'
			INNER JOIN {$wpdb->postmeta} ms ON ms.post_id = p.ID AND ms.meta_key = '_ticket_status'
			WHERE p.post_type = %s
			  AND p.post_status = 'publish'
			  AND ms.meta_value IN (%s, %s)
			  AND p.post_modified_gmt >= %s",
			'helpdesk_ticket',
			$closed_status,
			$resolved_status,
			$since
		)
	);

	if ( empty( $rows ) ) {
		return array(
			'avg_seconds' => 0,
			'count'       => 0,
		);
	}
	$total = 0;
	$count = 0;
	foreach ( $rows as $row ) {
		$open_ts     = strtotime( $row->post_date_gmt );
		$resolved_ts = (int) $row->resolved_ts;
		if ( $resolved_ts > $open_ts ) {
			$total += $resolved_ts - $open_ts;
			++$count;
		}
	}
	return array(
		'avg_seconds' => $count > 0 ? (int) round( $total / $count ) : 0,
		'count'       => $count,
	);
}

/**
 * Returns weekly opened/closed ticket counts for the last 8 weeks.
 *
 * @since 3.0.0
 * @return array<int, array{week: string, opened: int, closed: int}>
 */
function swh_report_weekly_trend() {
	$defs          = swh_get_defaults();
	$closed_status = get_option( 'swh_closed_status', $defs['swh_closed_status'] );
	$result        = array();
	for ( $i = 7; $i >= 0; $i-- ) {
		$week_start = gmdate( 'Y-m-d', strtotime( '-' . $i . ' weeks Monday this week' ) );
		$week_end   = gmdate( 'Y-m-d', strtotime( '-' . $i . ' weeks Sunday this week' ) + DAY_IN_SECONDS );
		$opened     = (int) ( new WP_Query(
			array(
				'post_type'      => 'helpdesk_ticket',
				'post_status'    => 'any',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'date_query'     => array(
					array(
						'column' => 'post_date_gmt',
						'after'  => $week_start,
						'before' => $week_end,
					),
				),
			)
		) )->found_posts;
		// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
		$closed   = (int) ( new WP_Query(
			array(
				'post_type'      => 'helpdesk_ticket',
				'post_status'    => 'any',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'date_query'     => array(
					array(
						'column' => 'post_modified_gmt',
						'after'  => $week_start,
						'before' => $week_end,
					),
				),
				'meta_query'     => array(
					array(
						'key'   => '_ticket_status',
						'value' => $closed_status,
					),
				),
			)
		) )->found_posts;
		$result[] = array(
			'week'   => $week_start,
			'opened' => $opened,
			'closed' => $closed,
		);
	}
	return $result;
}

/**
 * Returns the average first-response time in seconds for tickets responded to in the last 30 days.
 *
 * @since 3.0.0
 * @return array{avg_seconds: int, count: int}
 */
function swh_report_first_response_time() {
	global $wpdb;
	$since = time() - ( 30 * DAY_IN_SECONDS );

	$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->prepare(
			"SELECT p.post_date_gmt, m.meta_value AS first_response_ts
			FROM {$wpdb->posts} p
			INNER JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = '_ticket_first_response_at'
			WHERE p.post_type = %s
			  AND p.post_status = 'publish'
			  AND CAST(m.meta_value AS UNSIGNED) >= %d",
			'helpdesk_ticket',
			$since
		)
	);

	if ( empty( $rows ) ) {
		return array(
			'avg_seconds' => 0,
			'count'       => 0,
		);
	}
	$total = 0;
	$count = 0;
	foreach ( $rows as $row ) {
		$open_ts     = strtotime( $row->post_date_gmt );
		$response_ts = (int) $row->first_response_ts;
		if ( $response_ts > $open_ts ) {
			$total += $response_ts - $open_ts;
			++$count;
		}
	}
	return array(
		'avg_seconds' => $count > 0 ? (int) round( $total / $count ) : 0,
		'count'       => $count,
	);
}

/**
 * Returns top-level KPI summary data for the reporting dashboard.
 *
 * @since 3.4.0
 * @return array{total: int, open: int, avg_resolution: int, avg_first_response: int}
 */
function swh_report_kpi_data() {
	$defs            = swh_get_defaults();
	$closed_status   = swh_get_string_option( 'swh_closed_status', is_string( $defs['swh_closed_status'] ) ? $defs['swh_closed_status'] : '' );
	$resolved_status = swh_get_string_option( 'swh_resolved_status', is_string( $defs['swh_resolved_status'] ) ? $defs['swh_resolved_status'] : '' );

	$total = (int) ( new WP_Query(
		array(
			'post_type'      => 'helpdesk_ticket',
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'fields'         => 'ids',
		)
	) )->found_posts;

	// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
	$open = (int) ( new WP_Query(
		array(
			'post_type'      => 'helpdesk_ticket',
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'meta_query'     => array(
				'relation' => 'AND',
				array(
					'key'     => '_ticket_status',
					'value'   => $closed_status,
					'compare' => '!=',
				),
				array(
					'key'     => '_ticket_status',
					'value'   => $resolved_status,
					'compare' => '!=',
				),
			),
		)
	) )->found_posts;

	$cached_resolution = get_transient( 'swh_report_avg_resolution_time' );
	if ( is_array( $cached_resolution ) ) {
		$resolution = $cached_resolution;
	} else {
		$resolution = swh_report_avg_resolution_time();
		set_transient( 'swh_report_avg_resolution_time', $resolution, HOUR_IN_SECONDS );
	}

	$cached_first_response = get_transient( 'swh_report_first_response_time' );
	if ( is_array( $cached_first_response ) ) {
		$first_response = $cached_first_response;
	} else {
		$first_response = swh_report_first_response_time();
		set_transient( 'swh_report_first_response_time', $first_response, HOUR_IN_SECONDS );
	}

	$avg_res_raw  = $resolution['avg_seconds'] ?? null;
	$avg_fres_raw = $first_response['avg_seconds'] ?? null;
	return array(
		'total'              => $total,
		'open'               => $open,
		'avg_resolution'     => is_int( $avg_res_raw ) ? $avg_res_raw : 0,
		'avg_first_response' => is_int( $avg_fres_raw ) ? $avg_fres_raw : 0,
	);
}
