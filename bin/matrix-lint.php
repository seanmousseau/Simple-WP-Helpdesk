<?php
/**
 * Test Coverage Matrix linter.
 *
 * Parses docs/internal/test-coverage-matrix.md and emits a summary report
 * (cell counts per layer / per tier). Optionally compares the branch's
 * matrix against a base ref to detect coverage regressions.
 *
 * Modes:
 *   php bin/matrix-lint.php
 *       Summary report. Exits non-zero on structural problems or unknown
 *       cell markers.
 *
 *   php bin/matrix-lint.php --json
 *       Same checks, machine-readable output for CI.
 *
 *   php bin/matrix-lint.php --diff[=<base-ref>]
 *       Diff mode (PR 6 of the testing overhaul). Compares the matrix at
 *       HEAD against the matrix at the base ref (default: origin/main).
 *       Fails if any feature row regressed — i.e. a cell that was `✓` or
 *       `P` on the base is now `R` (red), OR a row that didn't exist
 *       before has red cells when it could have been seeded green / P /
 *       explicit N/A. Use --diff-base=<ref> to override the base.
 *
 *       This is the enforcement layer that makes the matrix self-policing:
 *       a PR that adds a feature without filling in its row, or removes
 *       a test that was the only thing keeping a cell green, fails CI.
 */

declare(strict_types=1);

$matrix_path = dirname( __DIR__ ) . '/docs/internal/test-coverage-matrix.md';
if ( ! is_readable( $matrix_path ) ) {
	fwrite( STDERR, "ERROR: matrix not found at $matrix_path\n" );
	exit( 2 );
}

// Argument parsing.
$as_json   = false;
$do_diff   = false;
$diff_base = 'origin/main';
foreach ( array_slice( $argv, 1 ) as $arg ) {
	if ( '--json' === $arg ) {
		$as_json = true;
	} elseif ( '--diff' === $arg ) {
		$do_diff = true;
	} elseif ( 0 === strpos( $arg, '--diff=' ) ) {
		$do_diff   = true;
		$diff_base = substr( $arg, 7 );
	} elseif ( 0 === strpos( $arg, '--diff-base=' ) ) {
		$do_diff   = true;
		$diff_base = substr( $arg, 12 );
	}
}

$lines = file( $matrix_path, FILE_IGNORE_NEW_LINES );
if ( false === $lines ) {
	fwrite( STDERR, "ERROR: could not read $matrix_path\n" );
	exit( 2 );
}

$tiers           = array();  // tier_name => array of rows
$current_tier    = null;
$expected_layers = array( 'L1 Invariant', 'L2 REST/AJAX', 'L3 Domain', 'L4 E2E', 'L5 Visual' );
$structural_problems = array();

foreach ( $lines as $lineno => $line ) {
	// Tier header: "### Tier N — <title>".
	if ( preg_match( '/^### Tier \d+ — (.+)$/', $line, $m ) ) {
		$current_tier            = trim( $m[1] );
		$tiers[ $current_tier ]  = array();
		continue;
	}
	// Stop tier scan once we hit "## Gap notes" or another major section.
	if ( preg_match( '/^## /', $line ) && null !== $current_tier && ! preg_match( '/^### Tier/', $line ) ) {
		$current_tier = null;
		continue;
	}
	if ( null === $current_tier ) {
		continue;
	}
	// Skip header/separator rows in markdown tables.
	if ( ! preg_match( '/^\|/', $line ) ) {
		continue;
	}
	if ( preg_match( '/^\|\s*-+\s*\|/', $line ) ) {
		continue;
	}
	// Skip header row (first row after each tier — has "Feature" in first cell).
	$cells = array_map( 'trim', explode( '|', trim( $line, '|' ) ) );
	if ( count( $cells ) < 6 ) {
		$structural_problems[] = "Line " . ( $lineno + 1 ) . ": expected 6 columns (Feature + 5 layers), got " . count( $cells );
		continue;
	}
	if ( strcasecmp( $cells[0], 'Feature' ) === 0 ) {
		continue;
	}
	$tiers[ $current_tier ][] = $cells;
}

// Empty-tiers guard: if the matrix has no parseable tier headers or every tier
// is empty, the document is malformed and the rest of the script would silently
// emit zeros. Catch that explicitly.
$has_rows = false;
foreach ( $tiers as $rows ) {
	if ( ! empty( $rows ) ) {
		$has_rows = true;
		break;
	}
}
if ( ! $has_rows ) {
	$structural_problems[] = "No tier rows parsed: expected '### Tier N — <title>' headers followed by a markdown table with 6 columns (Feature + 5 layers). Check the matrix structure.";
}

