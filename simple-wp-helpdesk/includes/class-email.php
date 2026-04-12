<?php
/**
 * Email utilities: template parsing, HTML wrapping, and email dispatch.
 *
 * @package Simple_WP_Helpdesk
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Parses a template string by processing conditional blocks and replacing placeholders.
 *
 * Steps: evaluate {if key}...{endif key} blocks, replace {placeholder} tokens,
 * clean up unreplaced placeholders, and collapse excess newlines.
 *
 * @param string               $template The raw template string.
 * @param array<string, mixed> $data     Key-value pairs for placeholder substitution.
 * @return string The fully rendered template string.
 */
function swh_parse_template( $template, $data ) {
	// 1. Process conditional blocks: {if key}...{endif key}
	$result = preg_replace_callback(
		'/\{if (\w+)\}(.*?)\{endif \1\}/s',
		function ( $matches ) use ( $data ) {
			$key = $matches[1];
			if ( isset( $data[ $key ] ) && '' !== $data[ $key ] ) {
				return $matches[2];
			}
			return '';
		},
		$template
	);
	if ( null === $result ) {
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional; logs PCRE failures for admin troubleshooting.
		error_log( 'Simple WP Helpdesk: preg_replace_callback() returned null in swh_parse_template (step 1) — PCRE error ' . preg_last_error() );
	} else {
		$template = $result;
	}
	// 2. Replace placeholders with data values.
	foreach ( $data as $key => $value ) {
		$template = str_replace( '{' . $key . '}', is_scalar( $value ) ? (string) $value : '', $template );
	}
	// 3. Clean up any unreplaced placeholders.
	$result = preg_replace( '/\{[a-zA-Z_]+\}/', '', $template );
	if ( null === $result ) {
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional; logs PCRE failures for admin troubleshooting.
		error_log( 'Simple WP Helpdesk: preg_replace() returned null in swh_parse_template (step 3) — PCRE error ' . preg_last_error() );
	} else {
		$template = $result;
	}
	// 4. Collapse runs of 3+ newlines down to 2.
	$result = preg_replace( '/\n{3,}/', "\n\n", $template );
	if ( null === $result ) {
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional; logs PCRE failures for admin troubleshooting.
		error_log( 'Simple WP Helpdesk: preg_replace() returned null in swh_parse_template (step 4) — PCRE error ' . preg_last_error() );
	} else {
		$template = $result;
	}
	$template = trim( $template );
	/**
	 * Filters the fully-rendered email template string.
	 *
	 * @since 2.1.0
	 * @param string               $template The rendered template output.
	 * @param array<string, mixed> $data     The template data array.
	 */
	return apply_filters( 'swh_parse_template', $template, $data );
}

/**
 * Sends a helpdesk email using a stored template key pair.
 *
 * Fetches the subject and body templates from options, parses them with $data,
 * and wraps the body in HTML if the email format is set to HTML.
 * Logs wp_mail() failures via error_log().
 *
 * @param string               $to          Recipient email address.
 * @param string               $subject_key Option key for the subject template.
 * @param string               $body_key    Option key for the body template.
 * @param array<string, mixed> $data        Template data for placeholder substitution.
 * @param string[]             $attachments Optional. Array of attachment file proxy URLs.
 * @return void
 */
