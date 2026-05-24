<?php
/**
 * Domain tests for the four scheduled cron events — covers gap #17.
 *
 * Each cron job has the same shape:
 *   1. Read a config value (days / hours).
 *   2. Early-return if config disables the job (`<= 0`).
 *   3. Early-return if the lock transient is already set.
 *   4. Acquire the lock, do work, release the lock.
 *
 * These tests pin (1)–(3) explicitly. The full happy-path side-effects
 * (status transitions, email dispatch, attachment deletion) are exercised
 * by integration / E2E coverage — mocking them at the unit layer would
 * require ~40 stubs per cron and create more maintenance load than signal.
 *
 * Why the early-return guards are load-bearing:
 *   - `$days <= 0` is the operator's kill switch (set to 0 to disable).
 *     An off-by-one (`< 0` instead of `<= 0`) would silently enable the
 *     job when an admin disables it via the setting.
 *   - The lock prevents two cron runners from colliding. If it stops
 *     being set/checked, retention could double-delete files / tickets.
 *
 * @see docs/internal/test-coverage-matrix.md — gap #17
 * @package Simple_WP_Helpdesk
 */

declare(strict_types=1);

namespace SWH\Tests\Unit;

use PHPUnit\Framework\TestCase;
use WP_Mock;

final class CronTest extends TestCase {

	/** @var array<string, mixed> Per-test option store (read via swh_get_int_option → get_option). */
	private array $options = array();

	/** @var array<string, mixed> Transient store; key → value. */
	private array $transients = array();

	/** @var array<int, array{string, mixed, int}> Captured set_transient calls. */
	private array $set_transient_calls = array();

	/** @var array<int, string> Captured delete_transient calls. */
	private array $delete_transient_calls = array();

	protected function setUp(): void {
		WP_Mock::setUp();
		$this->options                = array();
		$this->transients             = array();
		$this->set_transient_calls    = array();
		$this->delete_transient_calls = array();

		if ( ! function_exists( 'absint' ) ) {
			require_once __DIR__ . '/_csat_absint_stub.php';
		}
		require_once SWH_PLUGIN_DIR . 'includes/helpers.php';
		require_once SWH_PLUGIN_DIR . 'includes/deprecations.php';
		// class-cron.php declares add_action() at load time, which calls
		// add_action() (a WP global). Stub it so the require succeeds.
		WP_Mock::userFunction( 'add_action', array( 'return' => true ) );
		WP_Mock::userFunction( 'add_filter', array( 'return' => true ) );
		WP_Mock::userFunction( 'apply_filters_deprecated', array( 'return_arg' => 1 ) );
		require_once SWH_PLUGIN_DIR . 'includes/class-cron.php';

		WP_Mock::userFunction(
			'get_option',
			array(
				'return' => function ( $key, $default = false ) {
					return $this->options[ $key ] ?? $default;
				},
			)
		);

		WP_Mock::userFunction(
			'get_transient',
			array(
				'return' => function ( $key ) {
					return $this->transients[ $key ] ?? false;
				},
			)
		);
		WP_Mock::userFunction(
			'set_transient',
			array(
				'return' => function ( $key, $value, $ttl = 0 ) {
					$this->transients[ $key ]    = $value;
					$this->set_transient_calls[] = array( $key, $value, $ttl );
					return true;
				},
			)
		);
		WP_Mock::userFunction(
			'delete_transient',
			array(
				'return' => function ( $key ) {
					unset( $this->transients[ $key ] );
					$this->delete_transient_calls[] = $key;
					return true;
				},
			)
		);

		// Cron jobs that touch the DB / make no-op calls when no work is found.
		WP_Mock::userFunction( 'get_posts', array( 'return' => array() ) );
		WP_Mock::userFunction( 'apply_filters', array( 'return_arg' => 1 ) );

		// $wpdb global for the autoclose rate-limit cleanup.
		global $wpdb;
		$wpdb            = new \stdClass();
		$wpdb->options   = 'wp_options';
		$wpdb->prepare   = fn( ...$args ) => '';
		$wpdb_obj         = new class() {
			public string $options = 'wp_options';
			public function query( $sql ) {
				return 0;
			}
			public function prepare( $sql, ...$args ) {
				return $sql;
			}
		};
		$wpdb            = $wpdb_obj;
	}

	protected function tearDown(): void {
		WP_Mock::tearDown();
		global $wpdb;
		$wpdb = null;
	}

	// ---------- swh_process_autoclose ---------- //

	public function test_autoclose_returns_early_when_days_is_zero(): void {
		// Operator kill switch: setting autoclose_days=0 disables the job.
		// swh_get_int_option reads the FLAT option key, not the grouped settings array.
		$this->options['swh_autoclose_days'] = 0;

		swh_process_autoclose();

		$this->assertEmpty(
			$this->set_transient_calls,
			'autoclose with days=0 must return BEFORE acquiring the lock. If the lock '
				. 'is taken, a misconfigured admin would still pay the DB query cost.'
		);
	}

