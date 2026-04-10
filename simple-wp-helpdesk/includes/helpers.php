<?php
/**
 * Global helpers: defaults, statuses, anti-spam, rate limiting, and ticket link utilities.
 *
 * @package Simple_WP_Helpdesk
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Returns the plugin's complete default option values.
 *
 * Result is cached per request via a static variable to avoid repeated calls.
 *
 * @return array<string, mixed> Associative array of option keys to their default values.
 */
function swh_get_defaults() {
	static $defaults = null;
	if ( null === $defaults ) {
		$defaults = array(
			// General.
			'swh_ticket_priorities'          => 'Low, Medium, High',
			'swh_default_priority'           => 'Medium',
			'swh_ticket_statuses'            => 'Open, In Progress, Resolved, Closed',
			'swh_default_status'             => 'Open',
			'swh_resolved_status'            => 'Resolved',
			'swh_closed_status'              => 'Closed',
			'swh_reopened_status'            => 'Open',
			'swh_autoclose_days'             => 3,
			'swh_max_upload_size'            => 5,
			'swh_max_upload_count'           => 5,
			// Assignment & Routing.
			'swh_default_assignee'           => '',
			'swh_fallback_email'             => '',
			'swh_ticket_page_id'             => 0,
			'swh_token_expiration_days'      => 90,
			'swh_restrict_to_assigned'       => 'no',
			// Email Format.
			'swh_email_format'               => 'html',
			// Anti-Spam.
			'swh_spam_method'                => 'honeypot',
			'swh_recaptcha_site_key'         => '',
			'swh_recaptcha_secret_key'       => '',
			'swh_turnstile_site_key'         => '',
			'swh_turnstile_secret_key'       => '',
			// Data Retention & Tools.
			'swh_retention_attachments_days' => 0,
			'swh_retention_tickets_days'     => 0,
			'swh_delete_on_uninstall'        => 'no',
			// Email Templates.
			'swh_em_user_new_sub'            => 'Ticket Received: {title}',
			'swh_em_user_new_body'           => "Hi {name},\n\nWe have received your ticket (ID: {ticket_id}).\n\nYou can view your ticket status and reply to our technicians here:\n{ticket_url}",
			'swh_em_user_reply_sub'          => 'New Reply to Ticket {ticket_id}: {title}',
			'swh_em_user_reply_body'         => "Hi {name},\n\nA technician has replied to your ticket.\n\n{if message}Reply:\n{message}\n\n{endif message}View conversation and reply here:\n{ticket_url}",
			'swh_em_user_status_sub'         => 'Status Updated: Ticket {ticket_id}',
			'swh_em_user_status_body'        => "Hi {name},\n\nThe status of your ticket has been updated to: {status}\n\nView or reply to your ticket here:\n{ticket_url}",
			'swh_em_user_reply_status_sub'   => 'Ticket Updated: {title}',
			'swh_em_user_reply_status_body'  => "Hi {name},\n\nA technician has replied to your ticket and the status is now: {status}.\n\n{if message}Reply:\n{message}\n\n{endif message}View conversation and reply here:\n{ticket_url}",
			'swh_em_user_resolved_sub'       => 'Ticket Resolved: {title}',
			'swh_em_user_resolved_body'      => "Hi {name},\n\nYour ticket has been marked as resolved by a technician.\n\n{if message}Technician Note:\n{message}\n\n{endif message}{if autoclose_days}Please note: If we do not hear back from you, this ticket will be automatically closed in {autoclose_days} days.\n\n{endif autoclose_days}View or reply to your ticket here:\n{ticket_url}",
			'swh_em_user_reopen_sub'         => 'Ticket Re-Opened: {ticket_id}',
			'swh_em_user_reopen_body'        => "Hi {name},\n\nYour ticket has been re-opened.\n\nView or reply to your ticket here:\n{ticket_url}",
			'swh_em_user_autoclose_sub'      => 'Ticket Auto-Closed: {ticket_id}',
			'swh_em_user_autoclose_body'     => "Hi {name},\n\nSince we haven't heard from you recently, we have automatically closed your ticket.\n\nIf the problem still exists, you can re-open it by clicking the link below:\n{ticket_url}",
			'swh_em_user_closed_sub'         => 'Ticket Closed: {title}',
			'swh_em_user_closed_body'        => "Hi {name},\n\nYou have successfully closed your ticket.\n\nView your ticket here:\n{ticket_url}",
			'swh_em_admin_new_sub'           => 'New Ticket Submitted [{ticket_id}]',
			'swh_em_admin_new_body'          => "A new ticket was submitted by {name}.\n\nPriority: {priority}\nTitle: {title}\n\nDescription:\n{message}\n\nView/Edit Ticket in Admin:\n{admin_url}",
			'swh_em_admin_reply_sub'         => 'Client Reply on Ticket {ticket_id}',
			'swh_em_admin_reply_body'        => "{name} has replied to their ticket.\n\nReply:\n{message}\n\nView/Edit Ticket in Admin:\n{admin_url}",
			'swh_em_admin_reopen_sub'        => 'Ticket RE-OPENED [{ticket_id}]',
			'swh_em_admin_reopen_body'       => "{name} has re-opened their ticket.\n\n{if message}Reason:\n{message}\n\n{endif message}View/Edit Ticket in Admin:\n{admin_url}",
			'swh_em_admin_closed_sub'        => 'Ticket Closed by Client [{ticket_id}]',
			'swh_em_admin_closed_body'       => "{name} has marked their ticket as closed.\n\nView/Edit Ticket in Admin:\n{admin_url}",
			'swh_em_assigned_sub'            => 'Ticket #{ticket_id} Has Been Assigned to You',
			'swh_em_assigned_body'           => "Hi,\n\nTicket #{ticket_id} — {title} — has been assigned to you.\n\nPriority: {priority}\n\nView/Edit Ticket:\n{admin_url}",
			'swh_msg_success_new'            => 'Your ticket has been submitted successfully! Check your email for a secure link to track your ticket.',
			'swh_msg_success_reply'          => 'Your reply has been added.',
			'swh_msg_success_reopen'         => 'Your ticket has been successfully re-opened. Our team has been notified.',
			'swh_msg_success_closed'         => 'Your ticket has been successfully closed.',
			'swh_msg_err_spam'               => 'Anti-spam verification failed. Please try again.',
			'swh_msg_err_missing'            => 'Please fill in all required fields.',
			'swh_msg_err_invalid'            => 'Invalid or expired ticket link.',
			'swh_msg_err_expired'            => 'This ticket link has expired. Please use the lookup form below to receive a fresh link.',
			'swh_msg_success_lookup'         => 'If we have tickets on file for that email address, links have been sent.',
			// Lookup email template.
			'swh_em_user_lookup_sub'         => 'Your Open Tickets',
			'swh_em_user_lookup_body'        => "Hi,\n\nHere are links to your open helpdesk tickets:\n\n{ticket_links}\n\nIf you did not request this, you can safely ignore this email.",
			// Canned responses (structured array; registered here so factory reset + uninstall clean it up).
			'swh_canned_responses'           => array(),
		);
	}
	return $defaults;
}

