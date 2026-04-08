<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function swh_parse_template( $template, $data ) {
	// 1. Process conditional blocks: {if key}...{endif key}
	$template = preg_replace_callback(
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
	// 2. Replace placeholders with data values.
	foreach ( $data as $key => $value ) {
		$template = str_replace( '{' . $key . '}', $value, $template );
	}
	// 3. Clean up any unreplaced placeholders.
	$template = preg_replace( '/\{[a-zA-Z_]+\}/', '', $template );
	// 4. Collapse runs of 3+ newlines down to 2.
	$template = preg_replace( '/\n{3,}/', "\n\n", $template );
	return trim( $template );
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
	} elseif ( ! empty( $attachments ) ) {
			$lines = array();
		foreach ( $attachments as $url ) {
			parse_str( wp_parse_url( $url, PHP_URL_QUERY ) ?? '', $qs );
			$name    = ! empty( $qs['swh_file'] ) ? rawurldecode( $qs['swh_file'] ) : basename( $url );
			$lines[] = $name . ' — ' . $url;
		}
			$body .= "\n\nAttachments:\n" . implode( "\n", $lines );
	}
	if ( ! wp_mail( $to, $subject, $body, $headers ) ) {
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional; logs wp_mail() failures for site admin troubleshooting.
		error_log( 'Simple WP Helpdesk: wp_mail() failed — to: ' . $to . ', subject: ' . $subject );
	}
}

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
			parse_str( wp_parse_url( $url, PHP_URL_QUERY ) ?? '', $qs );
			$label            = ! empty( $qs['swh_file'] ) ? rawurldecode( $qs['swh_file'] ) : basename( $url );
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
