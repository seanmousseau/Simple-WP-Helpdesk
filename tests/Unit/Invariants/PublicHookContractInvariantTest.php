<?php
/**
 * Invariant: every public action and filter listed in api-contract.md is
 * actually emitted (or applied) somewhere in the plugin source, with a
 * single canonical do_action()/apply_filters() call site.
 *
 * Removing a documented hook is a MAJOR-version breaking change (see the
 * versioning policy in api-contract.md). This test fails if the source no
 * longer fires a documented hook — forcing the change to be a conscious
 * decision rather than an accidental deletion.
 *
 * Argument-count drift is covered by LifecycleActionsTest for the
 * lifecycle hooks; this test focuses on presence.
 *
 * @see docs/internal/test-coverage-matrix.md — gap #25
 * @see docs/internal/api-contract.md — Action hooks / Filter hooks
 * @package Simple_WP_Helpdesk
 */

namespace SWH\Tests\Unit\Invariants;

class PublicHookContractInvariantTest extends SourceScanTestCase {

	/**
	 * Documented action hooks. Removing one is a MAJOR-version break.
	 *
	 * @return string[]
	 */
	private function documented_actions(): array {
		return array(
			'swh_pre_ticket_create',
			'swh_ticket_created',
			'swh_ticket_status_changed',
			'swh_ticket_closed',
			'swh_ticket_reopened',
			'swh_ticket_replied',
			'swh_ticket_assigned',
			'swh_csat_submitted',
			'swh_sla_breached',
		);
	}

	/**
	 * Documented filter hooks. Deprecated filters are still on the public
	 * surface until v4.2 — see api-contract.md filter table.
	 *
	 * @return string[]
	 */
	private function documented_filters(): array {
		return array(
			// Active (non-deprecated):
			'swh_sla_open_statuses',
			// Deprecated through 4.2, still on the public surface:
			'swh_ticket_statuses',
			'swh_ticket_priorities',
			'swh_allowed_file_types',
			'swh_submission_data',
			'swh_autoclose_threshold',
			'swh_rate_limit_ttl',
			'swh_parse_template',
			'swh_email_headers',
		);
	}

	public function test_every_documented_action_is_emitted_somewhere(): void {
		$missing = array();
		foreach ( $this->documented_actions() as $hook ) {
			$pattern = '/do_action\s*\(\s*[\'"]' . preg_quote( $hook, '/' ) . '[\'"]/';
			$matches = $this->grep_plugin_source( $pattern );
			if ( empty( $matches ) ) {
				$missing[] = $hook;
			}
		}
		$this->assertSame(
			array(),
			$missing,
			"Documented action hook(s) not emitted in plugin source:\n  "
				. implode( "\n  ", $missing )
				. "\n\nRemoving a documented hook is a MAJOR-version break. If this is intentional, update "
				. 'docs/internal/api-contract.md, add the removal to docs/developer/deprecations.md, and '
				. 'remove the hook from this test.'
		);
	}

	public function test_every_documented_filter_is_applied_somewhere(): void {
		// Plugin code formats `swh_apply_deprecated_filter(` calls across multiple
		// lines (hook name on the line after the function-call opener), so a
		// per-line grep misses them. Scan each file's full contents instead.
		$sources = array();
		foreach ( $this->plugin_php_files() as $file ) {
			$sources[ $file ] = (string) file_get_contents( $file );
		}

		$missing = array();
		foreach ( $this->documented_filters() as $hook ) {
			$quoted = preg_quote( $hook, '/' );
			// Match either apply_filters(...) OR swh_apply_deprecated_filter(...)
			// with the hook name as the first string-literal argument. Multi-line.
			$pattern = '/(apply_filters|swh_apply_deprecated_filter)\s*\(\s*[\'"]' . $quoted . '[\'"]/s';
			$found   = false;
			foreach ( $sources as $contents ) {
				if ( preg_match( $pattern, $contents ) ) {
					$found = true;
					break;
				}
			}
			if ( ! $found ) {
				$missing[] = $hook;
			}
		}
		$this->assertSame(
			array(),
			$missing,
			"Documented filter hook(s) not applied in plugin source:\n  "
				. implode( "\n  ", $missing )
				. "\n\nIf a deprecated filter has finished its sunset window and been removed, also "
				. 'remove it from this allow-list AND from docs/internal/api-contract.md.'
		);
	}

	public function test_no_typoed_lifecycle_actions(): void {
		// Common typo guards. The lifecycle hooks have been the source of
		// past bugs where a `do_action` typo silently no-ops (`swh_ticket_replyed`,
		// `swh_ticket_assignment`). Flag those proactively.
		$typos = array(
			'swh_ticket_replyed',
			'swh_ticket_replies',
			'swh_ticket_assignment',
			'swh_ticket_close',     // should be _closed
			'swh_ticket_reopen',    // should be _reopened
			'swh_csat_submit',      // should be _submitted
			'swh_status_changed',   // missing _ticket_
		);
		$found = array();
		foreach ( $typos as $typo ) {
			$pattern = '/do_action\s*\(\s*[\'"]' . preg_quote( $typo, '/' ) . '[\'"]/';
			$matches = $this->grep_plugin_source( $pattern );
			if ( ! empty( $matches ) ) {
				$found = array_merge( $found, array_map( fn( $loc ) => "$loc — uses $typo", $matches ) );
			}
		}
		$this->assertSame(
			array(),
			$found,
			"Found do_action() call(s) using a near-miss hook name. Did you mean one of "
				. "the canonical lifecycle hooks?\n  "
				. implode( "\n  ", $found )
		);
	}
}
