<?php if ( ! defined( 'ABSPATH' ) ) {
	exit; }

// ==============================================================================
// BACKGROUND CRON TASKS (Micro-Batched to prevent cURL error 28)
// ==============================================================================

add_action( 'swh_autoclose_event', 'swh_process_autoclose' );
function swh_process_autoclose() {
	// Clean up expired rate-limit entries.
	global $wpdb;
	$wpdb->query(
		$wpdb->prepare(
			"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s AND option_value < %d",
			'swh\_rl\_%',
			time()
		)
	);

	$defs = swh_get_defaults();
	$days = (int) get_option( 'swh_autoclose_days', 3 );
	if ( $days <= 0 ) {
		return;
	}
	$lock_key = 'swh_lock_autoclose';
	if ( get_transient( $lock_key ) ) {
		return;
	}
	set_transient( $lock_key, 1, 5 * MINUTE_IN_SECONDS );
	$resolved_status = get_option( 'swh_resolved_status', $defs['swh_resolved_status'] );
	$closed_status   = get_option( 'swh_closed_status', $defs['swh_closed_status'] );
	$threshold       = time() - ( $days * DAY_IN_SECONDS );

    // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
	$tickets = get_posts(
		array(
			'post_type'      => 'helpdesk_ticket',
			'posts_per_page' => 2,
			'meta_query'     => array(
				'relation' => 'AND',
				array(
					'key'   => '_ticket_status',
					'value' => $resolved_status,
				),
				array(
					'key'     => '_resolved_timestamp',
					'value'   => $threshold,
					'compare' => '<=',
					'type'    => 'NUMERIC',
				),
			),
		)
	);
	if ( ! empty( $tickets ) ) {
		update_meta_cache( 'post', wp_list_pluck( $tickets, 'ID' ) );
	}
	foreach ( $tickets as $ticket ) {
		update_post_meta( $ticket->ID, '_ticket_status', $closed_status );
		$comment_id = wp_insert_comment(
			array(
				'comment_post_ID'  => $ticket->ID,
				'comment_author'   => __( 'System Auto-Close', 'simple-wp-helpdesk' ),
				/* translators: %d: number of days of inactivity */
				'comment_content'  => sprintf( __( 'Ticket automatically closed due to inactivity (%d days).', 'simple-wp-helpdesk' ), $days ),
				'comment_approved' => 1,
				'comment_type'     => 'helpdesk_reply',
			)
		);
		if ( $comment_id ) {
			update_comment_meta( $comment_id, '_is_internal_note', '1' );
		}

		$data = array(
			'name'           => get_post_meta( $ticket->ID, '_ticket_name', true ) ? get_post_meta( $ticket->ID, '_ticket_name', true ) : 'Client',
			'email'          => get_post_meta( $ticket->ID, '_ticket_email', true ),
			'ticket_id'      => get_post_meta( $ticket->ID, '_ticket_uid', true ),
			'title'          => $ticket->post_title,
			'status'         => $closed_status,
			'priority'       => get_post_meta( $ticket->ID, '_ticket_priority', true ),
			'ticket_url'     => swh_get_secure_ticket_link( $ticket->ID ),
			'admin_url'      => admin_url( 'post.php?post=' . $ticket->ID . '&action=edit' ),
			'autoclose_days' => $days,
			'message'        => '',
		);
		if ( $data['email'] && $data['ticket_url'] ) {
			swh_send_email( $data['email'], 'swh_em_user_autoclose_sub', 'swh_em_user_autoclose_body', $data );
		}
	}
	delete_transient( $lock_key );
}

