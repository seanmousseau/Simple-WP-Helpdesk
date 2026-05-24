<?php
/**
 * Stub for WordPress's absint() — used by handlers under test in CsatAjaxTest.
 * Not registered in tests/bootstrap.php because that file is shared with WP-Mock
 * suites that may register their own stubs; keep this scoped per-file.
 *
 * @package Simple_WP_Helpdesk
 */

if ( ! function_exists( 'absint' ) ) {
	/**
	 * @param mixed $maybeint
	 */
	function absint( $maybeint ): int {
		return abs( (int) $maybeint );
	}
}