// Tally cell states per tier/layer. A cell is classified by its leading marker.
$state_of = static function ( string $cell ): string {
	$cell = trim( $cell );
	if ( '' === $cell ) {
		return 'empty';
	}
	if ( str_starts_with( $cell, '✓' ) ) {
		return 'green';
	}
	if ( str_starts_with( $cell, 'P' ) && ( strlen( $cell ) === 1 || ! ctype_alpha( $cell[1] ) ) ) {
		return 'partial';
	}
	if ( str_starts_with( $cell, 'R' ) && ( strlen( $cell ) === 1 || ! ctype_alpha( $cell[1] ) ) ) {
		return 'red';
	}
	if ( str_starts_with( $cell, 'N/A' ) ) {
		return 'na';
	}
	return 'unknown';
};

$zero_counts = array(
	'green'   => 0,
	'partial' => 0,
	'red'     => 0,
	'na'      => 0,
	'unknown' => 0,
);
$totals   = $zero_counts;
$by_tier  = array();
$by_layer = array_fill_keys( $expected_layers, $zero_counts );

foreach ( $tiers as $tier_name => $rows ) {
	$by_tier[ $tier_name ] = $zero_counts;
	foreach ( $rows as $row ) {
		for ( $i = 1; $i <= 5; $i++ ) {
			$state = $state_of( $row[ $i ] ?? '' );
			if ( 'empty' === $state ) {
				continue;
			}
			$totals[ $state ]++;
			$by_tier[ $tier_name ][ $state ]++;
			$layer = $expected_layers[ $i - 1 ];
			$by_layer[ $layer ][ $state ]++;
		}
	}
}

// Unknown markers are validation failures, not just informational counts.
// They mean a matrix cell exists that the linter cannot classify — the cell
// will be invisible to coverage reporting until someone fixes it.
$unknown_failure = $totals['unknown'] > 0;

if ( $as_json ) {
	echo json_encode(
		array(
			'totals'          => $totals,
			'by_tier'         => $by_tier,
			'by_layer'        => $by_layer,
			'problems'        => $structural_problems,
			'unknown_markers' => $totals['unknown'],
		),
		JSON_PRETTY_PRINT
	), "\n";
	exit( ( empty( $structural_problems ) && ! $unknown_failure ) ? 0 : 1 );
}

$total_cells = $totals['green'] + $totals['partial'] + $totals['red'] + $totals['na'] + $totals['unknown'];
$covered     = $totals['green'];
$pct         = $total_cells > 0 ? round( ( $covered / max( 1, $total_cells - $totals['na'] ) ) * 100, 1 ) : 0;

echo "Test Coverage Matrix — Summary\n";
echo str_repeat( '=', 60 ), "\n\n";

echo "By layer\n";
echo str_repeat( '-', 60 ), "\n";
printf( "  %-16s  %5s  %5s  %5s  %5s\n", 'Layer', '✓', 'P', 'R', 'N/A' );
foreach ( $expected_layers as $layer ) {
	$row = $by_layer[ $layer ];
	printf(
		"  %-16s  %5d  %5d  %5d  %5d\n",
		$layer,
		$row['green'],
		$row['partial'],
		$row['red'],
		$row['na']
	);
}

echo "\nBy tier\n";
echo str_repeat( '-', 60 ), "\n";
printf( "  %-50s  %5s  %5s  %5s  %5s\n", 'Tier', '✓', 'P', 'R', 'N/A' );
foreach ( $by_tier as $name => $row ) {
	$short = strlen( $name ) > 48 ? substr( $name, 0, 47 ) . '…' : $name;
	printf(
		"  %-50s  %5d  %5d  %5d  %5d\n",
		$short,
		$row['green'],
		$row['partial'],
		$row['red'],
		$row['na']
	);
}

echo "\nTotals\n";
echo str_repeat( '-', 60 ), "\n";
printf( "  Green (✓)    : %d\n", $totals['green'] );
printf( "  Partial (P)  : %d\n", $totals['partial'] );
printf( "  Red (R)      : %d\n", $totals['red'] );
printf( "  N/A          : %d\n", $totals['na'] );
printf( "  Unknown      : %d\n", $totals['unknown'] );
printf( "  Total cells  : %d\n", $total_cells );
printf( "  Coverage     : %.1f%% (✓ / (cells − N/A))\n", $pct );

if ( ! empty( $structural_problems ) ) {
	echo "\nStructural problems\n";
	echo str_repeat( '-', 60 ), "\n";
	foreach ( $structural_problems as $p ) {
		echo "  - $p\n";
	}
	exit( 1 );
}

if ( $unknown_failure ) {
	echo "\nERROR: {$totals['unknown']} cell(s) with unrecognised marker. Cells must start with ✓, P, R, or N/A.\n";
	exit( 1 );
}

// ---- Diff mode (PR 6 enforcement) ----

if ( ! $do_diff ) {
	exit( 0 );
}

/**
 * Parse a matrix blob into [feature_key => {display, cells[5]}].
 *
 * Lightweight version of the main parser — skips structural validation since
 * diff cares only about feature-level cell states. feature_key is the
 * lowercased feature name with markdown formatting stripped, so backtick /
 * emphasis edits don't mis-key the comparison.
 *
 * @return array<string, array{display:string, cells:array<int,string>}>
 */
