<?php
/**
 * Test Coverage Matrix linter.
 *
 * Parses docs/internal/test-coverage-matrix.md and emits a summary report of
 * cell counts per layer and per tier. Exits non-zero on parse failure.
 *
 * Full git-diff–aware enforcement (fail if a changed feature has any new red
 * cells without explicit N/A) is scoped for PR 6 of the testing overhaul.
 * For now this script:
 *   - confirms the matrix is parseable,
 *   - reports totals so we can track progress over time,
 *   - flags structural problems (missing tier headers, malformed cells).
 *
 * Usage: php bin/matrix-lint.php [--json]
 */

declare(strict_types=1);

$matrix_path = dirname( __DIR__ ) . '/docs/internal/test-coverage-matrix.md';
if ( ! is_readable( $matrix_path ) ) {
	fwrite( STDERR, "ERROR: matrix not found at $matrix_path\n" );
	exit( 2 );
}

$as_json = in_array( '--json', $argv, true );

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

exit( 0 );
