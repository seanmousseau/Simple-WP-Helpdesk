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
			'swh_recaptcha_type'             => 'v2',
			'swh_recaptcha_project_id'       => '',
			'swh_recaptcha_api_key'          => '',
			'swh_recaptcha_threshold'        => '0.5',
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
			// Ticket templates (structured array; pre-configured submission types with pre-filled descriptions).
			'swh_ticket_templates'           => array(),
			// CC / Watchers.
			// (stored as _ticket_cc_emails post meta; no default option needed)
			// SLA.
			'swh_sla_warn_hours'             => '4',
			'swh_sla_breach_hours'           => '8',
			'swh_sla_notify_email'           => '',
			// Auto-assignment rules (JSON array of {category_term_id, assignee_user_id}).
			'swh_assignment_rules'           => array(),
			// Inbound email webhook.
			'swh_inbound_secret'             => '',
			// Email template: SLA breach digest.
			'swh_em_admin_sla_breach_sub'    => 'SLA Breach Alert: {sla_count} overdue ticket(s)',
			'swh_em_admin_sla_breach_body'   => "The following tickets have breached their SLA:\n\n{sla_list}",
			// Email templates: ticket merge notification.
			'swh_em_user_merged_sub'         => 'Your ticket has been merged: {ticket_id}',
			'swh_em_user_merged_body'        => "Hi {name},\n\nYour ticket ({ticket_id}) has been merged into ticket {target_ticket_id}.\n\nYou can continue viewing your conversation here:\n{target_ticket_url}",
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
	$defs             = swh_get_defaults();
	$default_statuses = is_string( $defs['swh_ticket_statuses'] ) ? $defs['swh_ticket_statuses'] : '';
	$statuses         = array_map( 'trim', explode( ',', swh_get_string_option( 'swh_ticket_statuses', $default_statuses ) ) );
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
	$defs               = swh_get_defaults();
	$default_priorities = is_string( $defs['swh_ticket_priorities'] ) ? $defs['swh_ticket_priorities'] : '';
	$priorities         = array_map( 'trim', explode( ',', swh_get_string_option( 'swh_ticket_priorities', $default_priorities ) ) );
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
	$page_id  = swh_get_int_option( 'swh_ticket_page_id', 0 );
	$base_url = $page_id ? get_permalink( $page_id ) : swh_get_string_meta( $ticket_id, '_ticket_url' );
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
	$days = swh_get_int_option( 'swh_token_expiration_days', 90 );
	if ( 0 === $days ) {
		return false;
	}
	$created = swh_get_int_meta( $ticket_id, '_ticket_token_created' );
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
	if ( ! empty( $_SERVER['HTTP_CF_CONNECTING_IP'] ) && is_string( $_SERVER['HTTP_CF_CONNECTING_IP'] ) ) {
		return sanitize_text_field( wp_unslash( $_SERVER['HTTP_CF_CONNECTING_IP'] ) );
	}
	if ( ! empty( $_SERVER['HTTP_X_FORWARDED_FOR'] ) && is_string( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) {
		$ips = explode( ',', sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) );
		return trim( $ips[0] );
	}
	return isset( $_SERVER['REMOTE_ADDR'] ) && is_string( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
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
		$recaptcha_type = swh_get_string_option( 'swh_recaptcha_type', 'v2' );
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- reCAPTCHA token has no nonce; validated server-side by Google's API.
		$token = isset( $_POST['g-recaptcha-response'] ) && is_string( $_POST['g-recaptcha-response'] ) ? sanitize_text_field( wp_unslash( $_POST['g-recaptcha-response'] ) ) : '';
		if ( 'enterprise' === $recaptcha_type ) {
			$project_id = swh_get_string_option( 'swh_recaptcha_project_id' );
			$api_key    = swh_get_string_option( 'swh_recaptcha_api_key' );
			$site_key   = swh_get_string_option( 'swh_recaptcha_site_key' );
			$threshold  = (float) swh_get_string_option( 'swh_recaptcha_threshold', '0.5' );
			if ( ! $project_id || ! $api_key ) {
				return true; // Fail closed: missing credentials → reject submission.
			}
			$body = wp_json_encode(
				array(
					'event' => array(
						'token'   => $token,
						'siteKey' => $site_key,
					),
				)
			);
			if ( false === $body ) {
				return true;
			}
			$resp = wp_remote_post(
				'https://recaptchaenterprise.googleapis.com/v1/projects/' . rawurlencode( $project_id ) . '/assessments?key=' . rawurlencode( $api_key ),
				array(
					'headers' => array( 'Content-Type' => 'application/json' ),
					'body'    => $body,
					'timeout' => 10,
				)
			);
			$json = json_decode( wp_remote_retrieve_body( $resp ) );
			if (
				! is_object( $json )
				|| empty( $json->tokenProperties->valid ) // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
				|| empty( $json->riskAnalysis->score ) // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
				|| (float) $json->riskAnalysis->score < $threshold // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
			) {
				return true;
			}
		} else {
			$resp = wp_remote_post(
				'https://www.google.com/recaptcha/api/siteverify',
				array(
					'body'    => array(
						'secret'   => swh_get_string_option( 'swh_recaptcha_secret_key' ),
						'response' => $token,
					),
					'timeout' => 10,
				)
			);
			$json = json_decode( wp_remote_retrieve_body( $resp ) );
			if ( ! is_object( $json ) || empty( $json->success ) ) {
				return true;
			}
		}
	} elseif ( 'turnstile' === $method ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Turnstile token has no nonce; validated server-side by Cloudflare's API.
		$turnstile_token = isset( $_POST['cf-turnstile-response'] ) && is_string( $_POST['cf-turnstile-response'] ) ? sanitize_text_field( wp_unslash( $_POST['cf-turnstile-response'] ) ) : '';
		$resp            = wp_remote_post(
			'https://challenges.cloudflare.com/turnstile/v0/siteverify',
			array(
				'body'    => array(
					'secret'   => swh_get_string_option( 'swh_turnstile_secret_key' ),
					'response' => $turnstile_token,
				),
				'timeout' => 10,
			)
		);
		$json            = json_decode( wp_remote_retrieve_body( $resp ) );
		if ( ! is_object( $json ) || empty( $json->success ) ) {
			return true;
		}
	}
	return false;
}

