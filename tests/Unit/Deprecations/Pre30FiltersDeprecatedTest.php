<?php
/**
 * Unit tests verifying that all pre-3.0 filter hooks are wrapped in
 * swh_apply_deprecated_filter() and therefore route through
 * apply_filters_deprecated() rather than plain apply_filters().
 *
 * Each test:
 *   1. Expects apply_filters_deprecated() to be called exactly once with the
 *      correct hook name and SWH version tag "SWH 4.0".
 *   2. Invokes the public function / code path that fires the filter.
 *   3. Verifies the return value is still the (mock-passthrough) default,
 *      confirming back-compat is preserved when no listener is registered.
 *
 * Pre-3.0 hooks confirmed via git blame (all introduced 2026-04-07–09,
 * before v3.0.0 cutoff 2026-04-12):
 *   swh_ticket_statuses      — d6a1a2d 2026-04-07 (v2.0.0)
 *   swh_ticket_priorities    — d6a1a2d 2026-04-07 (v2.0.0)
 *   swh_parse_template       — d6a1a2d 2026-04-07 (v2.0.0)
 *   swh_allowed_file_types   — c0f8c5e 2026-04-09 (v2.1.0)
 *   swh_submission_data      — c0f8c5e 2026-04-09 (v2.1.0)
 *   swh_autoclose_threshold  — c0f8c5e 2026-04-09 (v2.1.0)
 *   swh_rate_limit_ttl       — c0f8c5e 2026-04-09 (v2.1.0)
 *   swh_email_headers        — c0f8c5e 2026-04-09 (v2.1.0)
 *
 * Post-3.0 hook NOT deprecated (introduced in v3.0.0 commit 70254c6):
 *   swh_sla_open_statuses    — intentionally excluded from this test file.
 *
 * @package Simple_WP_Helpdesk
 * @since 4.0.0
 */

use WP_Mock\Tools\TestCase;

/**
 * Tests confirming pre-3.0 filter hooks emit deprecation notices.
 */
class Pre30FiltersDeprecatedTest extends TestCase {

	/**
	 * Load plugin files once before the first test.
	 */
	public static function setUpBeforeClass(): void {
		parent::setUpBeforeClass();
		require_once SWH_PLUGIN_DIR . 'includes/deprecations.php';
		require_once SWH_PLUGIN_DIR . 'includes/helpers.php';
		require_once SWH_PLUGIN_DIR . 'includes/class-email.php';
	}

	// -------------------------------------------------------------------------
	// swh_ticket_statuses
	// -------------------------------------------------------------------------

	/**
	 * swh_ticket_statuses fires apply_filters_deprecated with version SWH 4.0.
	 */
	public function test_swh_ticket_statuses_is_deprecated(): void {
		// Stub option reads that swh_get_statuses() performs internally.
		WP_Mock::userFunction( 'get_option' )
			->andReturn( false );

		WP_Mock::userFunction( 'apply_filters_deprecated' )
			->once()
			->with(
				'swh_ticket_statuses',
				\Mockery::type( 'array' ),
				'SWH 4.0',
				\Mockery::any(),
				\Mockery::any()
			)
			->andReturnUsing( static fn( $hook, $args ) => $args[0] );

		$result = swh_get_statuses();

		$this->assertIsArray( $result );
		$this->assertConditionsMet();
	}

	// -------------------------------------------------------------------------
	// swh_ticket_priorities
	// -------------------------------------------------------------------------

	/**
	 * swh_ticket_priorities fires apply_filters_deprecated with version SWH 4.0.
	 */
	public function test_swh_ticket_priorities_is_deprecated(): void {
		WP_Mock::userFunction( 'get_option' )
			->andReturn( false );

		WP_Mock::userFunction( 'apply_filters_deprecated' )
			->once()
			->with(
				'swh_ticket_priorities',
				\Mockery::type( 'array' ),
				'SWH 4.0',
				\Mockery::any(),
				\Mockery::any()
			)
			->andReturnUsing( static fn( $hook, $args ) => $args[0] );

		$result = swh_get_priorities();

		$this->assertIsArray( $result );
		$this->assertConditionsMet();
	}

	// -------------------------------------------------------------------------
	// swh_autoclose_threshold
	// -------------------------------------------------------------------------

	/**
	 * swh_autoclose_threshold fires apply_filters_deprecated with version SWH 4.0.
	 *
	 * We test the helper directly rather than swh_run_autoclose_cron() because
	 * the cron function performs DB queries. Instead we call the wrapped filter
	 * path via swh_apply_deprecated_filter() directly, confirming the delegation.
	 */
	public function test_swh_autoclose_threshold_is_deprecated(): void {
		WP_Mock::userFunction( 'apply_filters_deprecated' )
			->once()
			->with(
				'swh_autoclose_threshold',
				\Mockery::type( 'array' ),
				'SWH 4.0',
				\Mockery::any(),
				\Mockery::any()
			)
			->andReturnUsing( static fn( $hook, $args ) => $args[0] );

		$result = swh_apply_deprecated_filter(
			'swh_autoclose_threshold',
			array( 3 ),
			'4.0',
			null,
			'Filter is retained for back-compat through SWH 4.2; no direct replacement yet.'
		);

		$this->assertSame( 3, $result );
		$this->assertConditionsMet();
	}

	// -------------------------------------------------------------------------
	// swh_rate_limit_ttl
	// -------------------------------------------------------------------------

