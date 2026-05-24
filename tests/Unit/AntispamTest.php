<?php
/**
 * Domain tests for swh_check_antispam() — covers gap #7.
 *
 * Tests each of the three configured spam methods:
 *   honeypot — name="swh_website_url_hp" hidden field; non-empty means bot
 *   recaptcha (v2) — Google siteverify endpoint, success bool
 *   recaptcha (enterprise) — Google Cloud assessments endpoint, score >= threshold
 *   turnstile — Cloudflare siteverify endpoint, success bool
 *
 * Critical fail-closed cases: missing credentials, no JSON, network failure
 * — all must return true (treat as spam) so configuration mistakes don't
 * silently disable spam protection.
 *
 * @see docs/internal/test-coverage-matrix.md — gap #7
 * @package Simple_WP_Helpdesk
 */

declare(strict_types=1);

namespace SWH\Tests\Unit;

use PHPUnit\Framework\TestCase;
use WP_Mock;

final class AntispamTest extends TestCase {

	/** @var array<string, mixed> Per-test option store. */
	private array $options = array();

	/** @var array<int, array{string, array<string, mixed>}> Captured wp_remote_post calls. */
	private array $remote_calls = array();

	/** @var string|null Configured response body for the next wp_remote_post call. */
	private ?string $next_body = null;

	protected function setUp(): void {
		WP_Mock::setUp();
		$this->options      = array();
		$this->remote_calls = array();
		$this->next_body    = null;

		if ( ! function_exists( 'absint' ) ) {
			require_once __DIR__ . '/_csat_absint_stub.php';
		}
		require_once SWH_PLUGIN_DIR . 'includes/helpers.php';

		// get_option underlies both swh_get_option (grouped) and swh_get_string_option (flat).
		WP_Mock::userFunction(
			'get_option',
			array(
				'return' => function ( $key, $default = false ) {
					return $this->options[ $key ] ?? $default;
				},
			)
		);

		WP_Mock::userFunction(
			'wp_remote_post',
			array(
				'return' => function ( $url, $args = array() ) {
					$this->remote_calls[] = array( $url, $args );
					return array( 'body' => $this->next_body ?? '' );
				},
			)
		);
		WP_Mock::userFunction(
			'wp_remote_retrieve_body',
			array(
				'return' => fn( $resp ) => is_array( $resp ) && isset( $resp['body'] ) ? (string) $resp['body'] : '',
			)
		);
		WP_Mock::userFunction( 'sanitize_text_field', array( 'return_arg' => 0 ) );
		WP_Mock::userFunction( 'wp_unslash', array( 'return_arg' => 0 ) );
		WP_Mock::userFunction( 'wp_json_encode', array( 'return' => fn( $v ) => json_encode( $v ) ) );

		$_POST = array();
	}

	protected function tearDown(): void {
		WP_Mock::tearDown();
		$_POST = array();
	}

	private function set_method( string $method ): void {
		$this->options['swh_settings_tools'] = array( 'spam_method' => $method );
	}

	// ---------- Honeypot ---------- //

	public function test_honeypot_legit_submission_returns_false(): void {
		$this->set_method( 'honeypot' );
		// Honeypot field not filled — the legitimate-user signal.
		$this->assertFalse(
			swh_check_antispam( true ),
			'Empty honeypot field with method=honeypot must NOT be flagged.'
		);
	}

	public function test_honeypot_filled_field_flags_as_spam(): void {
		$this->set_method( 'honeypot' );
		$_POST['swh_website_url_hp'] = 'https://bot.example.com';
		$this->assertTrue(
			swh_check_antispam( true ),
			'Filled honeypot field with method=honeypot MUST flag as spam.'
		);
	}

	public function test_honeypot_skips_captcha_when_check_disabled(): void {
		// Lookup forms pass check_captcha=false. The honeypot path still runs;
		// CAPTCHA branches don't.
		$this->set_method( 'honeypot' );
		$_POST['swh_website_url_hp'] = 'x';
		$this->assertTrue(
			swh_check_antispam( false ),
			'Honeypot must still be checked even when CAPTCHA is skipped.'
		);
	}

	// ---------- reCAPTCHA v2 ---------- //

	public function test_recaptcha_v2_valid_response_passes(): void {
		$this->set_method( 'recaptcha' );
		// type defaults to v2 (the flat option is absent).
		$_POST['g-recaptcha-response'] = 'good-token';
		$this->next_body                = json_encode( array( 'success' => true ) );

		$this->assertFalse(
			swh_check_antispam( true ),
			'Google success=true must NOT be flagged.'
		);
		$this->assertSame(
			'https://www.google.com/recaptcha/api/siteverify',
			$this->remote_calls[0][0] ?? null,
			'Must POST to the v2 siteverify endpoint.'
		);
	}

	public function test_recaptcha_v2_failed_response_flags_as_spam(): void {
		$this->set_method( 'recaptcha' );
		$_POST['g-recaptcha-response'] = 'bad-token';
		$this->next_body                = json_encode( array( 'success' => false ) );

		$this->assertTrue(
			swh_check_antispam( true ),
			'Google success=false MUST flag as spam.'
		);
	}

