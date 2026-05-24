<?php
/**
 * Base class for invariant tests that scan plugin source code.
 *
 * @package Simple_WP_Helpdesk
 */

namespace SWH\Tests\Unit\Invariants;

use PHPUnit\Framework\TestCase;

/**
 * Provides helpers for source-pattern invariants.
 *
 * Invariant tests verify that load-bearing rules from design-document.md remain
 * true by scanning the plugin source. They cost O(file count) and run in the
 * standard PHPUnit suite — no WordPress bootstrap required.
 */
abstract class SourceScanTestCase extends TestCase {

	/**
	 * Returns the absolute path to the plugin source root.
	 */
	protected function plugin_root(): string {
		return dirname( __DIR__, 3 ) . '/simple-wp-helpdesk';
	}

	/**
	 * Returns every PHP file under the plugin source root, excluding vendor.
	 *
	 * @return string[] List of absolute paths.
	 */
	protected function plugin_php_files(): array {
		$root  = $this->plugin_root();
		$files = array();
		$iter  = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $root, \FilesystemIterator::SKIP_DOTS )
		);
		foreach ( $iter as $entry ) {
			$path = $entry->getPathname();
			if ( str_contains( $path, '/vendor/' ) ) {
				continue;
			}
			if ( ! str_ends_with( $path, '.php' ) ) {
				continue;
			}
			$files[] = $path;
		}
		sort( $files );
		return $files;
	}

	/**
	 * Greps all plugin source files for $pattern and returns matches as "relative/path:line".
	 *
	 * The regex receives one line of source at a time, so per-line patterns are sufficient.
	 * Comment lines (// and lines starting with * inside /* blocks) are stripped before matching.
	 *
	 * @param string $pattern PCRE pattern (with delimiters).
	 * @return string[] Match locations as "relative/path:lineno".
	 */
	protected function grep_plugin_source( string $pattern ): array {
		$root    = $this->plugin_root();
		$matches = array();
		foreach ( $this->plugin_php_files() as $file ) {
			$lines = file( $file, FILE_IGNORE_NEW_LINES );
			if ( false === $lines ) {
				continue;
			}
			foreach ( $lines as $lineno => $line ) {
				if ( $this->is_comment_line( $line ) ) {
					continue;
				}
				if ( preg_match( $pattern, $line ) ) {
					$rel       = ltrim( str_replace( $root, '', $file ), '/' );
					$matches[] = $rel . ':' . ( $lineno + 1 );
				}
			}
		}
		sort( $matches );
		return $matches;
	}

	/**
	 * Returns true if the line is wholly a comment (// ..., * ..., or /* ...).
	 *
	 * Block-comment middle/end lines and single-line comments are excluded.
	 * Inline trailing comments (`code(); // note`) are NOT excluded — the code
	 * portion is still scanned.
	 */
	private function is_comment_line( string $line ): bool {
		$trimmed = ltrim( $line );
		if ( '' === $trimmed ) {
			return true;
		}
		if ( str_starts_with( $trimmed, '//' ) ) {
			return true;
		}
		if ( str_starts_with( $trimmed, '#' ) && ! str_starts_with( $trimmed, '#[' ) ) {
			return true;
		}
		if ( str_starts_with( $trimmed, '*' ) ) {
			return true;
		}
		if ( str_starts_with( $trimmed, '/*' ) ) {
			return true;
		}
		return false;
	}

	/**
	 * Reads a single source file from the plugin root.
	 *
	 * @param string $relative_path Path relative to simple-wp-helpdesk/.
	 */
	protected function read_plugin_file( string $relative_path ): string {
		$abs = $this->plugin_root() . '/' . ltrim( $relative_path, '/' );
		$out = file_get_contents( $abs );
		$this->assertNotFalse( $out, "Could not read $relative_path" );
		return (string) $out;
	}

	/**
	 * Asserts that grepping the plugin source yields exactly $expected matches.
	 *
	 * Failure message lists both unexpected and missing matches so violations
	 * are immediately actionable.
	 *
	 * @param string[] $expected Allow-listed "relative/path:line" entries.
	 * @param string   $pattern  Regex pattern (with delimiters).
	 * @param string   $reason   Human-readable invariant description for failure output.
	 */
	protected function assertSourceMatchesAllowList( array $expected, string $pattern, string $reason ): void {
		$actual = $this->grep_plugin_source( $pattern );
		sort( $expected );
		$unexpected = array_diff( $actual, $expected );
		$missing    = array_diff( $expected, $actual );
		if ( empty( $unexpected ) && empty( $missing ) ) {
			$this->assertTrue( true );
			return;
		}
		$msg = "Invariant violation: $reason\n";
		if ( ! empty( $unexpected ) ) {
			$msg .= "\n  Unexpected matches (new code violating the invariant):\n";
			foreach ( $unexpected as $loc ) {
				$msg .= "    - $loc\n";
			}
		}
		if ( ! empty( $missing ) ) {
			$msg .= "\n  Missing matches (allow-list entry no longer in source — update the test or restore the call):\n";
			foreach ( $missing as $loc ) {
				$msg .= "    - $loc\n";
			}
		}
		$this->fail( $msg );
	}
}
