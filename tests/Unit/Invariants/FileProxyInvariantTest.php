<?php
/**
 * Invariant: the file proxy at includes/class-ticket.php:swh_serve_file()
 * enforces (a) constant-time token comparison, (b) capability fallback,
 * (c) token-expiration check for non-admin requests, and (d) realpath-based
 * path-traversal prevention against the upload subdirectory.
 *
 * A regression in any of these makes the proxy a directory-traversal vulnerability
 * or a token-leak vector. Pin the function shape so the next refactor can't
 * silently drop one of them.
 *
 * @see docs/internal/security-model.md — file proxy section
 * @package Simple_WP_Helpdesk
 */

namespace SWH\Tests\Unit\Invariants;

class FileProxyInvariantTest extends SourceScanTestCase {

	/**
	 * @var string Cached source of class-ticket.php.
	 */
	private string $source = '';

	/**
	 * @var string The body of swh_serve_file() extracted from source.
	 */
	private string $function_body = '';

	protected function setUp(): void {
		parent::setUp();
		$this->source = $this->read_plugin_file( 'includes/class-ticket.php' );

		// Extract function body by brace-matching from `function swh_serve_file(`.
		$start = strpos( $this->source, 'function swh_serve_file(' );
		$this->assertNotFalse( $start, 'swh_serve_file() not found in includes/class-ticket.php' );
		$brace_open = strpos( $this->source, '{', $start );
		$this->assertNotFalse( $brace_open, 'Opening brace of swh_serve_file() not found' );

		$depth = 0;
		$end   = $brace_open;
		$len   = strlen( $this->source );
		for ( $i = $brace_open; $i < $len; $i++ ) {
			$c = $this->source[ $i ];
			if ( '{' === $c ) {
				$depth++;
			} elseif ( '}' === $c ) {
				$depth--;
				if ( 0 === $depth ) {
					$end = $i;
					break;
				}
			}
		}
		$this->assertGreaterThan( $brace_open, $end, 'Closing brace of swh_serve_file() not found' );
		$this->function_body = substr( $this->source, $brace_open, $end - $brace_open + 1 );
	}

	public function test_token_compared_with_hash_equals(): void {
		$this->assertMatchesRegularExpression(
			'/hash_equals\s*\(\s*\$db_token\s*,\s*\$token\s*\)/',
			$this->function_body,
			'swh_serve_file() must compare the portal token with hash_equals( $db_token, $token ). '
				. 'Direct == comparison would leak token bytes via timing.'
		);
	}

	public function test_capability_fallback_present(): void {
		$this->assertMatchesRegularExpression(
			'/current_user_can\s*\(\s*[\'"]edit_posts[\'"]\s*\)/',
			$this->function_body,
			'swh_serve_file() must allow technicians+ (edit_posts) to access files even without a portal token. '
				. 'Removing this breaks admin attachment downloads.'
		);
	}

	public function test_token_expiration_checked_for_non_admins(): void {
		$this->assertMatchesRegularExpression(
			'/!\s*current_user_can\s*\(\s*[\'"]edit_posts[\'"]\s*\)\s*&&\s*swh_is_token_expired\s*\(/',
			$this->function_body,
			'swh_serve_file() must check swh_is_token_expired() for non-admin requests so expired portal links cannot download files.'
		);
	}

	public function test_path_traversal_blocked_via_realpath(): void {
		$this->assertMatchesRegularExpression(
			'/realpath\s*\(\s*\$file_path\s*\)/',
			$this->function_body,
			'swh_serve_file() must resolve the file path with realpath() before reading.'
		);
		$this->assertMatchesRegularExpression(
			'/realpath\s*\(\s*[^)]*swh-helpdesk[^)]*\)/',
			$this->function_body,
			'swh_serve_file() must resolve the expected upload subdirectory with realpath().'
		);
		$this->assertMatchesRegularExpression(
			'/strpos\s*\(\s*\$real_path\s*,\s*\$expected_dir\s*\)\s*!==?\s*0/',
			$this->function_body,
			'swh_serve_file() must reject paths that do not start with the expected upload subdirectory '
				. '(strpos( $real_path, $expected_dir ) !== 0).'
		);
	}

	public function test_hooked_at_init_priority_1(): void {
		$this->assertMatchesRegularExpression(
			"/add_action\s*\(\s*['\"]init['\"]\s*,\s*['\"]swh_serve_file['\"]\s*,\s*1\s*\)/",
			$this->source,
			'swh_serve_file() must be hooked to init at priority 1 so it fires before plugins that would otherwise '
				. 'short-circuit the request (e.g. caching plugins, redirect rules).'
		);
	}
}
