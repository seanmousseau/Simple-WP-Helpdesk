<?php if ( ! defined( 'ABSPATH' ) ) {
	exit; }

function swh_activate() {
	if ( ! get_role( 'technician' ) ) {
		add_role(
			'technician',
			'Technician',
			array(
				'read'                 => true,
				'edit_posts'           => true,
				'edit_others_posts'    => true,
				'edit_published_posts' => true,
				'publish_posts'        => true,
				'delete_posts'         => true,
				'upload_files'         => true,
			)
		);
	} else {
		// Ensure existing installs get the missing capabilities.
		$tech = get_role( 'technician' );
		foreach ( array( 'edit_others_posts', 'edit_published_posts', 'publish_posts', 'delete_posts' ) as $cap ) {
			if ( ! $tech->has_cap( $cap ) ) {
				$tech->add_cap( $cap );
			}
		}
	}
	if ( ! wp_next_scheduled( 'swh_autoclose_event' ) ) {
		wp_schedule_event( time(), 'hourly', 'swh_autoclose_event' );
	}
	if ( ! wp_next_scheduled( 'swh_retention_tickets_event' ) ) {
		wp_schedule_event( time() + 1800, 'hourly', 'swh_retention_tickets_event' );
	}
	if ( ! wp_next_scheduled( 'swh_retention_attachments_event' ) ) {
		wp_schedule_event( time() + 3600, 'hourly', 'swh_retention_attachments_event' );
	}
	swh_run_upgrade_routine();
	swh_ensure_upload_protection();
}

function swh_deactivate() {
	wp_clear_scheduled_hook( 'swh_autoclose_event' );
	wp_clear_scheduled_hook( 'swh_retention_tickets_event' );
	wp_clear_scheduled_hook( 'swh_retention_attachments_event' );
	// Clear legacy hooks just in case.
	wp_clear_scheduled_hook( 'swh_hourly_maintenance_event' );
	wp_clear_scheduled_hook( 'swh_daily_autoclose_event' );
}

add_action( 'admin_init', 'swh_run_upgrade_routine' );
function swh_run_upgrade_routine() {
	// One-time migration: set comment_type on ALL comments attached to ticket posts.
	// Uses a fresh flag name (v2) so previous broken migration flags don't block it.
	// No WHERE on comment_type — catches NULL, empty, 'comment', or any other value.
	if ( ! get_option( 'swh_comment_type_v2' ) ) {
		global $wpdb;
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->comments} c
             INNER JOIN {$wpdb->posts} p ON c.comment_post_ID = p.ID
             SET c.comment_type = %s
             WHERE p.post_type = %s",
				'helpdesk_reply',
				'helpdesk_ticket'
			)
		);
		update_option( 'swh_comment_type_v2', '1' );
	}

	$db_version = get_option( 'swh_db_version', '0.0' );
	if ( version_compare( $db_version, SWH_VERSION, '>=' ) ) {
		return;
	}
	// Add any missing options without overwriting existing values.
	foreach ( swh_get_defaults() as $key => $val ) {
		add_option( $key, $val );
	}
	// v2.0.0: Clean up old SWH_GitHub_Updater transients (replaced by plugin-update-checker).
	if ( version_compare( $db_version, '2.0.0', '<' ) ) {
		global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_swh_gh_release_%' OR option_name LIKE '_transient_timeout_swh_gh_release_%'" );
		// Migrate email templates to use conditional blocks (only if user hasn't customized them).
		$template_migrations = array(
			'swh_em_user_reply_body'        => array(
				"Hi {name},\n\nA technician has replied to your ticket.\n\nReply:\n{message}\n\nView conversation and reply here:\n{ticket_url}",
				"Hi {name},\n\nA technician has replied to your ticket.\n\n{if message}Reply:\n{message}\n\n{endif message}View conversation and reply here:\n{ticket_url}",
			),
			'swh_em_user_reply_status_body' => array(
				"Hi {name},\n\nA technician has replied to your ticket and the status is now: {status}.\n\nReply:\n{message}\n\nView conversation and reply here:\n{ticket_url}",
				"Hi {name},\n\nA technician has replied to your ticket and the status is now: {status}.\n\n{if message}Reply:\n{message}\n\n{endif message}View conversation and reply here:\n{ticket_url}",
			),
			'swh_em_user_resolved_body'     => array(
				"Hi {name},\n\nYour ticket has been marked as resolved by a technician.\n\nTechnician Note:\n{message}\n\nPlease note: If we do not hear back from you, this ticket will be automatically closed in {autoclose_days} days.\n\nView or reply to your ticket here:\n{ticket_url}",
				"Hi {name},\n\nYour ticket has been marked as resolved by a technician.\n\n{if message}Technician Note:\n{message}\n\n{endif message}{if autoclose_days}Please note: If we do not hear back from you, this ticket will be automatically closed in {autoclose_days} days.\n\n{endif autoclose_days}View or reply to your ticket here:\n{ticket_url}",
			),
			'swh_em_admin_reopen_body'      => array(
				"{name} has re-opened their ticket.\n\nReason:\n{message}\n\nView/Edit Ticket in Admin:\n{admin_url}",
				"{name} has re-opened their ticket.\n\n{if message}Reason:\n{message}\n\n{endif message}View/Edit Ticket in Admin:\n{admin_url}",
			),
		);
		foreach ( $template_migrations as $opt_key => $values ) {
			$current = get_option( $opt_key );
			if ( $current === $values[0] ) {
				update_option( $opt_key, $values[1] );
			}
		}
	}

	update_option( 'swh_db_version', SWH_VERSION );
}

