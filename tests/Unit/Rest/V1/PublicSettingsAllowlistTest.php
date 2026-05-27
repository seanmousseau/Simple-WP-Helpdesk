<?php
declare(strict_types=1);

namespace SWH\Tests\Unit\Rest\V1;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use SWH\Rest\V1\PublicSettingsAllowlist;
use WP_Mock;

/**
 * Drift detector + security gate for
 * {@see \SWH\Rest\V1\PublicSettingsAllowlist}.
 *
 * **This is the most important test in Phase 5.** It guards the only
 * public (no-auth) endpoint in the v4.1.0 surface. If any of these tests
 * fail because someone added an option key to the allowlist, that change
 * MUST come with:
 *   1. A corresponding update to `docs/internal/security-model.md`.
 *   2. A justification in the PR description that the value can never
 *      contain secrets.
 *   3. An update to the LOCKED_KEYS array below.
 *
 * @covers \SWH\Rest\V1\PublicSettingsAllowlist
 */
final class PublicSettingsAllowlistTest extends TestCase {

	/**
	 * The complete, locked allowlist. Adding a key without updating this
	 * array fails {@see test_allowlist_only_contains_locked_keys()} —
	 * which forces reviewers to notice the security review burden.
	 *
	 * @var string[]
	 */
	private const LOCKED_KEYS = array(
		'priority_labels',
		'status_labels',
		'sla_thresholds',
		'features',
	);

	/**
	 * Option keys that are KNOWN to carry operator secrets. Asserts they
	 * never appear in the allowlist. Drawn from the v4.1.0 settings
	 * inventory (see `data-dictionary.md`).
	 *
	 * @var string[]
	 */
	private const KNOWN_SECRET_KEYS = array(
		'inbound_webhook_secret',
		'swh_inbound_secret',
		'api_keys',
		'smtp_password',
		'smtp_user',
		'smtp_host',
		'swh_recaptcha_secret_key',
		'swh_recaptcha_api_key',
		'swh_turnstile_secret_key',
		// Email template bodies — operator-authored, may contain internal
		// phrasing or signed URLs.
		'email_templates',
		'swh_em_user_new_body',
		'swh_em_admin_new_body',
	);

	protected function setUp(): void {
		WP_Mock::setUp();
	}

	protected function tearDown(): void {
		WP_Mock::tearDown();
	}

	// -----------------------------------------------------------------------
	// Drift detector
	// -----------------------------------------------------------------------

	public function test_allowlist_only_contains_locked_keys(): void {
		$keys = array_keys( PublicSettingsAllowlist::map() );
		sort( $keys );
		$locked = self::LOCKED_KEYS;
		sort( $locked );
		$this->assertSame(
			$locked,
			$keys,
			'PublicSettingsAllowlist::map() drift — see test docblock. Adding a key requires updating security-model.md and the LOCKED_KEYS constant in this test.'
		);
	}

	public function test_resolve_returns_only_allowlisted_keys(): void {
		WP_Mock::userFunction( 'get_option', array( 'return' => '' ) );

		$resolved = PublicSettingsAllowlist::resolve();
		$got      = array_keys( $resolved );
		sort( $got );
		$locked = self::LOCKED_KEYS;
		sort( $locked );
		$this->assertSame(
			$locked,
			$got,
			'resolve() leaked an extra key — a reader must be returning more than the locked sub-schema.'
		);
	}

	// -----------------------------------------------------------------------
	// Known-secret exclusion (per-key)
	// -----------------------------------------------------------------------

	/**
	 * @dataProvider known_secret_keys
	 */
	public function test_known_secrets_are_not_in_allowlist( string $secret ): void {
		$keys = array_keys( PublicSettingsAllowlist::map() );
		$this->assertNotContains(
			$secret,
			$keys,
			sprintf( 'Secret-bearing key "%s" must never be in the public allowlist.', $secret )
		);
	}

	/**
	 * @return iterable<string,array{0:string}>
	 */
	public function known_secret_keys(): iterable {
		foreach ( self::KNOWN_SECRET_KEYS as $k ) {
			yield $k => array( $k );
		}
	}

	// -----------------------------------------------------------------------
	// No-wildcard guarantee (static inspection of source)
	// -----------------------------------------------------------------------

