<?php
/**
 * Regression for tests/bootstrap.php wp_unslash stub (#531).
 *
 * Real WP core recurses into arrays/objects. The stub now mirrors that so
 * future tests passing an array through unslash don't silently pass when
 * they would have surfaced a notice under real WP.
 *
 * @package Simple_WP_Helpdesk
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class WpUnslashStubTest extends TestCase {

	public function test_unslash_scalar_string_strips_slashes(): void {
		$this->assertSame( "a\\b", wp_unslash( "a\\\\b" ) );
	}

	public function test_unslash_array_recurses(): void {
		$input    = array( "a\\\\b", array( "c\\\\d" ) );
		$expected = array( "a\\b", array( "c\\d" ) );
		$this->assertSame( $expected, wp_unslash( $input ) );
	}

	public function test_unslash_passes_through_non_string_non_array(): void {
		$this->assertSame( 42, wp_unslash( 42 ) );
		$this->assertNull( wp_unslash( null ) );
	}
}