	public function test_recaptcha_v2_non_json_response_flags_as_spam(): void {
		// Fail-closed: a malformed Google response must NOT silently pass.
		$this->set_method( 'recaptcha' );
		$_POST['g-recaptcha-response'] = 'whatever';
		$this->next_body                = 'this is not JSON';

		$this->assertTrue(
			swh_check_antispam( true ),
			'Non-JSON Google response MUST flag as spam (fail closed).'
		);
	}

	public function test_recaptcha_v2_skipped_when_check_captcha_false(): void {
		$this->set_method( 'recaptcha' );
		$_POST['g-recaptcha-response'] = 'good-token';
		// We set up Google to fail, but check_captcha=false should short-circuit BEFORE the call.
		$this->next_body = json_encode( array( 'success' => false ) );

		$this->assertFalse(
			swh_check_antispam( false ),
			'check_captcha=false must skip the reCAPTCHA branch entirely.'
		);
		$this->assertEmpty(
			$this->remote_calls,
			'No HTTP call must be made when check_captcha=false.'
		);
	}

	// ---------- reCAPTCHA Enterprise ---------- //

	public function test_recaptcha_enterprise_missing_credentials_fails_closed(): void {
		// Missing project_id or api_key → reject. Prevents accidentally
		// disabling spam protection by misconfiguring the keys.
		$this->set_method( 'recaptcha' );
		$this->options['swh_recaptcha_type'] = 'enterprise';
		// project_id and api_key absent.

		$this->assertTrue(
			swh_check_antispam( true ),
			'Enterprise mode with missing project_id/api_key MUST fail closed.'
		);
	}

	public function test_recaptcha_enterprise_below_threshold_flags_as_spam(): void {
		$this->set_method( 'recaptcha' );
		$this->options['swh_recaptcha_type']       = 'enterprise';
		$this->options['swh_recaptcha_project_id'] = 'proj-x';
		$this->options['swh_recaptcha_api_key']    = 'key-x';
		$this->options['swh_recaptcha_threshold']  = '0.5';
		$_POST['g-recaptcha-response']             = 'token-x';
		// Score 0.3 < 0.5 threshold.
		$this->next_body = json_encode(
			array(
				'tokenProperties' => array( 'valid' => true ),
				'riskAnalysis'    => array( 'score' => 0.3 ),
			)
		);

		$this->assertTrue(
			swh_check_antispam( true ),
			'Enterprise score below threshold MUST flag as spam.'
		);
	}

	public function test_recaptcha_enterprise_at_or_above_threshold_passes(): void {
		$this->set_method( 'recaptcha' );
		$this->options['swh_recaptcha_type']       = 'enterprise';
		$this->options['swh_recaptcha_project_id'] = 'proj-x';
		$this->options['swh_recaptcha_api_key']    = 'key-x';
		$this->options['swh_recaptcha_threshold']  = '0.5';
		$_POST['g-recaptcha-response']             = 'token-x';
		$this->next_body                            = json_encode(
			array(
				'tokenProperties' => array( 'valid' => true ),
				'riskAnalysis'    => array( 'score' => 0.9 ),
			)
		);

		$this->assertFalse(
			swh_check_antispam( true ),
			'Enterprise score above threshold must NOT be flagged.'
		);
	}

	public function test_recaptcha_enterprise_invalid_token_flags_as_spam(): void {
		$this->set_method( 'recaptcha' );
		$this->options['swh_recaptcha_type']       = 'enterprise';
		$this->options['swh_recaptcha_project_id'] = 'proj-x';
		$this->options['swh_recaptcha_api_key']    = 'key-x';
		$_POST['g-recaptcha-response']             = 'bad';
		$this->next_body                            = json_encode(
			array(
				'tokenProperties' => array( 'valid' => false ),
				'riskAnalysis'    => array( 'score' => 0.9 ),
			)
		);

		$this->assertTrue(
			swh_check_antispam( true ),
			'Enterprise invalid-token response MUST flag as spam.'
		);
	}

	// ---------- Cloudflare Turnstile ---------- //

	public function test_turnstile_valid_response_passes(): void {
		$this->set_method( 'turnstile' );
		$_POST['cf-turnstile-response'] = 'good-token';
		$this->next_body                 = json_encode( array( 'success' => true ) );

		$this->assertFalse(
			swh_check_antispam( true ),
			'Turnstile success=true must NOT be flagged.'
		);
		$this->assertSame(
			'https://challenges.cloudflare.com/turnstile/v0/siteverify',
			$this->remote_calls[0][0] ?? null,
			'Must POST to Cloudflare\'s siteverify endpoint.'
		);
	}

	public function test_turnstile_failed_response_flags_as_spam(): void {
		$this->set_method( 'turnstile' );
		$_POST['cf-turnstile-response'] = 'bad';
		$this->next_body                 = json_encode( array( 'success' => false ) );

		$this->assertTrue(
			swh_check_antispam( true ),
			'Turnstile success=false MUST flag as spam.'
		);
	}

	public function test_turnstile_non_json_response_flags_as_spam(): void {
		// Fail-closed for Cloudflare network errors / 5xx with non-JSON bodies.
		$this->set_method( 'turnstile' );
		$_POST['cf-turnstile-response'] = 'whatever';
		$this->next_body                 = '<html>error page</html>';

		$this->assertTrue(
			swh_check_antispam( true ),
			'Non-JSON Turnstile response MUST flag as spam (fail closed).'
		);
	}
}