	public function test_no_wildcard_or_prefix_match(): void {
		$reflection = new ReflectionClass( PublicSettingsAllowlist::class );
		$file       = $reflection->getFileName();
		$this->assertNotFalse( $file, 'Could not locate PublicSettingsAllowlist source file.' );
		$source = (string) file_get_contents( (string) $file );

		// Forbidden patterns that would indicate dynamic / wildcard exposure.
		// `str_starts_with` against an option name (e.g. swh_) is the most
		// dangerous because a future settings rename can drag a secret in.
		$forbidden = array(
			'preg_match'      => '/preg_match\s*\(/',
			'preg_match_all'  => '/preg_match_all\s*\(/',
			'fnmatch'         => '/fnmatch\s*\(/',
			'str_starts_with' => '/str_starts_with\s*\(/',
			'str_ends_with'   => '/str_ends_with\s*\(/',
			'wp_load_alloptions' => '/wp_load_alloptions\s*\(/',
			'global_options_loop' => '/foreach\s*\(\s*\$wpdb/',
		);
		foreach ( $forbidden as $label => $pattern ) {
			$this->assertSame(
				0,
				preg_match( $pattern, $source ),
				sprintf( 'PublicSettingsAllowlist must not use "%s" — explicit map only.', $label )
			);
		}
	}

	// -----------------------------------------------------------------------
	// Reader sub-schema shapes
	// -----------------------------------------------------------------------

	public function test_priority_labels_reader_splits_csv(): void {
		WP_Mock::userFunction( 'get_option', array(
			'args'   => array( 'swh_ticket_priorities', WP_Mock\Functions::type( 'string' ) ),
			'return' => 'Low, Medium , High,Urgent',
		) );

		$labels = PublicSettingsAllowlist::read_priority_labels();
		$this->assertSame( array( 'Low', 'Medium', 'High', 'Urgent' ), $labels );
	}

	public function test_status_labels_reader_splits_csv(): void {
		WP_Mock::userFunction( 'get_option', array(
			'args'   => array( 'swh_ticket_statuses', WP_Mock\Functions::type( 'string' ) ),
			'return' => 'Open, In Progress, Resolved, Closed',
		) );

		$labels = PublicSettingsAllowlist::read_status_labels();
		$this->assertSame( array( 'Open', 'In Progress', 'Resolved', 'Closed' ), $labels );
	}

	public function test_sla_thresholds_locked_shape(): void {
		WP_Mock::userFunction( 'get_option', array(
			'return' => function ( string $key, $default = false ) {
				$map = array(
					'swh_sla_warn_hours'   => '6',
					'swh_sla_breach_hours' => '12',
				);
				return $map[ $key ] ?? $default;
			},
		) );

		$out = PublicSettingsAllowlist::read_sla_thresholds();
		$this->assertSame(
			array( 'low', 'medium', 'high', 'urgent' ),
			array_keys( $out )
		);
		$this->assertNull( $out['low'] );
		$this->assertSame( 6, $out['medium'] );
		$this->assertSame( 12, $out['high'] );
		$this->assertNull( $out['urgent'] );
	}

	public function test_features_locked_shape_only_booleans(): void {
		WP_Mock::userFunction( 'get_option', array(
			'return' => function ( string $key, $default = false ) {
				$map = array(
					'swh_csat_enabled'    => true,
					'swh_ticket_page_id'  => 42,
					'swh_max_upload_count' => 5,
				);
				return $map[ $key ] ?? $default;
			},
		) );

		$out = PublicSettingsAllowlist::read_features();
		$this->assertSame( array( 'csat', 'portal', 'attachments' ), array_keys( $out ) );
		foreach ( $out as $k => $v ) {
			$this->assertIsBool( $v, sprintf( 'features.%s must be a boolean — never a config value.', $k ) );
		}
		$this->assertTrue( $out['csat'] );
		$this->assertTrue( $out['portal'] );
		$this->assertTrue( $out['attachments'] );
	}

	public function test_features_portal_false_when_no_page_configured(): void {
		WP_Mock::userFunction( 'get_option', array(
			'return' => function ( string $key, $default = false ) {
				$map = array(
					'swh_csat_enabled'     => false,
					'swh_ticket_page_id'   => 0,
					'swh_max_upload_count' => 0,
				);
				return $map[ $key ] ?? $default;
			},
		) );

		$out = PublicSettingsAllowlist::read_features();
		$this->assertFalse( $out['csat'] );
		$this->assertFalse( $out['portal'] );
		$this->assertFalse( $out['attachments'] );
	}
}
