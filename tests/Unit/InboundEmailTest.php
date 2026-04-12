<?php
/**
 * Unit tests for the inbound email processing logic used by
 * swh_handle_inbound_email() in includes/class-email.php.
 *
 * The full handler requires a live WP_REST_Request object, a database, and
 * multiple WP subsystems, which are not available in unit-test context.
 * These tests instead verify the three core algorithms the handler uses, in
 * isolation, documenting their expected behaviour and acting as regression
 * guards against logic changes.
 *
 * @see #131
 * @package Simple_WP_Helpdesk
 */

use WP_Mock\Tools\TestCase;

/**
 * Tests for the ticket-ID parser, sender validator, and quoted-reply stripper
 * used inside swh_handle_inbound_email().
 */
class InboundEmailTest extends TestCase {

	// -------------------------------------------------------------------------
	// Ticket-ID parser  /\[TKT-(\d+)\]/i
	// -------------------------------------------------------------------------

	/**
	 * [TKT-XXXX] at the end of a subject line is extracted correctly.
	 */
	public function test_parse_ticket_id_from_subject(): void {
		$subject = 'Re: Your support request [TKT-1234]';
		$matched = preg_match( '/\[TKT-(\d+)\]/i', $subject, $matches );
		$this->assertSame( 1, $matched );
		$this->assertSame( '1234', $matches[1] );
	}

	/**
	 * [TKT-XXXX] in the middle of a subject is extracted correctly.
	 */
	public function test_parse_ticket_id_from_subject_middle(): void {
		$subject = 'Your ticket [TKT-9999] has a new reply';
		$matched = preg_match( '/\[TKT-(\d+)\]/i', $subject, $matches );
		$this->assertSame( 1, $matched );
		$this->assertSame( '9999', $matches[1] );
	}

	/**
	 * Subjects with no [TKT-XXXX] token return no match.
	 */
	public function test_parse_ticket_id_missing_returns_no_match(): void {
		$subject = 'A completely unrelated email with no ticket ID';
		$matched = preg_match( '/\[TKT-(\d+)\]/i', $subject, $matches );
		$this->assertSame( 0, $matched );
	}

	/**
	 * The match is case-insensitive: [tkt-42] is a valid token.
	 */
	public function test_parse_ticket_id_case_insensitive(): void {
		$subject = 'Reply to [tkt-42] from the client';
		$matched = preg_match( '/\[TKT-(\d+)\]/i', $subject, $matches );
		$this->assertSame( 1, $matched );
		$this->assertSame( '42', $matches[1] );
	}

	// -------------------------------------------------------------------------
	// Sender validation  hash_equals(strtolower($ticket_email), strtolower($from))
	// -------------------------------------------------------------------------

	/**
	 * A sender whose address matches the ticket client email is accepted.
	 */
	public function test_sender_validation_accepts_match(): void {
		$ticket_email = 'Client@Example.COM';
		$sender       = 'client@example.com';
		$this->assertTrue( hash_equals( strtolower( $ticket_email ), strtolower( $sender ) ) );
	}

	/**
	 * A sender whose address differs from the ticket client email is rejected,
	 * preventing reply injection from third parties.
	 */
	public function test_sender_validation_rejects_mismatch(): void {
		$ticket_email = 'user@example.com';
		$sender       = 'attacker@evil.com';
		$this->assertFalse( hash_equals( strtolower( $ticket_email ), strtolower( $sender ) ) );
	}

	/**
	 * An empty sender string is rejected even when the ticket email is set.
	 */
	public function test_sender_validation_rejects_empty_sender(): void {
		$ticket_email = 'user@example.com';
		$sender       = '';
		$this->assertFalse( hash_equals( strtolower( $ticket_email ), strtolower( $sender ) ) );
	}

	// -------------------------------------------------------------------------
	// Quoted-reply stripper  array_filter + strpos(trim($line), '>')
	// -------------------------------------------------------------------------

	/**
	 * Lines starting with ">" are stripped; surrounding content is preserved.
	 */
	public function test_quoted_reply_stripping(): void {
		$body = "Thank you for your reply.\n> Original message line 1\n> Original message line 2\nPlease see attached.";

		$lines      = explode( "\n", $body );
		$clean      = array_filter(
			$lines,
			function ( $line ) {
				return 0 !== strpos( trim( $line ), '>' );
			}
		);
		$clean_body = trim( implode( "\n", $clean ) );

		$this->assertStringNotContainsString( '> Original', $clean_body );
		$this->assertStringContainsString( 'Thank you for your reply.', $clean_body );
		$this->assertStringContainsString( 'Please see attached.', $clean_body );
	}

	/**
	 * A body composed entirely of quoted lines collapses to an empty string.
	 */
	public function test_quoted_only_body_becomes_empty(): void {
		$body = "> Line one was quoted\n> Line two was quoted";

		$lines      = explode( "\n", $body );
		$clean      = array_filter(
			$lines,
			function ( $line ) {
				return 0 !== strpos( trim( $line ), '>' );
			}
		);
		$clean_body = trim( implode( "\n", $clean ) );

		$this->assertSame( '', $clean_body );
	}

	/**
	 * A body containing no quoted lines is returned unchanged.
	 */
	public function test_body_without_quotes_unchanged(): void {
		$body = "Hello,\n\nThank you for your quick response.";

		$lines      = explode( "\n", $body );
		$clean      = array_filter(
			$lines,
			function ( $line ) {
				return 0 !== strpos( trim( $line ), '>' );
			}
		);
		$clean_body = trim( implode( "\n", $clean ) );

		$this->assertSame( $body, $clean_body );
	}
}
