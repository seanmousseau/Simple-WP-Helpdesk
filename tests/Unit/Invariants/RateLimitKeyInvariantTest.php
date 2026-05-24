<?php
/**
 * Invariant: portal actions rate-limit on PER-ACTION-PER-TICKET keys.
 *
 * Keys must follow the shape `portal_<action>_<ticket_id>` where <action> is
 * one of `close`, `reopen`, or `reply`. Using a shared key (e.g. just
 * `portal_<ticket_id>`) would let one action's lock block the other two —
 * specifically, the v3.x bug where closing a ticket prevented the client
 * from immediately reopening it.
 *
 * @see docs/internal/design-document.md — invariant table, rate-limit keys
 * @package Simple_WP_Helpdesk
 */

namespace SWH\Tests\Unit\Invariants;

class RateLimitKeyInvariantTest extends SourceScanTestCase {

	/**
	 * Every portal swh_is_rate_limited() call uses a per-action-per-ticket key.
	 *
	 * Allow-list locks the three documented call sites. A new portal action
	 * (or a refactor that consolidates the keys) must update this list and
	 * explain why in the commit message — otherwise the lockout-isolation
	 * guarantee silently degrades.
	 */
	public function test_portal_rate_limit_keys_are_per_action_per_ticket(): void {
		// Match swh_is_rate_limited( 'portal_<word>_' . $ticket_id, <ttl> ).
		// The trailing identifier ($ticket_id / $post_id / etc.) is intentionally
		// permissive — what matters is the literal action prefix and the
		// concatenation with a ticket-scoped identifier.
		$pattern = '/swh_is_rate_limited\s*\(\s*[\'"]portal_(close|reopen|reply)_[\'"]\s*\.\s*\$\w+/';

		// Allow-list:
		//   class-portal.php:520 — portal reply
		//   class-portal.php:643 — portal close
		//   class-portal.php:727 — portal reopen
		$allow = array(
			'frontend/class-portal.php:520',
			'frontend/class-portal.php:643',
			'frontend/class-portal.php:727',
		);

		$this->assertSourceMatchesAllowList(
			$allow,
			$pattern,
			'Portal rate-limit keys must be `portal_<action>_<ticket_id>` (action ∈ {close, reopen, reply}). '
				. 'Sharing a key across actions caused the v3.x bug where closing a ticket blocked the '
				. 'immediate-reopen flow. Adding a new portal action? Add its call site here AND ensure '
				. 'the key includes the action verb.'
		);
	}

	/**
	 * No portal action uses a shared (action-less) rate-limit key.
	 *
	 * Backstop: catches a regression where someone refactors to a "single key
	 * per ticket" shape without changing the documented call sites.
	 */
	public function test_no_shared_portal_rate_limit_key(): void {
		// Disallowed shapes: `portal_<ticket_id>`, `portal_action_<ticket_id>`, etc.
		// We flag any `swh_is_rate_limited( 'portal_' . $... )` that doesn't have
		// one of the three permitted verbs after `portal_`.
		$bad_pattern = '/swh_is_rate_limited\s*\(\s*[\'"]portal_[\'"]\s*\.\s*\$/';
		$matches = $this->grep_plugin_source( $bad_pattern );
		$this->assertSame(
			array(),
			$matches,
			"Found rate-limit call with an action-less portal key — must include the action verb:\n  "
				. implode( "\n  ", $matches )
		);
	}
}
