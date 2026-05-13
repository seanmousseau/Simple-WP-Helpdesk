<?php
/**
 * Unit tests for the SWH\Email\Mailer PSR-4 proof of concept (issue #394).
 *
 * Verifies the class is loadable via the PSR-4 autoloader configured in
 * simple-wp-helpdesk/composer.json (mapped at the repo root for tests) and
 * exposes the documented send() signature.
 *
 * @package Simple_WP_Helpdesk
 */

use PHPUnit\Framework\TestCase;
use SWH\Email\Mailer;

/**
 * Tests the PSR-4 autoloaded Mailer facade.
 */
final class MailerTest extends TestCase {

	/**
	 * The SWH\Email\Mailer class loads through the PSR-4 autoloader.
	 *
	 * @return void
	 */
	public function testMailerClassIsAutoloadable() {
		$this->assertTrue( class_exists( Mailer::class ), 'SWH\\Email\\Mailer must be autoloadable via composer PSR-4.' );
	}

	/**
	 * The send() method matches the documented signature (mirrors swh_send_email()).
	 *
	 * @return void
	 */
	public function testSendMethodSignatureMatchesSwhSendEmail() {
		$reflection = new ReflectionMethod( Mailer::class, 'send' );
		$params     = $reflection->getParameters();

		$this->assertCount( 6, $params, 'Mailer::send() should accept 6 parameters (to, subject_key, body_key, data, attachments, ticket_id).' );
		$this->assertSame( 'to', $params[0]->getName() );
		$this->assertSame( 'subject_key', $params[1]->getName() );
		$this->assertSame( 'body_key', $params[2]->getName() );
		$this->assertSame( 'data', $params[3]->getName() );
		$this->assertSame( 'attachments', $params[4]->getName() );
		$this->assertSame( 'ticket_id', $params[5]->getName() );

		$this->assertTrue( $params[4]->isOptional(), '$attachments must be optional.' );
		$this->assertTrue( $params[5]->isOptional(), '$ticket_id must be optional.' );
	}

	/**
	 * The class is declared final to discourage subclassing of the PoC facade.
	 *
	 * @return void
	 */
	public function testMailerClassIsFinal() {
		$reflection = new ReflectionClass( Mailer::class );
		$this->assertTrue( $reflection->isFinal(), 'SWH\\Email\\Mailer should be final.' );
	}
}
