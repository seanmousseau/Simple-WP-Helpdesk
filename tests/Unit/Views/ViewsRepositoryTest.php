<?php
declare(strict_types=1);

namespace SWH\Tests\Unit\Views;

use SWH\Views\ViewsRepository;
use WP_Mock;
use PHPUnit\Framework\TestCase;

final class ViewsRepositoryTest extends TestCase {
	protected function setUp(): void { WP_Mock::setUp(); }
	protected function tearDown(): void { WP_Mock::tearDown(); }

	public function test_get_all_returns_merged_personal_and_global(): void {
		WP_Mock::userFunction( 'get_user_meta', [
			'args'   => [ 7, 'swh_personal_views', true ],
			'return' => array( array( 'id' => 'p1', 'name' => 'My triage', 'filters' => array() ) ),
		] );
		WP_Mock::userFunction( 'get_user_meta', [
			'args'   => [ 7, 'swh_hidden_global_views', true ],
			'return' => array(),
		] );
		WP_Mock::userFunction( 'get_option', [
			'args'   => [ 'swh_global_views', array() ],
			'return' => array( array( 'id' => 'g1', 'name' => 'Team overflow', 'filters' => array() ) ),
		] );

		$repo  = new ViewsRepository();
		$views = $repo->get_all_for_user( 7 );

		$this->assertCount( 2, $views );
		// Global views come first, then personal.
		$this->assertSame( 'g1', $views[0]['id'] );
		$this->assertSame( 'global', $views[0]['scope'] );
		$this->assertSame( 'p1', $views[1]['id'] );
		$this->assertSame( 'personal', $views[1]['scope'] );
	}

	public function test_get_all_hides_global_views_listed_in_user_hidden(): void {
		WP_Mock::userFunction( 'get_user_meta', [ 'args' => [ 7, 'swh_personal_views', true ], 'return' => array() ] );
		WP_Mock::userFunction( 'get_user_meta', [ 'args' => [ 7, 'swh_hidden_global_views', true ], 'return' => array( 'g1' ) ] );
		WP_Mock::userFunction( 'get_option', [
			'args'   => [ 'swh_global_views', array() ],
			'return' => array(
				array( 'id' => 'g1', 'name' => 'A' ),
				array( 'id' => 'g2', 'name' => 'B' ),
			),
		] );

		$views = ( new ViewsRepository() )->get_all_for_user( 7 );

		$this->assertCount( 1, $views );
		$this->assertSame( 'g2', $views[0]['id'] );
	}

	public function test_save_personal_appends_and_returns_id(): void {
		WP_Mock::userFunction( 'get_user_meta', [
			'args'   => [ 7, 'swh_personal_views', true ],
			'return' => array( array( 'id' => 'p1', 'name' => 'Existing' ) ),
		] );
		WP_Mock::userFunction( 'update_user_meta', [
			'times'  => 1,
			'return' => true,
		] );
		WP_Mock::userFunction( 'wp_generate_uuid4', [ 'return' => 'abcd-1234' ] );

		$view  = array( 'name' => 'Newly added', 'filters' => array( 'status' => array( 'open' ) ) );
		$saved = ( new ViewsRepository() )->save_personal( 7, $view );

		$this->assertSame( 'abcd-1234', $saved['id'] );
		$this->assertSame( 'Newly added', $saved['name'] );
	}

	public function test_delete_personal_removes_by_id(): void {
		WP_Mock::userFunction( 'get_user_meta', [
			'args'   => [ 7, 'swh_personal_views', true ],
			'return' => array(
				array( 'id' => 'p1', 'name' => 'Keep' ),
				array( 'id' => 'p2', 'name' => 'Delete' ),
			),
		] );
		WP_Mock::userFunction( 'update_user_meta', [
			'args'   => [ 7, 'swh_personal_views', array( array( 'id' => 'p1', 'name' => 'Keep' ) ) ],
			'return' => true,
			'times'  => 1,
		] );

		$ok = ( new ViewsRepository() )->delete_personal( 7, 'p2' );

		$this->assertTrue( $ok );
	}

	public function test_save_global_requires_manage_options(): void {
		WP_Mock::userFunction( 'current_user_can', [
			'args'   => [ 'manage_options' ],
			'return' => false,
		] );

		$repo   = new ViewsRepository();
		$result = $repo->save_global( array( 'name' => 'X' ) );

		$this->assertNull( $result );  // caller treats null as denied
	}
}
