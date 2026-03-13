<?php
/**
 * Plugin Name: Simple WP Helpdesk
 * Description: A comprehensive helpdesk system with auto-close, custom templates, multi-file attachments, internal notes, anti-spam, deep uninstallation cleanup, and GitHub auto-updates.
 * Version: 1.3
 * Requires at least: 5.3
 * Requires PHP: 7.2
 * Author: SM WP Plugins
 */

// Exit immediately if accessed directly
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// ==============================================================================
// 1. PLUGIN SETUP, UPGRADE LOGIC & CRON
// ==============================================================================
define( 'SWH_VERSION', '1.3' );

register_activation_hook( __FILE__, 'swh_activate' );
function swh_activate() {
    add_role(
        'technician',
        'Technician',
        array(
            'read'         => true,
            'edit_posts'   => true,
            'upload_files' => true,
        )
    );
    if ( ! wp_next_scheduled( 'swh_autoclose_event' ) ) {
        wp_schedule_event( time(), 'hourly', 'swh_autoclose_event' );
    }
    if ( ! wp_next_scheduled( 'swh_retention_tickets_event' ) ) {
        wp_schedule_event( time() + 1800, 'hourly', 'swh_retention_tickets_event' );
    }
    if ( ! wp_next_scheduled( 'swh_retention_attachments_event' ) ) {
        wp_schedule_event( time() + 3600, 'hourly', 'swh_retention_attachments_event' );
    }
    swh_run_upgrade_routine();
}

register_deactivation_hook( __FILE__, 'swh_deactivate' );
function swh_deactivate() {
    wp_clear_scheduled_hook( 'swh_autoclose_event' );
    wp_clear_scheduled_hook( 'swh_retention_tickets_event' );
    wp_clear_scheduled_hook( 'swh_retention_attachments_event' );
    // Clear legacy hooks just in case
    wp_clear_scheduled_hook( 'swh_hourly_maintenance_event' );
    wp_clear_scheduled_hook( 'swh_daily_autoclose_event' );
}

add_action( 'admin_init', 'swh_run_upgrade_routine' );
function swh_run_upgrade_routine() {
    $db_version = get_option( 'swh_db_version', '0.0' );
    if ( version_compare( $db_version, SWH_VERSION, '<' ) ) {
        $defs = swh_get_defaults();
        foreach ( $defs as $key => $val ) {
            add_option( $key, $val );
        }
        add_option( 'swh_default_assignee', '' );
        add_option( 'swh_fallback_email', '' );
        add_option( 'swh_max_upload_size', 5 );
        add_option( 'swh_autoclose_days', 3 );
        add_option( 'swh_retention_tickets_days', 0 );
        add_option( 'swh_retention_attachments_days', 0 );
        add_option( 'swh_spam_method', 'none' );
        add_option( 'swh_recaptcha_site_key', '' );
        add_option( 'swh_recaptcha_secret_key', '' );
        add_option( 'swh_turnstile_site_key', '' );
        add_option( 'swh_turnstile_secret_key', '' );
        add_option( 'swh_delete_on_uninstall', 'no' );
        update_option( 'swh_db_version', SWH_VERSION );
    }
}

register_uninstall_hook( __FILE__, 'swh_uninstall' );
function swh_uninstall() {
    if ( 'yes' === get_option( 'swh_delete_on_uninstall' ) ) {
        $tickets = get_posts(
            array(
                'post_type'   => 'helpdesk_ticket',
                'numberposts' => -1,
                'post_status' => 'any',
            )
        );
        foreach ( $tickets as $t ) {
            swh_delete_ticket_and_files( $t->ID );
        }
        remove_role( 'technician' );
        foreach ( swh_get_all_option_keys() as $opt ) {
            delete_option( $opt );
        }
        delete_option( 'swh_delete_on_uninstall' );
        delete_option( 'swh_db_version' );
    }
}

add_action( 'init', 'swh_register_ticket_cpt' );
function swh_register_ticket_cpt() {
    register_post_type(
        'helpdesk_ticket',
        array(
            'labels'          => array(
                'name'          => 'Tickets',
                'singular_name' => 'Ticket',
                'add_new_item'  => 'Add New Ticket',
                'edit_item'     => 'Edit Ticket',
            ),
            'public'          => false,
            'show_ui'         => true,
            'menu_icon'       => 'dashicons-tickets-alt',
            'supports'        => array( 'title', 'editor' ),
            'capability_type' => 'post',
        )
    );
}

add_action( 'post_edit_form_tag', 'swh_add_enctype_to_post_form' );
function swh_add_enctype_to_post_form() {
    global $post;
    if ( $post && 'helpdesk_ticket' === $post->post_type ) {
        echo ' enctype="multipart/form-data"';
    }
}

add_action( 'wp_head', 'swh_load_spam_scripts_in_head' );
function swh_load_spam_scripts_in_head() {
    $spam_method = get_option( 'swh_spam_method', 'none' );
    if ( 'recaptcha' === $spam_method ) {
        // phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedScript
        echo '<script src="https://www.google.com/recaptcha/api.js" async defer></script>' . "\n";
    } elseif ( 'turnstile' === $spam_method ) {
        // phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedScript
        echo '<script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer></script>' . "\n";
    }
}

// ==============================================================================
// 2. HELPER FUNCTIONS & DEFAULTS
// ==============================================================================
function swh_get_defaults() {
    static $defaults = null;
    if ( null === $defaults ) {
        $defaults = array(
            'swh_ticket_priorities'         => 'Low, Medium, High',
            'swh_default_priority'          => 'Medium',
            'swh_ticket_statuses'           => 'Open, In Progress, Resolved, Closed',
            'swh_default_status'            => 'Open',
            'swh_resolved_status'           => 'Resolved',
            'swh_closed_status'             => 'Closed',
            'swh_reopened_status'           => 'Open',
            'swh_em_user_new_sub'           => 'Ticket Received: {title}',
            'swh_em_user_new_body'          => "Hi {name},\n\nWe have received your ticket (ID: {ticket_id}).\n\nYou can view your ticket status and reply to our technicians here:\n{ticket_url}",
            'swh_em_user_reply_sub'         => 'New Reply to Ticket {ticket_id}: {title}',
            'swh_em_user_reply_body'        => "Hi {name},\n\nA technician has replied to your ticket.\n\nReply:\n{message}\n\nView conversation and reply here:\n{ticket_url}",
            'swh_em_user_status_sub'        => 'Status Updated: Ticket {ticket_id}',
            'swh_em_user_status_body'       => "Hi {name},\n\nThe status of your ticket has been updated to: {status}\n\nView or reply to your ticket here:\n{ticket_url}",
            'swh_em_user_reply_status_sub'  => 'Ticket Updated: {title}',
            'swh_em_user_reply_status_body' => "Hi {name},\n\nA technician has replied to your ticket and the status is now: {status}.\n\nReply:\n{message}\n\nView conversation and reply here:\n{ticket_url}",
            'swh_em_user_resolved_sub'      => 'Ticket Resolved: {title}',
            'swh_em_user_resolved_body'     => "Hi {name},\n\nYour ticket has been marked as resolved by a technician.\n\nTechnician Note:\n{message}\n\nPlease note: If we do not hear back from you, this ticket will be automatically closed in {autoclose_days} days.\n\nView or reply to your ticket here:\n{ticket_url}",
            'swh_em_user_reopen_sub'        => 'Ticket Re-Opened: {ticket_id}',
            'swh_em_user_reopen_body'       => "Hi {name},\n\nYour ticket has been re-opened.\n\nView or reply to your ticket here:\n{ticket_url}",
            'swh_em_user_autoclose_sub'     => 'Ticket Auto-Closed: {ticket_id}',
            'swh_em_user_autoclose_body'    => "Hi {name},\n\nSince we haven't heard from you recently, we have automatically closed your ticket.\n\nIf the problem still exists, you can re-open it by clicking the link below:\n{ticket_url}",
            'swh_em_user_closed_sub'        => 'Ticket Closed: {title}',
            'swh_em_user_closed_body'       => "Hi {name},\n\nYou have successfully closed your ticket.\n\nView your ticket here:\n{ticket_url}",
            'swh_em_admin_new_sub'          => 'New Ticket Submitted [{ticket_id}]',
            'swh_em_admin_new_body'         => "A new ticket was submitted by {name}.\n\nPriority: {priority}\nTitle: {title}\n\nDescription:\n{message}\n\nView/Edit Ticket in Admin:\n{admin_url}",
            'swh_em_admin_reply_sub'        => 'Client Reply on Ticket {ticket_id}',
            'swh_em_admin_reply_body'       => "{name} has replied to their ticket.\n\nReply:\n{message}\n\nView/Edit Ticket in Admin:\n{admin_url}",
            'swh_em_admin_reopen_sub'       => 'Ticket RE-OPENED [{ticket_id}]',
            'swh_em_admin_reopen_body'      => "{name} has re-opened their ticket.\n\nReason:\n{message}\n\nView/Edit Ticket in Admin:\n{admin_url}",
            'swh_em_admin_closed_sub'       => 'Ticket Closed by Client [{ticket_id}]',
            'swh_em_admin_closed_body'      => "{name} has marked their ticket as closed.\n\nView/Edit Ticket in Admin:\n{admin_url}",
            'swh_msg_success_new'           => 'Your ticket has been submitted successfully! Check your email for a secure link to track your ticket.',
            'swh_msg_success_reply'         => 'Your reply has been added.',
            'swh_msg_success_reopen'        => 'Your ticket has been successfully re-opened. Our team has been notified.',
            'swh_msg_success_closed'        => 'Your ticket has been successfully closed.',
            'swh_msg_err_spam'              => 'Anti-spam verification failed. Please try again.',
            'swh_msg_err_missing'           => 'Please fill in all required fields.',
            'swh_msg_err_invalid'           => 'Invalid or expired ticket link.',
        );
    }
    return $defaults;
}

function swh_get_all_option_keys() {
    return array_merge(
        array_keys( swh_get_defaults() ),
        array(
            'swh_default_assignee',
            'swh_fallback_email',
            'swh_max_upload_size',
            'swh_autoclose_days',
            'swh_retention_tickets_days',
            'swh_retention_attachments_days',
            'swh_spam_method',
            'swh_recaptcha_site_key',
            'swh_recaptcha_secret_key',
            'swh_turnstile_site_key',
            'swh_turnstile_secret_key',
            'swh_db_version',
        )
    );
}

function swh_get_statuses() {
    $defs = swh_get_defaults();
    return array_map( 'trim', explode( ',', get_option( 'swh_ticket_statuses', $defs['swh_ticket_statuses'] ) ) );
}

function swh_get_priorities() {
    $defs = swh_get_defaults();
    return array_map( 'trim', explode( ',', get_option( 'swh_ticket_priorities', $defs['swh_ticket_priorities'] ) ) );
}

function swh_get_secure_ticket_link( $ticket_id ) {
    $base_url = get_post_meta( $ticket_id, '_ticket_url', true );
    $token    = get_post_meta( $ticket_id, '_ticket_token', true );
    if ( $base_url && $token ) {
        return add_query_arg(
            array(
                'swh_ticket' => $ticket_id,
                'token'      => $token,
            ),
            $base_url
        );
    }
    return false;
}

