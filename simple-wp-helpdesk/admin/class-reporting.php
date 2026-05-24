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
 * 'weekly_trend', 'first_response_time', or 'kpi'. Optional range params
 * `range_start`, `range_end`, and `compare_to_prior` extend the response
 * with `prior`, `range`, and `prior_range` keys (#359).
 *
 * When no range params are provided, results are cached in a 1-hour transient.
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
	$type = isset( $_REQUEST['type'] ) && is_string( $_REQUEST['type'] ) ? sanitize_key( wp_unslash( $_REQUEST['type'] ) ) : '';

	// Range params (v4.0.0 #359) — optional; validate as ISO Y-m-d.
	$start_raw = isset( $_REQUEST['range_start'] ) && is_string( $_REQUEST['range_start'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['range_start'] ) ) : null;
	$end_raw   = isset( $_REQUEST['range_end'] ) && is_string( $_REQUEST['range_end'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['range_end'] ) ) : null;
	$start     = $start_raw;
	$end       = $end_raw;
	if ( null !== $start && ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $start ) ) {
		$start = null;
	}
	if ( null !== $end && ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $end ) ) {
		$end = null;
	}
	$compare_raw = isset( $_REQUEST['compare_to_prior'] ) && is_string( $_REQUEST['compare_to_prior'] ) ? sanitize_key( wp_unslash( $_REQUEST['compare_to_prior'] ) ) : null;
	$compare     = null === $compare_raw || '1' === $compare_raw;

	// Range mode (v4.0.0): 'shift' (default) or 'ytd'. Passed from the JS preset.
	// Without this, YTD prior comparisons always used the 'shift' window, returning
	// wrong prior-period data (e.g. 30 days before Jan 1 instead of Jan 1 last year).
	$range_mode_raw = isset( $_REQUEST['range_mode'] ) && is_string( $_REQUEST['range_mode'] ) ? sanitize_key( wp_unslash( $_REQUEST['range_mode'] ) ) : 'shift';
	$range_mode     = in_array( $range_mode_raw, array( 'shift', 'ytd' ), true ) ? $range_mode_raw : 'shift';

	// Only use transient cache when no custom range is requested and compare mode is
	// the default (1). Include compare flag and range_mode in the key to avoid
	// cross-request cache poisoning: a compare_to_prior=0 response (prior=null) must
	// not be served to a default compare=1 request; a 'ytd' prior must not be served
	// for a 'shift' request.
	$use_cache = ( null === $start && null === $end );
	if ( $use_cache ) {
		$compare_suffix = $compare ? '1' : '0';
		$transient_key  = 'swh_report_' . $type . '_c' . $compare_suffix . '_m' . $range_mode;
		$cached         = get_transient( $transient_key );
		if ( false !== $cached && is_array( $cached ) ) {
			wp_send_json_success( $cached );
		}
	}

	switch ( $type ) {
		case 'status_breakdown':
			$data = swh_report_status_breakdown( $start, $end, $compare, $range_mode );
			break;
		case 'avg_resolution_time':
			$data = swh_report_avg_resolution_time( $start, $end, $compare, $range_mode );
			break;
		case 'weekly_trend':
			$data = swh_report_weekly_trend( $start, $end, $compare, $range_mode );
			break;
		case 'first_response_time':
			$data = swh_report_first_response_time( $start, $end, $compare, $range_mode );
			break;
		case 'kpi':
			$data = swh_report_kpi_data( $start, $end, $compare, $range_mode );
			break;
		default:
			wp_send_json_error( array( 'message' => __( 'Unknown report type.', 'simple-wp-helpdesk' ) ) );
			// wp_send_json_error calls wp_die() which terminates execution.
			// phpcs:ignore Squiz.PHP.NonExecutableCode.ReturnNotRequired -- defensive; WP_Mock may not terminate in tests.
			return; // @phpstan-ignore-line
	}

	if ( $use_cache ) {
		set_transient( $transient_key, $data, HOUR_IN_SECONDS ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions -- transient_key is safe.
	}
	wp_send_json_success( $data );
}

