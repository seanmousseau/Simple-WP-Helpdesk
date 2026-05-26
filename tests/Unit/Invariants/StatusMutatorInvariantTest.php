<?php
/**
 * Invariant: direct writes to the _ticket_status meta key are not allowed outside
 * an explicit allow-list. All other status transitions must go through
 * swh_set_ticket_status() which fires the lifecycle hooks and bumps the row version.
 *
 * @see docs/internal/design-document.md — invariant table, status mutator
 * @package Simple_WP_Helpdesk
 */

namespace SWH\Tests\Unit\Invariants;

class StatusMutatorInvariantTest extends SourceScanTestCase {

	/**
	 * Every direct write to _ticket_status meta is on the allow-list.
	 *
	 * Adding a new call site is a deliberate decision: it skips the lifecycle
	 * hooks (swh_ticket_status_changed, swh_ticket_closed, swh_ticket_reopened),
	 * the email dispatch, the SLA recalculation, and the optimistic-locking
	 * version bump. The only legitimate bypass is initial ticket creation,
	 * where there is no prior status to transition from.
	 *
	 * If this test fails with an unexpected match: refactor the new call to use
	 * swh_set_ticket_status() instead. If you genuinely need a new bypass,
	 * update this allow-list and explain why in the commit message.
	 */
	public function test_status_meta_writes_are_allow_listed(): void {
		$pattern = '/(update_post_meta|add_post_meta)\s*\([^,]+,\s*[\'"]_ticket_status[\'"]/';
		// Allow-list:
		//   frontend/class-shortcode.php:239 — initial ticket creation (no prior status).
		//   includes/helpers.php:287        — body of swh_set_ticket_status() itself.
		// Line shifted from 281 → 287 in v4.0.3 (#471) when 6 CSAT default keys were
		// added to swh_get_defaults() above it; swh_set_ticket_status() body unchanged.
		$allow = array(
			'frontend/class-shortcode.php:239',
			'includes/helpers.php:287',
		);
		$this->assertSourceMatchesAllowList(
			$allow,
			$pattern,
			'Direct _ticket_status meta writes must go through swh_set_ticket_status() (see design-document.md). '
				. 'Initial-create at shortcode.php:239 is the only allowed bypass.'
		);
	}
}
