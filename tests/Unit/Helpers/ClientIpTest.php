<?php
/**
 * Unit tests for swh_get_client_ip() + swh_select_xff_hop() + swh_ip_in_list().
 *
 * v4.1.1 (#534): trusted-proxy chain support. When SWH_TRUSTED_PROXIES is
 * defined, walk X-Forwarded-For right-to-left and return the first
 * untrusted hop. When the constant is undefined, preserve v4.1.0 leftmost
 * behavior (so existing deployments don't change).
 *
 * @package Simple_WP_Helpdesk
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class ClientIpTest extends TestCase {

	protected function setUp(): void {
		require_once SWH_PLUGIN_DIR . 'includes/helpers.php';
	}

	protected function tearDown(): void {
		$_SERVER = array();
	}

	// -----------------------------------------------------------------------
	// swh_ip_in_list — primitive
	// -----------------------------------------------------------------------

	public function test_ip_in_list_exact_ipv4_match(): void {
		$this->assertTrue( swh_ip_in_list( '10.0.0.1', array( '10.0.0.1' ) ) );
		$this->assertFalse( swh_ip_in_list( '10.0.0.2', array( '10.0.0.1' ) ) );
	}

	public function test_ip_in_list_ipv4_cidr_match(): void {
		$this->assertTrue( swh_ip_in_list( '10.5.6.7', array( '10.0.0.0/8' ) ) );
		$this->assertTrue( swh_ip_in_list( '192.168.1.50', array( '192.168.0.0/16' ) ) );
		$this->assertFalse( swh_ip_in_list( '11.5.6.7', array( '10.0.0.0/8' ) ) );
	}

	public function test_ip_in_list_ipv6_cidr_match(): void {
		$this->assertTrue( swh_ip_in_list( '2001:db8::1', array( '2001:db8::/32' ) ) );
		$this->assertFalse( swh_ip_in_list( '2001:db9::1', array( '2001:db8::/32' ) ) );
	}

	public function test_ip_in_list_rejects_invalid_ip(): void {
		$this->assertFalse( swh_ip_in_list( '', array( '10.0.0.0/8' ) ) );
		$this->assertFalse( swh_ip_in_list( 'not-an-ip', array( '10.0.0.0/8' ) ) );
	}

	// -----------------------------------------------------------------------
	// swh_select_xff_hop — the rightmost-pop algorithm
	// -----------------------------------------------------------------------

	public function test_xff_hop_single_proxy_pops_to_client(): void {
		// Client → proxy. XFF=[client], REMOTE_ADDR=proxy (trusted).
		// Even with a single XFF entry, the result is the client IP.
		$this->assertSame( '203.0.113.5', swh_select_xff_hop( array( '203.0.113.5' ), '10.0.0.0/8' ) );
	}

	public function test_xff_hop_multi_proxy_chain_pops_trusted_rightmost(): void {
		// Spoofed leftmost (1.2.3.4) + real client (203.0.113.5) + 2 trusted hops.
		// Rightmost-pop returns the first untrusted hop walking right-to-left:
		// 10.0.0.2 (trusted, pop), 10.0.0.1 (trusted, pop), 203.0.113.5 (untrusted, return).
		$ips = array( '1.2.3.4', '203.0.113.5', '10.0.0.1', '10.0.0.2' );
		$this->assertSame( '203.0.113.5', swh_select_xff_hop( $ips, '10.0.0.0/8' ) );
	}

	public function test_xff_hop_spoofed_leftmost_does_not_win(): void {
		// Attacker injects XFF: 6.6.6.6 from the public side; the real proxy
		// then appends the actual client+proxy. Rightmost-pop returns the
		// untrusted hop adjacent to the proxy, NOT the attacker's value.
		$ips = array( '6.6.6.6', '203.0.113.5', '10.0.0.1' );
		$this->assertSame( '203.0.113.5', swh_select_xff_hop( $ips, '10.0.0.0/8' ) );
	}

	public function test_xff_hop_returns_empty_when_every_entry_trusted(): void {
		$ips = array( '10.0.0.1', '10.0.0.2' );
		$this->assertSame( '', swh_select_xff_hop( $ips, '10.0.0.0/8' ) );
	}

	public function test_xff_hop_empty_trusted_returns_empty(): void {
		$this->assertSame( '', swh_select_xff_hop( array( '1.2.3.4' ), '' ) );
	}

	// -----------------------------------------------------------------------
	// swh_get_client_ip — end-to-end
	// -----------------------------------------------------------------------

	public function test_client_ip_falls_back_to_remote_addr_without_trust_proxy(): void {
		$_SERVER['REMOTE_ADDR']          = '203.0.113.42';
		$_SERVER['HTTP_X_FORWARDED_FOR'] = '6.6.6.6';
		// SWH_TRUST_PROXY undefined → header ignored.
		$this->assertSame( '203.0.113.42', swh_get_client_ip() );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_client_ip_leftmost_xff_when_trust_proxy_only(): void {
		define( 'SWH_TRUST_PROXY', true );
		$_SERVER['HTTP_X_FORWARDED_FOR'] = '203.0.113.5, 10.0.0.1';
		// SWH_TRUSTED_PROXIES undefined → preserve v4.1.0 leftmost behavior.
		$this->assertSame( '203.0.113.5', swh_get_client_ip() );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_client_ip_rightmost_pop_when_trusted_proxies_defined(): void {
		define( 'SWH_TRUST_PROXY', true );
		define( 'SWH_TRUSTED_PROXIES', '10.0.0.0/8' );
		$_SERVER['HTTP_X_FORWARDED_FOR'] = '6.6.6.6, 203.0.113.5, 10.0.0.1';
		// Spoofed leftmost is ignored; rightmost-pop returns 203.0.113.5.
		$this->assertSame( '203.0.113.5', swh_get_client_ip() );
	}
}