function swh_parse_template( $template, $data ) {
    foreach ( $data as $key => $value ) {
        $template = str_replace( '{' . $key . '}', $value, $template );
    }
    return $template;
}

function swh_get_admin_email( $ticket_id = 0 ) {
    if ( $ticket_id ) {
        $assigned = get_post_meta( $ticket_id, '_ticket_assigned_to', true );
        if ( $assigned ) {
            $user = get_userdata( $assigned );
            if ( $user ) {
                return $user->user_email;
            }
        }
    }
    $default_assignee = get_option( 'swh_default_assignee' );
    if ( $default_assignee ) {
        $user = get_userdata( $default_assignee );
        if ( $user ) {
            return $user->user_email;
        }
    }
    $fallback = get_option( 'swh_fallback_email' );
    if ( $fallback ) {
        return $fallback;
    }
    return get_option( 'admin_email' );
}

function swh_normalize_files_array( $files ) {
    $normalized = array();
    if ( isset( $files['name'] ) && is_array( $files['name'] ) ) {
        foreach ( $files['name'] as $key => $name ) {
            if ( $name ) {
                $normalized[] = array(
                    'name'     => $files['name'][ $key ],
                    'type'     => $files['type'][ $key ],
                    'tmp_name' => $files['tmp_name'][ $key ],
                    'error'    => $files['error'][ $key ],
                    'size'     => $files['size'][ $key ],
                );
            }
        }
    }
    return $normalized;
}

function swh_handle_multiple_uploads( $file_array ) {
    $files = swh_normalize_files_array( $file_array );
    if ( empty( $files ) ) {
        return array();
    }
    if ( ! function_exists( 'wp_handle_upload' ) ) {
        require_once ABSPATH . 'wp-admin/includes/file.php';
    }
    $max_size_mb   = (int) get_option( 'swh_max_upload_size', 5 );
    $max_bytes     = $max_size_mb * 1048576;
    $allowed_mimes = array(
        'jpg|jpeg|jpe' => 'image/jpeg',
        'gif'          => 'image/gif',
        'png'          => 'image/png',
        'pdf'          => 'application/pdf',
        'doc'          => 'application/msword',
        'docx'         => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'txt'          => 'text/plain',
    );
    $overrides     = array(
        'test_form' => false,
        'mimes'     => $allowed_mimes,
    );
    $uploaded_urls = array();
    foreach ( $files as $file ) {
        if ( $file['size'] > $max_bytes ) {
            continue;
        }
        $movefile = wp_handle_upload( $file, $overrides );
        if ( $movefile && ! isset( $movefile['error'] ) ) {
            $uploaded_urls[] = $movefile['url'];
        }
    }
    return $uploaded_urls;
}

function swh_delete_file_by_url( $url ) {
    if ( empty( $url ) ) {
        return;
    }
    $upload_dir = wp_get_upload_dir();
    if ( strpos( $url, $upload_dir['baseurl'] ) !== 0 ) {
        return;
    }
    $path = str_replace( $upload_dir['baseurl'], $upload_dir['basedir'], $url );
    if ( file_exists( $path ) ) {
        wp_delete_file( $path );
    }
}

function swh_delete_ticket_and_files( $ticket_id ) {
    $main_atts = get_post_meta( $ticket_id, '_ticket_attachments', true );
    if ( ! empty( $main_atts ) && is_array( $main_atts ) ) {
        foreach ( $main_atts as $url ) {
            swh_delete_file_by_url( $url );
        }
    }
    $legacy_id = get_post_meta( $ticket_id, '_ticket_attachment_id', true );
    if ( $legacy_id ) {
        wp_delete_attachment( $legacy_id, true );
    }
    $legacy_url = get_post_meta( $ticket_id, '_ticket_attachment_url', true );
    if ( $legacy_url ) {
        swh_delete_file_by_url( $legacy_url );
    }
    $comments = get_comments( array( 'post_id' => $ticket_id ) );
    foreach ( $comments as $c ) {
        $c_atts = get_comment_meta( $c->comment_ID, '_attachments', true );
        if ( ! empty( $c_atts ) && is_array( $c_atts ) ) {
            foreach ( $c_atts as $url ) {
                swh_delete_file_by_url( $url );
            }
        }
        $legacy_c_url = get_comment_meta( $c->comment_ID, '_attachment_url', true );
        if ( $legacy_c_url ) {
            swh_delete_file_by_url( $legacy_c_url );
        }
    }
    wp_delete_post( $ticket_id, true );
}

// ==============================================================================
// 3. BACKGROUND CRON TASKS (Micro-Batched to prevent cURL error 28)
// ==============================================================================

