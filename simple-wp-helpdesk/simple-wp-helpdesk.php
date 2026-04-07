<?php
/**
 * Plugin Name: Simple WP Helpdesk
 * Description: A comprehensive helpdesk system with auto-close, custom templates, multi-file attachments, internal notes, anti-spam, deep uninstallation cleanup, and GitHub auto-updates.
 * Version: 1.9.0
 * Requires at least: 5.3
 * Requires PHP: 7.4
 * Text Domain: simple-wp-helpdesk
 * Author: SM WP Plugins
 */

// Exit immediately if accessed directly
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// ==============================================================================
// 1. PLUGIN SETUP, UPGRADE LOGIC & CRON
// ==============================================================================
define( 'SWH_VERSION', '1.9.0' );

register_activation_hook( __FILE__, 'swh_activate' );
function swh_activate() {
    if ( ! get_role( 'technician' ) ) {
        add_role(
            'technician',
            'Technician',
            array(
                'read'                 => true,
                'edit_posts'           => true,
                'edit_others_posts'    => true,
                'edit_published_posts' => true,
                'publish_posts'        => true,
                'delete_posts'         => true,
                'upload_files'         => true,
            )
        );
    } else {
        // Ensure existing installs get the missing capabilities.
        $tech = get_role( 'technician' );
        foreach ( array( 'edit_others_posts', 'edit_published_posts', 'publish_posts', 'delete_posts' ) as $cap ) {
            if ( ! $tech->has_cap( $cap ) ) {
                $tech->add_cap( $cap );
            }
        }
    }
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
    swh_ensure_upload_protection();
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
    // One-time migration: set comment_type on ALL comments attached to ticket posts.
    // Uses a fresh flag name (v2) so previous broken migration flags don't block it.
    // No WHERE on comment_type — catches NULL, empty, 'comment', or any other value.
    if ( ! get_option( 'swh_comment_type_v2' ) ) {
        global $wpdb;
        $wpdb->query( $wpdb->prepare(
            "UPDATE {$wpdb->comments} c
             INNER JOIN {$wpdb->posts} p ON c.comment_post_ID = p.ID
             SET c.comment_type = %s
             WHERE p.post_type = %s",
            'helpdesk_reply',
            'helpdesk_ticket'
        ) );
        update_option( 'swh_comment_type_v2', '1' );
    }

    $db_version = get_option( 'swh_db_version', '0.0' );
    if ( version_compare( $db_version, SWH_VERSION, '>=' ) ) {
        return;
    }
    // Add any missing options without overwriting existing values.
    foreach ( swh_get_defaults() as $key => $val ) {
        add_option( $key, $val );
    }
    update_option( 'swh_db_version', SWH_VERSION );
}

add_action( 'admin_init', 'swh_ensure_technician_caps' );
function swh_ensure_technician_caps() {
    if ( get_option( 'swh_tech_caps_v2' ) ) {
        return;
    }
    $tech = get_role( 'technician' );
    if ( $tech ) {
        foreach ( array( 'edit_others_posts', 'edit_published_posts', 'publish_posts', 'delete_posts', 'upload_files' ) as $cap ) {
            if ( ! $tech->has_cap( $cap ) ) {
                $tech->add_cap( $cap );
            }
        }
    }
    update_option( 'swh_tech_caps_v2', '1' );
}

register_uninstall_hook( __FILE__, 'swh_uninstall' );
function swh_uninstall() {
    if ( 'yes' !== get_option( 'swh_delete_on_uninstall' ) ) {
        return;
    }
    // Delete all tickets and their files.
    $tickets = get_posts(
        array(
            'post_type'      => 'helpdesk_ticket',
            'posts_per_page' => -1,
            'post_status'    => 'any',
        )
    );
    foreach ( $tickets as $t ) {
        swh_delete_ticket_and_files( $t->ID );
    }
    // Clear cron hooks (defensive — deactivation may have been skipped).
    wp_clear_scheduled_hook( 'swh_autoclose_event' );
    wp_clear_scheduled_hook( 'swh_retention_tickets_event' );
    wp_clear_scheduled_hook( 'swh_retention_attachments_event' );
    // Remove upload protection files and directory.
    $upload_dir = wp_get_upload_dir();
    $swh_dir    = trailingslashit( $upload_dir['basedir'] ) . 'swh-helpdesk';
    // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
    @unlink( $swh_dir . '/.htaccess' );
    // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
    @unlink( $swh_dir . '/index.php' );
    // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
    @rmdir( $swh_dir );
    // Delete rate-limit options and transients.
    global $wpdb;
    $wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE 'swh\_rl\_%'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
    $wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_swh\_%' OR option_name LIKE '_transient_timeout_swh\_%'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
    // Delete migration flags.
    delete_option( 'swh_tech_caps_v2' );
    delete_option( 'swh_comment_type_migrated' );
    delete_option( 'swh_comment_type_v2' );
    // Reassign technician users to default role before removing role.
    $techs = get_users( array( 'role' => 'technician' ) );
    foreach ( $techs as $tech ) {
        $tech->set_role( get_option( 'default_role', 'subscriber' ) );
    }
    remove_role( 'technician' );
    // Delete all plugin options.
    foreach ( swh_get_all_option_keys() as $opt ) {
        delete_option( $opt );
    }
    delete_option( 'swh_delete_on_uninstall' );
    delete_option( 'swh_db_version' );
}

add_action( 'init', 'swh_serve_file', 1 );
function swh_serve_file() {
    if ( ! isset( $_GET['swh_file'], $_GET['swh_ticket'], $_GET['token'] ) ) {
        return;
    }
    $filename  = sanitize_file_name( wp_unslash( $_GET['swh_file'] ) );
    $ticket_id = absint( $_GET['swh_ticket'] );
    $token     = sanitize_text_field( wp_unslash( $_GET['token'] ) );

    if ( ! $filename || ! $ticket_id ) {
        wp_die( esc_html__( 'Invalid request.', 'simple-wp-helpdesk' ), 403 );
    }

    $post = get_post( $ticket_id );
    if ( ! $post || 'helpdesk_ticket' !== $post->post_type ) {
        wp_die( esc_html__( 'Invalid ticket.', 'simple-wp-helpdesk' ), 404 );
    }

    // Access check: valid portal token OR admin/technician capability.
    $db_token   = get_post_meta( $ticket_id, '_ticket_token', true );
    $has_access = false;
    if ( $db_token && hash_equals( $db_token, $token ) ) {
        $has_access = true;
    } elseif ( current_user_can( 'edit_posts' ) ) {
        $has_access = true;
    }
    if ( ! $has_access ) {
        wp_die( esc_html__( 'Access denied.', 'simple-wp-helpdesk' ), 403 );
    }

    if ( $has_access && ! current_user_can( 'edit_posts' ) && swh_is_token_expired( $ticket_id ) ) {
        wp_die( esc_html__( 'This link has expired.', 'simple-wp-helpdesk' ), 403 );
    }

    // Resolve file path within the protected upload directory.
    $upload_dir = wp_get_upload_dir();
    $file_path  = trailingslashit( $upload_dir['basedir'] ) . 'swh-helpdesk/' . $filename;

    // Path traversal prevention.
    $real_path    = realpath( $file_path );
    $expected_dir = realpath( $upload_dir['basedir'] . '/swh-helpdesk' );
    if ( ! $real_path || ! $expected_dir || strpos( $real_path, $expected_dir ) !== 0 ) {
        wp_die( esc_html__( 'File not found.', 'simple-wp-helpdesk' ), 404 );
    }

    $filetype = wp_check_filetype( $filename );
    $mimetype = $filetype['type'] ?: 'application/octet-stream';

    $filename = str_replace( array( "\r", "\n", '"', ';' ), '', $filename );
    header( 'Content-Type: ' . $mimetype );
    header( 'Content-Disposition: inline; filename="' . $filename . '"' );
    header( 'Content-Length: ' . filesize( $real_path ) );
    // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile
    readfile( $real_path );
    exit;
}

