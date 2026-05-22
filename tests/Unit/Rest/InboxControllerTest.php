<?php
/**
 * Unit tests for SWH\Rest\InboxController.
 *
 * @package Simple_WP_Helpdesk
 */

declare(strict_types=1);

namespace SWH\Tests\Unit\Rest;

use SWH\Rest\InboxController;
use WP_Mock;
use WP_REST_Request;
use PHPUnit\Framework\TestCase;

/**
 * Testable subclass that injects query results without hitting WP_Query.
 */
final class TestableInboxController extends InboxController {
	/** @var array{posts:int[],found_posts:int} */
	public array $stub_result = array(
		'posts'       => array(),
		'found_posts' => 0,
	);

	/** @param array<string,mixed> $args */
	protected function run_query( array $args ): array {
		return $this->stub_result;
	}
}

/**
 * Tests for InboxController.
 */
final class InboxControllerTest extends TestCase {
	protected function setUp(): void {
		WP_Mock::setUp();
	}

	protected function tearDown(): void {
		WP_Mock::tearDown();
	}

	public function test_register_routes_registers_get_tickets(): void {
		WP_Mock::userFunction(
			'register_rest_route',
			array(
				'args'  => array( 'swh/v1', '/tickets', WP_Mock\Functions::type( 'array' ) ),
				'times' => 1,
			)
		);

		( new InboxController() )->register_routes();
		$this->addToAssertionCount( 1 ); // WP_Mock expectation counts as assertion.
	}

	public function test_get_items_returns_ticket_list_shape(): void {
		WP_Mock::userFunction( 'current_user_can', array( 'return' => true ) );

		$controller              = new TestableInboxController();
		$controller->stub_result = array(
			'posts'       => array( 1247, 1246, 1245 ),
			'found_posts' => 3,
		);

		$this->mock_ticket_payload( 1247, 'open', 'Login throwing 500s', 'bob@example.com', HOUR_IN_SECONDS * 2 );
		$this->mock_ticket_payload( 1246, 'in_progress', 'Cannot download invoice', 'alice@example.com', HOUR_IN_SECONDS * 3 );
		$this->mock_ticket_payload( 1245, 'open', 'Feature: dark mode', 'carol@example.com', HOUR_IN_SECONDS * 5 );

		$req = new WP_REST_Request( 'GET', '/swh/v1/tickets' );
		$req->set_param( 'view', 'unassigned' );
		$req->set_param( 'page', 1 );
		$req->set_param( 'per_page', 50 );
		$req->set_param( 'sort', 'last_reply_desc' );
		$req->set_param( 'search', '' );

		$response = $controller->get_items( $req );
		$data     = $response->get_data();

		$this->assertArrayHasKey( 'tickets', $data );
		$this->assertArrayHasKey( 'total', $data );
		$this->assertCount( 3, $data['tickets'] );
		$this->assertSame( 3, $data['total'] );
		$this->assertSame( 1247, $data['tickets'][0]['id'] );
		$this->assertSame( 'Login throwing 500s', $data['tickets'][0]['title'] );
	}

	public function test_get_items_total_reflects_full_count_not_page_size(): void {
		WP_Mock::userFunction( 'current_user_can', array( 'return' => true ) );

		$controller = new TestableInboxController();
		// Simulate 87 total results but only 50 on this page.
		$controller->stub_result = array(
			'posts'       => range( 1000, 1049 ),
			'found_posts' => 87,
		);

		foreach ( range( 1000, 1049 ) as $id ) {
			WP_Mock::userFunction(
				'get_post',
				array(
					'args'   => array( $id ),
					'return' => (object) array(
						'ID'            => $id,
						'post_title'    => "Ticket $id",
						'post_date_gmt' => gmdate( 'Y-m-d H:i:s', time() - 3600 ),
					),
				)
			);
			WP_Mock::userFunction(
				'swh_get_string_meta',
				array(
					'args'   => array( $id, '_ticket_status' ),
					'return' => 'open',
				)
			);
			WP_Mock::userFunction(
				'swh_get_string_meta',
				array(
					'args'   => array( $id, '_ticket_priority' ),
					'return' => 'normal',
				)
			);
			WP_Mock::userFunction(
				'swh_get_int_meta',
				array(
					'args'   => array( $id, '_ticket_assigned' ),
					'return' => 0,
				)
			);
			WP_Mock::userFunction(
				'swh_get_string_meta',
				array(
					'args'   => array( $id, '_ticket_email' ),
					'return' => 'user@example.com',
				)
			);
			WP_Mock::userFunction(
				'swh_get_int_meta',
				array(
					'args'   => array( $id, '_swh_last_activity_at' ),
					'return' => time() - 3600,
				)
			);
		}

		$req = new WP_REST_Request( 'GET', '/swh/v1/tickets' );
		$req->set_param( 'view', 'unassigned' );
		$req->set_param( 'page', 1 );
		$req->set_param( 'per_page', 50 );
		$req->set_param( 'sort', 'last_reply_desc' );
		$req->set_param( 'search', '' );

		$response = $controller->get_items( $req );
		$data     = $response->get_data();

		$this->assertSame( 87, $data['total'] );
		$this->assertCount( 50, $data['tickets'] );
	}

	/**
	 * @param int    $id          Ticket post ID.
	 * @param string $status      Ticket status.
	 * @param string $title       Post title.
	 * @param string $email       Author email.
	 * @param int    $age_seconds Age of the ticket in seconds.
	 */
	private function mock_ticket_payload( int $id, string $status, string $title, string $email, int $age_seconds ): void {
		WP_Mock::userFunction(
			'get_post',
			array(
				'args'   => array( $id ),
				'return' => (object) array(
					'ID'            => $id,
					'post_title'    => $title,
					'post_date_gmt' => gmdate( 'Y-m-d H:i:s', time() - $age_seconds ),
				),
			)
		);
		WP_Mock::userFunction(
			'swh_get_string_meta',
			array(
				'args'   => array( $id, '_ticket_status' ),
				'return' => $status,
			)
		);
		WP_Mock::userFunction(
			'swh_get_string_meta',
			array(
				'args'   => array( $id, '_ticket_priority' ),
				'return' => 'normal',
			)
		);
		WP_Mock::userFunction(
			'swh_get_int_meta',
			array(
				'args'   => array( $id, '_ticket_assigned' ),
				'return' => 0,
			)
		);
		WP_Mock::userFunction(
			'swh_get_string_meta',
			array(
				'args'   => array( $id, '_ticket_email' ),
				'return' => $email,
			)
		);
		WP_Mock::userFunction(
			'swh_get_int_meta',
			array(
				'args'   => array( $id, '_swh_last_activity_at' ),
				'return' => time() - $age_seconds,
			)
		);
	}
}
