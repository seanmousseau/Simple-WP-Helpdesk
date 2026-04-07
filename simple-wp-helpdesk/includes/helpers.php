<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

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
