<?php
/**
 * Invariant: every swh_send_email() call site uses a matched subject/body
 * template pair from the documented allow-list.
 *
 * The 16-pair email template set is the public surface that operators
 * customise in Settings → Email. A typo or copy-paste error that swaps a
 * `_sub` key with the wrong `_body` (e.g. `em_user_reply_sub` paired with
 * `em_admin_reply_body`) silently sends an email with the right subject
 * line but the wrong body — and a real customer notification with the
 * wrong content. Several v3.x bugs (#218, #290) landed here.
 *
 * This source-scan invariant pins:
 *   1. Subject/body keys at every call site are a matched pair (the part
 *      between `swh_em_` and `_sub`/`_body` must be identical).
 *   2. Both keys are members of the documented template set.
 *
 * Approach is source-scanning rather than a runtime fixture per template
 * because (a) we have 30 call sites across 9 files; (b) wp_mail is
 * already covered by EmailTest; (c) the regression we care about is at
 * the call-site shape, not the dispatch pipeline.
 *
 * @see docs/internal/test-coverage-matrix.md — gap #20
 * @see docs/internal/api-contract.md — Settings → Email template pairs
 * @package Simple_WP_Helpdesk
 */

namespace SWH\Tests\Unit\Invariants;

class EmailTriggerInvariantTest extends SourceScanTestCase {

	/**
	 * The 16 documented template pairs. Keys here are the "stem" between
	 * `swh_em_` and the `_sub`/`_body` suffix.
	 *
	 * Removing a template is a MAJOR-version break (operators have settings
	 * bound to these keys). Adding one requires a new entry here AND in
	 * `swh_get_defaults()`.
	 *
	 * @return string[]
	 */
	private function documented_template_stems(): array {
		return array(
			// User-facing (sent to ticket requester).
			'user_new',
			'user_reply',
			'user_status',
			'user_reply_status',
			'user_resolved',
			'user_reopen',
			'user_autoclose',
			'user_closed',
			'user_lookup',
			'user_merged',
			// Admin-facing (sent to assignee / fallback / SLA notify).
			'admin_new',
			'admin_reply',
			'admin_reopen',
			'admin_closed',
			'admin_sla_breach',
			// Cross-role (sent to the freshly-assigned user).
			'assigned',
		);
	}

	/**
	 * Extracts every (subject_key, body_key, file:line) triple from the
	 * plugin source, parsing swh_send_email( $to, 'sub_key', 'body_key', ... )
	 * call sites. Returns each call site once.
	 *
	 * @return array<int, array{string, string, string}>
	 */
	private function extract_call_sites(): array {
		$out = array();
		// Match the 2nd and 3rd string-literal args of a swh_send_email( ... ) CALL.
		// The negative lookbehind on `function\s` excludes the swh_send_email()
		// function declaration itself, where the args are parameter names
		// ($subject_key, $body_key) rather than literals. The `s` flag makes `.`
		// span newlines so multiline call sites — `swh_send_email(\n  $to,\n  'sub',\n  'body', ...)` —
		// are captured the same as single-line ones. `[^,]` is replaced with
		// `[^,)]` per-arg so the first arg can span lines without swallowing
		// commas that belong to subsequent args.
		$pattern = '/(?<!function\s)swh_send_email\s*\(\s*[^,)]+?,\s*[\'"]([a-z_]+)[\'"]\s*,\s*[\'"]([a-z_]+)[\'"]/s';

		foreach ( $this->plugin_php_files() as $file ) {
			$contents = file_get_contents( $file );
			if ( false === $contents ) {
				continue;
			}
			if ( ! preg_match_all( $pattern, $contents, $matches, PREG_OFFSET_CAPTURE ) ) {
				continue;
			}
			$rel = ltrim( str_replace( $this->plugin_root(), '', $file ), '/' );
			foreach ( $matches[0] as $i => $whole ) {
				$offset  = (int) $whole[1];
				$sub_key  = (string) $matches[1][ $i ][0];
				$body_key = (string) $matches[2][ $i ][0];

				// Determine the source line by counting newlines before the offset.
				// substr_count( $contents, "\n", 0, $offset ) gives a 0-based count;
				// add 1 for 1-based line numbering.
				$lineno = substr_count( $contents, "\n", 0, $offset ) + 1;

				$out[] = array( $sub_key, $body_key, $rel . ':' . $lineno );
			}
		}
		return $out;
	}