/**
 * Returns a post meta value cast to string.
 *
 * Wrapper around get_post_meta() that guarantees a string return type for
 * PHPStan L9 compatibility.
 *
 * @param int    $post_id Post ID.
 * @param string $key     Meta key.
 * @return string
 */
function swh_get_string_meta( int $post_id, string $key ): string {
	$val = get_post_meta( $post_id, $key, true );
	return is_scalar( $val ) ? (string) $val : '';
}

/**
 * Returns a post meta value cast to int.
 *
 * @param int    $post_id Post ID.
 * @param string $key     Meta key.
 * @return int
 */
function swh_get_int_meta( int $post_id, string $key ): int {
	$val = get_post_meta( $post_id, $key, true );
	return is_scalar( $val ) ? intval( $val ) : 0;
}

/**
 * Returns an option value cast to string.
 *
 * @param string $key      Option name.
 * @param string $fallback Default value if option not set.
 * @return string
 */
function swh_get_string_option( string $key, string $fallback = '' ): string {
	$val = get_option( $key, $fallback );
	return is_scalar( $val ) ? (string) $val : $fallback;
}

/**
 * Returns an option value cast to int.
 *
 * @param string $key      Option name.
 * @param int    $fallback Default value if option not set.
 * @return int
 */
function swh_get_int_option( string $key, int $fallback = 0 ): int {
	$val = get_option( $key, $fallback );
	return is_scalar( $val ) ? intval( $val ) : $fallback;
}

/**
 * Returns a comment meta value cast to string.
 *
 * @param int    $comment_id Comment ID.
 * @param string $key        Meta key.
 * @return string
 */
function swh_get_string_comment_meta( int $comment_id, string $key ): string {
	$val = get_comment_meta( $comment_id, $key, true );
	return is_scalar( $val ) ? (string) $val : '';
}

/**
 * Returns CC/watcher email addresses for a ticket.
 *
 * Reads `_ticket_cc_emails` post meta (comma-separated), sanitizes each address,
 * and returns only valid email addresses.
 *
 * @since 3.0.0
 * @param int $ticket_id The ticket post ID.
 * @return string[] Array of sanitized, valid email addresses.
 */
function swh_get_cc_emails( int $ticket_id ): array {
	$raw = swh_get_string_meta( $ticket_id, '_ticket_cc_emails' );
	if ( ! $raw ) {
		return array();
	}
	$emails = array_map( 'sanitize_email', array_map( 'trim', explode( ',', $raw ) ) );
	return array_values(
		array_filter(
			$emails,
			function ( string $addr ): bool {
				return (bool) is_email( $addr );
			}
		)
	);
}

/**
 * Applies auto-assignment rules to a ticket, setting `_ticket_assigned_to`.
 *
 * Checks each configured rule ({category_term_id, assignee_user_id}) against
 * the ticket's `helpdesk_category` taxonomy terms. First matching rule wins.
 * Falls back to `swh_default_assignee` option if no rule matches.
 *
 * @since 3.0.0
 * @param int $ticket_id The ticket post ID.
 * @return void
 */