/**
 * Returns all plugin option keys managed by defaults (excludes swh_db_version).
 *
 * @return string[] List of option key names.
 */
function swh_get_all_option_keys() {
	// swh_db_version is managed separately and excluded from bulk operations.
	return array_keys( swh_get_defaults() );
}

/**
 * Returns the configured list of ticket statuses.
 *
 * @return string[] Trimmed status label strings.
 */
function swh_get_statuses() {
	$defs     = swh_get_defaults();
	$statuses = array_map( 'trim', explode( ',', get_option( 'swh_ticket_statuses', $defs['swh_ticket_statuses'] ) ) );
	/**
	 * Filters the list of available ticket statuses.
	 *
	 * @since 2.1.0
	 * @param string[] $statuses Array of status label strings.
	 */
	return apply_filters( 'swh_ticket_statuses', $statuses );
}

/**
 * Returns the configured list of ticket priorities.
 *
 * @return string[] Trimmed priority label strings.
 */
function swh_get_priorities() {
	$defs       = swh_get_defaults();
	$priorities = array_map( 'trim', explode( ',', get_option( 'swh_ticket_priorities', $defs['swh_ticket_priorities'] ) ) );
	/**
	 * Filters the list of available ticket priorities.
	 *
	 * @since 2.1.0
	 * @param string[] $priorities Array of priority label strings.
	 */
	return apply_filters( 'swh_ticket_priorities', $priorities );
}

