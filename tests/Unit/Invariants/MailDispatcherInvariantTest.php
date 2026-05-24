<?php
/**
 * Invariant: wp_mail() is only called from inside swh_send_email() in class-email.php.
 * Every other email path must go through swh_send_email() so the template parser,
 * {if} blocks, HTML wrapper, and error logging all apply uniformly.
 *
 * @see docs/internal/design-document.md — invariant table, mail dispatcher
 * @package Simple_WP_Helpdesk
 */

namespace SWH\Tests\Unit\Invariants;

class MailDispatcherInvariantTest extends SourceScanTestCase {

	/**
	 * Every wp_mail( call in plugin source is on the allow-list (only swh_send_email()).
	 *
	 * Bypassing swh_send_email() means: no template substitution, no {if} block
	 * support, no HTML wrapper, no error logging. Bugs found in v3.x where new
	 * code called wp_mail() directly produced wrong-looking emails that the user
	 * had no idea were missing the template engine. Pin it.
	 */
	public function test_wp_mail_calls_are_allow_listed(): void {
		// Match wp_mail( as a function call: not preceded by an identifier character,
		// and followed by an opening paren. Catches `wp_mail(...)` and `\wp_mail(...)`
		// but skips `swh_wp_mail`, `my_wp_mail_helper`, etc.
		$pattern = '/(?<![A-Za-z0-9_])\\\\?wp_mail\s*\(/';

		// Allow-list:
		//   includes/class-email.php:142 — the swh_send_email() dispatcher itself (real call).
		//   includes/class-email.php:145 — diagnostic string `'wp_mail() failed'` inside error_log() (text, not a call).
		$allow = array(
			'includes/class-email.php:142',
			'includes/class-email.php:145',
		);

		$this->assertSourceMatchesAllowList(
			$allow,
			$pattern,
			'Direct wp_mail() calls bypass swh_send_email() (template parser, {if} blocks, HTML wrapper, error logging). '
				. 'Refactor to call swh_send_email() instead.'
		);
	}
}