function swh_apply_assignment_rules( int $ticket_id ): void {
	$rules_raw = get_option( 'swh_assignment_rules', array() );
	$rules     = is_array( $rules_raw ) ? $rules_raw : array();
	$assigned  = 0;

	if ( ! empty( $rules ) ) {
		$term_ids = wp_get_post_terms( $ticket_id, 'helpdesk_category', array( 'fields' => 'ids' ) );
		if ( is_array( $term_ids ) && ! empty( $term_ids ) ) {
			foreach ( $rules as $rule ) {
				if ( ! is_array( $rule ) || empty( $rule['category_term_id'] ) || empty( $rule['assignee_user_id'] ) ) {
					continue;
				}
				$cat_term_id = is_scalar( $rule['category_term_id'] ) ? (int) $rule['category_term_id'] : 0;
				$assignee_id = is_scalar( $rule['assignee_user_id'] ) ? (int) $rule['assignee_user_id'] : 0;
				if ( in_array( $cat_term_id, $term_ids, true ) ) {
					$assigned = $assignee_id;
					break;
				}
			}
		}
	}

	if ( ! $assigned ) {
		$default  = get_option( 'swh_default_assignee' );
		$assigned = is_scalar( $default ) ? (int) $default : 0;
	}

	if ( $assigned > 0 ) {
		update_post_meta( $ticket_id, '_ticket_assigned_to', $assigned );
	}
}

/**
 * Merges source ticket into target ticket.
 *
 * Moves all helpdesk_reply comments from source to target, consolidates attachment
 * origname maps, adds system notes to both tickets, closes the source with an
 * internal note, and sends the client a notification linking to the target.
 *
 * @since 3.0.0
 * @param int $source_id Ticket to merge from (will be closed).
 * @param int $target_id Ticket to merge into (receives all conversation).
 * @return bool True on success, false if either post is invalid.
 */
function swh_merge_tickets( int $source_id, int $target_id ): bool {
	$source = get_post( $source_id );
	$target = get_post( $target_id );
	if ( ! $source || ! $target || 'helpdesk_ticket' !== $source->post_type || 'helpdesk_ticket' !== $target->post_type ) {
		return false;
	}

	// Move all helpdesk_reply comments from source to target.
	$comments = get_comments(
		array(
			'post_id' => $source_id,
			'type'    => 'helpdesk_reply',
			'status'  => 'approve',
			'number'  => 0,
			'order'   => 'ASC',
		)
	);
	$comments = is_array( $comments ) ? $comments : array();
	foreach ( $comments as $comment ) {
		if ( ! $comment instanceof WP_Comment ) {
			continue;
		}
		wp_update_comment(
			array(
				'comment_ID'      => $comment->comment_ID,
				'comment_post_ID' => $target_id,
			)
		);
	}

	// Merge attachment orignames.
	$source_names = get_post_meta( $source_id, '_swh_attachment_orignames', true );
	$target_names = get_post_meta( $target_id, '_swh_attachment_orignames', true );
	if ( is_array( $source_names ) && ! empty( $source_names ) ) {
		$merged = is_array( $target_names ) ? array_merge( $target_names, $source_names ) : $source_names;
		update_post_meta( $target_id, '_swh_attachment_orignames', $merged );
	}

	// Add system notes.
	$source_uid = swh_get_string_meta( $source_id, '_ticket_uid' );
	$target_uid = swh_get_string_meta( $target_id, '_ticket_uid' );
	wp_insert_comment(
		array(
			'comment_post_ID'  => $target_id,
			'comment_type'     => 'helpdesk_reply',
			'comment_content'  => sprintf( 'Merged from ticket %s.', $source_uid ),
			'comment_approved' => 1,
			'comment_meta'     => array( '_is_internal_note' => '1' ),
		)
	);
	wp_insert_comment(
		array(
			'comment_post_ID'  => $source_id,
			'comment_type'     => 'helpdesk_reply',
			'comment_content'  => sprintf( 'Merged into ticket %s.', $target_uid ),
			'comment_approved' => 1,
			'comment_meta'     => array( '_is_internal_note' => '1' ),
		)
	);

	// Close the source ticket.
	$defs          = swh_get_defaults();
	$closed_status = swh_get_string_option( 'swh_closed_status', is_string( $defs['swh_closed_status'] ) ? $defs['swh_closed_status'] : 'Closed' );
	update_post_meta( $source_id, '_ticket_status', $closed_status );

	// Notify source ticket client.
	$client_email = swh_get_string_meta( $source_id, '_ticket_email' );
	$client_name  = swh_get_string_meta( $source_id, '_ticket_name' );
	$target_link  = swh_get_secure_ticket_link( $target_id );
	if ( $client_email && $target_link ) {
		$mail_data = array(
			'name'              => $client_name,
			'ticket_id'         => $source_uid,
			'target_ticket_id'  => $target_uid,
			'target_ticket_url' => $target_link,
		);
		swh_send_email( $client_email, 'swh_em_user_merged_sub', 'swh_em_user_merged_body', $mail_data );
	}

	return true;
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
	$filtered_ttl = apply_filters( 'swh_rate_limit_ttl', $ttl, $action );
	$ttl          = is_scalar( $filtered_ttl ) ? intval( $filtered_ttl ) : $ttl;
	$key          = 'swh_rl_' . md5( $action . '_' . swh_get_client_ip() );
	$val          = get_option( $key );
	if ( false !== $val && is_scalar( $val ) && intval( $val ) > time() ) {
		return true;
	}
	update_option( $key, time() + $ttl, false );
	return false;
}
