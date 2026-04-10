<?php
/**
 * Ticket list table: columns, sorting, filter dropdowns, meta cache, and edit-lock suppression.
 *
 * @package Simple_WP_Helpdesk
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Hooks into admin_enqueue_scripts to enqueue the admin stylesheet on the ticket list screen.
 *
 * @since 2.1.0
 * @see swh_admin_list_styles()
 */
add_action( 'admin_enqueue_scripts', 'swh_admin_list_styles' );
/**
 * Enqueues the admin stylesheet on the helpdesk ticket list screen.
 *
 * @since 2.1.0
 * @return void
 */
function swh_admin_list_styles() {
	$screen = get_current_screen();
	if ( ! $screen || 'edit-helpdesk_ticket' !== $screen->id ) {
		return;
	}
	wp_enqueue_style( 'swh-admin', SWH_PLUGIN_URL . 'assets/swh-admin.css', array(), SWH_VERSION );
}

/**
 * Hooks into manage_helpdesk_ticket_posts_columns to define the ticket list table columns.
 *
 * @since 2.0.0
 * @see swh_ticket_columns()
 */
add_filter( 'manage_helpdesk_ticket_posts_columns', 'swh_ticket_columns' );
/**
 * Defines the columns shown in the helpdesk ticket list table.
 *
 * @since 2.0.0
 * @param string[] $columns Default column definitions.
 * @return string[] Modified column definitions.
 */
function swh_ticket_columns( $columns ) {
	$new                    = array();
	$new['cb']              = $columns['cb'];
	$new['ticket_uid']      = __( 'Ticket #', 'simple-wp-helpdesk' );
	$new['title']           = $columns['title'];
	$new['ticket_status']   = __( 'Status', 'simple-wp-helpdesk' );
	$new['ticket_priority'] = __( 'Priority', 'simple-wp-helpdesk' );
	$new['ticket_assigned'] = __( 'Assigned To', 'simple-wp-helpdesk' );
	$new['ticket_client']   = __( 'Client', 'simple-wp-helpdesk' );
	$new['date']            = $columns['date'];
	return $new;
}

/**
 * Hooks into manage_helpdesk_ticket_posts_custom_column to render custom column content.
 *
 * @since 2.0.0
 * @see swh_ticket_column_content()
 */
add_action( 'manage_helpdesk_ticket_posts_custom_column', 'swh_ticket_column_content', 10, 2 );
/**
 * Outputs the content for each custom column in the ticket list table.
 *
 * Handles: ticket_uid, ticket_status (colored badge), ticket_priority,
 * ticket_assigned, and ticket_client columns.
 *
 * @since 2.0.0
 * @param string $column  The column slug being rendered.
 * @param int    $post_id The current ticket post ID.
 * @return void
 */
function swh_ticket_column_content( $column, $post_id ) {
	$defs = swh_get_defaults();
	switch ( $column ) {
		case 'ticket_uid':
			echo esc_html( get_post_meta( $post_id, '_ticket_uid', true ) ? get_post_meta( $post_id, '_ticket_uid', true ) : '—' );
			break;
		case 'ticket_status':
			$status          = get_post_meta( $post_id, '_ticket_status', true );
			$closed_status   = get_option( 'swh_closed_status', $defs['swh_closed_status'] );
			$resolved_status = get_option( 'swh_resolved_status', $defs['swh_resolved_status'] );
			if ( $status === $closed_status ) {
				$bg    = '#f8d7da';
				$color = '#721c24';
			} elseif ( $status === $resolved_status ) {
				$bg    = '#e6f7ff';
				$color = '#005980';
			} elseif ( stripos( $status, 'progress' ) !== false ) {
				$bg    = '#fff3cd';
				$color = '#856404';
			} else {
				$bg    = '#d4edda';
				$color = '#155724';
			}
			echo '<span class="swh-status-badge" style="background:' . esc_attr( $bg ) . ';color:' . esc_attr( $color ) . ';">' . esc_html( $status ) . '</span>';
			break;
		case 'ticket_priority':
			echo esc_html( get_post_meta( $post_id, '_ticket_priority', true ) ? get_post_meta( $post_id, '_ticket_priority', true ) : '—' );
			break;
		case 'ticket_assigned':
			$assigned = get_post_meta( $post_id, '_ticket_assigned_to', true );
			if ( $assigned ) {
				$user = get_userdata( $assigned );
				echo $user ? esc_html( $user->display_name ) : esc_html__( 'Unknown', 'simple-wp-helpdesk' );
			} else {
				echo '<span style="color:#999;">' . esc_html__( 'Unassigned', 'simple-wp-helpdesk' ) . '</span>';
			}
			break;
		case 'ticket_client':
			$name  = get_post_meta( $post_id, '_ticket_name', true );
			$email = get_post_meta( $post_id, '_ticket_email', true );
			if ( $name ) {
				echo esc_html( $name );
			}
			if ( $email ) {
				echo '<br><small style="color:#666;">' . esc_html( $email ) . '</small>';
			}
			if ( ! $name && ! $email ) {
				echo '—';
			}
			break;
	}
}