/**
 * Returns ticket counts grouped by status, optionally constrained to a date range.
 *
 * Return shape (v4.0.0+): array with keys `data`, `range`, `prior`, `prior_range`.
 * When $compare is false, `prior` and `prior_range` are null.
 *
 * @since 3.0.0
 * @since 4.0.0 Accepts optional range args; return shape extended (#359).
 *
 * @param string|null $range_start ISO date (Y-m-d); null → defaults to 30d window.
 * @param string|null $range_end   ISO date; null → defaults to today.
 * @param bool        $compare     Whether to compute and return the prior-range data.
 * @param string      $range_mode  Prior comparison mode: 'shift' (default) or 'ytd'.
 * @return array{data: array<string,int>, range: array{start:string,end:string}, prior: array<string,int>|null, prior_range: array{start:string,end:string}|null}
 */
function swh_report_status_breakdown( ?string $range_start = null, ?string $range_end = null, bool $compare = true, string $range_mode = 'shift' ): array {
	if ( null === $range_start || null === $range_end ) {
		$range_end   = gmdate( 'Y-m-d' );
		$range_start = gmdate( 'Y-m-d', strtotime( '-30 days' ) );
	}

	$data = swh_report_status_breakdown_query( $range_start, $range_end );

	if ( ! $compare ) {
		return array(
			'data'        => $data,
			'range'       => array(
				'start' => $range_start,
				'end'   => $range_end,
			),
			'prior'       => null,
			'prior_range' => null,
		);
	}

	$prior_range = swh_report_prior_range( $range_start, $range_end, $range_mode );
	$prior       = swh_report_status_breakdown_query( $prior_range['start'], $prior_range['end'] );

	return array(
		'data'        => $data,
		'range'       => array(
			'start' => $range_start,
			'end'   => $range_end,
		),
		'prior'       => $prior,
		'prior_range' => $prior_range,
	);
}

/**
 * Inner query for swh_report_status_breakdown — counts tickets by status within a date window.
 *
 * @since 4.0.0 (#359)
 *
 * @param string $start ISO date (Y-m-d), inclusive.
 * @param string $end   ISO date (Y-m-d), inclusive.
 * @return array<string, int>
 */
function swh_report_status_breakdown_query( string $start, string $end ): array {
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
				'date_query'     => array(
					array(
						'after'     => $start,
						'before'    => $end,
						'inclusive' => true,
					),
				),
			)
		) )->found_posts;
		$result[ $status ] = $count;
	}
	return $result;
}

/**
 * Returns the average ticket resolution time in seconds, optionally constrained to a date range.
 *
 * @since 3.0.0
 * @since 4.0.0 Accepts optional range args; return shape extended (#359).
 *
 * @param string|null $range_start ISO date; null → 30d window.
 * @param string|null $range_end   ISO date; null → today.
 * @param bool        $compare     Whether to compute and return prior-range data.
 * @param string      $range_mode  Prior comparison mode: 'shift' (default) or 'ytd'.
 * @return array{data: array{avg_seconds:int,count:int}, range: array{start:string,end:string}, prior: array{avg_seconds:int,count:int}|null, prior_range: array{start:string,end:string}|null}
 */
function swh_report_avg_resolution_time( ?string $range_start = null, ?string $range_end = null, bool $compare = true, string $range_mode = 'shift' ): array {
	if ( null === $range_start || null === $range_end ) {
		$range_end   = gmdate( 'Y-m-d' );
		$range_start = gmdate( 'Y-m-d', strtotime( '-30 days' ) );
	}

	$data = swh_report_avg_resolution_time_query( $range_start, $range_end );

	if ( ! $compare ) {
		return array(
			'data'        => $data,
			'range'       => array(
				'start' => $range_start,
				'end'   => $range_end,
			),
			'prior'       => null,
			'prior_range' => null,
		);
	}

	$prior_range = swh_report_prior_range( $range_start, $range_end, $range_mode );
	$prior       = swh_report_avg_resolution_time_query( $prior_range['start'], $prior_range['end'] );

	return array(
		'data'        => $data,
		'range'       => array(
			'start' => $range_start,
			'end'   => $range_end,
		),
		'prior'       => $prior,
		'prior_range' => $prior_range,
	);
}

/**
 * Inner query for swh_report_avg_resolution_time — computes average within a date window.
 *
 * Constrains the window by post_modified_gmt (when ticket was last updated, i.e. resolved).
 *
 * @since 4.0.0 (#359)
 *
 * @param string $start ISO date (Y-m-d), inclusive.
 * @param string $end   ISO date (Y-m-d), inclusive.
 * @return array{avg_seconds: int, count: int}
 */
