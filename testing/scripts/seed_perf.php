<?php
/**
 * Performance baseline seeder.
 *
 * Creates N helpdesk_ticket posts with a realistic spread of statuses,
 * priorities, ages, and CC counts. Run via:
 *
 *   wp eval-file testing/scripts/seed_perf.php 100
 *
 * Tickets are tagged with meta key `_swh_perf_seed=1` so they can be
 * bulk-deleted with:
 *
 *   wp post delete $(wp post list --post_type=helpdesk_ticket \
 *     --meta_key=_swh_perf_seed --meta_value=1 --format=ids) --force
 *
 * @package Simple_WP_Helpdesk_Testing
 */

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	fwrite( STDERR, "Must be run via wp eval-file.\n" );
	exit( 1 );
}

$count = isset( $args[0] ) ? (int) $args[0] : 100;
if ( $count < 1 ) {
	WP_CLI::error( 'count must be >= 1' );
}

$statuses   = array( 'Open', 'In Progress', 'Resolved', 'Closed' );
$priorities = array( 'low', 'normal', 'high', 'urgent' );

// Seeded RNG for reproducibility.
mt_srand( 42 );

$now     = time();
$created = 0;

WP_CLI::log( "Seeding {$count} tickets..." );
$progress = \WP_CLI\Utils\make_progress_bar( 'Seeding', $count );

for ( $i = 0; $i < $count; $i++ ) {
	$age_days     = mt_rand( 0, 90 );
	$post_date_ts = $now - $age_days * DAY_IN_SECONDS;

	$post_id = wp_insert_post(
		array(
			'post_type'     => 'helpdesk_ticket',
			'post_status'   => 'publish',
			'post_title'    => sprintf( 'Perf Seed Ticket #%05d', $i + 1 ),
			'post_content'  => sprintf( 'Body for ticket %d. Lorem ipsum dolor sit amet, consectetur adipiscing elit. Sed do eiusmod tempor incididunt ut labore et dolore magna aliqua.', $i + 1 ),
			'post_date'     => gmdate( 'Y-m-d H:i:s', $post_date_ts ),
			'post_date_gmt' => gmdate( 'Y-m-d H:i:s', $post_date_ts ),
		),
		true
	);

	if ( is_wp_error( $post_id ) ) {
		WP_CLI::warning( "ticket $i: " . $post_id->get_error_message() );
		$progress->tick();
		continue;
	}

	$status   = $statuses[ mt_rand( 0, 3 ) ];
	$priority = $priorities[ mt_rand( 0, 3 ) ];

	update_post_meta( $post_id, '_ticket_status', $status );
	update_post_meta( $post_id, '_ticket_priority', $priority );
	update_post_meta( $post_id, '_ticket_email', 'perfseed+' . $i . '@example.test' );
	update_post_meta( $post_id, '_ticket_name', 'Perf Seed ' . $i );
	update_post_meta( $post_id, '_swh_perf_seed', 1 );

	// ~30% have a CC list.
	if ( mt_rand( 1, 10 ) <= 3 ) {
		update_post_meta( $post_id, '_ticket_cc_emails', 'cc1@example.test,cc2@example.test' );
	}

	$created++;
	$progress->tick();
}

$progress->finish();
WP_CLI::success( "Seeded {$created} tickets." );