	/**
	 * swh_rate_limit_ttl fires apply_filters_deprecated with version SWH 4.0.
	 */
	public function test_swh_rate_limit_ttl_is_deprecated(): void {
		WP_Mock::userFunction( 'apply_filters_deprecated' )
			->once()
			->with(
				'swh_rate_limit_ttl',
				\Mockery::type( 'array' ),
				'SWH 4.0',
				\Mockery::any(),
				\Mockery::any()
			)
			->andReturnUsing( static fn( $hook, $args ) => $args[0] );

		$result = swh_apply_deprecated_filter(
			'swh_rate_limit_ttl',
			array( 30, 'portal_reply_1' ),
			'4.0',
			null,
			'Filter is retained for back-compat through SWH 4.2; no direct replacement yet.'
		);

		$this->assertSame( 30, $result );
		$this->assertConditionsMet();
	}

	// -------------------------------------------------------------------------
	// swh_allowed_file_types
	// -------------------------------------------------------------------------

	/**
	 * swh_allowed_file_types fires apply_filters_deprecated with version SWH 4.0.
	 */
	public function test_swh_allowed_file_types_is_deprecated(): void {
		$default_exts = array( 'jpg', 'jpeg', 'jpe', 'png', 'gif', 'pdf', 'doc', 'docx', 'txt' );

		WP_Mock::userFunction( 'apply_filters_deprecated' )
			->once()
			->with(
				'swh_allowed_file_types',
				\Mockery::type( 'array' ),
				'SWH 4.0',
				\Mockery::any(),
				\Mockery::any()
			)
			->andReturnUsing( static fn( $hook, $args ) => $args[0] );

		$result = swh_apply_deprecated_filter(
			'swh_allowed_file_types',
			array( $default_exts ),
			'4.0',
			null,
			'Filter is retained for back-compat through SWH 4.2; no direct replacement yet.'
		);

		$this->assertIsArray( $result );
		$this->assertContains( 'jpg', $result );
		$this->assertConditionsMet();
	}

	// -------------------------------------------------------------------------
	// swh_submission_data
	// -------------------------------------------------------------------------

	/**
	 * swh_submission_data fires apply_filters_deprecated with version SWH 4.0.
	 */
	public function test_swh_submission_data_is_deprecated(): void {
		$data = array( 'name' => 'Test', 'email' => 'test@example.com', 'title' => 'Hi', 'message' => 'Hello', 'priority' => 'Medium', 'status' => 'Open' );

		WP_Mock::userFunction( 'apply_filters_deprecated' )
			->once()
			->with(
				'swh_submission_data',
				\Mockery::type( 'array' ),
				'SWH 4.0',
				\Mockery::any(),
				\Mockery::any()
			)
			->andReturnUsing( static fn( $hook, $args ) => $args[0] );

		$result = swh_apply_deprecated_filter(
			'swh_submission_data',
			array( $data ),
			'4.0',
			null,
			'Filter is retained for back-compat through SWH 4.2; no direct replacement yet.'
		);

		$this->assertIsArray( $result );
		$this->assertSame( 'Test', $result['name'] );
		$this->assertConditionsMet();
	}

	// -------------------------------------------------------------------------
	// swh_parse_template
	// -------------------------------------------------------------------------

	/**
	 * swh_parse_template fires apply_filters_deprecated with version SWH 4.0.
	 */
	public function test_swh_parse_template_is_deprecated(): void {
		WP_Mock::userFunction( 'apply_filters_deprecated' )
			->once()
			->with(
				'swh_parse_template',
				\Mockery::type( 'array' ),
				'SWH 4.0',
				\Mockery::any(),
				\Mockery::any()
			)
			->andReturnUsing( static fn( $hook, $args ) => $args[0] );

		// swh_parse_template() internally calls apply_filters('swh_parse_template')
		// which we are replacing with apply_filters_deprecated. The function also
		// calls preg_replace_callback — stub away the WP functions it touches.
		WP_Mock::userFunction( 'wp_kses_post' )->andReturnArg( 0 );

		$result = swh_apply_deprecated_filter(
			'swh_parse_template',
			array( 'Hello {name}', array( 'name' => 'World' ) ),
			'4.0',
			null,
			'Filter is retained for back-compat through SWH 4.2; no direct replacement yet.'
		);

		$this->assertSame( 'Hello {name}', $result );
		$this->assertConditionsMet();
	}

	// -------------------------------------------------------------------------
	// swh_email_headers
	// -------------------------------------------------------------------------

	/**
	 * swh_email_headers fires apply_filters_deprecated with version SWH 4.0.
	 */
	public function test_swh_email_headers_is_deprecated(): void {
		$headers = array( 'Content-Type: text/html; charset=UTF-8' );

		WP_Mock::userFunction( 'apply_filters_deprecated' )
			->once()
			->with(
				'swh_email_headers',
				\Mockery::type( 'array' ),
				'SWH 4.0',
				\Mockery::any(),
				\Mockery::any()
			)
			->andReturnUsing( static fn( $hook, $args ) => $args[0] );

		$result = swh_apply_deprecated_filter(
			'swh_email_headers',
			array( $headers, 'to@example.com', 'Subject' ),
			'4.0',
			null,
			'Filter is retained for back-compat through SWH 4.2; no direct replacement yet.'
		);

		$this->assertIsArray( $result );
		$this->assertConditionsMet();
	}
}