function swh_report_avg_resolution_time_query( string $start, string $end ): array {
	global $wpdb;
	$defs            = swh_get_defaults();
	$closed_status   = swh_get_option( 'general', 'closed_status', $defs['swh_closed_status'] );
	$resolved_status = swh_get_option( 'general', 'resolved_status', $defs['swh_resolved_status'] );
	// Convert Y-m-d boundaries to full datetime strings for the SQL comparison.
	$since_dt = $start . ' 00:00:00';
	$until_dt = $end . ' 23:59:59';

	$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->prepare(
			"SELECT p.post_date_gmt, m.meta_value AS resolved_ts
			FROM {$wpdb->posts} p
			INNER JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = '_resolved_timestamp'
			INNER JOIN {$wpdb->postmeta} ms ON ms.post_id = p.ID AND ms.meta_key = '_ticket_status'
			WHERE p.post_type = %s
			  AND p.post_status = 'publish'
			  AND ms.meta_value IN (%s, %s)
			  AND p.post_modified_gmt >= %s
			  AND p.post_modified_gmt <= %s",
			'helpdesk_ticket',
			$closed_status,
			$resolved_status,
			$since_dt,
			$until_dt
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
 * Returns weekly opened/closed ticket counts, optionally constrained to a date range.
 *
 * @since 3.0.0
 * @since 4.0.0 Accepts optional range args; return shape extended (#359).
 *
 * @param string|null $range_start ISO date; null → last 8 weeks.
 * @param string|null $range_end   ISO date; null → today.
 * @param bool        $compare     Whether to compute and return prior-range data.
 * @param string      $range_mode  Prior comparison mode: 'shift' (default) or 'ytd'.
 * @return array{data: array<int,array{week:string,opened:int,closed:int}>, range: array{start:string,end:string}, prior: array<int,array{week:string,opened:int,closed:int}>|null, prior_range: array{start:string,end:string}|null}
 */
function swh_report_weekly_trend( ?string $range_start = null, ?string $range_end = null, bool $compare = true, string $range_mode = 'shift' ): array {
	if ( null === $range_start || null === $range_end ) {
		$range_end   = gmdate( 'Y-m-d' );
		$range_start = gmdate( 'Y-m-d', strtotime( '-8 weeks' ) );
	}

	$data = swh_report_weekly_trend_query( $range_start, $range_end );

	if ( ! $compare ) {
		return array(
			'data'        => $data,
			'range'       => array(
				'start' => $range_start,
				'end'   => $range_end,
			),
			'prior'       => null,
			'prior_range' => null,
		);
	}

	$prior_range = swh_report_prior_range( $range_start, $range_end, $range_mode );
	$prior       = swh_report_weekly_trend_query( $prior_range['start'], $prior_range['end'] );

	return array(
		'data'        => $data,
		'range'       => array(
			'start' => $range_start,
			'end'   => $range_end,
		),
		'prior'       => $prior,
		'prior_range' => $prior_range,
	);
}

/**
 * Inner query for swh_report_weekly_trend — weekly buckets within a date window.
 *
 * @since 4.0.0 (#359)
 *
 * @param string $start ISO date (Y-m-d), inclusive.
 * @param string $end   ISO date (Y-m-d), inclusive.
 * @return array<int, array{week: string, opened: int, closed: int}>
 */
function swh_report_weekly_trend_query( string $start, string $end ): array {
	$defs          = swh_get_defaults();
	$closed_status = swh_get_option( 'general', 'closed_status', $defs['swh_closed_status'] );
	$result        = array();
	// Anchor week-bucket generation to the REQUESTED range end (not "this week") so
	// custom and prior ranges return correct/non-empty data (#359 CR fix).
	$anchor_ts = strtotime( $end );
	$anchor_ts = false !== $anchor_ts ? $anchor_ts : time();
	// Find the Monday of the week containing $end to anchor backward from.
	$anchor_monday_ts = strtotime( 'Monday this week', $anchor_ts );
	if ( false === $anchor_monday_ts ) {
		$anchor_monday_ts = $anchor_ts - ( (int) gmdate( 'N', $anchor_ts ) - 1 ) * DAY_IN_SECONDS;
	}
	for ( $i = 7; $i >= 0; $i-- ) {
		$week_start_ts = $anchor_monday_ts - $i * WEEK_IN_SECONDS;
		$week_end_ts   = $week_start_ts + 6 * DAY_IN_SECONDS;
		$week_start    = gmdate( 'Y-m-d', $week_start_ts );
		$week_end      = gmdate( 'Y-m-d', $week_end_ts );
		// Skip weeks entirely outside the requested range.
		if ( $week_end < $start || $week_start > $end ) {
			continue;
		}
		$opened = (int) ( new WP_Query(
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
 * Returns the average first-response time in seconds, optionally constrained to a date range.
 *
 * @since 3.0.0
 * @since 4.0.0 Accepts optional range args; return shape extended (#359).
 *
 * @param string|null $range_start ISO date; null → 30d window.
 * @param string|null $range_end   ISO date; null → today.
 * @param bool        $compare     Whether to compute and return prior-range data.
 * @param string      $range_mode  Prior comparison mode: 'shift' (default) or 'ytd'.
 * @return array{data: array{avg_seconds:int,count:int}, range: array{start:string,end:string}, prior: array{avg_seconds:int,count:int}|null, prior_range: array{start:string,end:string}|null}
 */
function swh_report_first_response_time( ?string $range_start = null, ?string $range_end = null, bool $compare = true, string $range_mode = 'shift' ): array {
	if ( null === $range_start || null === $range_end ) {
		$range_end   = gmdate( 'Y-m-d' );
		$range_start = gmdate( 'Y-m-d', strtotime( '-30 days' ) );
	}

	$data = swh_report_first_response_time_query( $range_start, $range_end );

	if ( ! $compare ) {
		return array(
			'data'        => $data,
			'range'       => array(
				'start' => $range_start,
				'end'   => $range_end,
			),
			'prior'       => null,
			'prior_range' => null,
		);
	}

	$prior_range = swh_report_prior_range( $range_start, $range_end, $range_mode );
	$prior       = swh_report_first_response_time_query( $prior_range['start'], $prior_range['end'] );

	return array(
		'data'        => $data,
		'range'       => array(
			'start' => $range_start,
			'end'   => $range_end,
		),
		'prior'       => $prior,
		'prior_range' => $prior_range,
	);
}

/**
 * Inner query for swh_report_first_response_time — average response time within a date window.
 *
 * Constrains the window by `_ticket_first_response_at` unix timestamp falling within [start, end].
 *
 * @since 4.0.0 (#359)
 *
 * @param string $start ISO date (Y-m-d), inclusive.
 * @param string $end   ISO date (Y-m-d), inclusive.
 * @return array{avg_seconds: int, count: int}
 */
function swh_report_first_response_time_query( string $start, string $end ): array {
	global $wpdb;
	$since_ts = strtotime( $start . ' 00:00:00' );
	$until_ts = strtotime( $end . ' 23:59:59' );

	$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->prepare(
			"SELECT p.post_date_gmt, m.meta_value AS first_response_ts
			FROM {$wpdb->posts} p
			INNER JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = '_ticket_first_response_at'
			WHERE p.post_type = %s
			  AND p.post_status = 'publish'
			  AND CAST(m.meta_value AS UNSIGNED) >= %d
			  AND CAST(m.meta_value AS UNSIGNED) <= %d",
			'helpdesk_ticket',
			$since_ts,
			$until_ts
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
 * @since 4.0.0 Accepts optional range args; return shape extended (#359).
 *
 * @param string|null $range_start ISO date; null → 30d window.
 * @param string|null $range_end   ISO date; null → today.
 * @param bool        $compare     Whether to compute and return prior-range data.
 * @param string      $range_mode  Prior comparison mode: 'shift' (default) or 'ytd'.
 * @return array{data: array{total:int,open:int,avg_resolution:int,avg_first_response:int}, range: array{start:string,end:string}, prior: array{total:int,open:int,avg_resolution:int,avg_first_response:int}|null, prior_range: array{start:string,end:string}|null}
 */
function swh_report_kpi_data( ?string $range_start = null, ?string $range_end = null, bool $compare = true, string $range_mode = 'shift' ): array {
	if ( null === $range_start || null === $range_end ) {
		$range_end   = gmdate( 'Y-m-d' );
		$range_start = gmdate( 'Y-m-d', strtotime( '-30 days' ) );
	}

	$data = swh_report_kpi_data_query( $range_start, $range_end );

	if ( ! $compare ) {
		return array(
			'data'        => $data,
			'range'       => array(
				'start' => $range_start,
				'end'   => $range_end,
			),
			'prior'       => null,
			'prior_range' => null,
		);
	}

	$prior_range = swh_report_prior_range( $range_start, $range_end, $range_mode );
	$prior       = swh_report_kpi_data_query( $prior_range['start'], $prior_range['end'] );

	return array(
		'data'        => $data,
		'range'       => array(
			'start' => $range_start,
			'end'   => $range_end,
		),
		'prior'       => $prior,
		'prior_range' => $prior_range,
	);
}

/**
 * Inner query for swh_report_kpi_data — computes KPIs within a date window.
 *
 * @since 4.0.0 (#359)
 *
 * @param string $start ISO date (Y-m-d), inclusive.
 * @param string $end   ISO date (Y-m-d), inclusive.
 * @return array{total: int, open: int, avg_resolution: int, avg_first_response: int}
 */
function swh_report_kpi_data_query( string $start, string $end ): array {
	$defs            = swh_get_defaults();
	$closed_status   = swh_get_string_option( 'swh_closed_status', is_string( $defs['swh_closed_status'] ) ? $defs['swh_closed_status'] : '' );
	$resolved_status = swh_get_string_option( 'swh_resolved_status', is_string( $defs['swh_resolved_status'] ) ? $defs['swh_resolved_status'] : '' );

	$total = (int) ( new WP_Query(
		array(
			'post_type'      => 'helpdesk_ticket',
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'date_query'     => array(
				array(
					'after'     => $start,
					'before'    => $end,
					'inclusive' => true,
				),
			),
		)
	) )->found_posts;

	// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
	$open = (int) ( new WP_Query(
		array(
			'post_type'      => 'helpdesk_ticket',
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'date_query'     => array(
				array(
					'after'     => $start,
					'before'    => $end,
					'inclusive' => true,
				),
			),
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

	$resolution     = swh_report_avg_resolution_time_query( $start, $end );
	$first_response = swh_report_first_response_time_query( $start, $end );

	$avg_res_raw  = $resolution['avg_seconds'] ?? null;
	$avg_fres_raw = $first_response['avg_seconds'] ?? null;
	return array(
		'total'              => $total,
		'open'               => $open,
		'avg_resolution'     => is_int( $avg_res_raw ) ? $avg_res_raw : 0,
		'avg_first_response' => is_int( $avg_fres_raw ) ? $avg_fres_raw : 0,
	);
}

/**
 * Compute the prior comparison range for a selected range.
 *
 * Default (mode = 'shift'): prior is the same length immediately preceding the
 * selected range. e.g. selected = 30d ending today; prior = the 30d before that.
 *
 * For mode = 'ytd', prior is the same calendar period in the previous year.
 *
 * @since 4.0.0 (#359)
 *
 * @param string $start ISO date (Y-m-d) — start of selected range, inclusive.
 * @param string $end   ISO date — end of selected range, inclusive.
 * @param string $mode  Either 'shift' (default) or 'ytd'.
 * @return array{start: string, end: string}
 */
function swh_report_prior_range( string $start, string $end, string $mode = 'shift' ): array {
	if ( 'ytd' === $mode ) {
		$start_ytd = strtotime( $start . ' -1 year' );
		$end_ytd   = strtotime( $end . ' -1 year' );
		return array(
			'start' => (string) gmdate( 'Y-m-d', false !== $start_ytd ? $start_ytd : 0 ),
			'end'   => (string) gmdate( 'Y-m-d', false !== $end_ytd ? $end_ytd : 0 ),
		);
	}
	$start_ts = strtotime( $start );
	$end_ts   = strtotime( $end );
	$start_ts = false !== $start_ts ? $start_ts : 0;
	$end_ts   = false !== $end_ts ? $end_ts : 0;
	$length   = $end_ts - $start_ts;
	return array(
		'start' => (string) gmdate( 'Y-m-d', $start_ts - $length ),
		'end'   => (string) gmdate( 'Y-m-d', $start_ts ),
	);
}

/**
 * Compute a signed percent delta between current and prior values.
 *
 * Returns null when prior is 0 (avoid divide-by-zero; UI shows "— no prior data").
 *
 * @since 4.0.0 (#359)
 *
 * @param int|float $current
 * @param int|float $prior
 * @return float|null Signed percent (e.g. 25.0 = +25%, -20.0 = -20%) or null.
 */
function swh_report_delta_percent( $current, $prior ): ?float {
	$prior_f   = (float) $prior;
	$current_f = (float) $current;
	if ( 0.0 === $prior_f ) {
		return null;
	}
	return round( ( ( $current_f - $prior_f ) / $prior_f ) * 100.0, 1 );
}