/**
 * Builds a secure client portal URL for a ticket using its token.
 *
 * Uses the configured helpdesk page ID if set; falls back to the stored _ticket_url meta.
 * Returns false if the ticket has no token or no base URL is available.
 *
 * @param int $ticket_id The ticket post ID.
 * @return string|false The full portal URL with token, or false on failure.
 */
function swh_get_secure_ticket_link( $ticket_id ) {
	$token = get_post_meta( $ticket_id, '_ticket_token', true );
	if ( ! $token ) {
		return false;
	}
	$page_id  = (int) get_option( 'swh_ticket_page_id', 0 );
	$base_url = $page_id ? get_permalink( $page_id ) : get_post_meta( $ticket_id, '_ticket_url', true );
	if ( ! $base_url ) {
		return false;
	}
	return add_query_arg(
		array(
			'swh_ticket' => $ticket_id,
			'token'      => $token,
		),
		$base_url
	);
}

/**
 * Checks whether a ticket's portal token has exceeded its configured TTL.
 *
 * Tickets created before v1.9.0 (without _ticket_token_created) are grandfathered
 * and never considered expired. A TTL of 0 also disables expiration.
 *
 * @param int $ticket_id The ticket post ID.
 * @return bool True if the token is expired, false otherwise.
 */
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

/**
 * Returns the client IP address, respecting Cloudflare and reverse proxy headers.
 *
 * Checks CF-Connecting-IP, X-Forwarded-For, then REMOTE_ADDR in order.
 * Never accesses REMOTE_ADDR directly.
 *
 * @return string The client IP address, or empty string if unavailable.
 */
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

/**
 * Runs the configured anti-spam check (honeypot, reCAPTCHA, or Turnstile).
 *
 * Returns true if spam is detected (i.e., the submission should be blocked).
 *
 * @param bool $check_captcha Whether to check CAPTCHA (skip on lookup forms that have no CAPTCHA).
 * @return bool True if the submission is spam, false if it is legitimate.
 */
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

/**
 * Checks and sets a rate-limit lock keyed by action name and client IP.
 *
 * Returns true if the client is currently rate-limited. When not limited,
 * sets the lock and returns false. Portal actions use per-action keys
 * (portal_close_, portal_reopen_, portal_reply_) to prevent interference.
 *
 * @param string $action A unique action identifier (e.g. 'portal_reply_42').
 * @param int    $ttl    Lock duration in seconds. Default 30.
 * @return bool True if rate-limited, false if the action is allowed.
 */
function swh_is_rate_limited( $action, $ttl = 30 ) {
	/**
	 * Filters the rate-limit TTL (in seconds) for a given action.
	 *
	 * @since 2.1.0
	 * @param int    $ttl    Lock duration in seconds.
	 * @param string $action The action identifier being rate-limited.
	 */
	$ttl = (int) apply_filters( 'swh_rate_limit_ttl', $ttl, $action );
	$key = 'swh_rl_' . md5( $action . '_' . swh_get_client_ip() );
	$val = get_option( $key );
	if ( false !== $val && (int) $val > time() ) {
		return true;
	}
	update_option( $key, time() + $ttl, false );
	return false;
}