/**
 * Hooks into manage_edit-helpdesk_ticket_sortable_columns to register sortable columns.
 *
 * @since 2.0.0
 * @see swh_ticket_sortable_columns()
 */
add_filter( 'manage_edit-helpdesk_ticket_sortable_columns', 'swh_ticket_sortable_columns' );
/**
 * Registers ticket_uid and ticket_status as sortable columns.
 *
 * @since 2.0.0
 * @param string[] $columns Existing sortable column definitions.
 * @return string[] Modified sortable column definitions.
 */
function swh_ticket_sortable_columns( $columns ) {
	$columns['ticket_uid']    = 'ticket_uid';
	$columns['ticket_status'] = 'ticket_status';
	return $columns;
}

/**
 * Hooks into pre_get_posts to modify the ticket list query for sorting, filtering, and restriction.
 *
 * @since 2.0.0
 * @see swh_ticket_list_query()
 */
add_action( 'pre_get_posts', 'swh_ticket_list_query' );
/**
 * Modifies the admin ticket list query for sorting and status/priority filter dropdowns.
 *
 * Also restricts technicians to their assigned tickets when `swh_restrict_to_assigned` is enabled.
 *
 * @since 2.0.0
 * @param WP_Query $query The current query object.
 * @return void
 */
function swh_ticket_list_query( $query ) {
	if ( ! is_admin() || ! $query->is_main_query() ) {
		return;
	}
	if ( 'helpdesk_ticket' !== $query->get( 'post_type' ) ) {
		return;
	}

	// Handle sortable columns. Only apply meta sort when explicitly requested
	// to avoid filtering out tickets that lack the meta key.
	$orderby = $query->get( 'orderby' );
	if ( 'ticket_status' === $orderby ) {
		$query->set( 'meta_key', '_ticket_status' );
		$query->set( 'orderby', 'meta_value' );
	} elseif ( 'ticket_uid' === $orderby ) {
		$query->set( 'meta_key', '_ticket_uid' );
		$query->set( 'orderby', 'meta_value' );
	}

	// Handle filter dropdowns.
	// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
	$meta_query = array();
	// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Admin list-table GET filter params; sanitized before use.
	if ( ! empty( $_GET['swh_filter_status'] ) ) {
		$meta_query[] = array(
			'key'   => '_ticket_status',
			'value' => sanitize_text_field( wp_unslash( $_GET['swh_filter_status'] ) ),
		);
	}
	if ( ! empty( $_GET['swh_filter_priority'] ) ) {
		$meta_query[] = array(
			'key'   => '_ticket_priority',
			'value' => sanitize_text_field( wp_unslash( $_GET['swh_filter_priority'] ) ),
		);
	}
	// phpcs:enable WordPress.Security.NonceVerification.Recommended
	if ( ! empty( $meta_query ) ) {
		$meta_query['relation'] = 'AND';
		$query->set( 'meta_query', $meta_query );
	}

	// Restrict technicians to assigned tickets if enabled.
	if ( 'yes' === get_option( 'swh_restrict_to_assigned', 'no' ) ) {
		$current_user = wp_get_current_user();
		if ( in_array( 'technician', (array) $current_user->roles, true ) ) {
			$meta_query   = $query->get( 'meta_query' ) ? $query->get( 'meta_query' ) : array();
			$meta_query[] = array(
				'key'   => '_ticket_assigned_to',
				'value' => $current_user->ID,
			);
			if ( empty( $meta_query['relation'] ) ) {
				$meta_query['relation'] = 'AND';
			}
			$query->set( 'meta_query', $meta_query );
		}
	}
}

/**
 * Hooks into load-post.php to block technicians from accessing unassigned ticket edit screens.
 *
 * @since 2.0.0
 * @see swh_restrict_ticket_edit()
 */
add_action( 'load-post.php', 'swh_restrict_ticket_edit' );
/**
 * Prevents technicians from opening ticket edit screens they are not assigned to.
 *
 * Only active when `swh_restrict_to_assigned` is enabled. Calls wp_die() on unauthorized access.
 *
 * @since 2.0.0
 * @return void
 */
