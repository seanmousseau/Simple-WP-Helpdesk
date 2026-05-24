<?php
/**
 * Invariant: every comparison of a secret-equivalent token uses hash_equals().
 *
 * Tokens covered: portal `_ticket_token`, inbound-email bearer (`swh_inbound_secret`),
 * and the case-insensitive From-address match in inbound parsing.
 *
 * Using `==` or `===` on a token leaks timing information that lets an attacker
 * recover the token byte-by-byte. hash_equals() is constant-time. This is a
 * hard rule across the codebase.
 *
 * @see docs/internal/design-document.md — invariant table, token comparison
 * @package Simple_WP_Helpdesk
 */

namespace SWH\Tests\Unit\Invariants;

class TokenComparisonInvariantTest extends SourceScanTestCase {

	/**
	 * The canonical hash_equals() call sites are all present.
	 *
	 * If a call site is removed, this test fails — forcing the author to either
	 * explain why the comparison is gone (token replaced with something else) or
	 * restore it. Prevents a silent "we don't need hash_equals here anymore"
	 * regression.
	 */
	public function test_hash_equals_call_sites_are_present(): void {
		$pattern = '/hash_equals\s*\(/';
		// Allow-list — every legitimate constant-time comparison in the plugin:
		//   class-portal.php:144 — portal page render
		//   class-portal.php:502 — portal reply
		//   class-portal.php:637 — portal close
		//   class-portal.php:721 — portal reopen
		//   class-portal.php:802 — portal lookup
		//   class-ticket.php:52  — file proxy token check
		//   class-email.php:247  — inbound-email bearer auth
		//   class-email.php:305  — inbound-email From-address match
		$allow = array(
			'frontend/class-portal.php:144',
			'frontend/class-portal.php:502',
			'frontend/class-portal.php:637',
			'frontend/class-portal.php:721',
			'frontend/class-portal.php:802',
			'includes/class-ticket.php:52',
			'includes/class-email.php:247',
			'includes/class-email.php:305',
		);
		$this->assertSourceMatchesAllowList(
			$allow,
			$pattern,
			'hash_equals() call sites have changed. If you removed a token comparison, confirm the security model still holds '
				. 'and update this allow-list. If you added a new comparison, add it to the allow-list AND ensure it uses hash_equals().'
		);
	}

	/**
	 * No source line compares a $token variable using == or === directly.
	 *
	 * This is a secondary backstop: even if someone introduces a new token
	 * comparison without touching the allow-list above, a direct == will be
	 * flagged. Variable names matched: $token, $db_token, $secret, $bearer.
	 */
	public function test_no_direct_token_equality_comparisons(): void {
		$risky_names = array( 'token', 'db_token', 'bearer', 'secret' );
		$violations  = array();

		foreach ( $this->plugin_php_files() as $file ) {
			$lines = file( $file, FILE_IGNORE_NEW_LINES );
			if ( false === $lines ) {
				continue;
			}
			foreach ( $lines as $lineno => $line ) {
				$trimmed = ltrim( $line );
				if ( '' === $trimmed || str_starts_with( $trimmed, '//' ) || str_starts_with( $trimmed, '*' ) || str_starts_with( $trimmed, '/*' ) ) {
					continue;
				}
				if ( ! preg_match( '/[=!]==?/', $line ) ) {
					continue;
				}
				foreach ( $risky_names as $name ) {
					// Match `$<name> ==`, `== $<name>`, `$<name> ===`, etc., but not
					// `'' === $token` (empty-string emptiness check, which is safe).
					$has_token_compare = preg_match(
						'/\$' . $name . '\s*[!=]==?\s*\$|\$\w+\s*[!=]==?\s*\$' . $name . '\b/',
						$line
					);
					$is_empty_check = preg_match( '/[\'"]\s*[\'"]\s*[!=]==?\s*\$' . $name . '\b|\$' . $name . '\s*[!=]==?\s*[\'"]\s*[\'"]/', $line );
					if ( $has_token_compare && ! $is_empty_check ) {
						$rel          = ltrim( str_replace( $this->plugin_root(), '', $file ), '/' );
						$violations[] = $rel . ':' . ( $lineno + 1 ) . ' — ' . trim( $line );
					}
				}
			}
		}

		$this->assertSame(
			array(),
			$violations,
			"Direct equality comparison on a token variable found — use hash_equals() instead:\n  "
				. implode( "\n  ", $violations )
		);
	}
}