function swh_send_email( $to, $subject_key, $body_key, $data, $attachments = array() ) {
	$defs        = swh_get_defaults();
	$subject_dfl = isset( $defs[ $subject_key ] ) && is_string( $defs[ $subject_key ] ) ? $defs[ $subject_key ] : '';
	$body_dfl    = isset( $defs[ $body_key ] ) && is_string( $defs[ $body_key ] ) ? $defs[ $body_key ] : '';
	$subject     = swh_parse_template( swh_get_string_option( $subject_key, $subject_dfl ), $data );
	$body        = swh_parse_template( swh_get_string_option( $body_key, $body_dfl ), $data );
	$headers     = array();
	$format      = get_option( 'swh_email_format', 'html' );
	if ( 'html' === $format ) {
		$headers[] = 'Content-Type: text/html; charset=UTF-8';
	}
	/**
	 * Filters the email headers array before sending.
	 *
	 * @since 2.1.0
	 * @param string[] $headers Array of header strings (e.g. 'Content-Type: text/html; charset=UTF-8').
	 * @param string   $to      Recipient email address.
	 * @param string   $subject Rendered email subject.
	 */
	$headers = apply_filters( 'swh_email_headers', $headers, $to, $subject );
	if ( 'html' === $format ) {
		$body = swh_wrap_html_email( $body, $attachments );
	} elseif ( ! empty( $attachments ) ) {
			$lines = array();
		foreach ( $attachments as $url ) {
			$query_string = wp_parse_url( $url, PHP_URL_QUERY );
			parse_str( is_string( $query_string ) ? $query_string : '', $qs );
			$name    = ! empty( $qs['swh_file'] ) && is_string( $qs['swh_file'] ) ? rawurldecode( $qs['swh_file'] ) : basename( $url );
			$lines[] = $name . ' — ' . $url;
		}
			$body .= "\n\nAttachments:\n" . implode( "\n", $lines );
	}
	if ( ! wp_mail( $to, $subject, $body, $headers ) ) {
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional; logs wp_mail() failures for site admin troubleshooting.
		error_log( 'Simple WP Helpdesk: wp_mail() failed — to: ' . $to . ', subject: ' . $subject );
	}
}

/**
 * Wraps a plain-text email body in a styled HTML email table layout.
 *
 * Converts newlines to <br>, auto-links bare URLs, and appends an attachment list.
 *
 * @param string   $body        The plain-text email body.
 * @param string[] $attachments Optional. Array of attachment file proxy URLs.
 * @return string A complete HTML email document string.
 */
function swh_wrap_html_email( $body, $attachments = array() ) {
	$html_body = nl2br( esc_html( $body ) );
	// Auto-link URLs that are not already inside HTML tags.
	$html_body       = preg_replace(
		'~(?<!href=["\'])(?<!">)(https?://[^\s<]+)~i',
		'<a href="$1" style="color:#0073aa;">$1</a>',
		$html_body
	);
	$attachment_html = '';
	if ( ! empty( $attachments ) ) {
		$attachment_html = '<p style="margin-top:15px;"><strong>Attachments:</strong><br>';
		foreach ( $attachments as $url ) {
			$query_string = wp_parse_url( $url, PHP_URL_QUERY );
			parse_str( is_string( $query_string ) ? $query_string : '', $qs );
			$label            = ! empty( $qs['swh_file'] ) && is_string( $qs['swh_file'] ) ? rawurldecode( $qs['swh_file'] ) : basename( $url );
			$attachment_html .= '<a href="' . esc_url( $url ) . '" style="color:#0073aa;">' . esc_html( $label ) . '</a><br>';
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

/**
 * Returns the best notification email address for a given ticket.
 *
 * Priority: assigned technician → default assignee setting → fallback email setting → admin_email.
 *
 * @param int $ticket_id Optional. Ticket post ID. Default 0 (skips assigned-tech lookup).
 * @return string The resolved email address.
 */
function swh_get_admin_email( $ticket_id = 0 ) {
	if ( $ticket_id ) {
		$assigned = swh_get_int_meta( $ticket_id, '_ticket_assigned_to' );
		if ( $assigned ) {
			$user = get_userdata( $assigned );
			if ( $user ) {
				return $user->user_email;
			}
		}
	}
	$default_assignee = swh_get_int_option( 'swh_default_assignee' );
	if ( $default_assignee ) {
		$user = get_userdata( $default_assignee );
		if ( $user ) {
			return $user->user_email;
		}
	}
	$fallback = swh_get_string_option( 'swh_fallback_email' );
	if ( $fallback ) {
		return $fallback;
	}
	return swh_get_string_option( 'admin_email' );
}
