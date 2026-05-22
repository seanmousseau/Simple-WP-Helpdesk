<?php
declare(strict_types=1);

namespace SWH\Tests\Unit\Concurrency;

use SWH\Concurrency\TicketVersion;
use WP_Mock;
use PHPUnit\Framework\TestCase;

final class TicketVersionTest extends TestCase {
	protected function setUp(): void { WP_Mock::setUp(); }
	protected function tearDown(): void { WP_Mock::tearDown(); }

	public function test_current_returns_1_when_meta_missing(): void {
		WP_Mock::userFunction( 'get_post_meta', [ 'args' => [ 42, '_swh_version', true ], 'return' => '' ] );
		$this->assertSame( 1, TicketVersion::current( 42 ) );
	}
	public function test_current_returns_stored_integer(): void {
		WP_Mock::userFunction( 'get_post_meta', [ 'args' => [ 42, '_swh_version', true ], 'return' => '7' ] );
		$this->assertSame( 7, TicketVersion::current( 42 ) );
	}
	public function test_bump_increments_and_persists(): void {
		WP_Mock::userFunction( 'get_post_meta', [ 'args' => [ 42, '_swh_version', true ], 'return' => '3' ] );
		WP_Mock::userFunction( 'update_post_meta', [ 'args' => [ 42, '_swh_version', 4 ], 'return' => true, 'times' => 1 ] );
		$this->assertSame( 4, TicketVersion::bump( 42 ) );
	}
	public function test_check_passes_when_versions_match(): void {
		WP_Mock::userFunction( 'get_post_meta', [ 'args' => [ 42, '_swh_version', true ], 'return' => '5' ] );
		$this->assertTrue( TicketVersion::check( 42, 5 ) );
	}
	public function test_check_fails_on_version_mismatch(): void {
		WP_Mock::userFunction( 'get_post_meta', [ 'args' => [ 42, '_swh_version', true ], 'return' => '5' ] );
		$this->assertFalse( TicketVersion::check( 42, 4 ) );
	}
}
