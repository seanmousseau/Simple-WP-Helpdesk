<?php
/**
 * Settings page: render, save handler, asset enqueue, and field helper.
 *
 * @package Simple_WP_Helpdesk
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Renders a settings field (text input or textarea) with a reset-to-default link.
 *
 * @since 2.0.0
 * @param string               $name The option name / input name attribute.
 * @param array<string, mixed> $defs The defaults array from swh_get_defaults().
 * @param string               $type Field type: 'text' (default) or 'textarea'.
 * @return void
 */
function swh_field( $name, $defs, $type = 'text' ) {
	$val     = get_option( $name, isset( $defs[ $name ] ) ? $defs[ $name ] : '' );
	$default = isset( $defs[ $name ] ) ? $defs[ $name ] : '';
	if ( 'textarea' === $type ) {
		echo '<textarea name="' . esc_attr( $name ) . '" rows="4" class="large-text" data-default="' . esc_attr( $default ) . '" data-field-name="' . esc_attr( $name ) . '">' . esc_textarea( $val ) . '</textarea>';
	} else {
		echo '<input type="text" name="' . esc_attr( $name ) . '" value="' . esc_attr( $val ) . '" class="regular-text" style="width:100%; max-width:500px;" data-default="' . esc_attr( $default ) . '" data-field-name="' . esc_attr( $name ) . '">';
	}
	echo '<br><a href="#" class="swh-reset-field" style="font-size:12px; color:#d63638;">' . esc_html__( 'Reset to default', 'simple-wp-helpdesk' ) . '</a>';
}

// Frontend CSS and JS are enqueued inside swh_ticket_frontend() only when the shortcode is rendered.

/**
 * Hooks into admin_enqueue_scripts to enqueue admin CSS and JS.
 *
 * @since 2.0.0
 * @see swh_enqueue_admin_assets()
 */
add_action( 'admin_enqueue_scripts', 'swh_enqueue_admin_assets' );
/**
 * Enqueues admin CSS and JS on the helpdesk settings page.
 *
 * @since 2.0.0
 * @param string $hook The current admin page hook suffix.
 * @return void
 */
function swh_enqueue_admin_assets( $hook ) {
	if ( 'helpdesk_ticket_page_swh-settings' !== $hook ) {
		return;
	}
	wp_enqueue_style( 'swh-admin', SWH_PLUGIN_URL . 'assets/swh-admin.css', array(), SWH_VERSION );
	wp_enqueue_script( 'swh-admin', SWH_PLUGIN_URL . 'assets/swh-admin.js', array(), SWH_VERSION, true );
}

/**
 * Hooks into admin_menu to register the Helpdesk Settings submenu page.
 *
 * @since 2.0.0
 * @see swh_register_settings_page()
 */
add_action( 'admin_menu', 'swh_register_settings_page' );
/**
 * Registers the Helpdesk Settings submenu page under the Tickets post type menu.
 *
 * @since 2.0.0
 * @return void
 */
function swh_register_settings_page() {
	add_submenu_page( 'edit.php?post_type=helpdesk_ticket', __( 'Helpdesk Settings', 'simple-wp-helpdesk' ), __( 'Settings', 'simple-wp-helpdesk' ), 'manage_options', 'swh-settings', 'swh_render_settings_page' );
}

/**
 * Hooks into admin_init to process the settings form POST and save options.
 *
 * @since 2.0.0
 * @see swh_handle_settings_save()
 */
add_action( 'admin_init', 'swh_handle_settings_save' );
/**
 * Processes the settings form submission and saves options, then redirects back.
 *
 * Runs on admin_init. Handles both the main form and the Tools form (separate nonces).
 * Never put redirect logic in the render callback.
 *
 * @since 2.0.0
 * @return void
 */