function swh_restrict_ticket_edit() {
	if ( 'yes' !== get_option( 'swh_restrict_to_assigned', 'no' ) ) {
		return;
	}
	$user = wp_get_current_user();
	if ( ! in_array( 'technician', (array) $user->roles, true ) ) {
		return;
	}
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Admin read-only GET param; capability check already performed above.
	$post_id = isset( $_GET['post'] ) ? absint( $_GET['post'] ) : 0;
	if ( ! $post_id ) {
		return;
	}
	$post = get_post( $post_id );
	if ( ! $post || 'helpdesk_ticket' !== $post->post_type ) {
		return;
	}
	if ( (int) get_post_meta( $post_id, '_ticket_assigned_to', true ) !== $user->ID ) {
		wp_die( esc_html__( 'You are not assigned to this ticket.', 'simple-wp-helpdesk' ), 403 );
	}
}

/**
 * Hooks into the_posts to prime post meta cache for all tickets and prevent N+1 queries.
 *
 * @since 2.0.0
 * @see swh_prime_ticket_meta_cache()
 */
add_filter( 'the_posts', 'swh_prime_ticket_meta_cache', 10, 2 );
/**
 * Primes the post meta cache for all tickets returned by the admin list query.
 *
 * Prevents N+1 queries when column content callbacks read post meta per row.
 *
 * @since 2.0.0
 * @param WP_Post[] $posts Array of post objects from the query.
 * @param WP_Query  $query The current query object.
 * @return WP_Post[] Unmodified posts array.
 */
function swh_prime_ticket_meta_cache( $posts, $query ) {
	if ( ! is_admin() || empty( $posts ) || 'helpdesk_ticket' !== $query->get( 'post_type' ) ) {
		return $posts;
	}
	update_meta_cache( 'post', wp_list_pluck( $posts, 'ID' ) );
	return $posts;
}

/**
 * Hooks into get_post_metadata to suppress the edit lock for recently reassigned tickets.
 *
 * @since 2.0.0
 * @see swh_suppress_stale_edit_lock()
 */
add_filter( 'get_post_metadata', 'swh_suppress_stale_edit_lock', 10, 4 );
/**
 * Suppresses the WordPress post edit lock for tickets recently reassigned.
 *
 * After reassignment the previous editor's heartbeat can restore the lock before
 * the page reloads. A transient set during save short-circuits `_edit_lock` reads
 * for 3 minutes, preventing the "Currently Editing" notice from appearing.
 *
 * @since 2.0.0
 * @param mixed  $value    The metadata value (null = not yet filtered).
 * @param int    $post_id  The post ID.
 * @param string $meta_key The meta key being read.
 * @param bool   $single   Whether a single value is requested (unused).
 * @return mixed Empty string to suppress the lock, or the original $value.
 */
// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- Hook signature requires $single; not needed here.
function swh_suppress_stale_edit_lock( $value, $post_id, $meta_key, $single ) {
	if ( '_edit_lock' !== $meta_key ) {
		return $value;
	}
	if ( get_transient( 'swh_lock_clear_' . $post_id ) ) {
		return '';
	}
	return $value;
}

/**
 * Hooks into bulk_actions-edit-helpdesk_ticket to add per-status bulk actions.
 *
 * @since 2.2.0
 * @see swh_bulk_actions_tickets()
 */
add_filter( 'bulk_actions-edit-helpdesk_ticket', 'swh_bulk_actions_tickets' );
/**
 * Adds a "Set Status: {Status}" bulk action for each configured ticket status.
 *
 * @since 2.2.0
 * @param array<string, string> $actions Existing bulk action definitions.
 * @return array<string, string> Modified bulk action definitions.
 */
function swh_bulk_actions_tickets( $actions ) {
	foreach ( swh_get_statuses() as $status ) {
		$slug             = sanitize_title( $status );
		$actions[ 'swh_status_' . $slug ] = sprintf(
			/* translators: %s: ticket status label */
			__( 'Set Status: %s', 'simple-wp-helpdesk' ),
			$status
		);
	}
	return $actions;
}

/**
 * Hooks into handle_bulk_actions-edit-helpdesk_ticket to process status bulk actions.
 *
 * @since 2.2.0
 * @see swh_handle_bulk_status()
 */
add_filter( 'handle_bulk_actions-edit-helpdesk_ticket', 'swh_handle_bulk_status', 10, 3 );
/**
 * Processes a bulk "Set Status" action, updating _ticket_status meta on each selected ticket.
 *
 * @since 2.2.0
 * @param string   $redirect_to The URL to redirect to after the action.
 * @param string   $action      The bulk action being processed.
 * @param int[]    $post_ids    Array of post IDs included in the bulk action.
 * @return string The redirect URL, with result query args appended when handled.
 */
