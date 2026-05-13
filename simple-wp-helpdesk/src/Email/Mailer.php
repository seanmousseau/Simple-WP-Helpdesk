<?php
/**
 * Thin OOP facade over the existing procedural email helpers.
 *
 * @package Simple_WP_Helpdesk
 * @since 3.7.0
 */

namespace SWH\Email;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Stateless wrapper around swh_send_email() to validate the PSR-4 autoload
 * path. Procedural callers continue to use swh_send_email() directly; this
 * facade is the proof of concept for v3.7's PSR-4 landing.
 *
 * @since 3.7.0
 */
final class Mailer {

	/**
	 * Send a plugin email via the existing swh_send_email() helper.
	 *
	 * Signature mirrors swh_send_email() so the facade is a thin pass-through.
	 *
	 * @param string               $to          Recipient email address.
	 * @param string               $subject_key Option key for the subject template.
	 * @param string               $body_key    Option key for the body template.
	 * @param array<string, mixed> $data        Template data for placeholder substitution.
	 * @param string[]             $attachments Optional. Array of attachment file proxy URLs.
	 * @param int                  $ticket_id   Optional. Ticket post ID for CC email resolution. Default 0.
	 * @return bool True if wp_mail() succeeded, false otherwise.
	 */
	public function send( $to, $subject_key, $body_key, $data, $attachments = array(), $ticket_id = 0 ) {
		return swh_send_email( $to, $subject_key, $body_key, $data, $attachments, $ticket_id );
	}
}