	public function test_subject_and_body_keys_at_each_call_site_are_a_matched_pair(): void {
		$mismatches = array();
		foreach ( $this->extract_call_sites() as $site ) {
			list( $sub_key, $body_key, $loc ) = $site;

			$sub_stem  = preg_replace( '/^swh_em_(.*)_sub$/', '$1', $sub_key );
			$body_stem = preg_replace( '/^swh_em_(.*)_body$/', '$1', $body_key );

			$malformed_sub  = $sub_stem === $sub_key;   // didn't match the prefix/suffix
			$malformed_body = $body_stem === $body_key;
			$pair_mismatch  = $sub_stem !== $body_stem;

			if ( $malformed_sub || $malformed_body || $pair_mismatch ) {
				$mismatches[] = "$loc — sub='$sub_key' body='$body_key' (stems: '$sub_stem' vs '$body_stem')";
			}
		}
		$this->assertSame(
			array(),
			$mismatches,
			"swh_send_email() call site uses mismatched or malformed template keys. Subject and body "
				. "must share the same stem (`swh_em_<stem>_sub` paired with `swh_em_<stem>_body`):\n  "
				. implode( "\n  ", $mismatches )
		);
	}

	public function test_every_used_template_stem_is_documented(): void {
		$stems = array_flip( $this->documented_template_stems() );
		$bad   = array();
		foreach ( $this->extract_call_sites() as $site ) {
			list( $sub_key, , $loc ) = $site;
			$stem = preg_replace( '/^swh_em_(.*)_sub$/', '$1', $sub_key );
			if ( ! isset( $stems[ $stem ] ) ) {
				$bad[] = "$loc — uses stem '$stem' which is not on the documented allow-list";
			}
		}
		$this->assertSame(
			array(),
			$bad,
			"swh_send_email() call site uses an undocumented template stem. Add the stem to "
				. "documented_template_stems() AND seed defaults for swh_em_<stem>_sub / _body "
				. "in swh_get_defaults():\n  "
				. implode( "\n  ", $bad )
		);
	}

	public function test_every_documented_stem_has_at_least_one_call_site(): void {
		// Reverse check: if a stem is documented but never sent from, either
		// the trigger was removed (update the allow-list) or someone forgot
		// to wire the new template into a trigger. Catches both directions.
		$used = array();
		foreach ( $this->extract_call_sites() as $site ) {
			list( $sub_key, , ) = $site;
			$stem               = preg_replace( '/^swh_em_(.*)_sub$/', '$1', $sub_key );
			$used[ $stem ]      = true;
		}
		$unused = array_diff( $this->documented_template_stems(), array_keys( $used ) );
		$this->assertSame(
			array(),
			array_values( $unused ),
			"Documented template stem(s) have no swh_send_email() call site. Either remove from the "
				. "allow-list (and from Settings → Email) or wire the trigger:\n  "
				. implode( "\n  ", $unused )
		);
	}

	public function test_no_dynamic_template_keys(): void {
		// Catches `swh_send_email( $to, $sub_key, $body_key, ... )` where the
		// key is a variable rather than a literal. Dynamic keys defeat the
		// static-extraction invariants above — flag any new instance for review.
		// Multiline-aware: same `s` flag pattern as extract_call_sites().
		$pattern = '/(?<!function\s)swh_send_email\s*\(\s*[^,)]+?,\s*\$/s';
		$findings = array();
		foreach ( $this->plugin_php_files() as $file ) {
			$rel = ltrim( str_replace( $this->plugin_root(), '', $file ), '/' );
			// The facade in src/Email/Mailer.php deliberately uses variable
			// arguments — it's a thin pass-through to swh_send_email.
			if ( 'src/Email/Mailer.php' === $rel ) {
				continue;
			}
			$contents = file_get_contents( $file );
			if ( false === $contents ) {
				continue;
			}
			if ( ! preg_match_all( $pattern, $contents, $matches, PREG_OFFSET_CAPTURE ) ) {
				continue;
			}
			foreach ( $matches[0] as $whole ) {
				$offset    = (int) $whole[1];
				$lineno    = substr_count( $contents, "\n", 0, $offset ) + 1;
				$findings[] = $rel . ':' . $lineno;
			}
		}
		$this->assertSame(
			array(),
			$findings,
			"swh_send_email() called with a dynamic (variable) subject/body key. Either inline the "
				. "literal so this invariant can verify it, or — if dynamic dispatch is truly required "
				. "— add the call site to the allow-list in this test and explain:\n  "
				. implode( "\n  ", $findings )
		);
	}
}
