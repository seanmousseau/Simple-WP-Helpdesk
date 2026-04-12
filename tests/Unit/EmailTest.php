<?php
/**
 * Unit tests for swh_parse_template() in includes/class-email.php.
 *
 * @package Simple_WP_Helpdesk
 */

use WP_Mock\Tools\TestCase;

/**
 * Tests for template placeholder substitution, {if}/{endif} conditional
 * blocks, unreplaced-placeholder cleanup, and PCRE failure preservation.
 */
class EmailTest extends TestCase {

	/**
	 * Load email helpers once (idempotent — guards with function_exists checks aren't
	 * needed because WP_Mock resets state, not function definitions).
	 */
	public function setUp(): void {
		parent::setUp();
		require_once SWH_PLUGIN_DIR . 'includes/helpers.php';
		require_once SWH_PLUGIN_DIR . 'includes/class-email.php';
	}

	// -------------------------------------------------------------------------
	// Placeholder substitution
	// -------------------------------------------------------------------------

	/** Simple placeholder replaced with scalar data value. */
	public function test_placeholder_replaced(): void {
		WP_Mock::userFunction( 'apply_filters' )
			->andReturnArg( 1 );

		$result = swh_parse_template( 'Hello {name}!', array( 'name' => 'Alice' ) );
		$this->assertSame( 'Hello Alice!', $result );
	}

	/** Multiple distinct placeholders all replaced. */
	public function test_multiple_placeholders(): void {
		WP_Mock::userFunction( 'apply_filters' )
			->andReturnArg( 1 );

		$result = swh_parse_template(
			'Ticket {ticket_id} for {name}.',
			array(
				'ticket_id' => 'TKT-0042',
				'name'      => 'Bob',
			)
		);
		$this->assertSame( 'Ticket TKT-0042 for Bob.', $result );
	}

	/**
	 * Unreplaced placeholders are cleaned up (no data key for token).
	 *
	 * Note: swh_parse_template() trims the result, so trailing spaces are stripped.
	 */
	public function test_unreplaced_placeholder_removed(): void {
		WP_Mock::userFunction( 'apply_filters' )
			->andReturnArg( 1 );

		$result = swh_parse_template( 'Hi {name}, your link: {ticket_url}', array( 'name' => 'Carol' ) );
		$this->assertSame( 'Hi Carol, your link:', $result );
	}

	/**
	 * Non-scalar data value replaced with empty string.
	 *
	 * Note: swh_parse_template() trims the result, so trailing spaces are stripped.
	 */
	public function test_non_scalar_value_becomes_empty(): void {
		WP_Mock::userFunction( 'apply_filters' )
			->andReturnArg( 1 );

		$result = swh_parse_template( 'Value: {items}', array( 'items' => array( 'a', 'b' ) ) );
		$this->assertSame( 'Value:', $result );
	}

	// -------------------------------------------------------------------------
	// {if key}…{endif key} conditionals
	// -------------------------------------------------------------------------

	/** Conditional block shown when key has a non-empty value. */
	public function test_conditional_block_shown_when_truthy(): void {
		WP_Mock::userFunction( 'apply_filters' )
			->andReturnArg( 1 );

		$template = 'Before. {if message}Reply: {message}{endif message} After.';
		$result   = swh_parse_template( $template, array( 'message' => 'Hello there.' ) );
		$this->assertStringContainsString( 'Reply: Hello there.', $result );
	}

	/** Conditional block hidden when key is empty string. */
	public function test_conditional_block_hidden_when_empty(): void {
		WP_Mock::userFunction( 'apply_filters' )
			->andReturnArg( 1 );

		$template = 'Before. {if message}Reply: {message}{endif message} After.';
		$result   = swh_parse_template( $template, array( 'message' => '' ) );
		$this->assertStringNotContainsString( 'Reply:', $result );
		$this->assertStringContainsString( 'Before.', $result );
		$this->assertStringContainsString( 'After.', $result );
	}

	/** Conditional block hidden when key is absent from data. */
	public function test_conditional_block_hidden_when_key_missing(): void {
		WP_Mock::userFunction( 'apply_filters' )
			->andReturnArg( 1 );

		$template = '{if autoclose_days}Closes in {autoclose_days} days.{endif autoclose_days}';
		$result   = swh_parse_template( $template, array() );
		$this->assertSame( '', $result );
	}

	/** Two independent conditional blocks processed correctly. */
	public function test_two_conditional_blocks(): void {
		WP_Mock::userFunction( 'apply_filters' )
			->andReturnArg( 1 );

		$template = '{if a}A:{a}{endif a} {if b}B:{b}{endif b}';
		$result   = swh_parse_template(
			$template,
			array(
				'a' => 'yes',
				'b' => '',
			)
		);
		$this->assertStringContainsString( 'A:yes', $result );
		$this->assertStringNotContainsString( 'B:', $result );
	}

	// -------------------------------------------------------------------------
	// Whitespace / newline collapsing
	// -------------------------------------------------------------------------

	/** Three or more consecutive newlines collapsed to two. */
	public function test_newline_collapsing(): void {
		WP_Mock::userFunction( 'apply_filters' )
			->andReturnArg( 1 );

		$result = swh_parse_template( "line1\n\n\n\nline2", array() );
		$this->assertSame( "line1\n\nline2", $result );
	}

	/** Template is trimmed of leading/trailing whitespace. */
	public function test_template_trimmed(): void {
		WP_Mock::userFunction( 'apply_filters' )
			->andReturnArg( 1 );

		$result = swh_parse_template( "  \n hello \n  ", array() );
		$this->assertSame( 'hello', $result );
	}

	// -------------------------------------------------------------------------
	// apply_filters pass-through
	// -------------------------------------------------------------------------

	/** Apply_filters result is returned (filter can modify output). */
	public function test_apply_filters_result_returned(): void {
		$data = array( 'name' => 'Alice' );

		WP_Mock::onFilter( 'swh_parse_template' )
			->with( 'Hello Alice!', $data )
			->reply( 'FILTERED' );

		$result = swh_parse_template( 'Hello {name}!', $data );
		$this->assertSame( 'FILTERED', $result );
	}
}