function swh_handle_bulk_status( $redirect_to, $action, $post_ids ) {
	if ( 0 !== strpos( $action, 'swh_status_' ) ) {
		return $redirect_to;
	}
	$slug     = substr( $action, strlen( 'swh_status_' ) );
	$status   = null;
	foreach ( swh_get_statuses() as $s ) {
		if ( sanitize_title( $s ) === $slug ) {
			$status = $s;
			break;
		}
	}
	if ( null === $status ) {
		return $redirect_to;
	}
	$defs            = swh_get_defaults();
	$resolved_status = get_option( 'swh_resolved_status', $defs['swh_resolved_status'] );
	$count           = 0;
	foreach ( $post_ids as $post_id ) {
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			continue;
		}
		$old_status = get_post_meta( (int) $post_id, '_ticket_status', true );
		update_post_meta( (int) $post_id, '_ticket_status', $status );
		if ( $resolved_status === $status && $old_status !== $status ) {
			update_post_meta( (int) $post_id, '_resolved_timestamp', time() );
		} elseif ( $resolved_status === $old_status && $resolved_status !== $status ) {
			delete_post_meta( (int) $post_id, '_resolved_timestamp' );
		}
		++$count;
	}
	return add_query_arg(
		array(
			'swh_bulk_updated' => $count,
			'swh_bulk_status'  => rawurlencode( $status ),
		),
		$redirect_to
	);
}

/**
 * Hooks into admin_notices to display a confirmation message after a bulk status update.
 *
 * @since 2.2.0
 * @see swh_bulk_status_notice()
 */
add_action( 'admin_notices', 'swh_bulk_status_notice' );
/**
 * Renders an admin notice confirming how many tickets were updated by a bulk status action.
 *
 * @since 2.2.0
 * @return void
 */
function swh_bulk_status_notice() {
	// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only GET params set by server redirect; used only for display.
	if ( empty( $_GET['swh_bulk_updated'] ) ) {
		return;
	}
	$count  = absint( $_GET['swh_bulk_updated'] );
	$status = isset( $_GET['swh_bulk_status'] ) ? sanitize_text_field( rawurldecode( wp_unslash( $_GET['swh_bulk_status'] ) ) ) : '';
	// phpcs:enable WordPress.Security.NonceVerification.Recommended
	echo '<div class="updated notice is-dismissible"><p>';
	/* translators: 1: number of tickets updated, 2: new status label */
	echo sprintf( esc_html__( '%1$s ticket(s) updated to status: %2$s', 'simple-wp-helpdesk' ), esc_html( (string) $count ), '<strong>' . esc_html( $status ) . '</strong>' );
	echo '</p></div>';
}

/**
 * Hooks into restrict_manage_posts to render Status and Priority filter dropdowns.
 *
 * @since 2.0.0
 * @see swh_ticket_filter_dropdowns()
 */
add_action( 'restrict_manage_posts', 'swh_ticket_filter_dropdowns' );
/**
 * Renders Status and Priority filter dropdowns above the ticket list table.
 *
 * @since 2.0.0
 * @param string $post_type The current post type (only renders for helpdesk_ticket).
 * @return void
 */
function swh_ticket_filter_dropdowns( $post_type ) {
	if ( 'helpdesk_ticket' !== $post_type ) {
		return;
	}
	$statuses = swh_get_statuses();
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Admin list-table filter GET param; sanitized before use.
	$current_status = isset( $_GET['swh_filter_status'] ) ? sanitize_text_field( wp_unslash( $_GET['swh_filter_status'] ) ) : '';
	$current_status = esc_attr( $current_status );
	echo '<select name="swh_filter_status"><option value="">' . esc_html__( 'All Statuses', 'simple-wp-helpdesk' ) . '</option>';
	foreach ( $statuses as $s ) {
		echo '<option value="' . esc_attr( $s ) . '"' . selected( $current_status, $s, false ) . '>' . esc_html( $s ) . '</option>';
	}
	echo '</select>';

	$priorities = swh_get_priorities();
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Admin list-table filter GET param; sanitized before use.
	$current_priority = isset( $_GET['swh_filter_priority'] ) ? sanitize_text_field( wp_unslash( $_GET['swh_filter_priority'] ) ) : '';
	$current_priority = esc_attr( $current_priority );
	echo '<select name="swh_filter_priority"><option value="">' . esc_html__( 'All Priorities', 'simple-wp-helpdesk' ) . '</option>';
	foreach ( $priorities as $p ) {
		echo '<option value="' . esc_attr( $p ) . '"' . selected( $current_priority, $p, false ) . '>' . esc_html( $p ) . '</option>';
	}
	echo '</select>';
}