add_action( 'swh_autoclose_event', 'swh_process_autoclose' );
function swh_process_autoclose() {
    if ( function_exists( 'set_time_limit' ) ) {
        // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
        @set_time_limit( 0 );
    }
    $defs = swh_get_defaults();
    $days = (int) get_option( 'swh_autoclose_days', 3 );
    if ( $days <= 0 ) {
        return;
    }
    $resolved_status = get_option( 'swh_resolved_status', $defs['swh_resolved_status'] );
    $closed_status   = get_option( 'swh_closed_status', $defs['swh_closed_status'] );
    $threshold       = time() - ( $days * DAY_IN_SECONDS );
    
    // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
    $tickets = get_posts(
        array(
            'post_type'   => 'helpdesk_ticket',
            'numberposts' => 2,
            'meta_query'  => array(
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
    foreach ( $tickets as $ticket ) {
        update_post_meta( $ticket->ID, '_ticket_status', $closed_status );
        $comment_id = wp_insert_comment(
            array(
                'comment_post_ID'  => $ticket->ID,
                'comment_author'   => 'System Auto-Close',
                'comment_content'  => "Ticket automatically closed due to inactivity ($days days).",
                'comment_approved' => 1,
            )
        );
        update_comment_meta( $comment_id, '_is_internal_note', '1' );
        
        $data = array(
            'name'           => get_post_meta( $ticket->ID, '_ticket_name', true ) ?: 'Client',
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
            $subject = swh_parse_template( get_option( 'swh_em_user_autoclose_sub', $defs['swh_em_user_autoclose_sub'] ), $data );
            $message = swh_parse_template( get_option( 'swh_em_user_autoclose_body', $defs['swh_em_user_autoclose_body'] ), $data );
            wp_mail( $data['email'], $subject, $message );
        }
    }
}

add_action( 'swh_retention_attachments_event', 'swh_process_retention_attachments' );
function swh_process_retention_attachments() {
    if ( function_exists( 'set_time_limit' ) ) {
        // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
        @set_time_limit( 0 );
    }
    $days = (int) get_option( 'swh_retention_attachments_days', 0 );
    if ( $days <= 0 ) {
        return;
    }
    $threshold_date = gmdate( 'Y-m-d H:i:s', time() - ( $days * DAY_IN_SECONDS ) );
    
    // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
    $tickets = get_posts(
        array(
            'post_type'   => 'helpdesk_ticket',
            'numberposts' => 1,
            'date_query'  => array( array( 'before' => $threshold_date ) ),
            'meta_query'  => array(
                array(
                    'key'     => '_ticket_attachments',
                    'compare' => 'EXISTS',
                ),
            ),
        )
    );
    foreach ( $tickets as $ticket ) {
        $atts = get_post_meta( $ticket->ID, '_ticket_attachments', true );
        if ( ! empty( $atts ) && is_array( $atts ) ) {
            foreach ( $atts as $url ) {
                swh_delete_file_by_url( $url );
            }
            delete_post_meta( $ticket->ID, '_ticket_attachments' );
            $comment_id = wp_insert_comment(
                array(
                    'comment_post_ID'  => $ticket->ID,
                    'comment_author'   => 'System Maintenance',
                    'comment_content'  => "Original ticket attachments automatically purged (older than $days days).",
                    'comment_approved' => 1,
                )
            );
            update_comment_meta( $comment_id, '_is_internal_note', '1' );
        }
    }
    
    // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
    $comments = get_comments(
        array(
            'post_type'  => 'helpdesk_ticket',
            'number'     => 1,
            'date_query' => array( array( 'before' => $threshold_date ) ),
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
        if ( ! empty( $atts ) && is_array( $atts ) ) {
            foreach ( $atts as $url ) {
                swh_delete_file_by_url( $url );
            }
            delete_comment_meta( $comment->comment_ID, '_attachments' );
            $new_content = $comment->comment_content . "\n\n*(Attachments automatically purged after $days days)*";
            wp_update_comment(
                array(
                    'comment_ID'      => $comment->comment_ID,
                    'comment_content' => $new_content,
                )
            );
        }
    }
}

add_action( 'swh_retention_tickets_event', 'swh_process_retention_tickets' );
function swh_process_retention_tickets() {
    if ( function_exists( 'set_time_limit' ) ) {
        // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
        @set_time_limit( 0 );
    }
    $days = (int) get_option( 'swh_retention_tickets_days', 0 );
    if ( $days <= 0 ) {
        return;
    }
    $threshold_date = gmdate( 'Y-m-d H:i:s', time() - ( $days * DAY_IN_SECONDS ) );
    $tickets        = get_posts(
        array(
            'post_type'   => 'helpdesk_ticket',
            'numberposts' => 1,
            'date_query'  => array(
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
}


// ==============================================================================
// 4. ADMIN SETTINGS PAGE
// ==============================================================================

add_action( 'admin_menu', 'swh_register_settings_page' );
function swh_register_settings_page() {
    add_submenu_page( 'edit.php?post_type=helpdesk_ticket', 'Helpdesk Settings', 'Settings', 'manage_options', 'swh-settings', 'swh_render_settings_page' );
}

function swh_render_settings_page() {
    if ( ! current_user_can( 'manage_options' ) ) {
        return;
    }
    $defs         = swh_get_defaults();
    $options_list = swh_get_all_option_keys();

    // GDPR SPECIFIC CLIENT DELETE
    if ( isset( $_POST['swh_gdpr_delete'], $_POST['swh_danger_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['swh_danger_nonce'] ) ), 'swh_danger_action' ) ) {
        $gdpr_email = isset( $_POST['swh_gdpr_email'] ) ? sanitize_email( wp_unslash( $_POST['swh_gdpr_email'] ) ) : '';
        if ( $gdpr_email ) {
            // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
            $tickets = get_posts(
                array(
                    'post_type'   => 'helpdesk_ticket',
                    'numberposts' => -1,
                    'post_status' => 'any',
                    'meta_query'  => array(
                        array(
                            'key'   => '_ticket_email',
                            'value' => $gdpr_email,
                        ),
                    ),
                )
            );
            $count   = count( $tickets );
            foreach ( $tickets as $t ) {
                swh_delete_ticket_and_files( $t->ID );
            }
            echo '<div class="updated error"><p><strong>Successfully deleted ' . esc_html( $count ) . ' ticket(s) and all associated files for ' . esc_html( $gdpr_email ) . '.</strong></p></div>';
        } else {
            echo '<div class="updated error"><p><strong>Please enter a valid email address.</strong></p></div>';
        }
    }

    // MASS EXECUTIONS
    if ( isset( $_POST['swh_purge_tickets'], $_POST['swh_danger_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['swh_danger_nonce'] ) ), 'swh_danger_action' ) ) {
        $tickets = get_posts(
            array(
                'post_type'   => 'helpdesk_ticket',
                'numberposts' => -1,
                'post_status' => 'any',
            )
        );
        foreach ( $tickets as $t ) {
            swh_delete_ticket_and_files( $t->ID );
        }
        echo '<div class="updated error"><p><strong>All tickets & files have been successfully purged.</strong></p></div>';
    }

    if ( isset( $_POST['swh_factory_reset'], $_POST['swh_danger_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['swh_danger_nonce'] ) ), 'swh_danger_action' ) ) {
        $tickets = get_posts(
            array(
                'post_type'   => 'helpdesk_ticket',
                'numberposts' => -1,
                'post_status' => 'any',
            )
        );
        foreach ( $tickets as $t ) {
            swh_delete_ticket_and_files( $t->ID );
        }
        foreach ( $options_list as $opt ) {
            delete_option( $opt );
        }
        delete_option( 'swh_delete_on_uninstall' );
        delete_option( 'swh_db_version' );
        echo '<div class="updated error"><p><strong>Plugin Factory Reset Complete. All tickets/files purged and settings reverted to default.</strong></p></div>';
    }

    // SAVE SETTINGS
    if ( isset( $_POST['swh_save_settings'], $_POST['swh_settings_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['swh_settings_nonce'] ) ), 'swh_save_settings_action' ) ) {
        $uninstall_val = isset( $_POST['swh_delete_on_uninstall'] ) ? 'yes' : 'no';
        update_option( 'swh_delete_on_uninstall', $uninstall_val );
        
        foreach ( $options_list as $opt ) {
            if ( isset( $_POST[ $opt ] ) ) {
                $val = strpos( $opt, '_body' ) !== false ? wp_kses_post( wp_unslash( $_POST[ $opt ] ) ) : sanitize_text_field( wp_unslash( $_POST[ $opt ] ) );
                update_option( $opt, $val );
            }
        }
        echo '<div class="updated"><p>Settings saved successfully.</p></div>';
    }

    $techs = get_users( array( 'role__in' => array( 'administrator', 'technician' ) ) );

    function swh_field( $name, $defs, $type = 'text' ) {
        $val = get_option( $name, $defs[ $name ] );
        if ( 'textarea' === $type ) {
            echo '<textarea name="' . esc_attr( $name ) . '" rows="4" class="large-text" data-default="' . esc_attr( $defs[ $name ] ) . '">' . esc_textarea( $val ) . '</textarea>';
        } else {
            echo '<input type="text" name="' . esc_attr( $name ) . '" value="' . esc_attr( $val ) . '" class="regular-text" style="width:100%; max-width:500px;" data-default="' . esc_attr( $defs[ $name ] ) . '">';
        }
        echo '<br><a href="#" class="swh-reset-field" style="font-size:12px; color:#d63638;">Reset to default</a>';
    }
    ?>
    <div class="wrap">
        <h2>Helpdesk Settings</h2>
        <h2 class="nav-tab-wrapper" id="swh-tabs">
            <a href="#" class="nav-tab nav-tab-active" data-tab="tab-general">General</a>
            <a href="#" class="nav-tab" data-tab="tab-routing">Assignment & Routing</a>
            <a href="#" class="nav-tab" data-tab="tab-emails">Email Templates</a>
            <a href="#" class="nav-tab" data-tab="tab-messages">Messages</a>
            <a href="#" class="nav-tab" data-tab="tab-spam">Anti-Spam</a>
            <a href="#" class="nav-tab" data-tab="tab-tools" style="color:#d63638;">Tools</a>
        </h2>
        <form method="POST" action="">
            <?php wp_nonce_field( 'swh_save_settings_action', 'swh_settings_nonce' ); ?>
            
            <div id="tab-general" class="swh-tab-content">
                <table class="form-table">
                    <tr><th scope="row">Custom Priorities</th><td><?php swh_field( 'swh_ticket_priorities', $defs ); ?></td></tr>
                    <tr><th scope="row">Default Priority</th><td><?php swh_field( 'swh_default_priority', $defs ); ?></td></tr>
                    <tr><td colspan="2"><hr></td></tr>
                    <tr><th scope="row">Custom Statuses</th><td><?php swh_field( 'swh_ticket_statuses', $defs ); ?></td></tr>
                    <tr><th scope="row">Default New Status</th><td><?php swh_field( 'swh_default_status', $defs ); ?></td></tr>
                    <tr><th scope="row">"Resolved" Status <br><small>(Triggers Auto-close)</small></th><td><?php swh_field( 'swh_resolved_status', $defs ); ?></td></tr>
                    <tr><th scope="row">"Closed" Status <br><small>(Disables replies)</small></th><td><?php swh_field( 'swh_closed_status', $defs ); ?></td></tr>
                    <tr><th scope="row">"Re-Opened" Status</th><td><?php swh_field( 'swh_reopened_status', $defs ); ?></td></tr>
                    <tr><td colspan="2"><hr></td></tr>
                    <tr><th scope="row">Auto-Close Days</th><td><input type="number" name="swh_autoclose_days" value="<?php echo esc_attr( get_option( 'swh_autoclose_days', 3 ) ); ?>" style="width:80px;"> days <p class="description">If a ticket is Resolved and the user doesn't reply in this many days, it automatically closes. Set to 0 to disable.</p></td></tr>
                    <tr><th scope="row">Max File Upload Size</th><td><input type="number" name="swh_max_upload_size" value="<?php echo esc_attr( get_option( 'swh_max_upload_size', 5 ) ); ?>" style="width:80px;"> MB</td></tr>
                </table>
            </div>
            
            <div id="tab-routing" class="swh-tab-content" style="display:none;">
                <table class="form-table">
                    <tr><th scope="row">Default Assignee</th>
                        <td><select name="swh_default_assignee"><option value="">-- Unassigned --</option>
                        <?php foreach ( $techs as $t ) : ?>
                            <option value="<?php echo esc_attr( $t->ID ); ?>" <?php selected( get_option( 'swh_default_assignee' ), $t->ID ); ?>><?php echo esc_html( $t->display_name ); ?></option>
                        <?php endforeach; ?></select></td>
                    </tr>
                    <tr><th scope="row">Fallback Alert Email</th><td><input type="email" name="swh_fallback_email" value="<?php echo esc_attr( get_option( 'swh_fallback_email' ) ); ?>" class="regular-text"></td></tr>
                </table>
            </div>

            <div id="tab-emails" class="swh-tab-content" style="display:none;">
                <p><strong>Placeholders:</strong> <code>{name}</code>, <code>{email}</code>, <code>{ticket_id}</code>, <code>{title}</code>, <code>{status}</code>, <code>{priority}</code>, <code>{message}</code>, <code>{ticket_url}</code>, <code>{admin_url}</code>, <code>{autoclose_days}</code></p><hr>
                <h3>Emails Sent to Client</h3>
                <table class="form-table">
                    <tr><th scope="row">New Ticket (Subject)</th><td><?php swh_field( 'swh_em_user_new_sub', $defs ); ?></td></tr>
                    <tr><th scope="row">New Ticket (Body)</th><td><?php swh_field( 'swh_em_user_new_body', $defs, 'textarea' ); ?></td></tr>
                    <tr><th scope="row">Tech Replied (Subject)</th><td><?php swh_field( 'swh_em_user_reply_sub', $defs ); ?></td></tr>
                    <tr><th scope="row">Tech Replied (Body)</th><td><?php swh_field( 'swh_em_user_reply_body', $defs, 'textarea' ); ?></td></tr>
                    <tr><th scope="row">Status Changed (Subject)</th><td><?php swh_field( 'swh_em_user_status_sub', $defs ); ?></td></tr>
                    <tr><th scope="row">Status Changed (Body)</th><td><?php swh_field( 'swh_em_user_status_body', $defs, 'textarea' ); ?></td></tr>
                    <tr style="background:#f9f9f9;"><th scope="row">Reply + Status Change (Subject)</th><td><?php swh_field( 'swh_em_user_reply_status_sub', $defs ); ?></td></tr>
                    <tr style="background:#f9f9f9;"><th scope="row">Reply + Status Change (Body)</th><td><?php swh_field( 'swh_em_user_reply_status_body', $defs, 'textarea' ); ?></td></tr>
                    <tr style="background:#e6f7ff;"><th scope="row">Ticket Resolved (Subject)</th><td><?php swh_field( 'swh_em_user_resolved_sub', $defs ); ?></td></tr>
                    <tr style="background:#e6f7ff;"><th scope="row">Ticket Resolved (Body)</th><td><?php swh_field( 'swh_em_user_resolved_body', $defs, 'textarea' ); ?></td></tr>
                    <tr><th scope="row">Ticket Re-opened (Subject)</th><td><?php swh_field( 'swh_em_user_reopen_sub', $defs ); ?></td></tr>
                    <tr><th scope="row">Ticket Re-opened (Body)</th><td><?php swh_field( 'swh_em_user_reopen_body', $defs, 'textarea' ); ?></td></tr>
                    <tr><th scope="row">Client Closed Ticket (Subject)</th><td><?php swh_field( 'swh_em_user_closed_sub', $defs ); ?></td></tr>
                    <tr><th scope="row">Client Closed Ticket (Body)</th><td><?php swh_field( 'swh_em_user_closed_body', $defs, 'textarea' ); ?></td></tr>
                    <tr><th scope="row">Auto-Closed (Subject)</th><td><?php swh_field( 'swh_em_user_autoclose_sub', $defs ); ?></td></tr>
                    <tr><th scope="row">Auto-Closed (Body)</th><td><?php swh_field( 'swh_em_user_autoclose_body', $defs, 'textarea' ); ?></td></tr>
                </table><hr>
                <h3>Emails Sent to Technician/Admin</h3>
                <table class="form-table">
                    <tr><th scope="row">New Ticket (Subject)</th><td><?php swh_field( 'swh_em_admin_new_sub', $defs ); ?></td></tr>
                    <tr><th scope="row">New Ticket (Body)</th><td><?php swh_field( 'swh_em_admin_new_body', $defs, 'textarea' ); ?></td></tr>
                    <tr><th scope="row">Client Replied (Sub)</th><td><?php swh_field( 'swh_em_admin_reply_sub', $defs ); ?></td></tr>
                    <tr><th scope="row">Client Replied (Body)</th><td><?php swh_field( 'swh_em_admin_reply_body', $defs, 'textarea' ); ?></td></tr>
                    <tr><th scope="row">Ticket Re-opened (Sub)</th><td><?php swh_field( 'swh_em_admin_reopen_sub', $defs ); ?></td></tr>
                    <tr><th scope="row">Ticket Re-opened (Body)</th><td><?php swh_field( 'swh_em_admin_reopen_body', $defs, 'textarea' ); ?></td></tr>
                    <tr><th scope="row">Client Closed Ticket (Sub)</th><td><?php swh_field( 'swh_em_admin_closed_sub', $defs ); ?></td></tr>
                    <tr><th scope="row">Client Closed Ticket (Body)</th><td><?php swh_field( 'swh_em_admin_closed_body', $defs, 'textarea' ); ?></td></tr>
                </table>
            </div>

            <div id="tab-messages" class="swh-tab-content" style="display:none;">
                <table class="form-table">
                    <tr><th scope="row">Success: Ticket Created</th><td><?php swh_field( 'swh_msg_success_new', $defs ); ?></td></tr>
                    <tr><th scope="row">Success: Reply Added</th><td><?php swh_field( 'swh_msg_success_reply', $defs ); ?></td></tr>
                    <tr><th scope="row">Success: Ticket Re-opened</th><td><?php swh_field( 'swh_msg_success_reopen', $defs ); ?></td></tr>
                    <tr><th scope="row">Success: Ticket Closed</th><td><?php swh_field( 'swh_msg_success_closed', $defs ); ?></td></tr>
                    <tr><th scope="row">Error: Anti-Spam Failed</th><td><?php swh_field( 'swh_msg_err_spam', $defs ); ?></td></tr>
                    <tr><th scope="row">Error: Missing Fields</th><td><?php swh_field( 'swh_msg_err_missing', $defs ); ?></td></tr>
                    <tr><th scope="row">Error: Invalid Link</th><td><?php swh_field( 'swh_msg_err_invalid', $defs ); ?></td></tr>
                </table>
            </div>

            <div id="tab-spam" class="swh-tab-content" style="display:none;">
                <?php $spam_method = get_option( 'swh_spam_method', 'none' ); ?>
                <table class="form-table">
                    <tr><th scope="row">Spam Prevention</th><td><select name="swh_spam_method"><option value="none" <?php selected( $spam_method, 'none' ); ?>>None</option><option value="honeypot" <?php selected( $spam_method, 'honeypot' ); ?>>Honeypot</option><option value="recaptcha" <?php selected( $spam_method, 'recaptcha' ); ?>>Google reCAPTCHA v2</option><option value="turnstile" <?php selected( $spam_method, 'turnstile' ); ?>>Cloudflare Turnstile</option></select></td></tr>
                    <tr><th scope="row">reCAPTCHA Site Key</th><td><input type="text" name="swh_recaptcha_site_key" value="<?php echo esc_attr( get_option( 'swh_recaptcha_site_key' ) ); ?>" class="regular-text"></td></tr>
                    <tr><th scope="row">reCAPTCHA Secret Key</th><td><input type="text" name="swh_recaptcha_secret_key" value="<?php echo esc_attr( get_option( 'swh_recaptcha_secret_key' ) ); ?>" class="regular-text"></td></tr>
                    <tr><th scope="row">Turnstile Site Key</th><td><input type="text" name="swh_turnstile_site_key" value="<?php echo esc_attr( get_option( 'swh_turnstile_site_key' ) ); ?>" class="regular-text"></td></tr>
                    <tr><th scope="row">Turnstile Secret Key</th><td><input type="text" name="swh_turnstile_secret_key" value="<?php echo esc_attr( get_option( 'swh_turnstile_secret_key' ) ); ?>" class="regular-text"></td></tr>
                </table>
            </div>
            <p class="submit" id="save-btn-container"><input type="submit" name="swh_save_settings" class="button button-primary" value="Save Changes"></p>
        </form>

        <div id="tab-tools" class="swh-tab-content" style="display:none;">
            <h3>Automated Data Retention</h3>
            <form method="POST" action="">
                <?php wp_nonce_field( 'swh_save_settings_action', 'swh_settings_nonce' ); ?>
                <table class="form-table">
                    <tr>
                        <th scope="row">Purge Old Attachments</th>
                        <td>
                            <input type="number" name="swh_retention_attachments_days" value="<?php echo esc_attr( get_option( 'swh_retention_attachments_days', 0 ) ); ?>" style="width:80px;"> days
                            <p class="description">Automatically delete physical file attachments older than this many days to save server space. Links to the files will be safely removed from the ticket. Set to 0 to disable.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Purge Old Tickets</th>
                        <td>
                            <input type="number" name="swh_retention_tickets_days" value="<?php echo esc_attr( get_option( 'swh_retention_tickets_days', 0 ) ); ?>" style="width:80px;"> days
                            <p class="description">Automatically delete entire tickets (and their files) that haven't been updated in this many days. Set to 0 to disable.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Uninstallation Behavior</th>
                        <td>
                            <label>
                                <input type="checkbox" name="swh_delete_on_uninstall" value="yes" <?php checked( get_option( 'swh_delete_on_uninstall' ), 'yes' ); ?>>
                                Delete all Plugin Data when uninstalled
                            </label>
                            <p class="description">If checked, completely deleting this plugin from the WP Plugins screen will wipe all tickets, files, and settings. Leave unchecked to safely preserve data.</p>
                        </td>
                    </tr>
                </table>
                <p><input type="submit" name="swh_save_settings" class="button button-primary" value="Save Retention Settings"></p>
            </form>
            <hr>
            <div style="background: #f8d7da; border: 1px solid #f5c6cb; padding: 20px; border-radius: 5px; color: #721c24; margin-top: 20px;">
                <h3 style="margin-top:0;">Danger Zone (Manual Cleanup)</h3>
                <p>These manual actions are permanent and cannot be undone.</p>
                <form method="POST" action="">
                    <?php wp_nonce_field( 'swh_danger_action', 'swh_danger_nonce' ); ?>
                    <p>
                        <strong>GDPR / Client Data Purge:</strong> Deletes all tickets, comments, and files associated with a specific email address.<br>
                        <input type="email" name="swh_gdpr_email" placeholder="client@example.com" class="regular-text" style="margin-top:5px; margin-bottom:5px;"><br>
                        <button type="submit" name="swh_gdpr_delete" class="button" onclick="return confirm('Are you sure you want to delete all data for this email?');">Delete Client Data</button>
                    </p>
                    <hr style="border-color:#f5c6cb;">
                    <p>
                        <strong>Purge ALL Tickets:</strong> Deletes all helpdesk tickets, conversation history, and associated file uploads for EVERYONE.<br>
                        <button type="submit" name="swh_purge_tickets" class="button" style="margin-top:5px;" onclick="return confirm('Are you sure you want to PURGE ALL TICKETS? This cannot be undone.');">Purge All Tickets</button>
                    </p>
                    <hr style="border-color:#f5c6cb;">
                    <p>
                        <strong>Factory Reset:</strong> Purges all tickets AND resets all plugin settings back to original defaults.<br>
                        <button type="submit" name="swh_factory_reset" class="button button-primary" style="background:#d63638; border-color:#d63638; margin-top:5px;" onclick="return confirm('Are you sure you want to FACTORY RESET the plugin? This cannot be undone.');">Factory Reset Plugin</button>
                    </p>
                </form>
            </div>
        </div>
    </div>
    
    <script>
    document.addEventListener('DOMContentLoaded', function() {
        var tabs = document.querySelectorAll('.nav-tab');
        var contents = document.querySelectorAll('.swh-tab-content');
        var saveBtn = document.getElementById('save-btn-container');
        tabs.forEach(function(tab) {
            tab.addEventListener('click', function(e) {
                e.preventDefault();
                tabs.forEach(t => t.classList.remove('nav-tab-active'));
                contents.forEach(c => c.style.display = 'none');
                tab.classList.add('nav-tab-active');
                document.getElementById(tab.dataset.tab).style.display = 'block';
                saveBtn.style.display = tab.dataset.tab === 'tab-tools' ? 'none' : 'block';
            });
        });
        document.querySelectorAll('.swh-reset-field').forEach(function(btn) {
            btn.addEventListener('click', function(e) {
                e.preventDefault();
                var target = this.previousElementSibling.previousElementSibling;
                target.value = target.getAttribute('data-default');
            });
        });
    });
    </script>
    <?php
}

// ==============================================================================
// 5. ADMIN DASHBOARD - TICKET EDITOR UI & LOGIC
// ==============================================================================
add_action( 'add_meta_boxes', 'swh_add_ticket_meta_boxes' );
function swh_add_ticket_meta_boxes() {
    add_meta_box( 'swh_ticket_status', 'Ticket Details', 'swh_status_meta_box_html', 'helpdesk_ticket', 'side', 'high' );
    add_meta_box( 'swh_ticket_conversation', 'Conversation & Reply', 'swh_conversation_meta_box_html', 'helpdesk_ticket', 'normal', 'high' );
}

function swh_status_meta_box_html( $post ) {
    $defs     = swh_get_defaults();
    $uid      = get_post_meta( $post->ID, '_ticket_uid', true );
    $status   = get_post_meta( $post->ID, '_ticket_status', true ) ?: get_option( 'swh_default_status', $defs['swh_default_status'] );
    $priority = get_post_meta( $post->ID, '_ticket_priority', true ) ?: get_option( 'swh_default_priority', $defs['swh_default_priority'] );
    $assignee = get_post_meta( $post->ID, '_ticket_assigned_to', true );
    $name     = get_post_meta( $post->ID, '_ticket_name', true ) ?: 'Unknown User';
    $email    = get_post_meta( $post->ID, '_ticket_email', true );
    
    $statuses   = swh_get_statuses();
    $priorities = swh_get_priorities();
    $techs      = get_users( array( 'role__in' => array( 'administrator', 'technician' ) ) );
    
    if ( $status && ! in_array( $status, $statuses, true ) ) {
        $statuses[] = $status;
    }
    if ( $priority && ! in_array( $priority, $priorities, true ) ) {
        $priorities[] = $priority;
    }
    wp_nonce_field( 'swh_save_ticket', 'swh_ticket_nonce' );
    ?>
    <div style="font-size: 16px; font-weight: bold; background: #f0f0f1; padding: 10px; text-align: center; margin-bottom: 15px;">ID: <?php echo esc_html( $uid ); ?></div>
    <p><strong>Submitted By:</strong><br><?php echo esc_html( $name ); ?><br><a href="mailto:<?php echo esc_attr( $email ); ?>"><?php echo esc_html( $email ); ?></a></p>
    <?php
    $main_attachments  = get_post_meta( $post->ID, '_ticket_attachments', true ) ?: array();
    $comments          = get_comments(
        array(
            'post_id' => $post->ID,
            'order'   => 'ASC',
        )
    );
    $reply_attachments = array();
    foreach ( $comments as $c ) {
        $atts = get_comment_meta( $c->comment_ID, '_attachments', true );
        if ( ! empty( $atts ) && is_array( $atts ) ) {
            $reply_attachments = array_merge( $reply_attachments, $atts );
        }
    }
    $all_attachments = array_merge( $main_attachments, $reply_attachments );
    if ( ! empty( $all_attachments ) ) :
    ?>
        <p><strong>All Attachments:</strong><br>
        <?php foreach ( $all_attachments as $i => $url ) : ?>
            <a href="<?php echo esc_url( $url ); ?>" target="_blank" class="button button-secondary button-small" style="margin-top:5px; margin-right:5px;">File <?php echo esc_html( $i + 1 ); ?></a>
        <?php endforeach; ?></p>
    <?php endif; ?>
    <hr>
    <p><strong>Assigned To:</strong></p>
    <select name="ticket_assigned_to" style="width: 100%; margin-bottom: 10px;">
        <option value="">-- Unassigned --</option>
        <?php foreach ( $techs as $t ) : ?>
            <option value="<?php echo esc_attr( $t->ID ); ?>" <?php selected( $assignee, $t->ID ); ?>><?php echo esc_html( $t->display_name ); ?></option>
        <?php endforeach; ?>
    </select>
    <p><strong>Priority:</strong></p>
    <select name="ticket_priority" style="width: 100%; margin-bottom: 10px;">
        <?php foreach ( $priorities as $p ) : ?>
            <option value="<?php echo esc_attr( $p ); ?>" <?php selected( $priority, $p ); ?>><?php echo esc_html( $p ); ?></option>
        <?php endforeach; ?>
    </select>
    <p><strong>Status:</strong></p>
    <select name="ticket_status" style="width: 100%;">
        <?php foreach ( $statuses as $s ) : ?>
            <option value="<?php echo esc_attr( $s ); ?>" <?php selected( $status, $s ); ?>><?php echo esc_html( $s ); ?></option>
        <?php endforeach; ?>
    </select>
    <?php
}

function swh_conversation_meta_box_html( $post ) {
    $comments = get_comments(
        array(
            'post_id' => $post->ID,
            'order'   => 'ASC',
        )
    );
    echo '<div style="max-height: 400px; overflow-y: auto; background: #fff; padding: 15px; border: 1px solid #ddd; margin-bottom: 20px;">';
    if ( $comments ) {
        foreach ( $comments as $comment ) {
            $is_internal = get_comment_meta( $comment->comment_ID, '_is_internal_note', true );
            $is_user     = get_comment_meta( $comment->comment_ID, '_is_user_reply', true );
            
            if ( $is_internal ) {
                $author_name = 'Internal Note (' . esc_html( $comment->comment_author ) . ')';
                $bg_color    = '#fff3cd';
                $border      = '#ffeeba';
            } else {
                $author_name = $is_user ? 'Client (' . esc_html( $comment->comment_author ) . ')' : 'Technician (' . esc_html( $comment->comment_author ) . ')';
                $bg_color    = $is_user ? '#f9f9f9' : '#e6f7ff';
                $border      = '#0073aa';
            }
            
            echo '<div style="background: ' . esc_attr( $bg_color ) . '; padding: 10px 15px; margin-bottom: 10px; border-left: 4px solid ' . esc_attr( $border ) . '; border-radius: 3px;">';
            echo '<strong style="display:block; margin-bottom: 5px;">' . esc_html( $author_name ) . ' <span style="font-weight:normal; font-size: 0.8em; color: #666;">(' . esc_html( $comment->comment_date ) . ')</span></strong>';
            echo nl2br( esc_html( $comment->comment_content ) );
            
            $attachments = get_comment_meta( $comment->comment_ID, '_attachments', true );
            if ( ! empty( $attachments ) && is_array( $attachments ) ) {
                echo '<div style="margin-top: 10px;">';
                foreach ( $attachments as $i => $url ) {
                    echo '<a href="' . esc_url( $url ) . '" target="_blank" class="button button-small" style="margin-right:5px;">Attachment ' . esc_html( $i + 1 ) . '</a>';
                }
                echo '</div>';
            }
            echo '</div>';
        }
    } else {
        echo '<p style="color: #666; font-style: italic;">No replies yet. Use the boxes below to start the conversation.</p>';
    }
    echo '</div>';
    ?>
    <div style="display:flex; gap: 20px;">
        <div style="flex:1;">
            <h4 style="margin-top:0;">Add a Public Reply</h4>
            <p style="font-size:12px;">This will be emailed to the client.</p>
            <textarea name="swh_tech_reply_text" style="width: 100%;" rows="5" placeholder="Type reply here..."></textarea>
            <p><strong>Attach Files (Optional):</strong><br>
            <input type="file" name="swh_tech_reply_attachments[]" multiple accept=".jpg,.jpeg,.png,.gif,.pdf,.doc,.docx,.txt">
            <br><small style="color:#666;">Allowed file types: JPG, JPEG, PNG, GIF, PDF, DOC, DOCX, TXT.</small>
            </p>
        </div>
        <div style="flex:1; background: #fff3cd; padding: 15px; border-radius: 5px; border: 1px solid #ffeeba;">
            <h4 style="margin-top:0; color: #856404;">Add Internal Note</h4>
            <p style="font-size:12px; color: #856404;">Hidden from client. For staff only.</p>
            <textarea name="swh_tech_note_text" style="width: 100%;" rows="5" placeholder="Type private note here..."></textarea>
        </div>
    </div>
    <p class="description">Click the <strong>Update</strong> button on the top right to save the ticket.</p>
    <?php
}

add_action( 'save_post_helpdesk_ticket', 'swh_save_ticket_data', 10, 3 );
function swh_save_ticket_data( $post_id, $post, $update ) {
    if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
        return;
    }
    if ( ! isset( $_POST['swh_ticket_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['swh_ticket_nonce'] ) ), 'swh_save_ticket' ) ) {
        return;
    }
    if ( ! current_user_can( 'edit_post', $post_id ) ) {
        return;
    }

    $defs         = swh_get_defaults();
    $old_status   = get_post_meta( $post_id, '_ticket_status', true );
    $new_status   = isset( $_POST['ticket_status'] ) ? sanitize_text_field( wp_unslash( $_POST['ticket_status'] ) ) : '';
    $new_priority = isset( $_POST['ticket_priority'] ) ? sanitize_text_field( wp_unslash( $_POST['ticket_priority'] ) ) : '';
    $assigned_to  = isset( $_POST['ticket_assigned_to'] ) ? sanitize_text_field( wp_unslash( $_POST['ticket_assigned_to'] ) ) : '';
    
    update_post_meta( $post_id, '_ticket_status', $new_status );
    update_post_meta( $post_id, '_ticket_priority', $new_priority );
    update_post_meta( $post_id, '_ticket_assigned_to', $assigned_to );
    
    $resolved_status = get_option( 'swh_resolved_status', $defs['swh_resolved_status'] );
    if ( $resolved_status === $new_status && $old_status !== $new_status ) {
        update_post_meta( $post_id, '_resolved_timestamp', time() );
    } elseif ( $resolved_status === $old_status && $resolved_status !== $new_status ) {
        delete_post_meta( $post_id, '_resolved_timestamp' );
    }
    
    $data = array(
        'name'           => get_post_meta( $post_id, '_ticket_name', true ) ?: 'Client',
        'email'          => get_post_meta( $post_id, '_ticket_email', true ),
        'ticket_id'      => get_post_meta( $post_id, '_ticket_uid', true ),
        'title'          => $post->post_title,
        'status'         => $new_status,
        'priority'       => $new_priority,
        'autoclose_days' => get_option( 'swh_autoclose_days', 3 ),
        'ticket_url'     => swh_get_secure_ticket_link( $post_id ),
        'admin_url'      => admin_url( 'post.php?post=' . $post_id . '&action=edit' ),
        'message'        => '',
    );
    
    $current_user = wp_get_current_user();
    
    if ( ! empty( $_POST['swh_tech_note_text'] ) ) {
        $note_text  = sanitize_textarea_field( wp_unslash( $_POST['swh_tech_note_text'] ) );
        $comment_id = wp_insert_comment(
            array(
                'comment_post_ID'      => $post_id,
                'comment_author'       => $current_user->display_name,
                'comment_author_email' => $current_user->user_email,
                'comment_content'      => $note_text,
                'comment_approved'     => 1,
            )
        );
        update_comment_meta( $comment_id, '_is_internal_note', '1' );
    }
    
    $just_replied = false;
    $attach_urls  = array();
    $reply_text   = isset( $_POST['swh_tech_reply_text'] ) ? sanitize_textarea_field( wp_unslash( $_POST['swh_tech_reply_text'] ) ) : '';
    
    // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
    $has_files = ! empty( $_FILES['swh_tech_reply_attachments']['name'][0] );
    
    if ( $reply_text || $has_files ) {
        $just_replied    = true;
        $comment_content = $reply_text ?: 'Attached file(s)';
        $comment_id      = wp_insert_comment(
            array(
                'comment_post_ID'      => $post_id,
                'comment_author'       => $current_user->display_name,
                'comment_author_email' => $current_user->user_email,
                'comment_content'      => $comment_content,
                'comment_approved'     => 1,
            )
        );
        update_comment_meta( $comment_id, '_is_user_reply', '0' );
        
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
        $attach_urls = swh_handle_multiple_uploads( $_FILES['swh_tech_reply_attachments'] );
        if ( ! empty( $attach_urls ) ) {
            update_comment_meta( $comment_id, '_attachments', $attach_urls );
        }
        $data['message'] = $reply_text ?: 'Attached file(s)';
    }
    
    $status_changed = ( $update && $old_status !== $new_status );
    $is_resolving   = ( $resolved_status === $new_status );
    
    if ( $data['email'] ) {
        if ( $just_replied && $status_changed && $is_resolving ) {
            $subject = swh_parse_template( get_option( 'swh_em_user_resolved_sub', $defs['swh_em_user_resolved_sub'] ), $data );
            $message = swh_parse_template( get_option( 'swh_em_user_resolved_body', $defs['swh_em_user_resolved_body'] ), $data );
            if ( ! empty( $attach_urls ) ) {
                $message .= "\n\nAttachments: \n" . implode( "\n", $attach_urls );
            }
            wp_mail( $data['email'], $subject, $message );
        } elseif ( $just_replied && $status_changed ) {
            $subject = swh_parse_template( get_option( 'swh_em_user_reply_status_sub', $defs['swh_em_user_reply_status_sub'] ), $data );
            $message = swh_parse_template( get_option( 'swh_em_user_reply_status_body', $defs['swh_em_user_reply_status_body'] ), $data );
            if ( ! empty( $attach_urls ) ) {
                $message .= "\n\nAttachments: \n" . implode( "\n", $attach_urls );
            }
            wp_mail( $data['email'], $subject, $message );
        } elseif ( $just_replied ) {
            $subject = swh_parse_template( get_option( 'swh_em_user_reply_sub', $defs['swh_em_user_reply_sub'] ), $data );
            $message = swh_parse_template( get_option( 'swh_em_user_reply_body', $defs['swh_em_user_reply_body'] ), $data );
            if ( ! empty( $attach_urls ) ) {
                $message .= "\n\nAttachments: \n" . implode( "\n", $attach_urls );
            }
            wp_mail( $data['email'], $subject, $message );
        } elseif ( $status_changed ) {
            $data['message'] = 'No additional notes provided.';
            if ( $is_resolving ) {
                $subject = swh_parse_template( get_option( 'swh_em_user_resolved_sub', $defs['swh_em_user_resolved_sub'] ), $data );
                $message = swh_parse_template( get_option( 'swh_em_user_resolved_body', $defs['swh_em_user_resolved_body'] ), $data );
            } elseif ( $new_status === get_option( 'swh_reopened_status', $defs['swh_reopened_status'] ) && $old_status === get_option( 'swh_closed_status', $defs['swh_closed_status'] ) ) {
                $subject = swh_parse_template( get_option( 'swh_em_user_reopen_sub', $defs['swh_em_user_reopen_sub'] ), $data );
                $message = swh_parse_template( get_option( 'swh_em_user_reopen_body', $defs['swh_em_user_reopen_body'] ), $data );
            } else {
                $subject = swh_parse_template( get_option( 'swh_em_user_status_sub', $defs['swh_em_user_status_sub'] ), $data );
                $message = swh_parse_template( get_option( 'swh_em_user_status_body', $defs['swh_em_user_status_body'] ), $data );
            }
            wp_mail( $data['email'], $subject, $message );
        }
    }
}

// ==============================================================================
// 6. FRONT-END SHORTCODE [submit_ticket]
// ==============================================================================
add_shortcode( 'submit_ticket', 'swh_ticket_frontend' );
function swh_ticket_frontend() {
    ob_start();
    
    $defs            = swh_get_defaults();
    $closed_status   = get_option( 'swh_closed_status', $defs['swh_closed_status'] );
    $resolved_status = get_option( 'swh_resolved_status', $defs['swh_resolved_status'] );
    $reopened_status = get_option( 'swh_reopened_status', $defs['swh_reopened_status'] );
    $default_status  = get_option( 'swh_default_status', $defs['swh_default_status'] );
    $priorities      = swh_get_priorities();
    $default_prio    = get_option( 'swh_default_priority', $defs['swh_default_priority'] );
    $spam_method     = get_option( 'swh_spam_method', 'none' );
    ?>
    <style>
    .swh-helpdesk-wrapper { max-width: 800px; margin: 0 auto; text-align: left !important; font-family: inherit; line-height: 1.6; color: #333; }
    .swh-helpdesk-wrapper * { box-sizing: border-box; }
    .swh-form-group { margin-bottom: 15px; text-align: left !important; }
    .swh-form-group label { display: block; font-weight: 600; margin-bottom: 5px; color: #333; }
    .swh-form-control { width: 100%; max-width: 100%; padding: 10px; border: 1px solid #ccc; border-radius: 4px; font-family: inherit; font-size: 15px; background: #fff; color:#333; }
    .swh-form-control:focus { border-color: #0073aa; outline: none; box-shadow: 0 0 3px rgba(0,115,170,.3); }
    .swh-btn { padding: 10px 20px; background-color: #0073aa; color: #fff; border: none; border-radius: 4px; cursor: pointer; font-size: 15px; font-weight: 600; display: inline-block; text-decoration: none; transition: background 0.2s; }
    .swh-btn:hover { background-color: #005177; color: #fff; }
    .swh-btn-danger { background-color: #dc3232; }
    .swh-btn-danger:hover { background-color: #a02222; }
    .swh-alert { padding: 15px; border-radius: 4px; margin-bottom: 20px; font-weight: 500; border: 1px solid transparent; }
    .swh-alert-success { background-color: #d4edda; border-color: #c3e6cb; color: #155724; }
    .swh-alert-error { background-color: #f8d7da; border-color: #f5c6cb; color: #721c24; }
    .swh-alert-info { background-color: #e6f7ff; border-color: #b3e0ff; color: #005980; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 15px; }
    .swh-card { border: 1px solid #ddd; padding: 20px; border-radius: 5px; margin-bottom: 20px; background: #fff; }
    .swh-badge { padding: 4px 8px; border-radius: 3px; font-size: 12px; font-weight: 600; display: inline-block; }
    .swh-badge-closed { background-color: #f8d7da; color: #721c24; }
    .swh-badge-open { background-color: #d4edda; color: #155724; }
    .swh-chat-bubble { padding: 15px; margin-bottom: 15px; border-radius: 4px; border-left: 4px solid #0073aa; }
    .swh-chat-user { background-color: #f9f9f9; border-color: #aaa; }
    .swh-chat-tech { background-color: #e6f7ff; border-color: #0073aa; }
    </style>
    <div class="swh-helpdesk-wrapper">
    <?php
    if ( isset( $_GET['swh_ticket'], $_GET['token'] ) ) {
        $ticket_id = intval( $_GET['swh_ticket'] );
        $token     = sanitize_text_field( wp_unslash( $_GET['token'] ) );
        $post      = get_post( $ticket_id );
        $db_token  = get_post_meta( $ticket_id, '_ticket_token', true );
        
        if ( ! $post || 'helpdesk_ticket' !== $post->post_type || ! hash_equals( $db_token, $token ) ) {
            echo '<div class="swh-alert swh-alert-error">' . esc_html( get_option( 'swh_msg_err_invalid', $defs['swh_msg_err_invalid'] ) ) . '</div></div>';
            return ob_get_clean();
        }
        
        $data = array(
            'name'       => get_post_meta( $ticket_id, '_ticket_name', true ) ?: 'Client',
            'email'      => get_post_meta( $ticket_id, '_ticket_email', true ),
            'ticket_id'  => get_post_meta( $ticket_id, '_ticket_uid', true ),
            'title'      => $post->post_title,
            'status'     => get_post_meta( $ticket_id, '_ticket_status', true ),
            'priority'   => get_post_meta( $ticket_id, '_ticket_priority', true ),
            'ticket_url' => swh_get_secure_ticket_link( $ticket_id ),
            'admin_url'  => admin_url( 'post.php?post=' . $ticket_id . '&action=edit' ),
            'message'    => '',
        );
        
        if ( isset( $_POST['swh_user_close_ticket_submit'], $_POST['swh_close_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['swh_close_nonce'] ) ), 'swh_user_close' ) ) {
            update_post_meta( $ticket_id, '_ticket_status', $closed_status );
            delete_post_meta( $ticket_id, '_resolved_timestamp' );
            $comment_id = wp_insert_comment(
                array(
                    'comment_post_ID'      => $ticket_id,
                    'comment_author'       => $data['name'],
                    'comment_author_email' => $data['email'],
                    'comment_content'      => 'TICKET CLOSED BY CLIENT',
                    'comment_approved'     => 1,
                )
            );
            update_comment_meta( $comment_id, '_is_user_reply', '1' );
            $admin_email = swh_get_admin_email( $ticket_id );
            $subject     = swh_parse_template( get_option( 'swh_em_admin_closed_sub', $defs['swh_em_admin_closed_sub'] ), $data );
            $message     = swh_parse_template( get_option( 'swh_em_admin_closed_body', $defs['swh_em_admin_closed_body'] ), $data );
            wp_mail( $admin_email, $subject, $message );
            $u_subject = swh_parse_template( get_option( 'swh_em_user_closed_sub', $defs['swh_em_user_closed_sub'] ), $data );
            $u_message = swh_parse_template( get_option( 'swh_em_user_closed_body', $defs['swh_em_user_closed_body'] ), $data );
            wp_mail( $data['email'], $u_subject, $u_message );
            echo '<div class="swh-alert swh-alert-success">' . esc_html( get_option( 'swh_msg_success_closed', $defs['swh_msg_success_closed'] ) ) . '</div>';
            $data['status'] = $closed_status;
        }
        
        if ( isset( $_POST['swh_user_reopen_submit'], $_POST['swh_reopen_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['swh_reopen_nonce'] ) ), 'swh_user_reopen' ) ) {
            $reply_text = isset( $_POST['ticket_reopen_text'] ) ? sanitize_textarea_field( wp_unslash( $_POST['ticket_reopen_text'] ) ) : '';
            // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
            $has_files = ! empty( $_FILES['swh_reopen_attachments']['name'][0] );
            if ( $reply_text || $has_files ) {
                update_post_meta( $ticket_id, '_ticket_status', $reopened_status );
                delete_post_meta( $ticket_id, '_resolved_timestamp' );
                $comment_content = $reply_text ?: 'Attached file(s)';
                $comment_id      = wp_insert_comment(
                    array(
                        'comment_post_ID'      => $ticket_id,
                        'comment_author'       => $data['name'],
                        'comment_author_email' => $data['email'],
                        'comment_content'      => "TICKET RE-OPENED: \n" . $comment_content,
                        'comment_approved'     => 1,
                    )
                );
                update_comment_meta( $comment_id, '_is_user_reply', '1' );
                // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
                $attach_urls = swh_handle_multiple_uploads( $_FILES['swh_reopen_attachments'] );
                if ( ! empty( $attach_urls ) ) {
                    update_comment_meta( $comment_id, '_attachments', $attach_urls );
                }
                $data['message'] = $reply_text ?: 'Attached file(s)';
                $admin_email = swh_get_admin_email( $ticket_id );
                $subject     = swh_parse_template( get_option( 'swh_em_admin_reopen_sub', $defs['swh_em_admin_reopen_sub'] ), $data );
                $message     = swh_parse_template( get_option( 'swh_em_admin_reopen_body', $defs['swh_em_admin_reopen_body'] ), $data );
                if ( ! empty( $attach_urls ) ) {
                    $message .= "\n\nAttachments:\n" . implode( "\n", $attach_urls );
                }
                wp_mail( $admin_email, $subject, $message );
                $u_subject = swh_parse_template( get_option( 'swh_em_user_reopen_sub', $defs['swh_em_user_reopen_sub'] ), $data );
                $u_message = swh_parse_template( get_option( 'swh_em_user_reopen_body', $defs['swh_em_user_reopen_body'] ), $data );
                wp_mail( $data['email'], $u_subject, $u_message );
                echo '<div class="swh-alert swh-alert-success">' . esc_html( get_option( 'swh_msg_success_reopen', $defs['swh_msg_success_reopen'] ) ) . '</div>';
                $data['status'] = $reopened_status;
            }
        }
        
        if ( isset( $_POST['swh_user_reply_submit'], $_POST['swh_reply_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['swh_reply_nonce'] ) ), 'swh_user_reply' ) ) {
            $reply_text = isset( $_POST['ticket_reply_text'] ) ? sanitize_textarea_field( wp_unslash( $_POST['ticket_reply_text'] ) ) : '';
            // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
            $has_files = ! empty( $_FILES['swh_user_reply_attachments']['name'][0] );
            if ( $reply_text || $has_files ) {
                if ( $resolved_status === $data['status'] ) {
                    $data['status'] = $reopened_status;
                    update_post_meta( $ticket_id, '_ticket_status', $reopened_status );
                    delete_post_meta( $ticket_id, '_resolved_timestamp' );
                }
                $comment_content = $reply_text ?: 'Attached file(s)';
                $comment_id      = wp_insert_comment(
                    array(
                        'comment_post_ID'      => $ticket_id,
                        'comment_author'       => $data['name'],
                        'comment_author_email' => $data['email'],
                        'comment_content'      => $comment_content,
                        'comment_approved'     => 1,
                    )
                );
                update_comment_meta( $comment_id, '_is_user_reply', '1' );
                // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
                $attach_urls = swh_handle_multiple_uploads( $_FILES['swh_user_reply_attachments'] );
                if ( ! empty( $attach_urls ) ) {
                    update_comment_meta( $comment_id, '_attachments', $attach_urls );
                }
                $data['message'] = $reply_text ?: 'Attached file(s)';
                $admin_email = swh_get_admin_email( $ticket_id );
                $subject     = swh_parse_template( get_option( 'swh_em_admin_reply_sub', $defs['swh_em_admin_reply_sub'] ), $data );
                $message     = swh_parse_template( get_option( 'swh_em_admin_reply_body', $defs['swh_em_admin_reply_body'] ), $data );
                if ( ! empty( $attach_urls ) ) {
                    $message .= "\n\nAttachments:\n" . implode( "\n", $attach_urls );
                }
                wp_mail( $admin_email, $subject, $message );
                echo '<div class="swh-alert swh-alert-success">' . esc_html( get_option( 'swh_msg_success_reply', $defs['swh_msg_success_reply'] ) ) . '</div>';
            }
        }
        ?>
        <div class="swh-card">
            <div style="float: right; font-weight: bold; color: #666; font-size: 1.2em;"><?php echo esc_html( $data['ticket_id'] ); ?></div>
            <h3 style="margin-top:0; font-size: 22px; color: #222;"><?php echo esc_html( $data['title'] ); ?></h3>
            <p style="margin: 0 0 15px 0;"><strong>Status:</strong> <span class="swh-badge <?php echo ( $closed_status === $data['status'] ) ? 'swh-badge-closed' : 'swh-badge-open'; ?>"><?php echo esc_html( $data['status'] ); ?></span>
            &nbsp;|&nbsp; <strong>Priority:</strong> <?php echo esc_html( $data['priority'] ); ?></p>
            <hr>
            <p><?php echo nl2br( esc_html( $post->post_content ) ); ?></p>
            <?php
            $attachments = get_post_meta( $ticket_id, '_ticket_attachments', true );
            if ( ! empty( $attachments ) && is_array( $attachments ) ) {
                echo '<p><strong>Attachments:</strong><br>';
                foreach ( $attachments as $i => $url ) {
                    echo '<a href="' . esc_url( $url ) . '" target="_blank" style="text-decoration: underline; margin-right:10px; color:#0073aa;">File ' . esc_html( $i + 1 ) . '</a>';
                }
                echo '</p>';
            }
            ?>
        </div>
        <h4 style="margin-bottom: 15px;">Conversation History</h4>
        <div style="margin-bottom: 20px;">
        <?php
        $comments = get_comments(
            array(
                'post_id' => $ticket_id,
                'order'   => 'ASC',
            )
        );
        if ( $comments ) {
            foreach ( $comments as $comment ) {
                if ( get_comment_meta( $comment->comment_ID, '_is_internal_note', true ) ) {
                    continue;
                }
                $is_user      = get_comment_meta( $comment->comment_ID, '_is_user_reply', true );
                $author_name  = $is_user ? 'You' : 'Technician (' . $comment->comment_author . ')';
                $bubble_class = $is_user ? 'swh-chat-user' : 'swh-chat-tech';
                $attach_urls  = get_comment_meta( $comment->comment_ID, '_attachments', true );
                
                echo '<div class="swh-chat-bubble ' . esc_attr( $bubble_class ) . '">';
                echo '<strong style="display:block; margin-bottom: 5px;">' . esc_html( $author_name ) . ' <span style="font-weight:normal; font-size: 0.85em; color: #777;">(' . esc_html( $comment->comment_date ) . ')</span></strong>';
                echo nl2br( esc_html( $comment->comment_content ) );
                if ( ! empty( $attach_urls ) && is_array( $attach_urls ) ) {
                    echo '<div style="margin-top: 10px;">';
                    foreach ( $attach_urls as $i => $url ) {
                        echo '<a href="' . esc_url( $url ) . '" target="_blank" style="text-decoration: underline; margin-right:10px; color:#0073aa; font-size:13px;">Attachment ' . esc_html( $i + 1 ) . '</a>';
                    }
                    echo '</div>';
                }
                echo '</div>';
            }
        } else {
            echo '<p>No replies yet.</p>';
        }
        ?>
        </div>
        <?php if ( $resolved_status === $data['status'] ) : ?>
            <div class="swh-alert swh-alert-info">
                <div>
                    <h4 style="margin: 0 0 5px 0; color: #005980;">Is your issue fully resolved?</h4>
                    <p style="margin: 0; font-size: 13px; color: #005980;">Click the button to close this ticket, or use the form below to reply if you still need help.</p>
                </div>
                <form method="POST" action="" style="margin:0;">
                    <?php wp_nonce_field( 'swh_user_close', 'swh_close_nonce' ); ?>
                    <input type="submit" name="swh_user_close_ticket_submit" value="Yes, Close Ticket" class="swh-btn">
                </form>
            </div>
        <?php endif; ?>
        <?php if ( $closed_status !== $data['status'] ) : ?>
            <form method="POST" action="" enctype="multipart/form-data">
                <?php wp_nonce_field( 'swh_user_reply', 'swh_reply_nonce' ); ?>
                <div class="swh-form-group">
                    <label>Add a Reply:</label>
                    <textarea name="ticket_reply_text" rows="4" class="swh-form-control"></textarea>
                </div>
                <div class="swh-form-group">
                    <label>Attach Files (Optional):</label>
                    <input type="file" name="swh_user_reply_attachments[]" multiple accept=".jpg,.jpeg,.png,.gif,.pdf,.doc,.docx,.txt" class="swh-form-control swh-file-input">
                    <small style="color:#666; display:block; margin-top:5px;">Allowed file types: JPG, JPEG, PNG, GIF, PDF, DOC, DOCX, TXT. Max size: <?php echo esc_html( get_option( 'swh_max_upload_size', 5 ) ); ?>MB per file.</small>
                </div>
                <div class="swh-form-group">
                    <input type="submit" name="swh_user_reply_submit" value="Send Reply" class="swh-btn">
                </div>
            </form>
        <?php else : ?>
            <div class="swh-alert swh-alert-error">
                <p style="margin-top: 0; font-weight: bold;">This ticket is closed.</p>
                <form method="POST" action="" enctype="multipart/form-data">
                    <?php wp_nonce_field( 'swh_user_reopen', 'swh_reopen_nonce' ); ?>
                    <div class="swh-form-group">
                        <label>Explain why you need this re-opened:</label>
                        <textarea name="ticket_reopen_text" rows="3" class="swh-form-control"></textarea>
                    </div>
                    <div class="swh-form-group">
                        <label>Attach Files (Optional):</label>
                        <input type="file" name="swh_reopen_attachments[]" multiple accept=".jpg,.jpeg,.png,.gif,.pdf,.doc,.docx,.txt" class="swh-form-control swh-file-input">
                        <small style="color:#721c24; display:block; margin-top:5px;">Allowed file types: JPG, JPEG, PNG, GIF, PDF, DOC, DOCX, TXT. Max size: <?php echo esc_html( get_option( 'swh_max_upload_size', 5 ) ); ?>MB.</small>
                    </div>
                    <div class="swh-form-group">
                        <input type="submit" name="swh_user_reopen_submit" value="Re-open Ticket" class="swh-btn swh-btn-danger">
                    </div>
                </form>
            </div>
        <?php endif; ?>
        </div> <!-- End .swh-helpdesk-wrapper -->
        <?php
        swh_render_js_validation();
        return ob_get_clean();
    }
    
    $current_user = wp_get_current_user();
    $form_name    = is_user_logged_in() ? $current_user->display_name : '';
    $form_email   = is_user_logged_in() ? $current_user->user_email : '';
    $form_prio    = $default_prio;
    $form_title   = '';
    $form_desc    = '';
    
    if ( isset( $_POST['swh_submit_ticket'], $_POST['swh_ticket_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['swh_ticket_nonce'] ) ), 'swh_create_ticket' ) ) {
        $data = array(
            'name'     => isset( $_POST['ticket_name'] ) ? sanitize_text_field( wp_unslash( $_POST['ticket_name'] ) ) : '',
            'email'    => isset( $_POST['ticket_email'] ) ? sanitize_email( wp_unslash( $_POST['ticket_email'] ) ) : '',
            'title'    => isset( $_POST['ticket_title'] ) ? sanitize_text_field( wp_unslash( $_POST['ticket_title'] ) ) : '',
            'message'  => isset( $_POST['ticket_desc'] ) ? sanitize_textarea_field( wp_unslash( $_POST['ticket_desc'] ) ) : '',
            'priority' => isset( $_POST['ticket_priority'] ) ? sanitize_text_field( wp_unslash( $_POST['ticket_priority'] ) ) : '',
            'status'   => $default_status,
        );
        $is_spam = false;
        if ( 'honeypot' === $spam_method && ! empty( $_POST['swh_website_url_hp'] ) ) {
            $is_spam = true;
        } elseif ( 'recaptcha' === $spam_method ) {
            $resp = wp_remote_post(
                'https://www.google.com/recaptcha/api/siteverify',
                array(
                    'body'    => array(
                        'secret'   => get_option( 'swh_recaptcha_secret_key' ),
                        'response' => isset( $_POST['g-recaptcha-response'] ) ? sanitize_text_field( wp_unslash( $_POST['g-recaptcha-response'] ) ) : '',
                    ),
                    'timeout' => 10,
                )
            );
            $json = json_decode( wp_remote_retrieve_body( $resp ) );
            if ( empty( $json->success ) ) {
                $is_spam = true;
            }
        } elseif ( 'turnstile' === $spam_method ) {
            $resp = wp_remote_post(
                'https://challenges.cloudflare.com/turnstile/v0/siteverify',
                array(
                    'body'    => array(
                        'secret'   => get_option( 'swh_turnstile_secret_key' ),
                        'response' => isset( $_POST['cf-turnstile-response'] ) ? sanitize_text_field( wp_unslash( $_POST['cf-turnstile-response'] ) ) : '',
                    ),
                    'timeout' => 10,
                )
            );
            $json = json_decode( wp_remote_retrieve_body( $resp ) );
            if ( empty( $json->success ) ) {
                $is_spam = true;
            }
        }
        
        if ( $is_spam ) {
            echo '<div class="swh-alert swh-alert-error">' . esc_html( get_option( 'swh_msg_err_spam', $defs['swh_msg_err_spam'] ) ) . '</div>';
        } elseif ( $data['name'] && $data['title'] && $data['message'] && $data['email'] ) {
            $ticket_id = wp_insert_post(
                array(
                    'post_title'   => $data['title'],
                    'post_content' => $data['message'],
                    'post_type'    => 'helpdesk_ticket',
                    'post_status'  => 'publish',
                )
            );
            if ( $ticket_id ) {
                // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
                $attach_urls = swh_handle_multiple_uploads( $_FILES['ticket_attachments'] );
                if ( ! empty( $attach_urls ) ) {
                    update_post_meta( $ticket_id, '_ticket_attachments', $attach_urls );
                }
                $token              = wp_generate_password( 20, false );
                $data['ticket_id']  = 'TKT-' . str_pad( $ticket_id, 4, '0', STR_PAD_LEFT );
                $data['ticket_url'] = swh_get_secure_ticket_link( $ticket_id ) ?: add_query_arg(
                    array(
                        'swh_ticket' => $ticket_id,
                        'token'      => $token,
                    ),
                    get_permalink()
                );
                $data['admin_url']  = admin_url( 'post.php?post=' . $ticket_id . '&action=edit' );
                update_post_meta( $ticket_id, '_ticket_uid', $data['ticket_id'] );
                update_post_meta( $ticket_id, '_ticket_name', $data['name'] );
                update_post_meta( $ticket_id, '_ticket_email', $data['email'] );
                update_post_meta( $ticket_id, '_ticket_status', $data['status'] );
                update_post_meta( $ticket_id, '_ticket_priority', $data['priority'] );
                update_post_meta( $ticket_id, '_ticket_token', $token );
                update_post_meta( $ticket_id, '_ticket_url', get_permalink() );
                $default_assignee = get_option( 'swh_default_assignee' );
                if ( $default_assignee ) {
                    update_post_meta( $ticket_id, '_ticket_assigned_to', $default_assignee );
                }
                $subject = swh_parse_template( get_option( 'swh_em_user_new_sub', $defs['swh_em_user_new_sub'] ), $data );
                $message = swh_parse_template( get_option( 'swh_em_user_new_body', $defs['swh_em_user_new_body'] ), $data );
                wp_mail( $data['email'], $subject, $message );
                $admin_email = swh_get_admin_email( $ticket_id );
                $admin_sub   = swh_parse_template( get_option( 'swh_em_admin_new_sub', $defs['swh_em_admin_new_sub'] ), $data );
                $admin_msg   = swh_parse_template( get_option( 'swh_em_admin_new_body', $defs['swh_em_admin_new_body'] ), $data );
                if ( ! empty( $attach_urls ) ) {
                    $admin_msg .= "\n\nAttachments:\n" . implode( "\n", $attach_urls );
                }
                wp_mail( $admin_email, $admin_sub, $admin_msg );
                echo '<div class="swh-alert swh-alert-success">' . esc_html( get_option( 'swh_msg_success_new', $defs['swh_msg_success_new'] ) ) . '</div>';
            }
        } else {
            echo '<div class="swh-alert swh-alert-error">' . esc_html( get_option( 'swh_msg_err_missing', $defs['swh_msg_err_missing'] ) ) . '</div>';
        }
        if ( empty( $ticket_id ) ) {
            $form_name  = isset( $_POST['ticket_name'] ) ? sanitize_text_field( wp_unslash( $_POST['ticket_name'] ) ) : $form_name;
            $form_email = isset( $_POST['ticket_email'] ) ? sanitize_email( wp_unslash( $_POST['ticket_email'] ) ) : $form_email;
            $form_prio  = isset( $_POST['ticket_priority'] ) ? sanitize_text_field( wp_unslash( $_POST['ticket_priority'] ) ) : $form_prio;
            $form_title = isset( $_POST['ticket_title'] ) ? sanitize_text_field( wp_unslash( $_POST['ticket_title'] ) ) : '';
            $form_desc  = isset( $_POST['ticket_desc'] ) ? sanitize_textarea_field( wp_unslash( $_POST['ticket_desc'] ) ) : '';
        }
    }
    ?>
    <form method="POST" action="" enctype="multipart/form-data">
        <?php wp_nonce_field( 'swh_create_ticket', 'swh_ticket_nonce' ); ?>
        <div class="swh-form-group">
            <label>Your Name:</label>
            <input type="text" name="ticket_name" required class="swh-form-control" value="<?php echo esc_attr( $form_name ); ?>">
        </div>
        <div class="swh-form-group">
            <label>Your Email:</label>
            <input type="email" name="ticket_email" required class="swh-form-control" value="<?php echo esc_attr( $form_email ); ?>">
        </div>
        <div class="swh-form-group">
            <label>Priority:</label>
            <select name="ticket_priority" class="swh-form-control">
                <?php foreach ( $priorities as $p ) : ?>
                    <option value="<?php echo esc_attr( $p ); ?>" <?php selected( $form_prio, $p ); ?>><?php echo esc_html( $p ); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="swh-form-group">
            <label>Problem Summary (Title):</label>
            <input type="text" name="ticket_title" required class="swh-form-control" value="<?php echo esc_attr( $form_title ); ?>">
        </div>
        <div class="swh-form-group">
            <label>Problem Description:</label>
            <textarea name="ticket_desc" rows="5" required class="swh-form-control"><?php echo esc_textarea( $form_desc ); ?></textarea>
        </div>
        <div class="swh-form-group">
            <label>Attachments (Optional):</label>
            <input type="file" name="ticket_attachments[]" multiple accept=".jpg,.jpeg,.png,.gif,.pdf,.doc,.docx,.txt" class="swh-form-control swh-file-input" style="padding: 5px;">
            <small style="color:#666; display:block; margin-top:5px;">Allowed file types: JPG, JPEG, PNG, GIF, PDF, DOC, DOCX, TXT. Max size: <?php echo esc_html( get_option( 'swh_max_upload_size', 5 ) ); ?>MB.</small>
        </div>
        <?php
        // EXPLICIT RENDERING FOR ANTI-SPAM
        if ( 'honeypot' === $spam_method ) {
            echo '<div style="position: absolute; left: -9999px;"><label>Leave this empty</label><input type="text" name="swh_website_url_hp" value="" tabindex="-1" autocomplete="off"></div>';
        } elseif ( 'recaptcha' === $spam_method ) {
            $key = get_option( 'swh_recaptcha_site_key' );
            echo '<div id="swh-recaptcha-box" style="margin-bottom: 15px;"></div>';
            echo '<script> window.swhRecaptchaLoad = function() { if(document.getElementById("swh-recaptcha-box") && window.grecaptcha) { grecaptcha.render("swh-recaptcha-box", {"sitekey": "' . esc_js( $key ) . '"}); } }; </script>';
            // phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedScript
            echo '<script src="https://www.google.com/recaptcha/api.js?onload=swhRecaptchaLoad&render=explicit" async defer></script>';
        } elseif ( 'turnstile' === $spam_method ) {
            $key = get_option( 'swh_turnstile_site_key' );
            echo '<div id="swh-turnstile-box" style="margin-bottom: 15px;"></div>';
            echo '<script> window.swhTurnstileLoad = function() { if(document.getElementById("swh-turnstile-box") && window.turnstile) { turnstile.render("#swh-turnstile-box", {sitekey: "' . esc_js( $key ) . '"}); } }; </script>';
            // phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedScript
            echo '<script src="https://challenges.cloudflare.com/turnstile/v0/api.js?onload=swhTurnstileLoad&render=explicit" async defer></script>';
        }
        ?>
        <div class="swh-form-group">
            <input type="submit" name="swh_submit_ticket" value="Submit Ticket" class="swh-btn">
        </div>
    </form>
    </div> <!-- End .swh-helpdesk-wrapper -->
    <?php
    swh_render_js_validation();
    return ob_get_clean();
}

function swh_render_js_validation() {
    $max_mb = (int) get_option( 'swh_max_upload_size', 5 );
    ?>
    <script>
    document.addEventListener('DOMContentLoaded', function() {
        var maxMb = <?php echo esc_js( $max_mb ); ?>;
        var maxBytes = maxMb * 1024 * 1024;
        var allowedExts = ['jpg', 'jpeg', 'jpe', 'png', 'gif', 'pdf', 'doc', 'docx', 'txt'];
        var fileInputs = document.querySelectorAll('.swh-file-input');
        fileInputs.forEach(function(input) {
            input.addEventListener('change', function() {
                var errorMsg = '';
                for (var i = 0; i < this.files.length; i++) {
                    var file = this.files[i];
                    var ext = file.name.split('.').pop().toLowerCase();
                    if (allowedExts.indexOf(ext) === -1) {
                        errorMsg += 'File "' + file.name + '" has an invalid type.\n';
                    }
                    if (file.size > maxBytes) {
                        errorMsg += 'File "' + file.name + '" exceeds the ' + maxMb + 'MB size limit.\n';
                    }
                }
                if (errorMsg !== '') {
                    alert(errorMsg);
                    this.value = '';
                }
            });
        });
    });
    </script>
    <?php
}

// ==============================================================================
// 7. GITHUB UPDATER
// ==============================================================================
class SWH_GitHub_Updater {
    private $github_user  = 'seanmousseau';
    private $github_repo  = 'Simple-WP-Helpdesk';
    private $github_token = '';
    private $plugin_slug;
    private $plugin_file;
    
    public function __construct() {
        $this->plugin_file = plugin_basename( __FILE__ );
        $this->plugin_slug = dirname( $this->plugin_file );
        add_filter( 'pre_set_site_transient_update_plugins', array( $this, 'check_update' ) );
        add_filter( 'plugins_api', array( $this, 'plugin_popup' ), 10, 3 );
        add_filter( 'upgrader_source_selection', array( $this, 'rename_github_zip_folder' ), 10, 3 );
    }
    
    private function fetch_github_release() {
        $transient_key = 'swh_gh_release_' . SWH_VERSION;
        $cached        = get_transient( $transient_key );
        if ( false !== $cached ) {
            return $cached;
        }
        $url     = sprintf( 'https://api.github.com/repos/%s/%s/releases/latest', $this->github_user, $this->github_repo );
        $headers = array(
            'Accept'     => 'application/vnd.github.v3+json',
            'User-Agent' => 'Simple-WP-Helpdesk-Updater',
        );
        if ( ! empty( $this->github_token ) ) {
            $headers['Authorization'] = 'token ' . $this->github_token;
        }
        $response = wp_remote_get(
            $url,
            array(
                'headers' => $headers,
                'timeout' => 10,
            )
        );
        if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
            set_transient( $transient_key, null, 12 * HOUR_IN_SECONDS );
            return null;
        }
        $data = json_decode( wp_remote_retrieve_body( $response ) );
        set_transient( $transient_key, $data, 12 * HOUR_IN_SECONDS );
        return $data;
    }
    
    public function check_update( $transient ) {
        if ( empty( $transient->checked ) ) {
            return $transient;
        }
        $release = $this->fetch_github_release();
        if ( ! $release ) {
            return $transient;
        }
        $latest_version = ltrim( $release->tag_name, 'v' );
        if ( version_compare( SWH_VERSION, $latest_version, '<' ) ) {
            $plugin_data = array(
                'slug'        => $this->plugin_slug,
                'plugin'      => $this->plugin_file,
                'new_version' => $latest_version,
                'url'         => $release->html_url,
                'package'     => $release->zipball_url,
            );
            $transient->response[ $this->plugin_file ] = (object) $plugin_data;
        }
        return $transient;
    }
    
    public function plugin_popup( $result, $action, $args ) {
        if ( 'plugin_information' !== $action || empty( $args->slug ) || $args->slug !== $this->plugin_slug ) {
            return $result;
        }
        $release = $this->fetch_github_release();
        if ( ! $release ) {
            return $result;
        }
        $latest_version = ltrim( $release->tag_name, 'v' );
        $plugin_info = array(
            'name'          => 'Simple WP Helpdesk',
            'slug'          => $this->plugin_slug,
            'version'       => $latest_version,
            'author'        => '<a href="https://github.com/' . esc_attr( $this->github_user ) . '">SM WP Plugins</a>',
            'homepage'      => $release->html_url,
            'requires'      => '5.3',
            'tested'        => get_bloginfo( 'version' ),
            'requires_php'  => '7.2',
            'last_updated'  => $release->published_at,
            'sections'      => array(
                'description' => 'A comprehensive helpdesk system natively built for WordPress.',
                'changelog'   => nl2br( esc_html( $release->body ) ),
            ),
            'download_link' => $release->zipball_url,
        );
        return (object) $plugin_info;
    }
    
    public function rename_github_zip_folder( $source, $remote_source, $wp_upgrader ) {
        global $wp_filesystem;
        if ( ! isset( $wp_upgrader->skin->plugin ) || $this->plugin_file !== $wp_upgrader->skin->plugin ) {
            return $source;
        }
        $new_source = trailingslashit( $remote_source ) . $this->plugin_slug;
        $wp_filesystem->move( $source, $new_source );
        return trailingslashit( $new_source );
    }
}
new SWH_GitHub_Updater();