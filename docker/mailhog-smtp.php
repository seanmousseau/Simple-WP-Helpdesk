<?php
/**
 * Plugin Name: MailHog SMTP (Test Environment)
 * Description: Routes wp_mail() through MailHog SMTP. Active only when MAILHOG_SMTP_HOST is set.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$swh_mailhog_host = getenv( 'MAILHOG_SMTP_HOST' );
if ( ! $swh_mailhog_host ) {
	return;
}

add_action(
	'phpmailer_init',
	function ( $phpmailer ) use ( $swh_mailhog_host ) {
		$phpmailer->isSMTP();
		$phpmailer->Host       = $swh_mailhog_host;
		$phpmailer->Port       = (int) ( getenv( 'MAILHOG_SMTP_PORT' ) ?: 1025 );
		$phpmailer->SMTPAuth   = false;
		$phpmailer->SMTPSecure = '';
	}
);