function swh_matrix_parse_for_diff( string $text ): array {
	$lines   = explode( "\n", $text );
	$out     = array();
	$in_tier = false;
	foreach ( $lines as $line ) {
		if ( preg_match( '/^### Tier \d+ — /', $line ) ) {
			$in_tier = true;
			continue;
		}
		if ( preg_match( '/^## /', $line ) && $in_tier && ! preg_match( '/^### Tier/', $line ) ) {
			$in_tier = false;
			continue;
		}
		if ( ! $in_tier || ! preg_match( '/^\|/', $line ) ) {
			continue;
		}
		if ( preg_match( '/^\|\s*-+\s*\|/', $line ) ) {
			continue;
		}
		$cells = array_map( 'trim', explode( '|', trim( $line, '|' ) ) );
		if ( count( $cells ) < 6 ) {
			continue;
		}
		if ( strcasecmp( $cells[0], 'Feature' ) === 0 ) {
			continue;
		}
		$feature_raw            = $cells[0];
		$feature_key            = strtolower( (string) preg_replace( '/[`*_]/', '', $feature_raw ) );
		$out[ $feature_key ]    = array(
			'display' => $feature_raw,
			'cells'   => array_slice( $cells, 1, 5 ),
		);
	}
	return $out;
}

// The diff base must be a file path that the caller has prepared (e.g. via
// `git show <ref>:docs/internal/test-coverage-matrix.md > /tmp/base.md`).
// Resolving the ref via subprocess is avoided here on purpose — keeps this
// PHP script free of shell invocations. The Makefile target `matrix-lint-diff`
// handles the shell side.
// Hard-coded /tmp default — works on Linux CI and macOS dev (where it
// symlinks to /private/tmp). Don't use sys_get_temp_dir() because it
// returns macOS's per-user $TMPDIR which the Makefile target can't predict.
$default_base_path = '/tmp/swh-matrix-base.md';
if ( 'origin/main' === $diff_base ) {
	$base_path = $default_base_path;
	if ( ! is_readable( $base_path ) ) {
		fwrite(
			STDERR,
			"\nERROR: Default base path $base_path doesn't exist. Either pass "
				. "--diff-base=<filepath> with a path to the base matrix, or invoke via "
				. "`make matrix-lint-diff` which prepares the default path.\n"
		);
		exit( 2 );
	}
} else {
	$base_path = $diff_base;
	if ( ! is_readable( $base_path ) ) {
		fwrite( STDERR, "\nERROR: Base matrix file not found at: $base_path\n" );
		exit( 2 );
	}
}

$base_blob    = (string) file_get_contents( $base_path );
$base_map     = swh_matrix_parse_for_diff( $base_blob );
$current_blob = (string) file_get_contents( $matrix_path );
$current_map  = swh_matrix_parse_for_diff( $current_blob );

// Compute regressions:
//   - features present in BOTH: any cell that went non-red → red is a regression
//   - features NEW in current: any red cell is a regression (added without seeding tests)
$regressions = array();
foreach ( $current_map as $key => $now ) {
	$was = $base_map[ $key ] ?? null;
	for ( $i = 0; $i < 5; $i++ ) {
		$now_state = $state_of( $now['cells'][ $i ] ?? '' );
		if ( 'red' !== $now_state ) {
			continue;
		}
		$layer = $expected_layers[ $i ];
		if ( null === $was ) {
			$regressions[] = array(
				'feature' => $now['display'],
				'layer'   => $layer,
				'was'     => '(new feature row)',
				'now'     => $now['cells'][ $i ],
				'kind'    => 'new-row-red',
			);
			continue;
		}
		$was_state = $state_of( $was['cells'][ $i ] ?? '' );
		if ( in_array( $was_state, array( 'green', 'partial', 'na' ), true ) ) {
			$regressions[] = array(
				'feature' => $now['display'],
				'layer'   => $layer,
				'was'     => $was['cells'][ $i ] ?? '(empty)',
				'now'     => $now['cells'][ $i ],
				'kind'    => 'regression',
			);
		}
	}
}

echo "\n";
echo str_repeat( '=', 60 ) . "\n";
echo "Diff vs base ($base_path)\n";
echo str_repeat( '=', 60 ) . "\n";

if ( empty( $regressions ) ) {
	echo "\nPASS — no coverage regressions in this branch.\n";
	exit( 0 );
}

echo "\nFAIL — " . count( $regressions ) . " coverage regression(s):\n";
foreach ( $regressions as $r ) {
	$tag = 'regression' === $r['kind'] ? 'REGRESSED' : 'NEW-ROW';
	echo "\n  [$tag] " . substr( (string) $r['feature'], 0, 90 ) . "\n";
	echo "    layer: {$r['layer']}\n";
	echo "    was  : " . substr( (string) $r['was'], 0, 80 ) . "\n";
	echo "    now  : " . substr( (string) $r['now'], 0, 80 ) . "\n";
}

echo "\nFix: turn the red cell(s) into ✓ / P / N/A with rationale, OR explain in "
	. "the PR description why this regression is intentional.\n";
exit( 1 );
