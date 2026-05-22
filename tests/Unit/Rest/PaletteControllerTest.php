<?php
declare(strict_types=1);

namespace SWH\Tests\Unit\Rest;

use SWH\Rest\PaletteController;
use WP_Mock;
use WP_REST_Request;
use PHPUnit\Framework\TestCase;

final class PaletteControllerTest extends TestCase {
	protected function setUp(): void {
		WP_Mock::setUp(); }
	protected function tearDown(): void {
		WP_Mock::tearDown(); }

	public function test_register_route(): void {
		WP_Mock::userFunction(
			'register_rest_route',
			array(
				'args'  => array( 'swh/v1', '/palette/suggest', WP_Mock\Functions::type( 'array' ) ),
				'times' => 1,
			)
		);
		( new PaletteController() )->register_routes();
		$this->addToAssertionCount( 1 );
	}

	public function test_suggest_returns_recent_tickets_for_empty_query(): void {
		WP_Mock::userFunction( 'current_user_can', array( 'return' => true ) );
		WP_Mock::userFunction(
			'get_posts',
			array(
				'return' => array(
					(object) array(
						'ID'         => 1247,
						'post_title' => 'Login throwing 500s',
					),
					(object) array(
						'ID'         => 1246,
						'post_title' => 'Cannot download invoice',
					),
				),
			)
		);
		WP_Mock::userFunction( 'get_post_meta', array( 'return' => 'open' ) );

		$req = new WP_REST_Request( 'GET', '/swh/v1/palette/suggest' );
		$req->set_param( 'q', '' );

		$response = ( new PaletteController() )->suggest( $req );
		$data     = $response->get_data();

		$this->assertArrayHasKey( 'tickets', $data );
		$this->assertCount( 2, $data['tickets'] );
		$this->assertSame( 1247, $data['tickets'][0]['id'] );
	}

	public function test_suggest_fuzzy_matches_query(): void {
		WP_Mock::userFunction( 'current_user_can', array( 'return' => true ) );
		WP_Mock::userFunction(
			'get_posts',
			array(
				'return' => array(
					(object) array(
						'ID'         => 942,
						'post_title' => 'Cannot close ticket from portal',
					),
				),
			)
		);
		WP_Mock::userFunction( 'get_post_meta', array( 'return' => 'open' ) );

		$req = new WP_REST_Request( 'GET', '/swh/v1/palette/suggest' );
		$req->set_param( 'q', 'close' );

		$response = ( new PaletteController() )->suggest( $req );
		$data     = $response->get_data();

		$this->assertCount( 1, $data['tickets'] );
		$this->assertSame( 942, $data['tickets'][0]['id'] );
	}
}
