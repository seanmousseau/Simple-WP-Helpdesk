<?php
declare(strict_types=1);

namespace SWH\Tests\Unit\Frontend;

use WP_Mock;
use PHPUnit\Framework\TestCase;

final class PortalV2RenderTest extends TestCase {
	protected function setUp(): void {
		WP_Mock::setUp();
		require_once SWH_PLUGIN_DIR . 'includes/helpers.php';
		require_once SWH_PLUGIN_DIR . 'frontend/class-portal.php';
	}
	protected function tearDown(): void {
		WP_Mock::tearDown();
	}

	public function test_render_thread_treats_post_body_as_first_message(): void {
		WP_Mock::userFunction( 'get_post', [
			'args'   => [ 42 ],
			'return' => (object) [
				'ID' => 42,
				'post_content' => 'Initial customer message.',
				'post_date_gmt' => '2026-05-22 10:00:00',
			],
		] );
		WP_Mock::userFunction( 'get_post_meta', [
			'args'   => [ 42, '_ticket_email', true ],
			'return' => 'bob@example.com',
		] );
		WP_Mock::userFunction( 'get_comments', [ 'return' => array() ] );

		$html = swh_portal_render_thread( 42 );

		$this->assertStringContainsString( 'swh-msg-customer', $html );
		$this->assertStringContainsString( 'Initial customer message.', $html );
		$this->assertStringContainsString( 'bob@example.com', $html );
	}

	public function test_render_thread_marks_staff_replies(): void {
		WP_Mock::userFunction( 'get_post', [
			'args'   => [ 42 ],
			'return' => (object) [ 'ID' => 42, 'post_content' => 'Body', 'post_date_gmt' => '2026-05-22 09:00:00' ],
		] );
		WP_Mock::userFunction( 'get_post_meta', [
			'args'   => [ 42, '_ticket_email', true ],
			'return' => 'bob@example.com',
		] );
		WP_Mock::userFunction( 'get_comments', [
			'return' => array(
				(object) [
					'comment_ID' => 101,
					'comment_author' => 'Alice',
					'comment_author_email' => 'alice@example.com',
					'comment_content' => 'Try clearing your cache.',
					'comment_date_gmt' => '2026-05-22 10:30:00',
					'user_id' => 3,
				],
			),
		] );
		WP_Mock::userFunction( 'get_comment_meta', [ 'return' => '' ] );

		$html = swh_portal_render_thread( 42 );

		$this->assertStringContainsString( 'swh-msg-staff', $html );
		$this->assertStringContainsString( 'Try clearing your cache.', $html );
		$this->assertStringContainsString( 'Alice', $html );
	}

	public function test_render_thread_skips_internal_notes(): void {
		WP_Mock::userFunction( 'get_post', [
			'args'   => [ 42 ],
			'return' => (object) [ 'ID' => 42, 'post_content' => 'Body', 'post_date_gmt' => '2026-05-22 09:00:00' ],
		] );
		WP_Mock::userFunction( 'get_post_meta', [
			'args'   => [ 42, '_ticket_email', true ],
			'return' => 'bob@example.com',
		] );
		WP_Mock::userFunction( 'get_comments', [
			'return' => array(
				(object) [
					'comment_ID' => 202,
					'comment_author' => 'Alice',
					'comment_author_email' => 'alice@example.com',
					'comment_content' => 'INTERNAL — do not show to customer.',
					'comment_date_gmt' => '2026-05-22 10:30:00',
					'user_id' => 3,
				],
			),
		] );
		WP_Mock::userFunction( 'get_comment_meta', [
			'args'   => [ 202, '_is_internal_note', true ],
			'return' => '1',
		] );

		$html = swh_portal_render_thread( 42 );

		$this->assertStringNotContainsString( 'INTERNAL', $html );
	}

	public function test_render_thread_returns_empty_string_for_missing_ticket(): void {
		WP_Mock::userFunction( 'get_post', [
			'args'   => [ 999 ],
			'return' => null,
		] );

		$this->assertSame( '', swh_portal_render_thread( 999 ) );
	}
}