add_action( 'swh_retention_attachments_event', 'swh_process_retention_attachments' );
function swh_process_retention_attachments() {
	$days = (int) get_option( 'swh_retention_attachments_days', 0 );
	if ( $days <= 0 ) {
		return;
	}
	$lock_key = 'swh_lock_retention_att';
	if ( get_transient( $lock_key ) ) {
		return;
	}
	set_transient( $lock_key, 1, 5 * MINUTE_IN_SECONDS );
	$threshold_date = gmdate( 'Y-m-d H:i:s', time() - ( $days * DAY_IN_SECONDS ) );

    // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
	$tickets = get_posts(
		array(
			'post_type'      => 'helpdesk_ticket',
			'posts_per_page' => 1,
			'date_query'     => array(
				array(
					'column' => 'post_modified',
					'before' => $threshold_date,
				),
			),
			'meta_query'     => array(
				array(
					'key'     => '_ticket_attachments',
					'compare' => 'EXISTS',
				),
			),
		)
	);
	if ( ! empty( $tickets ) ) {
		update_meta_cache( 'post', wp_list_pluck( $tickets, 'ID' ) );
	}
	foreach ( $tickets as $ticket ) {
		$atts = get_post_meta( $ticket->ID, '_ticket_attachments', true );
		if ( ! empty( $atts ) ) {
			if ( ! is_array( $atts ) ) {
				$atts = array( $atts );
			}
			foreach ( $atts as $url ) {
				swh_delete_file_by_url( $url );
			}
			delete_post_meta( $ticket->ID, '_ticket_attachments' );
			$comment_id = wp_insert_comment(
				array(
					'comment_post_ID'  => $ticket->ID,
					'comment_author'   => __( 'System Maintenance', 'simple-wp-helpdesk' ),
					/* translators: %d: number of days for retention */
					'comment_content'  => sprintf( __( 'Original ticket attachments automatically purged (older than %d days).', 'simple-wp-helpdesk' ), $days ),
					'comment_approved' => 1,
					'comment_type'     => 'helpdesk_reply',
				)
			);
			if ( $comment_id ) {
				update_comment_meta( $comment_id, '_is_internal_note', '1' );
			}
		}
		// Handle legacy single-URL attachment format.
		$legacy_url = get_post_meta( $ticket->ID, '_ticket_attachment_url', true );
		if ( $legacy_url ) {
			swh_delete_file_by_url( $legacy_url );
			delete_post_meta( $ticket->ID, '_ticket_attachment_url' );
		}
		$legacy_id = get_post_meta( $ticket->ID, '_ticket_attachment_id', true );
		if ( $legacy_id ) {
			wp_delete_attachment( $legacy_id, true );
			delete_post_meta( $ticket->ID, '_ticket_attachment_id' );
		}
	}

    // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
	$comments = get_comments(
		array(
			'post_type'  => 'helpdesk_ticket',
			'number'     => 1,
			'date_query' => array(
				array(
					'column' => 'comment_date',
					'before' => $threshold_date,
				),
			),
			'meta_query' => array(
				array(
					'key'     => '_attachments',
					'compare' => 'EXISTS',
				),
			),
		)
	);
	foreach ( $comments as $comment ) {
		$atts = get_comment_meta( $comment->comment_ID, '_attachments', true );
		if ( ! empty( $atts ) ) {
			if ( ! is_array( $atts ) ) {
				$atts = array( $atts );
			}
			foreach ( $atts as $url ) {
				swh_delete_file_by_url( $url );
			}
			delete_comment_meta( $comment->comment_ID, '_attachments' );
			/* translators: %d: number of days for retention */
			$new_content = $comment->comment_content . "\n\n*(" . sprintf( __( 'Attachments automatically purged after %d days', 'simple-wp-helpdesk' ), $days ) . ')*';
			wp_update_comment(
				array(
					'comment_ID'      => $comment->comment_ID,
					'comment_content' => $new_content,
				)
			);
		}
	}
	delete_transient( $lock_key );
}

add_action( 'swh_retention_tickets_event', 'swh_process_retention_tickets' );
function swh_process_retention_tickets() {
	$days = (int) get_option( 'swh_retention_tickets_days', 0 );
	if ( $days <= 0 ) {
		return;
	}
	$lock_key = 'swh_lock_retention_tkt';
	if ( get_transient( $lock_key ) ) {
		return;
	}
	set_transient( $lock_key, 1, 5 * MINUTE_IN_SECONDS );
	$threshold_date = gmdate( 'Y-m-d H:i:s', time() - ( $days * DAY_IN_SECONDS ) );
	$tickets        = get_posts(
		array(
			'post_type'      => 'helpdesk_ticket',
			'posts_per_page' => 1,
			'date_query'     => array(
				array(
					'column' => 'post_modified',
					'before' => $threshold_date,
				),
			),
		)
	);
	foreach ( $tickets as $ticket ) {
		swh_delete_ticket_and_files( $ticket->ID );
	}
	delete_transient( $lock_key );
}