add_action( 'init', 'swh_load_textdomain' );
function swh_load_textdomain() {
    load_plugin_textdomain( 'simple-wp-helpdesk', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
}

add_action( 'init', 'swh_register_ticket_cpt' );
function swh_register_ticket_cpt() {
    register_post_type(
        'helpdesk_ticket',
        array(
            'labels'          => array(
                'name'               => __( 'Tickets', 'simple-wp-helpdesk' ),
                'singular_name'      => __( 'Ticket', 'simple-wp-helpdesk' ),
                'add_new_item'       => __( 'Add New Ticket', 'simple-wp-helpdesk' ),
                'edit_item'          => __( 'Edit Ticket', 'simple-wp-helpdesk' ),
                'all_items'          => __( 'All Tickets', 'simple-wp-helpdesk' ),
                'view_item'          => __( 'View Ticket', 'simple-wp-helpdesk' ),
                'search_items'       => __( 'Search Tickets', 'simple-wp-helpdesk' ),
                'not_found'          => __( 'No tickets found.', 'simple-wp-helpdesk' ),
                'not_found_in_trash' => __( 'No tickets found in Trash.', 'simple-wp-helpdesk' ),
                'menu_name'          => __( 'Tickets', 'simple-wp-helpdesk' ),
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

// Anti-spam scripts are loaded inline by swh_ticket_frontend() only when the
// [submit_ticket] shortcode is rendered, using explicit render mode to avoid
// conflicts and unnecessary loading on unrelated pages.

// ==============================================================================
// 2. HELPER FUNCTIONS & DEFAULTS
// ==============================================================================
function swh_get_defaults() {
    static $defaults = null;
    if ( null === $defaults ) {
        $defaults = array(
            // General.
            'swh_ticket_priorities'            => 'Low, Medium, High',
            'swh_default_priority'             => 'Medium',
            'swh_ticket_statuses'              => 'Open, In Progress, Resolved, Closed',
            'swh_default_status'               => 'Open',
            'swh_resolved_status'              => 'Resolved',
            'swh_closed_status'                => 'Closed',
            'swh_reopened_status'              => 'Open',
            'swh_autoclose_days'               => 3,
            'swh_max_upload_size'              => 5,
            'swh_max_upload_count'             => 5,
            // Assignment & Routing.
            'swh_default_assignee'             => '',
            'swh_fallback_email'               => '',
            'swh_ticket_page_id'               => 0,
            'swh_token_expiration_days'        => 90,
            'swh_restrict_to_assigned'         => 'no',
            // Email Format.
            'swh_email_format'                 => 'html',
            // Anti-Spam.
            'swh_spam_method'                  => 'honeypot',
            'swh_recaptcha_site_key'           => '',
            'swh_recaptcha_secret_key'         => '',
            'swh_turnstile_site_key'           => '',
            'swh_turnstile_secret_key'         => '',
            // Data Retention & Tools.
            'swh_retention_attachments_days'   => 0,
            'swh_retention_tickets_days'       => 0,
            'swh_delete_on_uninstall'          => 'no',
            // Email Templates.
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
            'swh_em_assigned_sub'           => 'Ticket #{ticket_id} Has Been Assigned to You',
            'swh_em_assigned_body'          => "Hi,\n\nTicket #{ticket_id} — {title} — has been assigned to you.\n\nPriority: {priority}\n\nView/Edit Ticket:\n{admin_url}",
            'swh_msg_success_new'           => 'Your ticket has been submitted successfully! Check your email for a secure link to track your ticket.',
            'swh_msg_success_reply'         => 'Your reply has been added.',
            'swh_msg_success_reopen'        => 'Your ticket has been successfully re-opened. Our team has been notified.',
            'swh_msg_success_closed'        => 'Your ticket has been successfully closed.',
            'swh_msg_err_spam'              => 'Anti-spam verification failed. Please try again.',
            'swh_msg_err_missing'           => 'Please fill in all required fields.',
            'swh_msg_err_invalid'           => 'Invalid or expired ticket link.',
            'swh_msg_err_expired'           => 'This ticket link has expired. Please use the lookup form below to receive a fresh link.',
            'swh_msg_success_lookup'        => 'If we have tickets on file for that email address, links have been sent.',
            // Lookup email template.
            'swh_em_user_lookup_sub'        => 'Your Open Tickets',
            'swh_em_user_lookup_body'       => "Hi,\n\nHere are links to your open helpdesk tickets:\n\n{ticket_links}\n\nIf you did not request this, you can safely ignore this email.",
        );
    }
    return $defaults;
}

function swh_get_all_option_keys() {
    // swh_db_version is managed separately and excluded from bulk operations.
    return array_keys( swh_get_defaults() );
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

function swh_is_token_expired( $ticket_id ) {
    $days = (int) get_option( 'swh_token_expiration_days', 90 );
    if ( 0 === $days ) {
        return false;
    }
    $created = (int) get_post_meta( $ticket_id, '_ticket_token_created', true );
    if ( ! $created ) {
        return false;
    }
    return ( time() - $created ) > ( $days * DAY_IN_SECONDS );
}

function swh_parse_template( $template, $data ) {
    foreach ( $data as $key => $value ) {
        $template = str_replace( '{' . $key . '}', $value, $template );
    }
    return $template;
}

function swh_send_email( $to, $subject_key, $body_key, $data, $attachments = array() ) {
    $defs    = swh_get_defaults();
    $subject = swh_parse_template( get_option( $subject_key, isset( $defs[ $subject_key ] ) ? $defs[ $subject_key ] : '' ), $data );
    $body    = swh_parse_template( get_option( $body_key, isset( $defs[ $body_key ] ) ? $defs[ $body_key ] : '' ), $data );
    $headers = array();
    $format  = get_option( 'swh_email_format', 'html' );
    if ( 'html' === $format ) {
        $headers[] = 'Content-Type: text/html; charset=UTF-8';
        $body      = swh_wrap_html_email( $body, $attachments );
    } else {
        if ( ! empty( $attachments ) ) {
            $body .= "\n\nAttachments:\n" . implode( "\n", $attachments );
        }
    }
    if ( ! wp_mail( $to, $subject, $body, $headers ) ) {
        error_log( 'Simple WP Helpdesk: wp_mail() failed — to: ' . $to . ', subject: ' . $subject );
    }
}

function swh_wrap_html_email( $body, $attachments = array() ) {
    $html_body = nl2br( esc_html( $body ) );
    // Auto-link URLs that are not already inside HTML tags.
    $html_body = preg_replace(
        '~(?<!href=["\'])(?<!">)(https?://[^\s<]+)~i',
        '<a href="$1" style="color:#0073aa;">$1</a>',
        $html_body
    );
    $attachment_html = '';
    if ( ! empty( $attachments ) ) {
        $attachment_html = '<p style="margin-top:15px;"><strong>Attachments:</strong><br>';
        foreach ( $attachments as $url ) {
            $attachment_html .= '<a href="' . esc_url( $url ) . '" style="color:#0073aa;">' . esc_html( basename( $url ) ) . '</a><br>';
        }
        $attachment_html .= '</p>';
    }
    return '<!DOCTYPE html><html><head><meta charset="UTF-8"></head>'
        . '<body style="margin:0;padding:0;background:#f5f5f5;">'
        . '<table width="100%" cellpadding="0" cellspacing="0" style="background:#f5f5f5;padding:20px 0;">'
        . '<tr><td align="center">'
        . '<table width="600" cellpadding="0" cellspacing="0" style="background:#ffffff;border:1px solid #ddd;border-radius:4px;padding:30px;font-family:Arial,sans-serif;font-size:15px;line-height:1.6;color:#333;">'
        . '<tr><td>' . $html_body . $attachment_html . '</td></tr>'
        . '</table>'
        . '</td></tr></table></body></html>';
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

function swh_get_client_ip() {
    if ( ! empty( $_SERVER['HTTP_CF_CONNECTING_IP'] ) ) {
        return sanitize_text_field( wp_unslash( $_SERVER['HTTP_CF_CONNECTING_IP'] ) );
    }
    if ( ! empty( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) {
        $ips = explode( ',', sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) );
        return trim( $ips[0] );
    }
    return isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
}

function swh_check_antispam( $check_captcha = true ) {
    $method = get_option( 'swh_spam_method', 'honeypot' );
    if ( 'honeypot' === $method && ! empty( $_POST['swh_website_url_hp'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
        return true;
    }
    if ( ! $check_captcha ) {
        return false;
    }
    if ( 'recaptcha' === $method ) {
        $resp = wp_remote_post(
            'https://www.google.com/recaptcha/api/siteverify',
            array(
                'body'    => array(
                    'secret'   => get_option( 'swh_recaptcha_secret_key' ),
                    'response' => isset( $_POST['g-recaptcha-response'] ) ? sanitize_text_field( wp_unslash( $_POST['g-recaptcha-response'] ) ) : '', // phpcs:ignore WordPress.Security.NonceVerification.Missing
                ),
                'timeout' => 10,
            )
        );
        $json = json_decode( wp_remote_retrieve_body( $resp ) );
        if ( empty( $json->success ) ) {
            return true;
        }
    } elseif ( 'turnstile' === $method ) {
        $resp = wp_remote_post(
            'https://challenges.cloudflare.com/turnstile/v0/siteverify',
            array(
                'body'    => array(
                    'secret'   => get_option( 'swh_turnstile_secret_key' ),
                    'response' => isset( $_POST['cf-turnstile-response'] ) ? sanitize_text_field( wp_unslash( $_POST['cf-turnstile-response'] ) ) : '', // phpcs:ignore WordPress.Security.NonceVerification.Missing
                ),
                'timeout' => 10,
            )
        );
        $json = json_decode( wp_remote_retrieve_body( $resp ) );
        if ( empty( $json->success ) ) {
            return true;
        }
    }
    return false;
}

function swh_is_rate_limited( $action, $ttl = 30 ) {
    $key = 'swh_rl_' . md5( $action . '_' . swh_get_client_ip() );
    $val = get_option( $key );
    if ( false !== $val && (int) $val > time() ) {
        return true;
    }
    update_option( $key, time() + $ttl, false );
    return false;
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

function swh_custom_upload_dir( $dirs ) {
    $dirs['subdir'] = '/swh-helpdesk';
    $dirs['path']   = $dirs['basedir'] . '/swh-helpdesk';
    $dirs['url']    = $dirs['baseurl'] . '/swh-helpdesk';
    return $dirs;
}

function swh_ensure_upload_protection() {
    $upload_dir = wp_get_upload_dir();
    $dir        = $upload_dir['basedir'] . '/swh-helpdesk';
    wp_mkdir_p( $dir );
    $htaccess = trailingslashit( $dir ) . '.htaccess';
    if ( ! file_exists( $htaccess ) ) {
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
        file_put_contents( $htaccess, "Order deny,allow\nDeny from all\n" );
    }
    $index = trailingslashit( $dir ) . 'index.php';
    if ( ! file_exists( $index ) ) {
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
        file_put_contents( $index, "<?php\n// Silence is golden.\n" );
    }
}

function swh_get_file_proxy_url( $url, $ticket_id ) {
    $token = get_post_meta( $ticket_id, '_ticket_token', true );
    if ( ! $token ) {
        return $url;
    }
    return add_query_arg(
        array(
            'swh_file'   => rawurlencode( basename( $url ) ),
            'swh_ticket' => $ticket_id,
            'token'      => $token,
        ),
        site_url( '/' )
    );
}

function swh_handle_multiple_uploads( $file_array ) {
    $files = swh_normalize_files_array( $file_array );
    if ( empty( $files ) ) {
        return array();
    }
    $max_count = (int) get_option( 'swh_max_upload_count', 5 );
    if ( $max_count > 0 && count( $files ) > $max_count ) {
        $files = array_slice( $files, 0, $max_count );
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
    swh_ensure_upload_protection();
    add_filter( 'upload_dir', 'swh_custom_upload_dir' );
    foreach ( $files as $file ) {
        if ( $file['size'] > $max_bytes ) {
            error_log( sprintf( 'SWH upload skipped: "%1$s" exceeds %2$dMB limit.', $file['name'], $max_size_mb ) );
            continue;
        }
        $movefile = wp_handle_upload( $file, $overrides );
        if ( $movefile && ! isset( $movefile['error'] ) ) {
            $uploaded_urls[] = $movefile['url'];
        } elseif ( isset( $movefile['error'] ) ) {
            error_log( 'SWH upload failed for "' . $file['name'] . '": ' . $movefile['error'] );
        }
    }
    remove_filter( 'upload_dir', 'swh_custom_upload_dir' );
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
    // Clean up expired rate-limit entries.
    global $wpdb;
    $wpdb->query( $wpdb->prepare(
        "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s AND option_value < %d",
        'swh\_rl\_%',
        time()
    ) );

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
            'post_type'   => 'helpdesk_ticket',
            'posts_per_page' => 2,
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
            'post_type'   => 'helpdesk_ticket',
            'posts_per_page' => 1,
            'date_query'  => array( array( 'column' => 'post_modified', 'before' => $threshold_date ) ),
            'meta_query'  => array(
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
            'date_query' => array( array( 'column' => 'comment_date', 'before' => $threshold_date ) ),
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
            'post_type'   => 'helpdesk_ticket',
            'posts_per_page' => 1,
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
    delete_transient( $lock_key );
}


// ==============================================================================
// 4. ADMIN SETTINGS PAGE
// ==============================================================================

// Renders a settings field (text input or textarea) with a reset-to-default link.
function swh_field( $name, $defs, $type = 'text' ) {
    $val = get_option( $name, isset( $defs[ $name ] ) ? $defs[ $name ] : '' );
    $default = isset( $defs[ $name ] ) ? $defs[ $name ] : '';
    if ( 'textarea' === $type ) {
        echo '<textarea name="' . esc_attr( $name ) . '" rows="4" class="large-text" data-default="' . esc_attr( $default ) . '" data-field-name="' . esc_attr( $name ) . '">' . esc_textarea( $val ) . '</textarea>';
    } else {
        echo '<input type="text" name="' . esc_attr( $name ) . '" value="' . esc_attr( $val ) . '" class="regular-text" style="width:100%; max-width:500px;" data-default="' . esc_attr( $default ) . '" data-field-name="' . esc_attr( $name ) . '">';
    }
    echo '<br><a href="#" class="swh-reset-field" style="font-size:12px; color:#d63638;">' . esc_html__( 'Reset to default', 'simple-wp-helpdesk' ) . '</a>';
}

// Frontend CSS and JS are enqueued inside swh_ticket_frontend() only when the shortcode is rendered.

add_action( 'admin_enqueue_scripts', 'swh_enqueue_admin_assets' );
function swh_enqueue_admin_assets( $hook ) {
    if ( 'helpdesk_ticket_page_swh-settings' !== $hook ) {
        return;
    }
    wp_enqueue_script( 'swh-admin', plugin_dir_url( __FILE__ ) . 'assets/swh-admin.js', array(), SWH_VERSION, true );
}

add_action( 'admin_menu', 'swh_register_settings_page' );
function swh_register_settings_page() {
    add_submenu_page( 'edit.php?post_type=helpdesk_ticket', __( 'Helpdesk Settings', 'simple-wp-helpdesk' ), __( 'Settings', 'simple-wp-helpdesk' ), 'manage_options', 'swh-settings', 'swh_render_settings_page' );
}

add_action( 'admin_init', 'swh_handle_settings_save' );
function swh_handle_settings_save() {
    // Only process on the settings page.
    // phpcs:ignore WordPress.Security.NonceVerification.Missing
    if ( ! isset( $_POST['swh_save_settings'] ) && ! isset( $_POST['swh_gdpr_delete'] ) && ! isset( $_POST['swh_purge_tickets'] ) && ! isset( $_POST['swh_factory_reset'] ) ) {
        return;
    }
    if ( ! current_user_can( 'manage_options' ) ) {
        return;
    }
    $defs         = swh_get_defaults();
    $options_list = swh_get_all_option_keys();
    $integer_opts = array( 'swh_autoclose_days', 'swh_max_upload_size', 'swh_max_upload_count', 'swh_retention_attachments_days', 'swh_retention_tickets_days', 'swh_ticket_page_id', 'swh_token_expiration_days' );

    // GDPR SPECIFIC CLIENT DELETE
    if ( isset( $_POST['swh_gdpr_delete'], $_POST['swh_danger_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['swh_danger_nonce'] ) ), 'swh_danger_action' ) ) {
        $gdpr_email = isset( $_POST['swh_gdpr_email'] ) ? sanitize_email( wp_unslash( $_POST['swh_gdpr_email'] ) ) : '';
        if ( $gdpr_email ) {
            // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
            $tickets = get_posts(
                array(
                    'post_type'   => 'helpdesk_ticket',
                    'posts_per_page' => -1,
                    'post_status' => 'any',
                    'meta_query'  => array(
                        array(
                            'key'   => '_ticket_email',
                            'value' => $gdpr_email,
                        ),
                    ),
                )
            );
            $count = count( $tickets );
            foreach ( $tickets as $t ) {
                swh_delete_ticket_and_files( $t->ID );
            }
            wp_safe_redirect( add_query_arg( array( 'swh_notice' => 'gdpr_done', 'swh_count' => $count, 'swh_email' => rawurlencode( $gdpr_email ), 'swh_tab' => 'tab-tools' ), menu_page_url( 'swh-settings', false ) ) );
            exit;
        } else {
            wp_safe_redirect( add_query_arg( array( 'swh_notice' => 'gdpr_fail', 'swh_tab' => 'tab-tools' ), menu_page_url( 'swh-settings', false ) ) );
            exit;
        }
    }

    // MASS EXECUTIONS
    if ( isset( $_POST['swh_purge_tickets'], $_POST['swh_danger_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['swh_danger_nonce'] ) ), 'swh_danger_action' ) ) {
        $tickets = get_posts( array( 'post_type' => 'helpdesk_ticket', 'posts_per_page' => -1, 'post_status' => 'any' ) );
        foreach ( $tickets as $t ) {
            swh_delete_ticket_and_files( $t->ID );
        }
        wp_safe_redirect( add_query_arg( array( 'swh_notice' => 'purged', 'swh_tab' => 'tab-tools' ), menu_page_url( 'swh-settings', false ) ) );
        exit;
    }

    if ( isset( $_POST['swh_factory_reset'], $_POST['swh_danger_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['swh_danger_nonce'] ) ), 'swh_danger_action' ) ) {
        $tickets = get_posts( array( 'post_type' => 'helpdesk_ticket', 'posts_per_page' => -1, 'post_status' => 'any' ) );
        foreach ( $tickets as $t ) {
            swh_delete_ticket_and_files( $t->ID );
        }
        foreach ( $options_list as $opt ) {
            delete_option( $opt );
        }
        delete_option( 'swh_db_version' );
        wp_safe_redirect( add_query_arg( array( 'swh_notice' => 'reset', 'swh_tab' => 'tab-tools' ), menu_page_url( 'swh-settings', false ) ) );
        exit;
    }

    // SAVE TOOLS/RETENTION SETTINGS (separate form with its own nonce).
    if ( isset( $_POST['swh_save_settings'], $_POST['swh_tools_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['swh_tools_nonce'] ) ), 'swh_save_tools_action' ) ) {
        update_option( 'swh_retention_attachments_days', absint( isset( $_POST['swh_retention_attachments_days'] ) ? $_POST['swh_retention_attachments_days'] : 0 ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
        update_option( 'swh_retention_tickets_days', absint( isset( $_POST['swh_retention_tickets_days'] ) ? $_POST['swh_retention_tickets_days'] : 0 ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
        update_option( 'swh_delete_on_uninstall', isset( $_POST['swh_delete_on_uninstall'] ) ? 'yes' : 'no' );
        wp_safe_redirect( add_query_arg( array( 'swh_notice' => 'saved', 'swh_tab' => 'tab-tools' ), menu_page_url( 'swh-settings', false ) ) );
        exit;
    }

    // SAVE GENERAL SETTINGS (main form).
    if ( isset( $_POST['swh_save_settings'], $_POST['swh_settings_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['swh_settings_nonce'] ) ), 'swh_save_settings_action' ) ) {
        if ( ! isset( $_POST['swh_restrict_to_assigned'] ) ) {
            update_option( 'swh_restrict_to_assigned', 'no' );
        }
        $active_tab = isset( $_POST['swh_active_tab'] ) ? sanitize_key( $_POST['swh_active_tab'] ) : 'tab-general';
        $tools_only = array( 'swh_retention_attachments_days', 'swh_retention_tickets_days', 'swh_delete_on_uninstall' );

        foreach ( $options_list as $opt ) {
            if ( in_array( $opt, $tools_only, true ) || ! isset( $_POST[ $opt ] ) ) {
                continue;
            }
            if ( in_array( $opt, $integer_opts, true ) ) {
                $val = absint( $_POST[ $opt ] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
            } elseif ( strpos( $opt, '_body' ) !== false ) {
                $val = wp_kses_post( wp_unslash( $_POST[ $opt ] ) );
            } else {
                $val = sanitize_text_field( wp_unslash( $_POST[ $opt ] ) );
            }
            update_option( $opt, $val );
        }
        wp_safe_redirect( add_query_arg( array( 'swh_notice' => 'saved', 'swh_tab' => $active_tab ), menu_page_url( 'swh-settings', false ) ) );
        exit;
    }
}

function swh_render_settings_page() {
    if ( ! current_user_can( 'manage_options' ) ) {
        return;
    }
    $defs         = swh_get_defaults();

    // Display notices from redirects.
    if ( isset( $_GET['swh_notice'] ) ) {
        $notice = sanitize_key( $_GET['swh_notice'] );
        if ( 'saved' === $notice ) {
            echo '<div class="updated notice is-dismissible"><p><strong>' . esc_html__( 'Settings saved successfully.', 'simple-wp-helpdesk' ) . '</strong></p></div>';
        } elseif ( 'reset' === $notice ) {
            echo '<div class="updated error notice is-dismissible"><p><strong>' . esc_html__( 'Plugin Factory Reset Complete. All tickets/files purged and settings reverted to default.', 'simple-wp-helpdesk' ) . '</strong></p></div>';
        } elseif ( 'purged' === $notice ) {
            echo '<div class="updated error notice is-dismissible"><p><strong>' . esc_html__( 'All tickets & files have been successfully purged.', 'simple-wp-helpdesk' ) . '</strong></p></div>';
        } elseif ( 'gdpr_done' === $notice ) {
            $count      = absint( isset( $_GET['swh_count'] ) ? $_GET['swh_count'] : 0 );
            $gdpr_email = isset( $_GET['swh_email'] ) ? sanitize_email( rawurldecode( wp_unslash( $_GET['swh_email'] ) ) ) : '';
            /* translators: 1: number of tickets deleted, 2: email address */
            echo '<div class="updated error notice is-dismissible"><p><strong>' . sprintf( esc_html__( 'Successfully deleted %1$s ticket(s) and all associated files for %2$s.', 'simple-wp-helpdesk' ), esc_html( $count ), esc_html( $gdpr_email ) ) . '</strong></p></div>';
        } elseif ( 'gdpr_fail' === $notice ) {
            echo '<div class="notice notice-error is-dismissible"><p><strong>' . esc_html__( 'Please enter a valid email address.', 'simple-wp-helpdesk' ) . '</strong></p></div>';
        }
    }

    $techs = get_users( array( 'role__in' => array( 'administrator', 'technician' ) ) );
    ?>
    <div class="wrap">
        <h2><?php esc_html_e( 'Helpdesk Settings', 'simple-wp-helpdesk' ); ?></h2>
        <h2 class="nav-tab-wrapper" id="swh-tabs">
            <a href="#" class="nav-tab nav-tab-active" data-tab="tab-general"><?php esc_html_e( 'General', 'simple-wp-helpdesk' ); ?></a>
            <a href="#" class="nav-tab" data-tab="tab-routing"><?php esc_html_e( 'Assignment & Routing', 'simple-wp-helpdesk' ); ?></a>
            <a href="#" class="nav-tab" data-tab="tab-emails"><?php esc_html_e( 'Email Templates', 'simple-wp-helpdesk' ); ?></a>
            <a href="#" class="nav-tab" data-tab="tab-messages"><?php esc_html_e( 'Messages', 'simple-wp-helpdesk' ); ?></a>
            <a href="#" class="nav-tab" data-tab="tab-spam"><?php esc_html_e( 'Anti-Spam', 'simple-wp-helpdesk' ); ?></a>
            <a href="#" class="nav-tab" data-tab="tab-tools" style="color:#d63638;"><?php esc_html_e( 'Tools', 'simple-wp-helpdesk' ); ?></a>
        </h2>
        <form method="POST" action="">
            <?php wp_nonce_field( 'swh_save_settings_action', 'swh_settings_nonce' ); ?>
            <input type="hidden" name="swh_active_tab" id="swh_active_tab" value="tab-general">

            <div id="tab-general" class="swh-tab-content">
                <table class="form-table">
                    <tr><th scope="row"><?php esc_html_e( 'Custom Priorities', 'simple-wp-helpdesk' ); ?></th><td><?php swh_field( 'swh_ticket_priorities', $defs ); ?></td></tr>
                    <tr><th scope="row"><?php esc_html_e( 'Default Priority', 'simple-wp-helpdesk' ); ?></th><td><?php swh_field( 'swh_default_priority', $defs ); ?></td></tr>
                    <tr><td colspan="2"><hr></td></tr>
                    <tr><th scope="row"><?php esc_html_e( 'Custom Statuses', 'simple-wp-helpdesk' ); ?></th><td><?php swh_field( 'swh_ticket_statuses', $defs ); ?></td></tr>
                    <tr><th scope="row"><?php esc_html_e( 'Default New Status', 'simple-wp-helpdesk' ); ?></th><td><?php swh_field( 'swh_default_status', $defs ); ?></td></tr>
                    <tr><th scope="row"><?php esc_html_e( '"Resolved" Status', 'simple-wp-helpdesk' ); ?> <br><small>(<?php esc_html_e( 'Triggers Auto-close', 'simple-wp-helpdesk' ); ?>)</small></th><td><?php swh_field( 'swh_resolved_status', $defs ); ?></td></tr>
                    <tr><th scope="row"><?php esc_html_e( '"Closed" Status', 'simple-wp-helpdesk' ); ?> <br><small>(<?php esc_html_e( 'Disables replies', 'simple-wp-helpdesk' ); ?>)</small></th><td><?php swh_field( 'swh_closed_status', $defs ); ?></td></tr>
                    <tr><th scope="row"><?php esc_html_e( '"Re-Opened" Status', 'simple-wp-helpdesk' ); ?></th><td><?php swh_field( 'swh_reopened_status', $defs ); ?></td></tr>
                    <tr><td colspan="2"><hr></td></tr>
                    <tr><th scope="row"><?php esc_html_e( 'Auto-Close Days', 'simple-wp-helpdesk' ); ?></th><td><input type="number" name="swh_autoclose_days" value="<?php echo esc_attr( get_option( 'swh_autoclose_days', 3 ) ); ?>" style="width:80px;"> <?php esc_html_e( 'days', 'simple-wp-helpdesk' ); ?> <p class="description"><?php esc_html_e( 'If a ticket is Resolved and the user doesn\'t reply in this many days, it automatically closes. Set to 0 to disable.', 'simple-wp-helpdesk' ); ?></p></td></tr>
                    <tr><th scope="row"><?php esc_html_e( 'Max File Upload Size', 'simple-wp-helpdesk' ); ?></th><td><input type="number" name="swh_max_upload_size" value="<?php echo esc_attr( get_option( 'swh_max_upload_size', 5 ) ); ?>" style="width:80px;"> <?php esc_html_e( 'MB', 'simple-wp-helpdesk' ); ?></td></tr>
                    <tr><th scope="row"><?php esc_html_e( 'Max Files Per Upload', 'simple-wp-helpdesk' ); ?></th><td><input type="number" name="swh_max_upload_count" value="<?php echo esc_attr( get_option( 'swh_max_upload_count', 5 ) ); ?>" style="width:80px;"> <?php esc_html_e( 'files', 'simple-wp-helpdesk' ); ?> <p class="description"><?php esc_html_e( 'Maximum number of files a user can attach per submission. Set to 0 for unlimited.', 'simple-wp-helpdesk' ); ?></p></td></tr>
                </table>
            </div>
            
            <div id="tab-routing" class="swh-tab-content" style="display:none;">
                <table class="form-table">
                    <tr><th scope="row"><?php esc_html_e( 'Default Assignee', 'simple-wp-helpdesk' ); ?></th>
                        <td><select name="swh_default_assignee"><option value=""><?php echo '-- ' . esc_html__( 'Unassigned', 'simple-wp-helpdesk' ) . ' --'; ?></option>
                        <?php foreach ( $techs as $t ) : ?>
                            <option value="<?php echo esc_attr( $t->ID ); ?>" <?php selected( get_option( 'swh_default_assignee' ), $t->ID ); ?>><?php echo esc_html( $t->display_name ); ?></option>
                        <?php endforeach; ?></select></td>
                    </tr>
                    <tr><th scope="row"><?php esc_html_e( 'Fallback Alert Email', 'simple-wp-helpdesk' ); ?></th><td><input type="email" name="swh_fallback_email" value="<?php echo esc_attr( get_option( 'swh_fallback_email' ) ); ?>" class="regular-text"></td></tr>
                    <tr>
                        <th scope="row"><?php esc_html_e( 'Helpdesk Page', 'simple-wp-helpdesk' ); ?> <br><small>(<?php esc_html_e( 'Portal URL for admin-created tickets', 'simple-wp-helpdesk' ); ?>)</small></th>
                        <td>
                            <?php
                            $pages          = get_pages( array( 'post_status' => 'publish' ) );
                            $current_page   = (int) get_option( 'swh_ticket_page_id', 0 );
                            ?>
                            <select name="swh_ticket_page_id">
                                <option value="0"><?php echo '-- ' . esc_html__( 'Select a page', 'simple-wp-helpdesk' ) . ' --'; ?></option>
                                <?php foreach ( $pages as $page ) : ?>
                                    <option value="<?php echo esc_attr( $page->ID ); ?>" <?php selected( $current_page, $page->ID ); ?>><?php echo esc_html( $page->post_title ); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <p class="description"><?php esc_html_e( 'The page containing the [submit_ticket] shortcode. Used to generate the secure portal link for tickets created by admins.', 'simple-wp-helpdesk' ); ?></p>
                        </td>
                    </tr>
                <tr>
                    <th scope="row"><?php esc_html_e( 'Portal Link Expiration', 'simple-wp-helpdesk' ); ?></th>
                    <td>
                        <input type="number" name="swh_token_expiration_days" value="<?php echo esc_attr( get_option( 'swh_token_expiration_days', 90 ) ); ?>" style="width:80px;" min="0">
                        <?php esc_html_e( 'days (0 = never expires)', 'simple-wp-helpdesk' ); ?>
                        <p class="description"><?php esc_html_e( 'Ticket portal links expire after this many days. Clients can request fresh links via the lookup form.', 'simple-wp-helpdesk' ); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e( 'Restrict Technicians', 'simple-wp-helpdesk' ); ?></th>
                    <td>
                        <label><input type="checkbox" name="swh_restrict_to_assigned" value="yes" <?php checked( get_option( 'swh_restrict_to_assigned', 'no' ), 'yes' ); ?>>
                        <?php esc_html_e( 'Technicians can only view tickets assigned to them', 'simple-wp-helpdesk' ); ?></label>
                    </td>
                </tr>
                </table>
            </div>

            <div id="tab-emails" class="swh-tab-content" style="display:none;">
                <table class="form-table">
                    <tr>
                        <th scope="row"><?php esc_html_e( 'Email Format', 'simple-wp-helpdesk' ); ?></th>
                        <td>
                            <?php $email_format = get_option( 'swh_email_format', 'html' ); ?>
                            <select name="swh_email_format">
                                <option value="html" <?php selected( $email_format, 'html' ); ?>><?php esc_html_e( 'HTML (Recommended)', 'simple-wp-helpdesk' ); ?></option>
                                <option value="plain" <?php selected( $email_format, 'plain' ); ?>><?php esc_html_e( 'Plain Text', 'simple-wp-helpdesk' ); ?></option>
                            </select>
                            <p class="description"><?php esc_html_e( 'HTML emails include clickable links and a clean layout. Switch to Plain Text for basic SMTP setups.', 'simple-wp-helpdesk' ); ?></p>
                        </td>
                    </tr>
                </table>
                <hr>
                <p><strong><?php esc_html_e( 'Placeholders:', 'simple-wp-helpdesk' ); ?></strong> <code>{name}</code>, <code>{email}</code>, <code>{ticket_id}</code>, <code>{title}</code>, <code>{status}</code>, <code>{priority}</code>, <code>{message}</code>, <code>{ticket_url}</code>, <code>{admin_url}</code>, <code>{autoclose_days}</code>, <code>{ticket_links}</code> (<?php esc_html_e( 'lookup only', 'simple-wp-helpdesk' ); ?>)</p><hr>
                <h3><?php esc_html_e( 'Emails Sent to Client', 'simple-wp-helpdesk' ); ?></h3>
                <table class="form-table">
                    <tr><th scope="row"><?php esc_html_e( 'New Ticket (Subject)', 'simple-wp-helpdesk' ); ?></th><td><?php swh_field( 'swh_em_user_new_sub', $defs ); ?></td></tr>
                    <tr><th scope="row"><?php esc_html_e( 'New Ticket (Body)', 'simple-wp-helpdesk' ); ?></th><td><?php swh_field( 'swh_em_user_new_body', $defs, 'textarea' ); ?></td></tr>
                    <tr><th scope="row"><?php esc_html_e( 'Tech Replied (Subject)', 'simple-wp-helpdesk' ); ?></th><td><?php swh_field( 'swh_em_user_reply_sub', $defs ); ?></td></tr>
                    <tr><th scope="row"><?php esc_html_e( 'Tech Replied (Body)', 'simple-wp-helpdesk' ); ?></th><td><?php swh_field( 'swh_em_user_reply_body', $defs, 'textarea' ); ?></td></tr>
                    <tr><th scope="row"><?php esc_html_e( 'Status Changed (Subject)', 'simple-wp-helpdesk' ); ?></th><td><?php swh_field( 'swh_em_user_status_sub', $defs ); ?></td></tr>
                    <tr><th scope="row"><?php esc_html_e( 'Status Changed (Body)', 'simple-wp-helpdesk' ); ?></th><td><?php swh_field( 'swh_em_user_status_body', $defs, 'textarea' ); ?></td></tr>
                    <tr style="background:#f9f9f9;"><th scope="row"><?php esc_html_e( 'Reply + Status Change (Subject)', 'simple-wp-helpdesk' ); ?></th><td><?php swh_field( 'swh_em_user_reply_status_sub', $defs ); ?></td></tr>
                    <tr style="background:#f9f9f9;"><th scope="row"><?php esc_html_e( 'Reply + Status Change (Body)', 'simple-wp-helpdesk' ); ?></th><td><?php swh_field( 'swh_em_user_reply_status_body', $defs, 'textarea' ); ?></td></tr>
                    <tr style="background:#e6f7ff;"><th scope="row"><?php esc_html_e( 'Ticket Resolved (Subject)', 'simple-wp-helpdesk' ); ?></th><td><?php swh_field( 'swh_em_user_resolved_sub', $defs ); ?></td></tr>
                    <tr style="background:#e6f7ff;"><th scope="row"><?php esc_html_e( 'Ticket Resolved (Body)', 'simple-wp-helpdesk' ); ?></th><td><?php swh_field( 'swh_em_user_resolved_body', $defs, 'textarea' ); ?></td></tr>
                    <tr><th scope="row"><?php esc_html_e( 'Ticket Re-opened (Subject)', 'simple-wp-helpdesk' ); ?></th><td><?php swh_field( 'swh_em_user_reopen_sub', $defs ); ?></td></tr>
                    <tr><th scope="row"><?php esc_html_e( 'Ticket Re-opened (Body)', 'simple-wp-helpdesk' ); ?></th><td><?php swh_field( 'swh_em_user_reopen_body', $defs, 'textarea' ); ?></td></tr>
                    <tr><th scope="row"><?php esc_html_e( 'Client Closed Ticket (Subject)', 'simple-wp-helpdesk' ); ?></th><td><?php swh_field( 'swh_em_user_closed_sub', $defs ); ?></td></tr>
                    <tr><th scope="row"><?php esc_html_e( 'Client Closed Ticket (Body)', 'simple-wp-helpdesk' ); ?></th><td><?php swh_field( 'swh_em_user_closed_body', $defs, 'textarea' ); ?></td></tr>
                    <tr><th scope="row"><?php esc_html_e( 'Auto-Closed (Subject)', 'simple-wp-helpdesk' ); ?></th><td><?php swh_field( 'swh_em_user_autoclose_sub', $defs ); ?></td></tr>
                    <tr><th scope="row"><?php esc_html_e( 'Auto-Closed (Body)', 'simple-wp-helpdesk' ); ?></th><td><?php swh_field( 'swh_em_user_autoclose_body', $defs, 'textarea' ); ?></td></tr>
                    <tr><th scope="row"><?php esc_html_e( 'Ticket Lookup (Subject)', 'simple-wp-helpdesk' ); ?></th><td><?php swh_field( 'swh_em_user_lookup_sub', $defs ); ?></td></tr>
                    <tr><th scope="row"><?php esc_html_e( 'Ticket Lookup (Body)', 'simple-wp-helpdesk' ); ?></th><td><?php swh_field( 'swh_em_user_lookup_body', $defs, 'textarea' ); ?></td></tr>
                </table><hr>
                <h3><?php esc_html_e( 'Emails Sent to Technician/Admin', 'simple-wp-helpdesk' ); ?></h3>
                <table class="form-table">
                    <tr><th scope="row"><?php esc_html_e( 'New Ticket (Subject)', 'simple-wp-helpdesk' ); ?></th><td><?php swh_field( 'swh_em_admin_new_sub', $defs ); ?></td></tr>
                    <tr><th scope="row"><?php esc_html_e( 'New Ticket (Body)', 'simple-wp-helpdesk' ); ?></th><td><?php swh_field( 'swh_em_admin_new_body', $defs, 'textarea' ); ?></td></tr>
                    <tr><th scope="row"><?php esc_html_e( 'Client Replied (Sub)', 'simple-wp-helpdesk' ); ?></th><td><?php swh_field( 'swh_em_admin_reply_sub', $defs ); ?></td></tr>
                    <tr><th scope="row"><?php esc_html_e( 'Client Replied (Body)', 'simple-wp-helpdesk' ); ?></th><td><?php swh_field( 'swh_em_admin_reply_body', $defs, 'textarea' ); ?></td></tr>
                    <tr><th scope="row"><?php esc_html_e( 'Ticket Re-opened (Sub)', 'simple-wp-helpdesk' ); ?></th><td><?php swh_field( 'swh_em_admin_reopen_sub', $defs ); ?></td></tr>
                    <tr><th scope="row"><?php esc_html_e( 'Ticket Re-opened (Body)', 'simple-wp-helpdesk' ); ?></th><td><?php swh_field( 'swh_em_admin_reopen_body', $defs, 'textarea' ); ?></td></tr>
                    <tr><th scope="row"><?php esc_html_e( 'Client Closed Ticket (Sub)', 'simple-wp-helpdesk' ); ?></th><td><?php swh_field( 'swh_em_admin_closed_sub', $defs ); ?></td></tr>
                    <tr><th scope="row"><?php esc_html_e( 'Client Closed Ticket (Body)', 'simple-wp-helpdesk' ); ?></th><td><?php swh_field( 'swh_em_admin_closed_body', $defs, 'textarea' ); ?></td></tr>
                    <tr style="background:#e6f7ff;"><th scope="row"><?php esc_html_e( 'Ticket Assigned to You (Subject)', 'simple-wp-helpdesk' ); ?></th><td><?php swh_field( 'swh_em_assigned_sub', $defs ); ?></td></tr>
                    <tr style="background:#e6f7ff;"><th scope="row"><?php esc_html_e( 'Ticket Assigned to You (Body)', 'simple-wp-helpdesk' ); ?></th><td><?php swh_field( 'swh_em_assigned_body', $defs, 'textarea' ); ?></td></tr>
                </table>
            </div>

            <div id="tab-messages" class="swh-tab-content" style="display:none;">
                <table class="form-table">
                    <tr><th scope="row"><?php esc_html_e( 'Success: Ticket Created', 'simple-wp-helpdesk' ); ?></th><td><?php swh_field( 'swh_msg_success_new', $defs ); ?></td></tr>
                    <tr><th scope="row"><?php esc_html_e( 'Success: Reply Added', 'simple-wp-helpdesk' ); ?></th><td><?php swh_field( 'swh_msg_success_reply', $defs ); ?></td></tr>
                    <tr><th scope="row"><?php esc_html_e( 'Success: Ticket Re-opened', 'simple-wp-helpdesk' ); ?></th><td><?php swh_field( 'swh_msg_success_reopen', $defs ); ?></td></tr>
                    <tr><th scope="row"><?php esc_html_e( 'Success: Ticket Closed', 'simple-wp-helpdesk' ); ?></th><td><?php swh_field( 'swh_msg_success_closed', $defs ); ?></td></tr>
                    <tr><th scope="row"><?php esc_html_e( 'Error: Anti-Spam Failed', 'simple-wp-helpdesk' ); ?></th><td><?php swh_field( 'swh_msg_err_spam', $defs ); ?></td></tr>
                    <tr><th scope="row"><?php esc_html_e( 'Error: Missing Fields', 'simple-wp-helpdesk' ); ?></th><td><?php swh_field( 'swh_msg_err_missing', $defs ); ?></td></tr>
                    <tr><th scope="row"><?php esc_html_e( 'Error: Invalid Link', 'simple-wp-helpdesk' ); ?></th><td><?php swh_field( 'swh_msg_err_invalid', $defs ); ?></td></tr>
                    <tr><th scope="row"><?php esc_html_e( 'Error: Expired Link', 'simple-wp-helpdesk' ); ?></th><td><?php swh_field( 'swh_msg_err_expired', $defs ); ?></td></tr>
                    <tr><th scope="row"><?php esc_html_e( 'Success: Ticket Links Sent', 'simple-wp-helpdesk' ); ?></th><td><?php swh_field( 'swh_msg_success_lookup', $defs ); ?></td></tr>
                </table>
            </div>

            <div id="tab-spam" class="swh-tab-content" style="display:none;">
                <?php $spam_method = get_option( 'swh_spam_method', 'none' ); ?>
                <table class="form-table">
                    <tr><th scope="row"><?php esc_html_e( 'Spam Prevention', 'simple-wp-helpdesk' ); ?></th><td><select name="swh_spam_method"><option value="none" <?php selected( $spam_method, 'none' ); ?>><?php esc_html_e( 'None', 'simple-wp-helpdesk' ); ?></option><option value="honeypot" <?php selected( $spam_method, 'honeypot' ); ?>><?php esc_html_e( 'Honeypot', 'simple-wp-helpdesk' ); ?></option><option value="recaptcha" <?php selected( $spam_method, 'recaptcha' ); ?>><?php esc_html_e( 'Google reCAPTCHA v2', 'simple-wp-helpdesk' ); ?></option><option value="turnstile" <?php selected( $spam_method, 'turnstile' ); ?>><?php esc_html_e( 'Cloudflare Turnstile', 'simple-wp-helpdesk' ); ?></option></select></td></tr>
                    <tr><th scope="row"><?php esc_html_e( 'reCAPTCHA Site Key', 'simple-wp-helpdesk' ); ?></th><td><input type="text" name="swh_recaptcha_site_key" value="<?php echo esc_attr( get_option( 'swh_recaptcha_site_key' ) ); ?>" class="regular-text"></td></tr>
                    <tr><th scope="row"><?php esc_html_e( 'reCAPTCHA Secret Key', 'simple-wp-helpdesk' ); ?></th><td><input type="text" name="swh_recaptcha_secret_key" value="<?php echo esc_attr( get_option( 'swh_recaptcha_secret_key' ) ); ?>" class="regular-text"></td></tr>
                    <tr><th scope="row"><?php esc_html_e( 'Turnstile Site Key', 'simple-wp-helpdesk' ); ?></th><td><input type="text" name="swh_turnstile_site_key" value="<?php echo esc_attr( get_option( 'swh_turnstile_site_key' ) ); ?>" class="regular-text"></td></tr>
                    <tr><th scope="row"><?php esc_html_e( 'Turnstile Secret Key', 'simple-wp-helpdesk' ); ?></th><td><input type="text" name="swh_turnstile_secret_key" value="<?php echo esc_attr( get_option( 'swh_turnstile_secret_key' ) ); ?>" class="regular-text"></td></tr>
                </table>
            </div>
            <p class="submit" id="save-btn-container"><input type="submit" name="swh_save_settings" class="button button-primary" value="<?php esc_attr_e( 'Save Changes', 'simple-wp-helpdesk' ); ?>"></p>
        </form>

        <div id="tab-tools" class="swh-tab-content" style="display:none;">
            <h3><?php esc_html_e( 'Automated Data Retention', 'simple-wp-helpdesk' ); ?></h3>
            <form method="POST" action="">
                <?php wp_nonce_field( 'swh_save_tools_action', 'swh_tools_nonce' ); ?>
                <table class="form-table">
                    <tr>
                        <th scope="row"><?php esc_html_e( 'Purge Old Attachments', 'simple-wp-helpdesk' ); ?></th>
                        <td>
                            <input type="number" name="swh_retention_attachments_days" value="<?php echo esc_attr( get_option( 'swh_retention_attachments_days', 0 ) ); ?>" style="width:80px;"> <?php esc_html_e( 'days', 'simple-wp-helpdesk' ); ?>
                            <p class="description"><?php esc_html_e( 'Automatically delete physical file attachments older than this many days to save server space. Links to the files will be safely removed from the ticket. Set to 0 to disable.', 'simple-wp-helpdesk' ); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e( 'Purge Old Tickets', 'simple-wp-helpdesk' ); ?></th>
                        <td>
                            <input type="number" name="swh_retention_tickets_days" value="<?php echo esc_attr( get_option( 'swh_retention_tickets_days', 0 ) ); ?>" style="width:80px;"> <?php esc_html_e( 'days', 'simple-wp-helpdesk' ); ?>
                            <p class="description"><?php esc_html_e( 'Automatically delete entire tickets (and their files) that haven\'t been updated in this many days. Set to 0 to disable.', 'simple-wp-helpdesk' ); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e( 'Uninstallation Behavior', 'simple-wp-helpdesk' ); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="swh_delete_on_uninstall" value="yes" <?php checked( get_option( 'swh_delete_on_uninstall' ), 'yes' ); ?>>
                                <?php esc_html_e( 'Delete all Plugin Data when uninstalled', 'simple-wp-helpdesk' ); ?>
                            </label>
                            <p class="description"><?php esc_html_e( 'If checked, completely deleting this plugin from the WP Plugins screen will wipe all tickets, files, and settings. Leave unchecked to safely preserve data.', 'simple-wp-helpdesk' ); ?></p>
                        </td>
                    </tr>
                </table>
                <p><input type="submit" name="swh_save_settings" class="button button-primary" value="<?php esc_attr_e( 'Save Retention Settings', 'simple-wp-helpdesk' ); ?>"></p>
            </form>
            <hr>
            <div style="background: #f8d7da; border: 1px solid #f5c6cb; padding: 20px; border-radius: 5px; color: #721c24; margin-top: 20px;">
                <h3 style="margin-top:0;"><?php esc_html_e( 'Danger Zone (Manual Cleanup)', 'simple-wp-helpdesk' ); ?></h3>
                <p><?php esc_html_e( 'These manual actions are permanent and cannot be undone.', 'simple-wp-helpdesk' ); ?></p>
                <form method="POST" action="">
                    <?php wp_nonce_field( 'swh_danger_action', 'swh_danger_nonce' ); ?>
                    <p>
                        <strong><?php esc_html_e( 'GDPR / Client Data Purge:', 'simple-wp-helpdesk' ); ?></strong> <?php esc_html_e( 'Deletes all tickets, comments, and files associated with a specific email address.', 'simple-wp-helpdesk' ); ?><br>
                        <input type="email" name="swh_gdpr_email" placeholder="client@example.com" class="regular-text" style="margin-top:5px; margin-bottom:5px;"><br>
                        <button type="submit" name="swh_gdpr_delete" class="button" onclick="return confirm('<?php echo esc_js( __( 'Are you sure you want to delete all data for this email?', 'simple-wp-helpdesk' ) ); ?>');"><?php esc_html_e( 'Delete Client Data', 'simple-wp-helpdesk' ); ?></button>
                    </p>
                    <hr style="border-color:#f5c6cb;">
                    <p>
                        <strong><?php esc_html_e( 'Purge ALL Tickets:', 'simple-wp-helpdesk' ); ?></strong> <?php esc_html_e( 'Deletes all helpdesk tickets, conversation history, and associated file uploads for EVERYONE.', 'simple-wp-helpdesk' ); ?><br>
                        <button type="submit" name="swh_purge_tickets" class="button" style="margin-top:5px;" onclick="return confirm('<?php echo esc_js( __( 'Are you sure you want to PURGE ALL TICKETS? This cannot be undone.', 'simple-wp-helpdesk' ) ); ?>');"><?php esc_html_e( 'Purge All Tickets', 'simple-wp-helpdesk' ); ?></button>
                    </p>
                    <hr style="border-color:#f5c6cb;">
                    <p>
                        <strong><?php esc_html_e( 'Factory Reset:', 'simple-wp-helpdesk' ); ?></strong> <?php esc_html_e( 'Purges all tickets AND resets all plugin settings back to original defaults.', 'simple-wp-helpdesk' ); ?><br>
                        <button type="submit" name="swh_factory_reset" class="button button-primary" style="background:#d63638; border-color:#d63638; margin-top:5px;" onclick="return confirm('<?php echo esc_js( __( 'Are you sure you want to FACTORY RESET the plugin? This cannot be undone.', 'simple-wp-helpdesk' ) ); ?>');"><?php esc_html_e( 'Factory Reset Plugin', 'simple-wp-helpdesk' ); ?></button>
                    </p>
                </form>
            </div>
        </div>
    </div>
    
    <?php
}

// ==============================================================================
// 4.5  ADMIN TICKET LIST — COLUMNS, SORTING & FILTERS
// ==============================================================================

add_filter( 'pre_get_comments', 'swh_exclude_helpdesk_comments' );
function swh_exclude_helpdesk_comments( $query ) {
    // Don't exclude when the query explicitly requests helpdesk replies (e.g. client portal).
    $requested_type = isset( $query->query_vars['type'] ) ? $query->query_vars['type'] : '';
    if ( 'helpdesk_reply' === $requested_type ) {
        return $query;
    }
    // Don't exclude when querying a specific helpdesk ticket (admin editor, cron).
    $post_id = isset( $query->query_vars['post_id'] ) ? (int) $query->query_vars['post_id'] : 0;
    if ( $post_id && 'helpdesk_ticket' === get_post_type( $post_id ) ) {
        return $query;
    }
    $types = isset( $query->query_vars['type__not_in'] ) ? $query->query_vars['type__not_in'] : array();
    if ( ! is_array( $types ) ) {
        $types = array( $types );
    }
    $types[] = 'helpdesk_reply';
    $query->query_vars['type__not_in'] = $types;
    return $query;
}

add_filter( 'comment_feed_where', 'swh_exclude_helpdesk_from_feed', 10, 2 );
function swh_exclude_helpdesk_from_feed( $where, $query ) {
    global $wpdb;
    $where .= $wpdb->prepare( " AND {$wpdb->comments}.comment_type != %s", 'helpdesk_reply' );
    return $where;
}

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

// ==============================================================================
// 5. ADMIN DASHBOARD - TICKET EDITOR UI & LOGIC
// ==============================================================================
add_action( 'admin_notices', 'swh_admin_helpdesk_page_notice' );
function swh_admin_helpdesk_page_notice() {
    $screen = get_current_screen();
    if ( ! $screen || false === strpos( $screen->id, 'helpdesk_ticket' ) ) {
        return;
    }
    if ( ! current_user_can( 'manage_options' ) ) {
        return;
    }
    $page_id = (int) get_option( 'swh_ticket_page_id', 0 );
    if ( $page_id && get_post( $page_id ) ) {
        return;
    }
    echo '<div class="notice notice-warning is-dismissible"><p>';
    echo '<strong>' . esc_html__( 'Simple WP Helpdesk:', 'simple-wp-helpdesk' ) . '</strong> ' . esc_html__( 'The Helpdesk Page setting is not configured. Admin-created tickets will not have a client portal URL.', 'simple-wp-helpdesk' ) . ' ';
    echo '<a href="' . esc_url( admin_url( 'edit.php?post_type=helpdesk_ticket&page=swh-settings' ) ) . '">' . esc_html__( 'Configure it under Settings &rarr; Assignment & Routing.', 'simple-wp-helpdesk' ) . '</a>';
    echo '</p></div>';
}

add_action( 'admin_notices', 'swh_reassigned_notice' );
function swh_reassigned_notice() {
    if ( ! isset( $_GET['swh_reassigned'] ) ) {
        return;
    }
    echo '<div class="notice notice-success is-dismissible"><p>';
    echo esc_html__( 'Ticket reassigned successfully.', 'simple-wp-helpdesk' );
    echo '</p></div>';
}

add_action( 'add_meta_boxes', 'swh_add_ticket_meta_boxes' );
function swh_add_ticket_meta_boxes() {
    add_meta_box( 'swh_ticket_status', __( 'Ticket Details', 'simple-wp-helpdesk' ), 'swh_status_meta_box_html', 'helpdesk_ticket', 'side', 'high' );
    add_meta_box( 'swh_ticket_conversation', __( 'Conversation & Reply', 'simple-wp-helpdesk' ), 'swh_conversation_meta_box_html', 'helpdesk_ticket', 'normal', 'high' );
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
    $is_new_ticket = empty( $uid );
    ?>
    <div style="font-size: 16px; font-weight: bold; background: #f0f0f1; padding: 10px; text-align: center; margin-bottom: 15px;">
        <?php echo $is_new_ticket ? esc_html__( 'New Ticket', 'simple-wp-helpdesk' ) : esc_html__( 'ID:', 'simple-wp-helpdesk' ) . ' ' . esc_html( $uid ); ?>
    </div>
    <p style="margin-bottom: 5px;"><strong><?php esc_html_e( 'Client Name:', 'simple-wp-helpdesk' ); ?></strong></p>
    <input type="text" name="ticket_client_name" value="<?php echo esc_attr( $name !== 'Unknown User' ? $name : '' ); ?>" placeholder="<?php esc_attr_e( 'Client name', 'simple-wp-helpdesk' ); ?>" style="width:100%; margin-bottom:8px;">
    <p style="margin-bottom: 5px;"><strong><?php esc_html_e( 'Client Email:', 'simple-wp-helpdesk' ); ?></strong></p>
    <input type="email" name="ticket_client_email" value="<?php echo esc_attr( $email ); ?>" placeholder="client@example.com" style="width:100%; margin-bottom:8px;">
    <?php if ( $is_new_ticket ) : ?>
    <p><label><input type="checkbox" name="swh_send_client_email" value="1"> <?php esc_html_e( 'Send confirmation email to client', 'simple-wp-helpdesk' ); ?></label></p>
    <?php elseif ( $email ) : ?>
    <p style="font-size:12px; color:#666;"><a href="mailto:<?php echo esc_attr( $email ); ?>"><?php echo esc_html( $email ); ?></a></p>
    <?php endif; ?>
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
        <p><strong><?php esc_html_e( 'All Attachments:', 'simple-wp-helpdesk' ); ?></strong><br>
        <?php foreach ( $all_attachments as $i => $url ) : ?>
            <a href="<?php echo esc_url( swh_get_file_proxy_url( $url, $post->ID ) ); ?>" target="_blank" class="button button-secondary button-small" style="margin-top:5px; margin-right:5px;"><?php echo esc_html( basename( $url ) ); ?></a>
        <?php endforeach; ?></p>
    <?php endif; ?>
    <hr>
    <p><strong><?php esc_html_e( 'Assigned To:', 'simple-wp-helpdesk' ); ?></strong></p>
    <select name="ticket_assigned_to" style="width: 100%; margin-bottom: 10px;">
        <option value=""><?php echo '-- ' . esc_html__( 'Unassigned', 'simple-wp-helpdesk' ) . ' --'; ?></option>
        <?php foreach ( $techs as $t ) : ?>
            <option value="<?php echo esc_attr( $t->ID ); ?>" <?php selected( $assignee, $t->ID ); ?>><?php echo esc_html( $t->display_name ); ?></option>
        <?php endforeach; ?>
    </select>
    <p><strong><?php esc_html_e( 'Priority:', 'simple-wp-helpdesk' ); ?></strong></p>
    <select name="ticket_priority" style="width: 100%; margin-bottom: 10px;">
        <?php foreach ( $priorities as $p ) : ?>
            <option value="<?php echo esc_attr( $p ); ?>" <?php selected( $priority, $p ); ?>><?php echo esc_html( $p ); ?></option>
        <?php endforeach; ?>
    </select>
    <p><strong><?php esc_html_e( 'Status:', 'simple-wp-helpdesk' ); ?></strong></p>
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
                /* translators: %s: comment author name */
                $author_label = sprintf( __( 'Internal Note (%s)', 'simple-wp-helpdesk' ), $comment->comment_author );
                $bg_color     = '#fff3cd';
                $border       = '#ffeeba';
            } else {
                /* translators: %s: comment author name */
                $author_label = $is_user ? sprintf( __( 'Client (%s)', 'simple-wp-helpdesk' ), $comment->comment_author ) : sprintf( __( 'Technician (%s)', 'simple-wp-helpdesk' ), $comment->comment_author );
                $bg_color     = $is_user ? '#f9f9f9' : '#e6f7ff';
                $border       = '#0073aa';
            }

            echo '<div style="background: ' . esc_attr( $bg_color ) . '; padding: 10px 15px; margin-bottom: 10px; border-left: 4px solid ' . esc_attr( $border ) . '; border-radius: 3px;">';
            echo '<strong style="display:block; margin-bottom: 5px;">' . esc_html( $author_label ) . ' <span style="font-weight:normal; font-size: 0.8em; color: #666;">(' . esc_html( $comment->comment_date ) . ')</span></strong>';
            echo nl2br( esc_html( $comment->comment_content ) );
            
            $attachments = get_comment_meta( $comment->comment_ID, '_attachments', true );
            if ( ! empty( $attachments ) && is_array( $attachments ) ) {
                echo '<div style="margin-top: 10px;">';
                foreach ( $attachments as $url ) {
                    echo '<a href="' . esc_url( swh_get_file_proxy_url( $url, $post->ID ) ) . '" target="_blank" class="button button-small" style="margin-right:5px;">' . esc_html( basename( $url ) ) . '</a>';
                }
                echo '</div>';
            }
            echo '</div>';
        }
    } else {
        echo '<p style="color: #666; font-style: italic;">' . esc_html__( 'No replies yet. Use the boxes below to start the conversation.', 'simple-wp-helpdesk' ) . '</p>';
    }
    echo '</div>';
    ?>
    <div style="display:flex; gap: 20px;">
        <div style="flex:1;">
            <h4 style="margin-top:0;"><?php esc_html_e( 'Add a Public Reply', 'simple-wp-helpdesk' ); ?></h4>
            <p style="font-size:12px;"><?php esc_html_e( 'This will be emailed to the client.', 'simple-wp-helpdesk' ); ?></p>
            <textarea name="swh_tech_reply_text" style="width: 100%;" rows="5" placeholder="<?php esc_attr_e( 'Type reply here...', 'simple-wp-helpdesk' ); ?>"></textarea>
            <p><strong><?php esc_html_e( 'Attach Files (Optional):', 'simple-wp-helpdesk' ); ?></strong><br>
            <input type="file" name="swh_tech_reply_attachments[]" multiple accept=".jpg,.jpeg,.png,.gif,.pdf,.doc,.docx,.txt">
            <br><small style="color:#666;"><?php esc_html_e( 'Allowed file types: JPG, JPEG, PNG, GIF, PDF, DOC, DOCX, TXT.', 'simple-wp-helpdesk' ); ?></small>
            </p>
        </div>
        <div style="flex:1; background: #fff3cd; padding: 15px; border-radius: 5px; border: 1px solid #ffeeba;">
            <h4 style="margin-top:0; color: #856404;"><?php esc_html_e( 'Add Internal Note', 'simple-wp-helpdesk' ); ?></h4>
            <p style="font-size:12px; color: #856404;"><?php esc_html_e( 'Hidden from client. For staff only.', 'simple-wp-helpdesk' ); ?></p>
            <textarea name="swh_tech_note_text" style="width: 100%;" rows="5" placeholder="<?php esc_attr_e( 'Type private note here...', 'simple-wp-helpdesk' ); ?>"></textarea>
        </div>
    </div>
    <p class="description"><?php /* translators: "Update" refers to the WordPress post editor button */
    printf( esc_html__( 'Click the %s button on the top right to save the ticket.', 'simple-wp-helpdesk' ), '<strong>' . esc_html__( 'Update', 'simple-wp-helpdesk' ) . '</strong>' ); ?></p>
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

    $defs            = swh_get_defaults();
    $old_status      = get_post_meta( $post_id, '_ticket_status', true );
    $old_assigned_to = (int) get_post_meta( $post_id, '_ticket_assigned_to', true );
    $new_status      = isset( $_POST['ticket_status'] ) ? sanitize_text_field( wp_unslash( $_POST['ticket_status'] ) ) : '';
    $new_priority    = isset( $_POST['ticket_priority'] ) ? sanitize_text_field( wp_unslash( $_POST['ticket_priority'] ) ) : '';
    $assigned_to     = isset( $_POST['ticket_assigned_to'] ) ? absint( $_POST['ticket_assigned_to'] ) : 0; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput

    // Validate status and priority against configured lists.
    $allowed_statuses  = swh_get_statuses();
    $allowed_priorities = swh_get_priorities();
    if ( $new_status && ! in_array( $new_status, $allowed_statuses, true ) ) {
        $new_status = $old_status; // Reject unknown status; keep existing.
    }
    if ( $new_priority && ! in_array( $new_priority, $allowed_priorities, true ) ) {
        $new_priority = get_post_meta( $post_id, '_ticket_priority', true );
    }
    // Validate assignee: must be an administrator or technician (0 = unassigned).
    if ( $assigned_to ) {
        $assignee_data = get_userdata( $assigned_to );
        if ( ! $assignee_data || empty( array_intersect( array( 'administrator', 'technician' ), (array) $assignee_data->roles ) ) ) {
            $assigned_to = 0;
        }
    }

    update_post_meta( $post_id, '_ticket_status', $new_status );
    update_post_meta( $post_id, '_ticket_priority', $new_priority );
    update_post_meta( $post_id, '_ticket_assigned_to', $assigned_to ? $assigned_to : '' );

    // When ticket is reassigned, clear the post lock and suppress lock reads for
    // 3 minutes. A simple delete_post_meta is not enough because the previous
    // editor's browser heartbeat can re-set the lock before the page reloads.
    if ( $assigned_to !== $old_assigned_to ) {
        delete_post_meta( $post_id, '_edit_lock' );
        set_transient( 'swh_lock_clear_' . $post_id, 1, 3 * MINUTE_IN_SECONDS );
        add_filter( 'redirect_post_location', function () {
            return admin_url( 'edit.php?post_type=helpdesk_ticket&swh_reassigned=1' );
        } );
    }

    // Send assignment notification when a ticket is newly assigned or reassigned.
    if ( $assigned_to && $assigned_to !== $old_assigned_to ) {
        $assignee_user = get_userdata( $assigned_to );
        if ( $assignee_user && $assignee_user->user_email ) {
            $assign_data = array(
                'name'           => get_post_meta( $post_id, '_ticket_name', true ) ?: 'Client',
                'email'          => get_post_meta( $post_id, '_ticket_email', true ),
                'ticket_id'      => get_post_meta( $post_id, '_ticket_uid', true ) ?: 'TKT-' . str_pad( $post_id, 4, '0', STR_PAD_LEFT ),
                'title'          => $post->post_title,
                'status'         => $new_status,
                'priority'       => $new_priority,
                'ticket_url'     => swh_get_secure_ticket_link( $post_id ),
                'admin_url'      => admin_url( 'post.php?post=' . $post_id . '&action=edit' ),
                'message'        => '',
                'autoclose_days' => get_option( 'swh_autoclose_days', $defs['swh_autoclose_days'] ),
            );
            swh_send_email( $assignee_user->user_email, 'swh_em_assigned_sub', 'swh_em_assigned_body', $assign_data );
        }
    }

    // Save editable client name/email (admin-created tickets or corrections).
    $client_name  = isset( $_POST['ticket_client_name'] ) ? sanitize_text_field( wp_unslash( $_POST['ticket_client_name'] ) ) : '';
    $client_email = isset( $_POST['ticket_client_email'] ) ? sanitize_email( wp_unslash( $_POST['ticket_client_email'] ) ) : '';
    if ( $client_name ) {
        update_post_meta( $post_id, '_ticket_name', $client_name );
    }
    if ( $client_email ) {
        update_post_meta( $post_id, '_ticket_email', $client_email );
    }

    // For admin-created tickets that have no UID yet, bootstrap the ticket identity.
    if ( ! get_post_meta( $post_id, '_ticket_uid', true ) ) {
        $uid   = 'TKT-' . str_pad( $post_id, 4, '0', STR_PAD_LEFT );
        $token = wp_generate_password( 20, false );
        update_post_meta( $post_id, '_ticket_uid', $uid );
        update_post_meta( $post_id, '_ticket_token', $token );
        update_post_meta( $post_id, '_ticket_token_created', time() );
        $portal_page_id = (int) get_option( 'swh_ticket_page_id', 0 );
        if ( $portal_page_id ) {
            update_post_meta( $post_id, '_ticket_url', get_permalink( $portal_page_id ) );
        }
        // Send confirmation email to client if email is set and checkbox is checked.
        if ( isset( $_POST['swh_send_client_email'] ) && $client_email ) {
            $ticket_link = swh_get_secure_ticket_link( $post_id );
            if ( $ticket_link ) {
                $new_data = array(
                    'name'           => $client_name ?: 'Client',
                    'email'          => $client_email,
                    'ticket_id'      => $uid,
                    'title'          => $post->post_title,
                    'status'         => $new_status,
                    'priority'       => $new_priority,
                    'ticket_url'     => $ticket_link,
                    'admin_url'      => admin_url( 'post.php?post=' . $post_id . '&action=edit' ),
                    'message'        => '',
                    'autoclose_days' => get_option( 'swh_autoclose_days', $defs['swh_autoclose_days'] ),
                );
                swh_send_email( $client_email, 'swh_em_user_new_sub', 'swh_em_user_new_body', $new_data );
            }
        }
    }

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
                'comment_type'         => 'helpdesk_reply',
            )
        );
        if ( $comment_id ) {
            update_comment_meta( $comment_id, '_is_internal_note', '1' );
        }
    }

    $just_replied = false;
    $attach_urls  = array();
    $reply_text   = isset( $_POST['swh_tech_reply_text'] ) ? sanitize_textarea_field( wp_unslash( $_POST['swh_tech_reply_text'] ) ) : '';
    
    // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
    $has_files = ! empty( $_FILES['swh_tech_reply_attachments']['name'][0] );
    
    if ( $reply_text || $has_files ) {
        $just_replied    = true;
        $comment_content = $reply_text ?: __( 'Attached file(s)', 'simple-wp-helpdesk' );
        $comment_id      = wp_insert_comment(
            array(
                'comment_post_ID'      => $post_id,
                'comment_author'       => $current_user->display_name,
                'comment_author_email' => $current_user->user_email,
                'comment_content'      => $comment_content,
                'comment_approved'     => 1,
                'comment_type'         => 'helpdesk_reply',
            )
        );
        if ( $comment_id ) {
            update_comment_meta( $comment_id, '_is_user_reply', '0' );
        }

        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
        $attach_urls = swh_handle_multiple_uploads( $_FILES['swh_tech_reply_attachments'] );
        if ( $comment_id && ! empty( $attach_urls ) ) {
            update_comment_meta( $comment_id, '_attachments', $attach_urls );
        }
        $data['message'] = $reply_text ?: __( 'Attached file(s)', 'simple-wp-helpdesk' );
    }
    
    $status_changed = ( $update && $old_status !== $new_status );
    $is_resolving   = ( $resolved_status === $new_status );
    
    $proxy_attach_urls = array_map( function( $url ) use ( $post_id ) {
        return swh_get_file_proxy_url( $url, $post_id );
    }, $attach_urls );

    if ( $data['email'] ) {
        if ( $just_replied && $status_changed && $is_resolving ) {
            swh_send_email( $data['email'], 'swh_em_user_resolved_sub', 'swh_em_user_resolved_body', $data, $proxy_attach_urls );
        } elseif ( $just_replied && $status_changed ) {
            swh_send_email( $data['email'], 'swh_em_user_reply_status_sub', 'swh_em_user_reply_status_body', $data, $proxy_attach_urls );
        } elseif ( $just_replied ) {
            swh_send_email( $data['email'], 'swh_em_user_reply_sub', 'swh_em_user_reply_body', $data, $proxy_attach_urls );
        } elseif ( $status_changed ) {
            $data['message'] = __( 'No additional notes provided.', 'simple-wp-helpdesk' );
            if ( $is_resolving ) {
                swh_send_email( $data['email'], 'swh_em_user_resolved_sub', 'swh_em_user_resolved_body', $data );
            } elseif ( $new_status === get_option( 'swh_reopened_status', $defs['swh_reopened_status'] ) && $old_status === get_option( 'swh_closed_status', $defs['swh_closed_status'] ) ) {
                swh_send_email( $data['email'], 'swh_em_user_reopen_sub', 'swh_em_user_reopen_body', $data );
            } else {
                swh_send_email( $data['email'], 'swh_em_user_status_sub', 'swh_em_user_status_body', $data );
            }
        }
    }
}

// ==============================================================================
// 6. FRONT-END SHORTCODE [submit_ticket]
// ==============================================================================
add_shortcode( 'submit_ticket', 'swh_ticket_frontend' );
function swh_ticket_frontend() {
    wp_enqueue_style( 'swh-frontend', plugin_dir_url( __FILE__ ) . 'assets/swh-frontend.css', array(), SWH_VERSION );
    wp_enqueue_script( 'swh-frontend', plugin_dir_url( __FILE__ ) . 'assets/swh-frontend.js', array(), SWH_VERSION, true );
    wp_localize_script( 'swh-frontend', 'swhConfig', array(
        'maxMb'       => (int) get_option( 'swh_max_upload_size', 5 ),
        'maxFiles'    => (int) get_option( 'swh_max_upload_count', 5 ),
        'allowedExts' => array( 'jpg', 'jpeg', 'jpe', 'png', 'gif', 'pdf', 'doc', 'docx', 'txt' ),
        'i18n'        => array(
            'maxFilesError' => __( 'You may only attach up to %d file(s) per upload.', 'simple-wp-helpdesk' ),
            'invalidType'   => __( 'File "%s" has an invalid type.', 'simple-wp-helpdesk' ),
            'sizeExceeded'  => __( 'File "%s" exceeds the %dMB size limit.', 'simple-wp-helpdesk' ),
        ),
    ) );

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
    <div class="swh-helpdesk-wrapper">
    <?php
    if ( isset( $_GET['swh_ticket'], $_GET['token'] ) ) {
        $ticket_id = absint( $_GET['swh_ticket'] );
        $token     = sanitize_text_field( wp_unslash( $_GET['token'] ) );
        $post      = get_post( $ticket_id );
        $db_token  = get_post_meta( $ticket_id, '_ticket_token', true );
        
        if ( ! $post || 'helpdesk_ticket' !== $post->post_type || ! hash_equals( $db_token, $token ) ) {
            echo '<div class="swh-alert swh-alert-error">' . esc_html( get_option( 'swh_msg_err_invalid', $defs['swh_msg_err_invalid'] ) ) . '</div>';
            return ob_get_clean();
        }

        if ( swh_is_token_expired( $ticket_id ) ) {
            echo '<div class="swh-alert swh-alert-error">' . esc_html( get_option( 'swh_msg_err_expired', $defs['swh_msg_err_expired'] ) ) . '</div>';
            echo '</div>';
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

        // Rate-limit frontend POST actions to one per 30 seconds per ticket + IP.
        $is_post_action = isset( $_POST['swh_user_close_ticket_submit'] ) || isset( $_POST['swh_user_reopen_submit'] ) || isset( $_POST['swh_user_reply_submit'] );
        if ( $is_post_action && swh_is_rate_limited( 'portal_' . $ticket_id, 30 ) ) {
            echo '<div class="swh-alert swh-alert-error">' . esc_html__( 'Please wait a moment before submitting again.', 'simple-wp-helpdesk' ) . '</div>';
            $is_post_action = false;
        }

        if ( $is_post_action && isset( $_POST['swh_user_close_ticket_submit'], $_POST['swh_close_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['swh_close_nonce'] ) ), 'swh_user_close' ) ) {
            update_post_meta( $ticket_id, '_ticket_status', $closed_status );
            delete_post_meta( $ticket_id, '_resolved_timestamp' );
            $comment_id = wp_insert_comment(
                array(
                    'comment_post_ID'      => $ticket_id,
                    'comment_author'       => $data['name'],
                    'comment_author_email' => $data['email'],
                    'comment_content'      => __( 'TICKET CLOSED BY CLIENT', 'simple-wp-helpdesk' ),
                    'comment_approved'     => 1,
                    'comment_type'         => 'helpdesk_reply',
                )
            );
            if ( $comment_id ) {
                update_comment_meta( $comment_id, '_is_user_reply', '1' );
            }
            $admin_email = swh_get_admin_email( $ticket_id );
            swh_send_email( $admin_email, 'swh_em_admin_closed_sub', 'swh_em_admin_closed_body', $data );
            swh_send_email( $data['email'], 'swh_em_user_closed_sub', 'swh_em_user_closed_body', $data );
            echo '<div class="swh-alert swh-alert-success">' . esc_html( get_option( 'swh_msg_success_closed', $defs['swh_msg_success_closed'] ) ) . '</div>';
            $data['status'] = $closed_status;
        } elseif ( $is_post_action && isset( $_POST['swh_user_reopen_submit'], $_POST['swh_reopen_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['swh_reopen_nonce'] ) ), 'swh_user_reopen' ) ) {
            if ( swh_check_antispam( false ) ) {
                echo '<div class="swh-alert swh-alert-error">' . esc_html( get_option( 'swh_msg_err_spam', $defs['swh_msg_err_spam'] ) ) . '</div>';
            } else {
            $reply_text = isset( $_POST['ticket_reopen_text'] ) ? sanitize_textarea_field( wp_unslash( $_POST['ticket_reopen_text'] ) ) : '';
            // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
            $has_files = ! empty( $_FILES['swh_reopen_attachments']['name'][0] );
            if ( $reply_text || $has_files ) {
                update_post_meta( $ticket_id, '_ticket_status', $reopened_status );
                delete_post_meta( $ticket_id, '_resolved_timestamp' );
                $comment_content = $reply_text ?: __( 'Attached file(s)', 'simple-wp-helpdesk' );
                $comment_id      = wp_insert_comment(
                    array(
                        'comment_post_ID'      => $ticket_id,
                        'comment_author'       => $data['name'],
                        'comment_author_email' => $data['email'],
                        'comment_content'      => __( 'TICKET RE-OPENED:', 'simple-wp-helpdesk' ) . " \n" . $comment_content,
                        'comment_approved'     => 1,
                        'comment_type'         => 'helpdesk_reply',
                    )
                );
                if ( $comment_id ) {
                    update_comment_meta( $comment_id, '_is_user_reply', '1' );
                }
                // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
                $attach_urls = swh_handle_multiple_uploads( $_FILES['swh_reopen_attachments'] );
                if ( $comment_id && ! empty( $attach_urls ) ) {
                    update_comment_meta( $comment_id, '_attachments', $attach_urls );
                }
                $data['message'] = $reply_text ?: __( 'Attached file(s)', 'simple-wp-helpdesk' );
                $admin_email = swh_get_admin_email( $ticket_id );
                $proxy_urls  = array_map( function( $u ) use ( $ticket_id ) { return swh_get_file_proxy_url( $u, $ticket_id ); }, $attach_urls );
                swh_send_email( $admin_email, 'swh_em_admin_reopen_sub', 'swh_em_admin_reopen_body', $data, $proxy_urls );
                swh_send_email( $data['email'], 'swh_em_user_reopen_sub', 'swh_em_user_reopen_body', $data );
                echo '<div class="swh-alert swh-alert-success">' . esc_html( get_option( 'swh_msg_success_reopen', $defs['swh_msg_success_reopen'] ) ) . '</div>';
                $data['status'] = $reopened_status;
            }
            } // end anti-spam else
        } elseif ( $is_post_action && isset( $_POST['swh_user_reply_submit'], $_POST['swh_reply_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['swh_reply_nonce'] ) ), 'swh_user_reply' ) ) {
            if ( swh_check_antispam( true ) ) {
                echo '<div class="swh-alert swh-alert-error">' . esc_html( get_option( 'swh_msg_err_spam', $defs['swh_msg_err_spam'] ) ) . '</div>';
            } else {
            $reply_text = isset( $_POST['ticket_reply_text'] ) ? sanitize_textarea_field( wp_unslash( $_POST['ticket_reply_text'] ) ) : '';
            // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
            $has_files = ! empty( $_FILES['swh_user_reply_attachments']['name'][0] );
            if ( $reply_text || $has_files ) {
                if ( $resolved_status === $data['status'] ) {
                    $data['status'] = $reopened_status;
                    update_post_meta( $ticket_id, '_ticket_status', $reopened_status );
                    delete_post_meta( $ticket_id, '_resolved_timestamp' );
                }
                $comment_content = $reply_text ?: __( 'Attached file(s)', 'simple-wp-helpdesk' );
                $comment_id      = wp_insert_comment(
                    array(
                        'comment_post_ID'      => $ticket_id,
                        'comment_author'       => $data['name'],
                        'comment_author_email' => $data['email'],
                        'comment_content'      => $comment_content,
                        'comment_approved'     => 1,
                        'comment_type'         => 'helpdesk_reply',
                    )
                );
                if ( $comment_id ) {
                    update_comment_meta( $comment_id, '_is_user_reply', '1' );
                }
                // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
                $attach_urls = swh_handle_multiple_uploads( $_FILES['swh_user_reply_attachments'] );
                if ( $comment_id && ! empty( $attach_urls ) ) {
                    update_comment_meta( $comment_id, '_attachments', $attach_urls );
                }
                $data['message'] = $reply_text ?: __( 'Attached file(s)', 'simple-wp-helpdesk' );
                $admin_email = swh_get_admin_email( $ticket_id );
                $proxy_urls  = array_map( function( $u ) use ( $ticket_id ) { return swh_get_file_proxy_url( $u, $ticket_id ); }, $attach_urls );
                swh_send_email( $admin_email, 'swh_em_admin_reply_sub', 'swh_em_admin_reply_body', $data, $proxy_urls );
                echo '<div class="swh-alert swh-alert-success">' . esc_html( get_option( 'swh_msg_success_reply', $defs['swh_msg_success_reply'] ) ) . '</div>';
            }
            } // end anti-spam else
        }
        ?>
        <div class="swh-card">
            <div style="float: right; font-weight: bold; color: #666; font-size: 1.2em;"><?php echo esc_html( $data['ticket_id'] ); ?></div>
            <h3 style="margin-top:0; font-size: 22px; color: #222;"><?php echo esc_html( $data['title'] ); ?></h3>
            <p style="margin: 0 0 15px 0;"><strong><?php esc_html_e( 'Status:', 'simple-wp-helpdesk' ); ?></strong> <span class="swh-badge <?php echo ( $closed_status === $data['status'] ) ? 'swh-badge-closed' : 'swh-badge-open'; ?>"><?php echo esc_html( $data['status'] ); ?></span>
            &nbsp;|&nbsp; <strong><?php esc_html_e( 'Priority:', 'simple-wp-helpdesk' ); ?></strong> <?php echo esc_html( $data['priority'] ); ?></p>
            <hr>
            <p><?php echo nl2br( esc_html( $post->post_content ) ); ?></p>
            <?php
            $attachments = get_post_meta( $ticket_id, '_ticket_attachments', true );
            if ( ! empty( $attachments ) && is_array( $attachments ) ) {
                echo '<p><strong>' . esc_html__( 'Attachments:', 'simple-wp-helpdesk' ) . '</strong><br>';
                foreach ( $attachments as $url ) {
                    echo '<a href="' . esc_url( swh_get_file_proxy_url( $url, $ticket_id ) ) . '" target="_blank" style="text-decoration: underline; margin-right:10px; color:#0073aa;">' . esc_html( basename( $url ) ) . '</a>';
                }
                echo '</p>';
            }
            ?>
        </div>
        <h4 style="margin-bottom: 15px;"><?php esc_html_e( 'Conversation History', 'simple-wp-helpdesk' ); ?></h4>
        <div style="margin-bottom: 20px;">
        <?php
        $comments = get_comments(
            array(
                'post_id' => $ticket_id,
                'order'   => 'ASC',
                'type'    => 'helpdesk_reply',
            )
        );
        if ( $comments ) {
            foreach ( $comments as $comment ) {
                if ( get_comment_meta( $comment->comment_ID, '_is_internal_note', true ) ) {
                    continue;
                }
                $is_user      = get_comment_meta( $comment->comment_ID, '_is_user_reply', true );
                /* translators: %s: technician name */
                $author_name  = $is_user ? __( 'You', 'simple-wp-helpdesk' ) : sprintf( __( 'Technician (%s)', 'simple-wp-helpdesk' ), $comment->comment_author );
                $bubble_class = $is_user ? 'swh-chat-user' : 'swh-chat-tech';
                $attach_urls  = get_comment_meta( $comment->comment_ID, '_attachments', true );
                
                echo '<div class="swh-chat-bubble ' . esc_attr( $bubble_class ) . '">';
                echo '<strong style="display:block; margin-bottom: 5px;">' . esc_html( $author_name ) . ' <span style="font-weight:normal; font-size: 0.85em; color: #777;">(' . esc_html( $comment->comment_date ) . ')</span></strong>';
                echo nl2br( esc_html( $comment->comment_content ) );
                if ( ! empty( $attach_urls ) && is_array( $attach_urls ) ) {
                    echo '<div style="margin-top: 10px;">';
                    foreach ( $attach_urls as $url ) {
                        echo '<a href="' . esc_url( swh_get_file_proxy_url( $url, $ticket_id ) ) . '" target="_blank" style="text-decoration: underline; margin-right:10px; color:#0073aa; font-size:13px;">' . esc_html( basename( $url ) ) . '</a>';
                    }
                    echo '</div>';
                }
                echo '</div>';
            }
        } else {
            echo '<p>' . esc_html__( 'No replies yet.', 'simple-wp-helpdesk' ) . '</p>';
        }
        ?>
        </div>
        <?php if ( $resolved_status === $data['status'] ) : ?>
            <div class="swh-alert swh-alert-info">
                <div>
                    <h4 style="margin: 0 0 5px 0; color: #005980;"><?php esc_html_e( 'Is your issue fully resolved?', 'simple-wp-helpdesk' ); ?></h4>
                    <p style="margin: 0; font-size: 13px; color: #005980;"><?php esc_html_e( 'Click the button to close this ticket, or use the form below to reply if you still need help.', 'simple-wp-helpdesk' ); ?></p>
                </div>
                <form method="POST" action="" style="margin:0;">
                    <?php wp_nonce_field( 'swh_user_close', 'swh_close_nonce' ); ?>
                    <input type="submit" name="swh_user_close_ticket_submit" value="<?php esc_attr_e( 'Yes, Close Ticket', 'simple-wp-helpdesk' ); ?>" class="swh-btn">
                </form>
            </div>
        <?php endif; ?>
        <?php if ( $closed_status !== $data['status'] ) : ?>
            <form method="POST" action="" enctype="multipart/form-data">
                <?php wp_nonce_field( 'swh_user_reply', 'swh_reply_nonce' ); ?>
                <div class="swh-form-group">
                    <label><?php esc_html_e( 'Add a Reply:', 'simple-wp-helpdesk' ); ?></label>
                    <textarea name="ticket_reply_text" rows="4" class="swh-form-control"></textarea>
                </div>
                <div class="swh-form-group">
                    <label><?php esc_html_e( 'Attach Files (Optional):', 'simple-wp-helpdesk' ); ?></label>
                    <input type="file" name="swh_user_reply_attachments[]" multiple accept=".jpg,.jpeg,.png,.gif,.pdf,.doc,.docx,.txt" class="swh-form-control swh-file-input">
                    <small style="color:#666; display:block; margin-top:5px;"><?php
                        /* translators: 1: max upload size in MB, 2: max file count */
                        printf( esc_html__( 'Allowed file types: JPG, JPEG, PNG, GIF, PDF, DOC, DOCX, TXT. Max size: %1$sMB per file. Max files: %2$s.', 'simple-wp-helpdesk' ), esc_html( get_option( 'swh_max_upload_size', 5 ) ), esc_html( get_option( 'swh_max_upload_count', 5 ) ) );
                    ?></small>
                </div>
                <?php
                if ( 'honeypot' === $spam_method ) {
                    echo '<div style="position: absolute; left: -9999px;"><label>Leave this empty</label><input type="text" name="swh_website_url_hp" value="" tabindex="-1" autocomplete="off"></div>';
                } elseif ( 'recaptcha' === $spam_method ) {
                    $key = get_option( 'swh_recaptcha_site_key' );
                    echo '<div id="swh-recaptcha-reply" style="margin-bottom: 15px;"></div>';
                    wp_enqueue_script( 'google-recaptcha', 'https://www.google.com/recaptcha/api.js?onload=swhRecaptchaLoad&render=explicit', array(), null, true );
                    wp_add_inline_script( 'google-recaptcha', 'window.swhRecaptchaLoad = function() { document.querySelectorAll("[id^=swh-recaptcha-]").forEach(function(el) { if(window.grecaptcha && !el.hasChildNodes()) { grecaptcha.render(el.id, {"sitekey": "' . esc_js( $key ) . '"}); } }); };', 'before' );
                } elseif ( 'turnstile' === $spam_method ) {
                    $key = get_option( 'swh_turnstile_site_key' );
                    echo '<div id="swh-turnstile-reply" style="margin-bottom: 15px;"></div>';
                    wp_enqueue_script( 'cf-turnstile', 'https://challenges.cloudflare.com/turnstile/v0/api.js?onload=swhTurnstileLoad&render=explicit', array(), null, true );
                    wp_add_inline_script( 'cf-turnstile', 'window.swhTurnstileLoad = function() { document.querySelectorAll("[id^=swh-turnstile-]").forEach(function(el) { if(window.turnstile && !el.hasChildNodes()) { turnstile.render("#" + el.id, {sitekey: "' . esc_js( $key ) . '"}); } }); };', 'before' );
                }
                ?>
                <div class="swh-form-group">
                    <input type="submit" name="swh_user_reply_submit" value="<?php esc_attr_e( 'Send Reply', 'simple-wp-helpdesk' ); ?>" class="swh-btn">
                </div>
            </form>
        <?php else : ?>
            <div class="swh-alert swh-alert-error">
                <p style="margin-top: 0; font-weight: bold;"><?php esc_html_e( 'This ticket is closed.', 'simple-wp-helpdesk' ); ?></p>
                <form method="POST" action="" enctype="multipart/form-data">
                    <?php wp_nonce_field( 'swh_user_reopen', 'swh_reopen_nonce' ); ?>
                    <div class="swh-form-group">
                        <label><?php esc_html_e( 'Explain why you need this re-opened:', 'simple-wp-helpdesk' ); ?></label>
                        <textarea name="ticket_reopen_text" rows="3" class="swh-form-control"></textarea>
                    </div>
                    <div class="swh-form-group">
                        <label><?php esc_html_e( 'Attach Files (Optional):', 'simple-wp-helpdesk' ); ?></label>
                        <input type="file" name="swh_reopen_attachments[]" multiple accept=".jpg,.jpeg,.png,.gif,.pdf,.doc,.docx,.txt" class="swh-form-control swh-file-input">
                        <small style="color:#721c24; display:block; margin-top:5px;"><?php
                            /* translators: 1: max upload size in MB, 2: max file count */
                            printf( esc_html__( 'Allowed file types: JPG, JPEG, PNG, GIF, PDF, DOC, DOCX, TXT. Max size: %1$sMB. Max files: %2$s.', 'simple-wp-helpdesk' ), esc_html( get_option( 'swh_max_upload_size', 5 ) ), esc_html( get_option( 'swh_max_upload_count', 5 ) ) );
                        ?></small>
                    </div>
                    <?php if ( 'honeypot' === $spam_method ) : ?>
                        <div style="position: absolute; left: -9999px;"><label>Leave this empty</label><input type="text" name="swh_website_url_hp" value="" tabindex="-1" autocomplete="off"></div>
                    <?php endif; ?>
                    <div class="swh-form-group">
                        <input type="submit" name="swh_user_reopen_submit" value="<?php esc_attr_e( 'Re-open Ticket', 'simple-wp-helpdesk' ); ?>" class="swh-btn swh-btn-danger">
                    </div>
                </form>
            </div>
        <?php endif; ?>
        </div> <!-- End .swh-helpdesk-wrapper -->
        <?php
        return ob_get_clean();
    }
    
    $current_user = wp_get_current_user();
    $form_name    = is_user_logged_in() ? $current_user->display_name : '';
    $form_email   = is_user_logged_in() ? $current_user->user_email : '';
    $form_prio    = $default_prio;
    $form_title   = '';
    $form_desc    = '';
    
    $submit_rate_passed = true;
    if ( isset( $_POST['swh_submit_ticket'] ) ) {
        if ( swh_is_rate_limited( 'submit', 30 ) ) {
            echo '<div class="swh-alert swh-alert-error">' . esc_html__( 'Please wait a moment before submitting again.', 'simple-wp-helpdesk' ) . '</div>';
            $submit_rate_passed = false;
        }
    }

    if ( $submit_rate_passed && isset( $_POST['swh_submit_ticket'], $_POST['swh_ticket_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['swh_ticket_nonce'] ) ), 'swh_create_ticket' ) ) {
        $data = array(
            'name'     => isset( $_POST['ticket_name'] ) ? sanitize_text_field( wp_unslash( $_POST['ticket_name'] ) ) : '',
            'email'    => isset( $_POST['ticket_email'] ) ? sanitize_email( wp_unslash( $_POST['ticket_email'] ) ) : '',
            'title'    => isset( $_POST['ticket_title'] ) ? sanitize_text_field( wp_unslash( $_POST['ticket_title'] ) ) : '',
            'message'  => isset( $_POST['ticket_desc'] ) ? sanitize_textarea_field( wp_unslash( $_POST['ticket_desc'] ) ) : '',
            'priority' => isset( $_POST['ticket_priority'] ) ? sanitize_text_field( wp_unslash( $_POST['ticket_priority'] ) ) : '',
            'status'   => $default_status,
        );
        // Validate priority against allowed list.
        if ( ! in_array( $data['priority'], $priorities, true ) ) {
            $data['priority'] = $default_prio;
        }
        $is_spam = swh_check_antispam( true );
        
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
            if ( $ticket_id && ! is_wp_error( $ticket_id ) ) {
                $attach_urls = array();
                // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
                if ( ! empty( $_FILES['ticket_attachments']['name'][0] ) ) {
                    $attach_urls = swh_handle_multiple_uploads( $_FILES['ticket_attachments'] );
                }
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
                update_post_meta( $ticket_id, '_ticket_token_created', time() );
                update_post_meta( $ticket_id, '_ticket_url', get_permalink() );
                $default_assignee = get_option( 'swh_default_assignee' );
                if ( $default_assignee ) {
                    update_post_meta( $ticket_id, '_ticket_assigned_to', $default_assignee );
                }
                swh_send_email( $data['email'], 'swh_em_user_new_sub', 'swh_em_user_new_body', $data );
                $admin_email = swh_get_admin_email( $ticket_id );
                $proxy_urls  = array_map( function( $u ) use ( $ticket_id ) { return swh_get_file_proxy_url( $u, $ticket_id ); }, $attach_urls );
                swh_send_email( $admin_email, 'swh_em_admin_new_sub', 'swh_em_admin_new_body', $data, $proxy_urls );
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

    // Handle ticket lookup form.
    if ( isset( $_POST['swh_ticket_lookup'], $_POST['swh_lookup_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['swh_lookup_nonce'] ) ), 'swh_ticket_lookup' ) ) {
        if ( swh_is_rate_limited( 'lookup', 60 ) ) {
            echo '<div class="swh-alert swh-alert-error">' . esc_html__( 'Please wait a moment before submitting again.', 'simple-wp-helpdesk' ) . '</div>';
        } elseif ( swh_check_antispam( false ) ) {
            echo '<div class="swh-alert swh-alert-error">' . esc_html( get_option( 'swh_msg_err_spam', $defs['swh_msg_err_spam'] ) ) . '</div>';
        } else {
            $lookup_email = isset( $_POST['swh_lookup_email'] ) ? sanitize_email( wp_unslash( $_POST['swh_lookup_email'] ) ) : '';
            if ( $lookup_email ) {
                // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
                $lookup_tickets = get_posts(
                    array(
                        'post_type'      => 'helpdesk_ticket',
                        'posts_per_page' => -1,
                        'post_status'    => 'publish',
                        'meta_query'     => array(
                            'relation' => 'AND',
                            array( 'key' => '_ticket_email', 'value' => $lookup_email ),
                            array( 'key' => '_ticket_status', 'value' => $closed_status, 'compare' => '!=' ),
                        ),
                    )
                );
                if ( ! empty( $lookup_tickets ) ) {
                    $ticket_links = '';
                    foreach ( $lookup_tickets as $lt ) {
                    $new_token = wp_generate_password( 20, false );
                    update_post_meta( $lt->ID, '_ticket_token', $new_token );
                    update_post_meta( $lt->ID, '_ticket_token_created', time() );
                        $link = swh_get_secure_ticket_link( $lt->ID );
                        if ( $link ) {
                            $uid = get_post_meta( $lt->ID, '_ticket_uid', true );
                            $ticket_links .= '- ' . $uid . ': ' . $lt->post_title . "\n  " . $link . "\n\n";
                        }
                    }
                    $lookup_data = array(
                        'email'        => $lookup_email,
                        'ticket_links' => $ticket_links,
                    );
                    swh_send_email( $lookup_email, 'swh_em_user_lookup_sub', 'swh_em_user_lookup_body', $lookup_data );
                }
            }
            // Always show the same message to prevent email enumeration.
            echo '<div class="swh-alert swh-alert-success">' . esc_html( get_option( 'swh_msg_success_lookup', $defs['swh_msg_success_lookup'] ) ) . '</div>';
        }
    }
    ?>
    <form method="POST" action="" enctype="multipart/form-data">
        <?php wp_nonce_field( 'swh_create_ticket', 'swh_ticket_nonce' ); ?>
        <div class="swh-form-group">
            <label><?php esc_html_e( 'Your Name:', 'simple-wp-helpdesk' ); ?></label>
            <input type="text" name="ticket_name" required class="swh-form-control" value="<?php echo esc_attr( $form_name ); ?>">
        </div>
        <div class="swh-form-group">
            <label><?php esc_html_e( 'Your Email:', 'simple-wp-helpdesk' ); ?></label>
            <input type="email" name="ticket_email" required class="swh-form-control" value="<?php echo esc_attr( $form_email ); ?>">
        </div>
        <div class="swh-form-group">
            <label><?php esc_html_e( 'Priority:', 'simple-wp-helpdesk' ); ?></label>
            <select name="ticket_priority" class="swh-form-control">
                <?php foreach ( $priorities as $p ) : ?>
                    <option value="<?php echo esc_attr( $p ); ?>" <?php selected( $form_prio, $p ); ?>><?php echo esc_html( $p ); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="swh-form-group">
            <label><?php esc_html_e( 'Problem Summary (Title):', 'simple-wp-helpdesk' ); ?></label>
            <input type="text" name="ticket_title" required class="swh-form-control" value="<?php echo esc_attr( $form_title ); ?>">
        </div>
        <div class="swh-form-group">
            <label><?php esc_html_e( 'Problem Description:', 'simple-wp-helpdesk' ); ?></label>
            <textarea name="ticket_desc" rows="5" required class="swh-form-control"><?php echo esc_textarea( $form_desc ); ?></textarea>
        </div>
        <div class="swh-form-group">
            <label><?php esc_html_e( 'Attachments (Optional):', 'simple-wp-helpdesk' ); ?></label>
            <input type="file" name="ticket_attachments[]" multiple accept=".jpg,.jpeg,.png,.gif,.pdf,.doc,.docx,.txt" class="swh-form-control swh-file-input" style="padding: 5px;">
            <small style="color:#666; display:block; margin-top:5px;"><?php
                /* translators: 1: max upload size in MB, 2: max file count */
                printf( esc_html__( 'Allowed file types: JPG, JPEG, PNG, GIF, PDF, DOC, DOCX, TXT. Max size: %1$sMB. Max files: %2$s.', 'simple-wp-helpdesk' ), esc_html( get_option( 'swh_max_upload_size', 5 ) ), esc_html( get_option( 'swh_max_upload_count', 5 ) ) );
            ?></small>
        </div>
        <?php
        // EXPLICIT RENDERING FOR ANTI-SPAM
        if ( 'honeypot' === $spam_method ) {
            echo '<div style="position: absolute; left: -9999px;"><label>Leave this empty</label><input type="text" name="swh_website_url_hp" value="" tabindex="-1" autocomplete="off"></div>';
        } elseif ( 'recaptcha' === $spam_method ) {
            $key = get_option( 'swh_recaptcha_site_key' );
            echo '<div id="swh-recaptcha-box" style="margin-bottom: 15px;"></div>';
            wp_enqueue_script( 'google-recaptcha', 'https://www.google.com/recaptcha/api.js?onload=swhRecaptchaLoad&render=explicit', array(), null, true );
            wp_add_inline_script(
                'google-recaptcha',
                'window.swhRecaptchaLoad = function() { if(document.getElementById("swh-recaptcha-box") && window.grecaptcha) { grecaptcha.render("swh-recaptcha-box", {"sitekey": "' . esc_js( $key ) . '"}); } };',
                'before'
            );
        } elseif ( 'turnstile' === $spam_method ) {
            $key = get_option( 'swh_turnstile_site_key' );
            echo '<div id="swh-turnstile-box" style="margin-bottom: 15px;"></div>';
            wp_enqueue_script( 'cf-turnstile', 'https://challenges.cloudflare.com/turnstile/v0/api.js?onload=swhTurnstileLoad&render=explicit', array(), null, true );
            wp_add_inline_script(
                'cf-turnstile',
                'window.swhTurnstileLoad = function() { if(document.getElementById("swh-turnstile-box") && window.turnstile) { turnstile.render("#swh-turnstile-box", {sitekey: "' . esc_js( $key ) . '"}); } };',
                'before'
            );
        }
        ?>
        <div class="swh-form-group">
            <input type="submit" name="swh_submit_ticket" value="<?php esc_attr_e( 'Submit Ticket', 'simple-wp-helpdesk' ); ?>" class="swh-btn">
        </div>
    </form>
    <div class="swh-lookup-section">
        <p><a href="#" id="swh-toggle-lookup"><?php esc_html_e( 'Already submitted a ticket? Resend my ticket links', 'simple-wp-helpdesk' ); ?></a></p>
        <div id="swh-lookup-form" style="display:none;">
            <form method="POST" action="">
                <?php wp_nonce_field( 'swh_ticket_lookup', 'swh_lookup_nonce' ); ?>
                <div class="swh-form-group">
                    <label><?php esc_html_e( 'Your Email Address:', 'simple-wp-helpdesk' ); ?></label>
                    <input type="email" name="swh_lookup_email" required class="swh-form-control">
                </div>
                <?php if ( 'honeypot' === $spam_method ) : ?>
                    <div style="position: absolute; left: -9999px;"><label>Leave this empty</label><input type="text" name="swh_website_url_hp" value="" tabindex="-1" autocomplete="off"></div>
                <?php endif; ?>
                <div class="swh-form-group">
                    <input type="submit" name="swh_ticket_lookup" value="<?php esc_attr_e( 'Send My Ticket Links', 'simple-wp-helpdesk' ); ?>" class="swh-btn">
                </div>
            </form>
        </div>
    </div>
    </div> <!-- End .swh-helpdesk-wrapper -->
    <?php
    return ob_get_clean();
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
        // Cache-busted key to force an immediate fresh check of the API
        $transient_key = 'swh_gh_release_v3_' . SWH_VERSION;
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
        
        $download_url = $release->zipball_url;
        if ( ! empty( $release->assets ) && isset( $release->assets[0]->browser_download_url ) ) {
            $download_url = $release->assets[0]->browser_download_url;
        }

        if ( version_compare( SWH_VERSION, $latest_version, '<' ) ) {
            $plugin_data = array(
                'slug'        => $this->plugin_slug,
                'plugin'      => $this->plugin_file,
                'new_version' => $latest_version,
                'url'         => $release->html_url,
                'package'     => $download_url,
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
        
        $download_url = $release->zipball_url;
        if ( ! empty( $release->assets ) && isset( $release->assets[0]->browser_download_url ) ) {
            $download_url = $release->assets[0]->browser_download_url;
        }

        $plugin_info = array(
            'name'          => 'Simple WP Helpdesk',
            'slug'          => $this->plugin_slug,
            'version'       => $latest_version,
            'author'        => '<a href="https://github.com/' . esc_attr( $this->github_user ) . '">SM WP Plugins</a>',
            'homepage'      => $release->html_url,
            'requires'      => '5.3',
            'tested'        => get_bloginfo( 'version' ),
            'requires_php'  => '7.4',
            'last_updated'  => $release->published_at,
            'sections'      => array(
                'description' => 'A comprehensive helpdesk system natively built for WordPress.',
                'changelog'   => nl2br( esc_html( $release->body ) ),
            ),
            'download_link' => $download_url,
        );
        return (object) $plugin_info;
    }
    
    public function rename_github_zip_folder( $source, $remote_source, $wp_upgrader ) {
        global $wp_filesystem;
        if ( ! isset( $wp_upgrader->skin->plugin ) || $this->plugin_file !== $wp_upgrader->skin->plugin ) {
            return $source;
        }
        
        // Smart Folder-Flattening: If the repo has the plugin buried in a subfolder matching the slug, target that folder.
        $inner_folder = trailingslashit( $source ) . $this->plugin_slug;
        if ( $wp_filesystem->is_dir( $inner_folder ) ) {
            $source = $inner_folder;
        }
        
        $new_source = trailingslashit( $remote_source ) . $this->plugin_slug;
        
        if ( untrailingslashit( $source ) !== untrailingslashit( $new_source ) ) {
            $wp_filesystem->move( $source, $new_source );
            return trailingslashit( $new_source );
        }
        
        return trailingslashit( $source );
    }
}
new SWH_GitHub_Updater();