	public function test_autoclose_returns_early_when_lock_held(): void {
		// Another runner already holds the lock — abort to avoid double-processing.
		$this->options['swh_autoclose_days']    = 3;
		$this->transients['swh_lock_autoclose'] = 1;

		swh_process_autoclose();

		$this->assertEmpty(
			$this->set_transient_calls,
			'Held lock must short-circuit BEFORE the work loop.'
		);
		$this->assertEmpty(
			$this->delete_transient_calls,
			'Held lock must NOT be deleted by the other runner — the holder releases its own lock.'
		);
	}

	public function test_autoclose_acquires_and_releases_lock_on_normal_run(): void {
		// No tickets matched → no work done, but lock must be acquired and released.
		$this->options['swh_autoclose_days'] = 3;

		swh_process_autoclose();

		$this->assertNotEmpty(
			$this->set_transient_calls,
			'Normal run must acquire the lock.'
		);
		$this->assertSame(
			'swh_lock_autoclose',
			$this->set_transient_calls[0][0],
			'Lock key must be swh_lock_autoclose.'
		);
		$this->assertContains(
			'swh_lock_autoclose',
			$this->delete_transient_calls,
			'Normal run must release the lock so the next hourly tick can proceed.'
		);
	}

	// ---------- swh_process_retention_attachments ---------- //

	public function test_retention_attachments_returns_early_when_days_is_zero(): void {
		$this->options['swh_retention_attachments_days'] = 0;

		swh_process_retention_attachments();

		$this->assertEmpty(
			$this->set_transient_calls,
			'retention_attachments with days=0 (disabled) must NOT acquire the lock.'
		);
	}

	public function test_retention_attachments_returns_early_when_lock_held(): void {
		$this->options['swh_retention_attachments_days'] = 7;
		$this->transients['swh_lock_retention_att']      = 1;

		swh_process_retention_attachments();

		$this->assertEmpty(
			$this->set_transient_calls,
			'Held lock must short-circuit retention_attachments.'
		);
	}

	// ---------- swh_process_retention_tickets ---------- //

	public function test_retention_tickets_returns_early_when_days_is_zero(): void {
		$this->options['swh_retention_tickets_days'] = 0;

		swh_process_retention_tickets();

		$this->assertEmpty(
			$this->set_transient_calls,
			'retention_tickets with days=0 (disabled) must NOT acquire the lock.'
		);
	}

	public function test_retention_tickets_returns_early_when_lock_held(): void {
		$this->options['swh_retention_tickets_days'] = 30;
		$this->transients['swh_lock_retention_tkt']  = 1;

		swh_process_retention_tickets();

		$this->assertEmpty(
			$this->set_transient_calls,
			'Held lock must short-circuit retention_tickets.'
		);
	}

	public function test_retention_tickets_acquires_lock_on_normal_run(): void {
		$this->options['swh_retention_tickets_days'] = 30;

		swh_process_retention_tickets();

		$this->assertSame(
			'swh_lock_retention_tkt',
			$this->set_transient_calls[0][0] ?? null,
			'retention_tickets must use the swh_lock_retention_tkt key — distinct from autoclose '
				. 'and attachment locks so a long-running tickets sweep does not block other crons.'
		);
	}

	// ---------- swh_process_sla_check ---------- //

	public function test_sla_check_returns_early_when_both_thresholds_zero(): void {
		// Both warn=0 AND breach=0 disables the job.
		$this->options['swh_sla_warn_hours']   = 0;
		$this->options['swh_sla_breach_hours'] = 0;

		swh_process_sla_check();

		$this->assertEmpty(
			$this->set_transient_calls,
			'SLA check with both thresholds disabled must NOT acquire the lock.'
		);
	}

	public function test_sla_check_returns_early_when_lock_held(): void {
		$this->options['swh_sla_warn_hours']   = 4;
		$this->options['swh_sla_breach_hours'] = 8;
		$this->transients['swh_lock_sla']      = 1;

		swh_process_sla_check();

		$this->assertEmpty(
			$this->set_transient_calls,
			'Held SLA lock must short-circuit the run.'
		);
	}

	public function test_sla_check_proceeds_when_only_warn_threshold_set(): void {
		// Either threshold non-zero must trigger the job — the early-return
		// is `<=0 AND <=0`, not `<=0 OR <=0`.
		$this->options['swh_sla_warn_hours']   = 4;
		$this->options['swh_sla_breach_hours'] = 0;

		swh_process_sla_check();

		$this->assertNotEmpty(
			$this->set_transient_calls,
			'SLA check with warn>0 alone must still run — protects against admins who set only the warn threshold.'
		);
	}

	// ---------- Cross-cron isolation ---------- //

	public function test_lock_keys_are_distinct_across_crons(): void {
		// Pin the four lock keys explicitly. Sharing a key would let one
		// long-running cron block the others.
		$expected = array(
			'swh_lock_autoclose',
			'swh_lock_retention_att',
			'swh_lock_retention_tkt',
			'swh_lock_sla',
		);
		$source = file_get_contents( SWH_PLUGIN_DIR . 'includes/class-cron.php' );
		foreach ( $expected as $key ) {
			$this->assertStringContainsString(
				"'$key'",
				(string) $source,
				"Lock key '$key' must remain in class-cron.php. Sharing lock keys across crons would let "
					. 'one long-running job block the others.'
			);
		}
	}
}