add_action( 'admin_init', 'swh_ensure_technician_caps' );
function swh_ensure_technician_caps() {
	if ( get_option( 'swh_tech_caps_v2' ) ) {
		return;
	}
	$tech = get_role( 'technician' );
	if ( $tech ) {
		foreach ( array( 'edit_others_posts', 'edit_published_posts', 'publish_posts', 'delete_posts', 'upload_files' ) as $cap ) {
			if ( ! $tech->has_cap( $cap ) ) {
				$tech->add_cap( $cap );
			}
		}
	}
	update_option( 'swh_tech_caps_v2', '1' );
}

function swh_uninstall() {
	if ( 'yes' !== get_option( 'swh_delete_on_uninstall' ) ) {
		return;
	}
	// Delete all tickets and their files.
	$tickets = get_posts(
		array(
			'post_type'      => 'helpdesk_ticket',
			'posts_per_page' => -1,
			'post_status'    => 'any',
		)
	);
	foreach ( $tickets as $t ) {
		swh_delete_ticket_and_files( $t->ID );
	}
	// Clear cron hooks (defensive; deactivation may have been skipped)..
	wp_clear_scheduled_hook( 'swh_autoclose_event' );
	wp_clear_scheduled_hook( 'swh_retention_tickets_event' );
	wp_clear_scheduled_hook( 'swh_retention_attachments_event' );
	// Remove upload protection files and directory.
	$upload_dir = wp_get_upload_dir();
	$swh_dir    = trailingslashit( $upload_dir['basedir'] ) . 'swh-helpdesk';
	// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.unlink_unlink
	@unlink( $swh_dir . '/.htaccess' );
	// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.unlink_unlink
	@unlink( $swh_dir . '/index.php' );
	// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
	@rmdir( $swh_dir );
	// Delete rate-limit options and transients.
	global $wpdb;
	$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE 'swh\_rl\_%'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_swh\_%' OR option_name LIKE '_transient_timeout_swh\_%'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	// Delete migration flags.
	delete_option( 'swh_tech_caps_v2' );
	delete_option( 'swh_comment_type_migrated' );
	delete_option( 'swh_comment_type_v2' );
	// Reassign technician users to default role before removing role.
	$techs = get_users( array( 'role' => 'technician' ) );
	foreach ( $techs as $tech ) {
		$tech->set_role( get_option( 'default_role', 'subscriber' ) );
	}
	remove_role( 'technician' );
	// Delete all plugin options.
	foreach ( swh_get_all_option_keys() as $opt ) {
		delete_option( $opt );
	}
	delete_option( 'swh_delete_on_uninstall' );
	delete_option( 'swh_db_version' );
}

add_action( 'init', 'swh_load_textdomain' );
function swh_load_textdomain() {
	load_plugin_textdomain( 'simple-wp-helpdesk', false, dirname( plugin_basename( SWH_PLUGIN_FILE ) ) . '/languages' );
}

add_action( 'init', 'swh_register_ticket_cpt' );
function swh_register_ticket_cpt() {
	register_post_type(
		'helpdesk_ticket',
		array(
			'labels'          => array(
				'name'               => __( 'Tickets', 'simple-wp-helpdesk' ),
				'singular_name'      => __( 'Ticket', 'simple-wp-helpdesk' ),
				'add_new_item'       => __( 'Add New Ticket', 'simple-wp-helpdesk' ),
				'edit_item'          => __( 'Edit Ticket', 'simple-wp-helpdesk' ),
				'all_items'          => __( 'All Tickets', 'simple-wp-helpdesk' ),
				'view_item'          => __( 'View Ticket', 'simple-wp-helpdesk' ),
				'search_items'       => __( 'Search Tickets', 'simple-wp-helpdesk' ),
				'not_found'          => __( 'No tickets found.', 'simple-wp-helpdesk' ),
				'not_found_in_trash' => __( 'No tickets found in Trash.', 'simple-wp-helpdesk' ),
				'menu_name'          => __( 'Tickets', 'simple-wp-helpdesk' ),
			),
			'public'          => false,
			'show_ui'         => true,
			'menu_icon'       => 'dashicons-tickets-alt',
			'supports'        => array( 'title', 'editor' ),
			'capability_type' => 'post',
		)
	);
}