function swh_handle_settings_save() {
	// Only process on the settings page.
    // phpcs:ignore WordPress.Security.NonceVerification.Missing
	if ( ! isset( $_POST['swh_save_settings'] ) && ! isset( $_POST['swh_gdpr_delete'] ) && ! isset( $_POST['swh_purge_tickets'] ) && ! isset( $_POST['swh_factory_reset'] ) ) {
		return;
	}
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	$defs         = swh_get_defaults();
	$options_list = swh_get_all_option_keys();
	$integer_opts = array( 'swh_autoclose_days', 'swh_max_upload_size', 'swh_max_upload_count', 'swh_retention_attachments_days', 'swh_retention_tickets_days', 'swh_ticket_page_id', 'swh_token_expiration_days' );

	// GDPR specific client delete.
	if ( isset( $_POST['swh_gdpr_delete'], $_POST['swh_danger_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['swh_danger_nonce'] ) ), 'swh_danger_action' ) ) {
		$gdpr_email = isset( $_POST['swh_gdpr_email'] ) ? sanitize_email( wp_unslash( $_POST['swh_gdpr_email'] ) ) : '';
		if ( $gdpr_email ) {
            // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
			$tickets = get_posts(
				array(
					'post_type'      => 'helpdesk_ticket',
					'posts_per_page' => -1,
					'post_status'    => 'any',
					'meta_query'     => array(
						array(
							'key'   => '_ticket_email',
							'value' => $gdpr_email,
						),
					),
				)
			);
			$count   = count( $tickets );
			foreach ( $tickets as $t ) {
				swh_delete_ticket_and_files( $t->ID );
			}
			wp_safe_redirect(
				add_query_arg(
					array(
						'swh_notice' => 'gdpr_done',
						'swh_count'  => $count,
						'swh_email'  => rawurlencode( $gdpr_email ),
						'swh_tab'    => 'tab-tools',
					),
					admin_url( 'edit.php?post_type=helpdesk_ticket&page=swh-settings' )
				)
			);
			exit;
		} else {
			wp_safe_redirect(
				add_query_arg(
					array(
						'swh_notice' => 'gdpr_fail',
						'swh_tab'    => 'tab-tools',
					),
					admin_url( 'edit.php?post_type=helpdesk_ticket&page=swh-settings' )
				)
			);
			exit;
		}
	}

	// Mass executions.
	if ( isset( $_POST['swh_purge_tickets'], $_POST['swh_danger_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['swh_danger_nonce'] ) ), 'swh_danger_action' ) ) {
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
		wp_safe_redirect(
			add_query_arg(
				array(
					'swh_notice' => 'purged',
					'swh_tab'    => 'tab-tools',
				),
				admin_url( 'edit.php?post_type=helpdesk_ticket&page=swh-settings' )
			)
		);
		exit;
	}

	if ( isset( $_POST['swh_factory_reset'], $_POST['swh_danger_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['swh_danger_nonce'] ) ), 'swh_danger_action' ) ) {
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
		foreach ( $options_list as $opt ) {
			delete_option( $opt );
		}
		delete_option( 'swh_db_version' );
		wp_safe_redirect(
			add_query_arg(
				array(
					'swh_notice' => 'reset',
					'swh_tab'    => 'tab-tools',
				),
				admin_url( 'edit.php?post_type=helpdesk_ticket&page=swh-settings' )
			)
		);
		exit;
	}

	// SAVE TOOLS/RETENTION SETTINGS (separate form with its own nonce).
	if ( isset( $_POST['swh_save_settings'], $_POST['swh_tools_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['swh_tools_nonce'] ) ), 'swh_save_tools_action' ) ) {
		update_option( 'swh_retention_attachments_days', absint( isset( $_POST['swh_retention_attachments_days'] ) ? $_POST['swh_retention_attachments_days'] : 0 ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		update_option( 'swh_retention_tickets_days', absint( isset( $_POST['swh_retention_tickets_days'] ) ? $_POST['swh_retention_tickets_days'] : 0 ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		update_option( 'swh_delete_on_uninstall', isset( $_POST['swh_delete_on_uninstall'] ) ? 'yes' : 'no' );
		wp_safe_redirect(
			add_query_arg(
				array(
					'swh_notice' => 'saved',
					'swh_tab'    => 'tab-tools',
				),
				admin_url( 'edit.php?post_type=helpdesk_ticket&page=swh-settings' )
			)
		);
		exit;
	}

	// SAVE GENERAL SETTINGS (main form).
	if ( isset( $_POST['swh_save_settings'], $_POST['swh_settings_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['swh_settings_nonce'] ) ), 'swh_save_settings_action' ) ) {
		if ( ! isset( $_POST['swh_restrict_to_assigned'] ) ) {
			update_option( 'swh_restrict_to_assigned', 'no' );
		}
		$active_tab = isset( $_POST['swh_active_tab'] ) ? sanitize_key( $_POST['swh_active_tab'] ) : 'tab-general';
		$tools_only = array( 'swh_retention_attachments_days', 'swh_retention_tickets_days', 'swh_delete_on_uninstall' );

		foreach ( $options_list as $opt ) {
			if ( in_array( $opt, $tools_only, true ) || ! isset( $_POST[ $opt ] ) ) {
				continue;
			}
			if ( in_array( $opt, $integer_opts, true ) ) {
				$val = absint( $_POST[ $opt ] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			} elseif ( strpos( $opt, '_body' ) !== false ) {
				$val = wp_kses_post( wp_unslash( $_POST[ $opt ] ) );
			} else {
				$val = sanitize_text_field( wp_unslash( $_POST[ $opt ] ) );
			}
			update_option( $opt, $val );
		}
		wp_safe_redirect(
			add_query_arg(
				array(
					'swh_notice' => 'saved',
					'swh_tab'    => $active_tab,
				),
				admin_url( 'edit.php?post_type=helpdesk_ticket&page=swh-settings' )
			)
		);
		exit;
	}
}

/**
 * Renders the full Helpdesk Settings admin page HTML.
 *
 * Output only — do not place any redirect logic here. Use swh_handle_settings_save() instead.
 *
 * @since 2.0.0
 * @return void
 */
function swh_render_settings_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	$defs = swh_get_defaults();

	// Display notices from redirects.
	// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only GET params set by server redirect; used only for display.
	if ( isset( $_GET['swh_notice'] ) ) {
		$notice = sanitize_key( $_GET['swh_notice'] );
		if ( 'saved' === $notice ) {
			echo '<div class="updated notice is-dismissible"><p><strong>' . esc_html__( 'Settings saved successfully.', 'simple-wp-helpdesk' ) . '</strong></p></div>';
		} elseif ( 'reset' === $notice ) {
			echo '<div class="updated error notice is-dismissible"><p><strong>' . esc_html__( 'Plugin Factory Reset Complete. All tickets/files purged and settings reverted to default.', 'simple-wp-helpdesk' ) . '</strong></p></div>';
		} elseif ( 'purged' === $notice ) {
			echo '<div class="updated error notice is-dismissible"><p><strong>' . esc_html__( 'All tickets & files have been successfully purged.', 'simple-wp-helpdesk' ) . '</strong></p></div>';
		} elseif ( 'gdpr_done' === $notice ) {
			$count = absint( isset( $_GET['swh_count'] ) ? $_GET['swh_count'] : 0 );
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitize_email + rawurldecode constitutes sanitization.
			$gdpr_email = isset( $_GET['swh_email'] ) ? sanitize_email( rawurldecode( wp_unslash( $_GET['swh_email'] ) ) ) : '';
			/* translators: 1: number of tickets deleted, 2: email address */
			echo '<div class="updated error notice is-dismissible"><p><strong>' . sprintf( esc_html__( 'Successfully deleted %1$s ticket(s) and all associated files for %2$s.', 'simple-wp-helpdesk' ), esc_html( (string) $count ), esc_html( $gdpr_email ) ) . '</strong></p></div>';
		} elseif ( 'gdpr_fail' === $notice ) {
			echo '<div class="notice notice-error is-dismissible"><p><strong>' . esc_html__( 'Please enter a valid email address.', 'simple-wp-helpdesk' ) . '</strong></p></div>';
		}
	}
	// phpcs:enable WordPress.Security.NonceVerification.Recommended

	$techs = get_users( array( 'role__in' => array( 'administrator', 'technician' ) ) );
	?>
	<div class="wrap">
		<h2>
			<img src="<?php echo esc_url( SWH_ICON_1X ); ?>" alt="" style="width:32px;height:32px;vertical-align:middle;margin-right:6px;border-radius:4px;">
			<?php esc_html_e( 'Helpdesk Settings', 'simple-wp-helpdesk' ); ?>
		</h2>
		<div class="nav-tab-wrapper" id="swh-tabs" role="tablist" aria-label="<?php esc_attr_e( 'Settings Sections', 'simple-wp-helpdesk' ); ?>">
			<button type="button" class="nav-tab nav-tab-active" role="tab" id="swh-tab-general" data-tab="tab-general" aria-selected="true" aria-controls="tab-general" tabindex="0"><?php esc_html_e( 'General', 'simple-wp-helpdesk' ); ?></button>
			<button type="button" class="nav-tab" role="tab" id="swh-tab-routing" data-tab="tab-routing" aria-selected="false" aria-controls="tab-routing" tabindex="-1"><?php esc_html_e( 'Assignment & Routing', 'simple-wp-helpdesk' ); ?></button>
			<button type="button" class="nav-tab" role="tab" id="swh-tab-emails" data-tab="tab-emails" aria-selected="false" aria-controls="tab-emails" tabindex="-1"><?php esc_html_e( 'Email Templates', 'simple-wp-helpdesk' ); ?></button>
			<button type="button" class="nav-tab" role="tab" id="swh-tab-messages" data-tab="tab-messages" aria-selected="false" aria-controls="tab-messages" tabindex="-1"><?php esc_html_e( 'Messages', 'simple-wp-helpdesk' ); ?></button>
			<button type="button" class="nav-tab" role="tab" id="swh-tab-spam" data-tab="tab-spam" aria-selected="false" aria-controls="tab-spam" tabindex="-1"><?php esc_html_e( 'Anti-Spam', 'simple-wp-helpdesk' ); ?></button>
			<button type="button" class="nav-tab swh-tab-tools" role="tab" id="swh-tab-tools" data-tab="tab-tools" aria-selected="false" aria-controls="tab-tools" tabindex="-1"><?php esc_html_e( 'Tools', 'simple-wp-helpdesk' ); ?></button>
		</div>
		<form method="POST" action="">
			<?php wp_nonce_field( 'swh_save_settings_action', 'swh_settings_nonce' ); ?>
			<input type="hidden" name="swh_active_tab" id="swh_active_tab" value="tab-general">

			<div id="tab-general" class="swh-tab-content" role="tabpanel" aria-labelledby="swh-tab-general" tabindex="0">
				<table class="form-table">
					<tr><th scope="row"><?php esc_html_e( 'Custom Priorities', 'simple-wp-helpdesk' ); ?></th><td><?php swh_field( 'swh_ticket_priorities', $defs ); ?></td></tr>
					<tr><th scope="row"><?php esc_html_e( 'Default Priority', 'simple-wp-helpdesk' ); ?></th><td><?php swh_field( 'swh_default_priority', $defs ); ?></td></tr>
					<tr><td colspan="2"><hr></td></tr>
					<tr><th scope="row"><?php esc_html_e( 'Custom Statuses', 'simple-wp-helpdesk' ); ?></th><td><?php swh_field( 'swh_ticket_statuses', $defs ); ?></td></tr>
					<tr><th scope="row"><?php esc_html_e( 'Default New Status', 'simple-wp-helpdesk' ); ?></th><td><?php swh_field( 'swh_default_status', $defs ); ?></td></tr>
					<tr><th scope="row"><?php esc_html_e( '"Resolved" Status', 'simple-wp-helpdesk' ); ?> <br><small>(<?php esc_html_e( 'Triggers Auto-close', 'simple-wp-helpdesk' ); ?>)</small></th><td><?php swh_field( 'swh_resolved_status', $defs ); ?></td></tr>
					<tr><th scope="row"><?php esc_html_e( '"Closed" Status', 'simple-wp-helpdesk' ); ?> <br><small>(<?php esc_html_e( 'Disables replies', 'simple-wp-helpdesk' ); ?>)</small></th><td><?php swh_field( 'swh_closed_status', $defs ); ?></td></tr>
					<tr><th scope="row"><?php esc_html_e( '"Re-Opened" Status', 'simple-wp-helpdesk' ); ?></th><td><?php swh_field( 'swh_reopened_status', $defs ); ?></td></tr>
					<tr><td colspan="2"><hr></td></tr>
					<tr><th scope="row"><?php esc_html_e( 'Auto-Close Days', 'simple-wp-helpdesk' ); ?></th><td><input type="number" name="swh_autoclose_days" value="<?php echo esc_attr( get_option( 'swh_autoclose_days', 3 ) ); ?>" style="width:80px;"> <?php esc_html_e( 'days', 'simple-wp-helpdesk' ); ?> <p class="description"><?php esc_html_e( 'If a ticket is Resolved and the user doesn\'t reply in this many days, it automatically closes. Set to 0 to disable.', 'simple-wp-helpdesk' ); ?></p></td></tr>
					<tr><th scope="row"><?php esc_html_e( 'Max File Upload Size', 'simple-wp-helpdesk' ); ?></th><td><input type="number" name="swh_max_upload_size" value="<?php echo esc_attr( get_option( 'swh_max_upload_size', 5 ) ); ?>" style="width:80px;"> <?php esc_html_e( 'MB', 'simple-wp-helpdesk' ); ?></td></tr>
					<tr><th scope="row"><?php esc_html_e( 'Max Files Per Upload', 'simple-wp-helpdesk' ); ?></th><td><input type="number" name="swh_max_upload_count" value="<?php echo esc_attr( get_option( 'swh_max_upload_count', 5 ) ); ?>" style="width:80px;"> <?php esc_html_e( 'files', 'simple-wp-helpdesk' ); ?> <p class="description"><?php esc_html_e( 'Maximum number of files a user can attach per submission. Set to 0 for unlimited.', 'simple-wp-helpdesk' ); ?></p></td></tr>
				</table>
			</div>

			<div id="tab-routing" class="swh-tab-content" role="tabpanel" aria-labelledby="swh-tab-routing" tabindex="0" style="display:none;">
				<table class="form-table">
					<tr><th scope="row"><?php esc_html_e( 'Default Assignee', 'simple-wp-helpdesk' ); ?></th>
						<td><select name="swh_default_assignee"><option value=""><?php echo '-- ' . esc_html__( 'Unassigned', 'simple-wp-helpdesk' ) . ' --'; ?></option>
						<?php foreach ( $techs as $t ) : ?>
							<option value="<?php echo esc_attr( $t->ID ); ?>" <?php selected( get_option( 'swh_default_assignee' ), $t->ID ); ?>><?php echo esc_html( $t->display_name ); ?></option>
						<?php endforeach; ?></select></td>
					</tr>
					<tr><th scope="row"><?php esc_html_e( 'Fallback Alert Email', 'simple-wp-helpdesk' ); ?></th><td><input type="email" name="swh_fallback_email" value="<?php echo esc_attr( get_option( 'swh_fallback_email' ) ); ?>" class="regular-text"></td></tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Helpdesk Page', 'simple-wp-helpdesk' ); ?> <br><small>(<?php esc_html_e( 'Client portal destination', 'simple-wp-helpdesk' ); ?>)</small></th>
						<td>
							<?php
							$pages        = get_pages( array( 'post_status' => 'publish' ) );
							$current_page = (int) get_option( 'swh_ticket_page_id', 0 );
							?>
							<select name="swh_ticket_page_id">
								<option value="0"><?php echo '-- ' . esc_html__( 'Select a page', 'simple-wp-helpdesk' ) . ' --'; ?></option>
								<?php foreach ( $pages as $page ) : ?>
									<option value="<?php echo esc_attr( (string) $page->ID ); ?>" <?php selected( $current_page, $page->ID ); ?>><?php echo esc_html( $page->post_title ); ?></option>
								<?php endforeach; ?>
							</select>
							<p class="description"><?php esc_html_e( 'The page clients visit to view their ticket. Use the page containing [helpdesk_portal] if you have a dedicated portal page, or the page containing [submit_ticket] if you use a combined layout. All secure portal links will point here.', 'simple-wp-helpdesk' ); ?></p>
						</td>
					</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Portal Link Expiration', 'simple-wp-helpdesk' ); ?></th>
					<td>
						<input type="number" name="swh_token_expiration_days" value="<?php echo esc_attr( get_option( 'swh_token_expiration_days', 90 ) ); ?>" style="width:80px;" min="0">
						<?php esc_html_e( 'days (0 = never expires)', 'simple-wp-helpdesk' ); ?>
						<p class="description"><?php esc_html_e( 'Ticket portal links expire after this many days. Clients can request fresh links via the lookup form.', 'simple-wp-helpdesk' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Restrict Technicians', 'simple-wp-helpdesk' ); ?></th>
					<td>
						<label><input type="checkbox" name="swh_restrict_to_assigned" value="yes" <?php checked( get_option( 'swh_restrict_to_assigned', 'no' ), 'yes' ); ?>>
						<?php esc_html_e( 'Technicians can only view tickets assigned to them', 'simple-wp-helpdesk' ); ?></label>
					</td>
				</tr>
				</table>
			</div>

			<div id="tab-emails" class="swh-tab-content" role="tabpanel" aria-labelledby="swh-tab-emails" tabindex="0" style="display:none;">
				<table class="form-table">
					<tr>
						<th scope="row"><?php esc_html_e( 'Email Format', 'simple-wp-helpdesk' ); ?></th>
						<td>
							<?php $email_format = get_option( 'swh_email_format', 'html' ); ?>
							<select name="swh_email_format">
								<option value="html" <?php selected( $email_format, 'html' ); ?>><?php esc_html_e( 'HTML (Recommended)', 'simple-wp-helpdesk' ); ?></option>
								<option value="plain" <?php selected( $email_format, 'plain' ); ?>><?php esc_html_e( 'Plain Text', 'simple-wp-helpdesk' ); ?></option>
							</select>
							<p class="description"><?php esc_html_e( 'HTML emails include clickable links and a clean layout. Switch to Plain Text for basic SMTP setups.', 'simple-wp-helpdesk' ); ?></p>
						</td>
					</tr>
				</table>
				<hr>
				<p><strong><?php esc_html_e( 'Placeholders:', 'simple-wp-helpdesk' ); ?></strong> <code>{name}</code>, <code>{email}</code>, <code>{ticket_id}</code>, <code>{title}</code>, <code>{status}</code>, <code>{priority}</code>, <code>{message}</code>, <code>{ticket_url}</code>, <code>{admin_url}</code>, <code>{autoclose_days}</code>, <code>{ticket_links}</code> (<?php esc_html_e( 'lookup only', 'simple-wp-helpdesk' ); ?>)</p>
				<p><strong><?php esc_html_e( 'Conditional blocks:', 'simple-wp-helpdesk' ); ?></strong> <code>{if key}...{endif key}</code> — <?php esc_html_e( 'content is only included when the placeholder has a value.', 'simple-wp-helpdesk' ); ?></p><hr>
				<h3><?php esc_html_e( 'Emails Sent to Client', 'simple-wp-helpdesk' ); ?></h3>
				<table class="form-table">
					<tr><th scope="row"><?php esc_html_e( 'New Ticket (Subject)', 'simple-wp-helpdesk' ); ?></th><td><?php swh_field( 'swh_em_user_new_sub', $defs ); ?></td></tr>
					<tr><th scope="row"><?php esc_html_e( 'New Ticket (Body)', 'simple-wp-helpdesk' ); ?></th><td><?php swh_field( 'swh_em_user_new_body', $defs, 'textarea' ); ?></td></tr>
					<tr><th scope="row"><?php esc_html_e( 'Tech Replied (Subject)', 'simple-wp-helpdesk' ); ?></th><td><?php swh_field( 'swh_em_user_reply_sub', $defs ); ?></td></tr>
					<tr><th scope="row"><?php esc_html_e( 'Tech Replied (Body)', 'simple-wp-helpdesk' ); ?></th><td><?php swh_field( 'swh_em_user_reply_body', $defs, 'textarea' ); ?></td></tr>
					<tr><th scope="row"><?php esc_html_e( 'Status Changed (Subject)', 'simple-wp-helpdesk' ); ?></th><td><?php swh_field( 'swh_em_user_status_sub', $defs ); ?></td></tr>
					<tr><th scope="row"><?php esc_html_e( 'Status Changed (Body)', 'simple-wp-helpdesk' ); ?></th><td><?php swh_field( 'swh_em_user_status_body', $defs, 'textarea' ); ?></td></tr>
					<tr style="background:#f9f9f9;"><th scope="row"><?php esc_html_e( 'Reply + Status Change (Subject)', 'simple-wp-helpdesk' ); ?></th><td><?php swh_field( 'swh_em_user_reply_status_sub', $defs ); ?></td></tr>
					<tr style="background:#f9f9f9;"><th scope="row"><?php esc_html_e( 'Reply + Status Change (Body)', 'simple-wp-helpdesk' ); ?></th><td><?php swh_field( 'swh_em_user_reply_status_body', $defs, 'textarea' ); ?></td></tr>
					<tr style="background:#e6f7ff;"><th scope="row"><?php esc_html_e( 'Ticket Resolved (Subject)', 'simple-wp-helpdesk' ); ?></th><td><?php swh_field( 'swh_em_user_resolved_sub', $defs ); ?></td></tr>
					<tr style="background:#e6f7ff;"><th scope="row"><?php esc_html_e( 'Ticket Resolved (Body)', 'simple-wp-helpdesk' ); ?></th><td><?php swh_field( 'swh_em_user_resolved_body', $defs, 'textarea' ); ?></td></tr>
					<tr><th scope="row"><?php esc_html_e( 'Ticket Re-opened (Subject)', 'simple-wp-helpdesk' ); ?></th><td><?php swh_field( 'swh_em_user_reopen_sub', $defs ); ?></td></tr>
					<tr><th scope="row"><?php esc_html_e( 'Ticket Re-opened (Body)', 'simple-wp-helpdesk' ); ?></th><td><?php swh_field( 'swh_em_user_reopen_body', $defs, 'textarea' ); ?></td></tr>
					<tr><th scope="row"><?php esc_html_e( 'Client Closed Ticket (Subject)', 'simple-wp-helpdesk' ); ?></th><td><?php swh_field( 'swh_em_user_closed_sub', $defs ); ?></td></tr>
					<tr><th scope="row"><?php esc_html_e( 'Client Closed Ticket (Body)', 'simple-wp-helpdesk' ); ?></th><td><?php swh_field( 'swh_em_user_closed_body', $defs, 'textarea' ); ?></td></tr>
					<tr><th scope="row"><?php esc_html_e( 'Auto-Closed (Subject)', 'simple-wp-helpdesk' ); ?></th><td><?php swh_field( 'swh_em_user_autoclose_sub', $defs ); ?></td></tr>
					<tr><th scope="row"><?php esc_html_e( 'Auto-Closed (Body)', 'simple-wp-helpdesk' ); ?></th><td><?php swh_field( 'swh_em_user_autoclose_body', $defs, 'textarea' ); ?></td></tr>
					<tr><th scope="row"><?php esc_html_e( 'Ticket Lookup (Subject)', 'simple-wp-helpdesk' ); ?></th><td><?php swh_field( 'swh_em_user_lookup_sub', $defs ); ?></td></tr>
					<tr><th scope="row"><?php esc_html_e( 'Ticket Lookup (Body)', 'simple-wp-helpdesk' ); ?></th><td><?php swh_field( 'swh_em_user_lookup_body', $defs, 'textarea' ); ?></td></tr>
				</table><hr>
				<h3><?php esc_html_e( 'Emails Sent to Technician/Admin', 'simple-wp-helpdesk' ); ?></h3>
				<table class="form-table">
					<tr><th scope="row"><?php esc_html_e( 'New Ticket (Subject)', 'simple-wp-helpdesk' ); ?></th><td><?php swh_field( 'swh_em_admin_new_sub', $defs ); ?></td></tr>
					<tr><th scope="row"><?php esc_html_e( 'New Ticket (Body)', 'simple-wp-helpdesk' ); ?></th><td><?php swh_field( 'swh_em_admin_new_body', $defs, 'textarea' ); ?></td></tr>
					<tr><th scope="row"><?php esc_html_e( 'Client Replied (Sub)', 'simple-wp-helpdesk' ); ?></th><td><?php swh_field( 'swh_em_admin_reply_sub', $defs ); ?></td></tr>
					<tr><th scope="row"><?php esc_html_e( 'Client Replied (Body)', 'simple-wp-helpdesk' ); ?></th><td><?php swh_field( 'swh_em_admin_reply_body', $defs, 'textarea' ); ?></td></tr>
					<tr><th scope="row"><?php esc_html_e( 'Ticket Re-opened (Sub)', 'simple-wp-helpdesk' ); ?></th><td><?php swh_field( 'swh_em_admin_reopen_sub', $defs ); ?></td></tr>
					<tr><th scope="row"><?php esc_html_e( 'Ticket Re-opened (Body)', 'simple-wp-helpdesk' ); ?></th><td><?php swh_field( 'swh_em_admin_reopen_body', $defs, 'textarea' ); ?></td></tr>
					<tr><th scope="row"><?php esc_html_e( 'Client Closed Ticket (Sub)', 'simple-wp-helpdesk' ); ?></th><td><?php swh_field( 'swh_em_admin_closed_sub', $defs ); ?></td></tr>
					<tr><th scope="row"><?php esc_html_e( 'Client Closed Ticket (Body)', 'simple-wp-helpdesk' ); ?></th><td><?php swh_field( 'swh_em_admin_closed_body', $defs, 'textarea' ); ?></td></tr>
					<tr style="background:#e6f7ff;"><th scope="row"><?php esc_html_e( 'Ticket Assigned to You (Subject)', 'simple-wp-helpdesk' ); ?></th><td><?php swh_field( 'swh_em_assigned_sub', $defs ); ?></td></tr>
					<tr style="background:#e6f7ff;"><th scope="row"><?php esc_html_e( 'Ticket Assigned to You (Body)', 'simple-wp-helpdesk' ); ?></th><td><?php swh_field( 'swh_em_assigned_body', $defs, 'textarea' ); ?></td></tr>
				</table>
			</div>

			<div id="tab-messages" class="swh-tab-content" role="tabpanel" aria-labelledby="swh-tab-messages" tabindex="0" style="display:none;">
				<table class="form-table">
					<tr><th scope="row"><?php esc_html_e( 'Success: Ticket Created', 'simple-wp-helpdesk' ); ?></th><td><?php swh_field( 'swh_msg_success_new', $defs ); ?></td></tr>
					<tr><th scope="row"><?php esc_html_e( 'Success: Reply Added', 'simple-wp-helpdesk' ); ?></th><td><?php swh_field( 'swh_msg_success_reply', $defs ); ?></td></tr>
					<tr><th scope="row"><?php esc_html_e( 'Success: Ticket Re-opened', 'simple-wp-helpdesk' ); ?></th><td><?php swh_field( 'swh_msg_success_reopen', $defs ); ?></td></tr>
					<tr><th scope="row"><?php esc_html_e( 'Success: Ticket Closed', 'simple-wp-helpdesk' ); ?></th><td><?php swh_field( 'swh_msg_success_closed', $defs ); ?></td></tr>
					<tr><th scope="row"><?php esc_html_e( 'Error: Anti-Spam Failed', 'simple-wp-helpdesk' ); ?></th><td><?php swh_field( 'swh_msg_err_spam', $defs ); ?></td></tr>
					<tr><th scope="row"><?php esc_html_e( 'Error: Missing Fields', 'simple-wp-helpdesk' ); ?></th><td><?php swh_field( 'swh_msg_err_missing', $defs ); ?></td></tr>
					<tr><th scope="row"><?php esc_html_e( 'Error: Invalid Link', 'simple-wp-helpdesk' ); ?></th><td><?php swh_field( 'swh_msg_err_invalid', $defs ); ?></td></tr>
					<tr><th scope="row"><?php esc_html_e( 'Error: Expired Link', 'simple-wp-helpdesk' ); ?></th><td><?php swh_field( 'swh_msg_err_expired', $defs ); ?></td></tr>
					<tr><th scope="row"><?php esc_html_e( 'Success: Ticket Links Sent', 'simple-wp-helpdesk' ); ?></th><td><?php swh_field( 'swh_msg_success_lookup', $defs ); ?></td></tr>
				</table>
			</div>

			<div id="tab-spam" class="swh-tab-content" role="tabpanel" aria-labelledby="swh-tab-spam" tabindex="0" style="display:none;">
				<?php $spam_method = get_option( 'swh_spam_method', 'none' ); ?>
				<table class="form-table">
					<tr><th scope="row"><?php esc_html_e( 'Spam Prevention', 'simple-wp-helpdesk' ); ?></th><td><select name="swh_spam_method"><option value="none" <?php selected( $spam_method, 'none' ); ?>><?php esc_html_e( 'None', 'simple-wp-helpdesk' ); ?></option><option value="honeypot" <?php selected( $spam_method, 'honeypot' ); ?>><?php esc_html_e( 'Honeypot', 'simple-wp-helpdesk' ); ?></option><option value="recaptcha" <?php selected( $spam_method, 'recaptcha' ); ?>><?php esc_html_e( 'Google reCAPTCHA v2', 'simple-wp-helpdesk' ); ?></option><option value="turnstile" <?php selected( $spam_method, 'turnstile' ); ?>><?php esc_html_e( 'Cloudflare Turnstile', 'simple-wp-helpdesk' ); ?></option></select></td></tr>
					<tr><th scope="row"><?php esc_html_e( 'reCAPTCHA Site Key', 'simple-wp-helpdesk' ); ?></th><td><input type="text" name="swh_recaptcha_site_key" value="<?php echo esc_attr( get_option( 'swh_recaptcha_site_key' ) ); ?>" class="regular-text"></td></tr>
					<tr><th scope="row"><?php esc_html_e( 'reCAPTCHA Secret Key', 'simple-wp-helpdesk' ); ?></th><td><input type="text" name="swh_recaptcha_secret_key" value="<?php echo esc_attr( get_option( 'swh_recaptcha_secret_key' ) ); ?>" class="regular-text"></td></tr>
					<tr><th scope="row"><?php esc_html_e( 'Turnstile Site Key', 'simple-wp-helpdesk' ); ?></th><td><input type="text" name="swh_turnstile_site_key" value="<?php echo esc_attr( get_option( 'swh_turnstile_site_key' ) ); ?>" class="regular-text"></td></tr>
					<tr><th scope="row"><?php esc_html_e( 'Turnstile Secret Key', 'simple-wp-helpdesk' ); ?></th><td><input type="text" name="swh_turnstile_secret_key" value="<?php echo esc_attr( get_option( 'swh_turnstile_secret_key' ) ); ?>" class="regular-text"></td></tr>
				</table>
			</div>
			<p class="submit" id="save-btn-container"><input type="submit" name="swh_save_settings" class="button button-primary" value="<?php esc_attr_e( 'Save Changes', 'simple-wp-helpdesk' ); ?>"></p>
		</form>

		<div id="tab-tools" class="swh-tab-content" role="tabpanel" aria-labelledby="swh-tab-tools" tabindex="0" style="display:none;">
			<h3><?php esc_html_e( 'Automated Data Retention', 'simple-wp-helpdesk' ); ?></h3>
			<form method="POST" action="">
				<?php wp_nonce_field( 'swh_save_tools_action', 'swh_tools_nonce' ); ?>
				<table class="form-table">
					<tr>
						<th scope="row"><?php esc_html_e( 'Purge Old Attachments', 'simple-wp-helpdesk' ); ?></th>
						<td>
							<input type="number" name="swh_retention_attachments_days" value="<?php echo esc_attr( get_option( 'swh_retention_attachments_days', 0 ) ); ?>" style="width:80px;"> <?php esc_html_e( 'days', 'simple-wp-helpdesk' ); ?>
							<p class="description"><?php esc_html_e( 'Automatically delete physical file attachments older than this many days to save server space. Links to the files will be safely removed from the ticket. Set to 0 to disable.', 'simple-wp-helpdesk' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Purge Old Tickets', 'simple-wp-helpdesk' ); ?></th>
						<td>
							<input type="number" name="swh_retention_tickets_days" value="<?php echo esc_attr( get_option( 'swh_retention_tickets_days', 0 ) ); ?>" style="width:80px;"> <?php esc_html_e( 'days', 'simple-wp-helpdesk' ); ?>
							<p class="description"><?php esc_html_e( 'Automatically delete entire tickets (and their files) that haven\'t been updated in this many days. Set to 0 to disable.', 'simple-wp-helpdesk' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Uninstallation Behavior', 'simple-wp-helpdesk' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="swh_delete_on_uninstall" value="yes" <?php checked( get_option( 'swh_delete_on_uninstall' ), 'yes' ); ?>>
								<?php esc_html_e( 'Delete all Plugin Data when uninstalled', 'simple-wp-helpdesk' ); ?>
							</label>
							<p class="description"><?php esc_html_e( 'If checked, completely deleting this plugin from the WP Plugins screen will wipe all tickets, files, and settings. Leave unchecked to safely preserve data.', 'simple-wp-helpdesk' ); ?></p>
						</td>
					</tr>
				</table>
				<p><input type="submit" name="swh_save_settings" class="button button-primary" value="<?php esc_attr_e( 'Save Retention Settings', 'simple-wp-helpdesk' ); ?>"></p>
			</form>
			<hr>
			<div style="background: #f8d7da; border: 1px solid #f5c6cb; padding: 20px; border-radius: 5px; color: #721c24; margin-top: 20px;">
				<h3 style="margin-top:0;"><?php esc_html_e( 'Danger Zone (Manual Cleanup)', 'simple-wp-helpdesk' ); ?></h3>
				<p><?php esc_html_e( 'These manual actions are permanent and cannot be undone.', 'simple-wp-helpdesk' ); ?></p>
				<form method="POST" action="">
					<?php wp_nonce_field( 'swh_danger_action', 'swh_danger_nonce' ); ?>
					<p>
						<strong><?php esc_html_e( 'GDPR / Client Data Purge:', 'simple-wp-helpdesk' ); ?></strong> <?php esc_html_e( 'Deletes all tickets, comments, and files associated with a specific email address.', 'simple-wp-helpdesk' ); ?><br>
						<input type="email" name="swh_gdpr_email" placeholder="client@example.com" class="regular-text" style="margin-top:5px; margin-bottom:5px;"><br>
						<button type="submit" name="swh_gdpr_delete" class="button" onclick="return confirm('<?php echo esc_js( __( 'Are you sure you want to delete all data for this email?', 'simple-wp-helpdesk' ) ); ?>');"><?php esc_html_e( 'Delete Client Data', 'simple-wp-helpdesk' ); ?></button>
					</p>
					<hr style="border-color:#f5c6cb;">
					<p>
						<strong><?php esc_html_e( 'Purge ALL Tickets:', 'simple-wp-helpdesk' ); ?></strong> <?php esc_html_e( 'Deletes all helpdesk tickets, conversation history, and associated file uploads for EVERYONE.', 'simple-wp-helpdesk' ); ?><br>
						<button type="submit" name="swh_purge_tickets" class="button" style="margin-top:5px;" onclick="return confirm('<?php echo esc_js( __( 'Are you sure you want to PURGE ALL TICKETS? This cannot be undone.', 'simple-wp-helpdesk' ) ); ?>');"><?php esc_html_e( 'Purge All Tickets', 'simple-wp-helpdesk' ); ?></button>
					</p>
					<hr style="border-color:#f5c6cb;">
					<p>
						<strong><?php esc_html_e( 'Factory Reset:', 'simple-wp-helpdesk' ); ?></strong> <?php esc_html_e( 'Purges all tickets AND resets all plugin settings back to original defaults.', 'simple-wp-helpdesk' ); ?><br>
						<button type="submit" name="swh_factory_reset" class="button button-primary" style="background:#d63638; border-color:#d63638; margin-top:5px;" onclick="return confirm('<?php echo esc_js( __( 'Are you sure you want to FACTORY RESET the plugin? This cannot be undone.', 'simple-wp-helpdesk' ) ); ?>');"><?php esc_html_e( 'Factory Reset Plugin', 'simple-wp-helpdesk' ); ?></button>
					</p>
				</form>
			</div>
		</div>
	</div>

	<?php
}
