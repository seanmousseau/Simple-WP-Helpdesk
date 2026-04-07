<?php if ( ! defined( 'ABSPATH' ) ) { exit; }

add_action( 'admin_head', 'swh_admin_list_styles' );
function swh_admin_list_styles() {
    $screen = get_current_screen();
    if ( ! $screen || 'edit-helpdesk_ticket' !== $screen->id ) {
        return;
    }
    echo '<style>
        .swh-status-badge { padding:3px 8px; border-radius:3px; font-size:12px; font-weight:600; display:inline-block; white-space:nowrap; }
        .column-ticket_uid { width:100px; }
        .column-ticket_status { width:120px; }
        .column-ticket_priority { width:100px; }
        .column-ticket_assigned { width:140px; }
        .column-ticket_client { width:160px; }
    </style>';
}

add_filter( 'manage_helpdesk_ticket_posts_columns', 'swh_ticket_columns' );
function swh_ticket_columns( $columns ) {
    $new = array();
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

add_action( 'manage_helpdesk_ticket_posts_custom_column', 'swh_ticket_column_content', 10, 2 );
function swh_ticket_column_content( $column, $post_id ) {
    $defs = swh_get_defaults();
    switch ( $column ) {
        case 'ticket_uid':
            echo esc_html( get_post_meta( $post_id, '_ticket_uid', true ) ?: '—' );
            break;
        case 'ticket_status':
            $status          = get_post_meta( $post_id, '_ticket_status', true );
            $closed_status   = get_option( 'swh_closed_status', $defs['swh_closed_status'] );
            $resolved_status = get_option( 'swh_resolved_status', $defs['swh_resolved_status'] );
            if ( $status === $closed_status ) {
                $bg = '#f8d7da'; $color = '#721c24';
            } elseif ( $status === $resolved_status ) {
                $bg = '#e6f7ff'; $color = '#005980';
            } elseif ( stripos( $status, 'progress' ) !== false ) {
                $bg = '#fff3cd'; $color = '#856404';
            } else {
                $bg = '#d4edda'; $color = '#155724';
            }
            echo '<span class="swh-status-badge" style="background:' . esc_attr( $bg ) . ';color:' . esc_attr( $color ) . ';">' . esc_html( $status ) . '</span>';
            break;
        case 'ticket_priority':
            echo esc_html( get_post_meta( $post_id, '_ticket_priority', true ) ?: '—' );
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

add_filter( 'manage_edit-helpdesk_ticket_sortable_columns', 'swh_ticket_sortable_columns' );
function swh_ticket_sortable_columns( $columns ) {
    $columns['ticket_uid']    = 'ticket_uid';
    $columns['ticket_status'] = 'ticket_status';
    return $columns;
}

add_action( 'pre_get_posts', 'swh_ticket_list_query' );
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
    if ( ! empty( $meta_query ) ) {
        $meta_query['relation'] = 'AND';
        $query->set( 'meta_query', $meta_query );
    }

    // Restrict technicians to assigned tickets if enabled.
    if ( 'yes' === get_option( 'swh_restrict_to_assigned', 'no' ) ) {
        $current_user = wp_get_current_user();
        if ( in_array( 'technician', (array) $current_user->roles, true ) ) {
            $meta_query   = $query->get( 'meta_query' ) ?: array();
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

add_action( 'load-post.php', 'swh_restrict_ticket_edit' );
function swh_restrict_ticket_edit() {
    if ( 'yes' !== get_option( 'swh_restrict_to_assigned', 'no' ) ) {
        return;
    }
    $user = wp_get_current_user();
    if ( ! in_array( 'technician', (array) $user->roles, true ) ) {
        return;
    }
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

add_filter( 'the_posts', 'swh_prime_ticket_meta_cache', 10, 2 );
function swh_prime_ticket_meta_cache( $posts, $query ) {
    if ( ! is_admin() || empty( $posts ) || 'helpdesk_ticket' !== $query->get( 'post_type' ) ) {
        return $posts;
    }
    update_meta_cache( 'post', wp_list_pluck( $posts, 'ID' ) );
    return $posts;
}

add_filter( 'get_post_metadata', 'swh_suppress_stale_edit_lock', 10, 4 );
function swh_suppress_stale_edit_lock( $value, $post_id, $meta_key, $single ) {
    if ( '_edit_lock' !== $meta_key ) {
        return $value;
    }
    if ( get_transient( 'swh_lock_clear_' . $post_id ) ) {
        return '';
    }
    return $value;
}

add_action( 'restrict_manage_posts', 'swh_ticket_filter_dropdowns' );
function swh_ticket_filter_dropdowns( $post_type ) {
    if ( 'helpdesk_ticket' !== $post_type ) {
        return;
    }
    $statuses        = swh_get_statuses();
    $current_status  = isset( $_GET['swh_filter_status'] ) ? sanitize_text_field( wp_unslash( $_GET['swh_filter_status'] ) ) : '';
    echo '<select name="swh_filter_status"><option value="">' . esc_html__( 'All Statuses', 'simple-wp-helpdesk' ) . '</option>';
    foreach ( $statuses as $s ) {
        echo '<option value="' . esc_attr( $s ) . '"' . selected( $current_status, $s, false ) . '>' . esc_html( $s ) . '</option>';
    }
    echo '</select>';

    $priorities       = swh_get_priorities();
    $current_priority = isset( $_GET['swh_filter_priority'] ) ? sanitize_text_field( wp_unslash( $_GET['swh_filter_priority'] ) ) : '';
    echo '<select name="swh_filter_priority"><option value="">' . esc_html__( 'All Priorities', 'simple-wp-helpdesk' ) . '</option>';
    foreach ( $priorities as $p ) {
        echo '<option value="' . esc_attr( $p ) . '"' . selected( $current_priority, $p, false ) . '>' . esc_html( $p ) . '</option>';
    }
    echo '</select>';
}
