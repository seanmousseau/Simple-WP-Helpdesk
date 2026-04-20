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
	$default = isset( $defs[ $name ] ) && is_scalar( $defs[ $name ] ) ? (string) $defs[ $name ] : '';
	$val     = swh_get_string_option( $name, $default );
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
	$is_settings = ( 'helpdesk_ticket_page_swh-settings' === $hook );
	$is_editor   = in_array( $hook, array( 'post.php', 'post-new.php' ), true );

	if ( $is_editor ) {
		$screen    = get_current_screen();
		$is_editor = $screen && 'helpdesk_ticket' === $screen->post_type;
	}

	if ( ! $is_settings && ! $is_editor ) {
		return;
	}

	wp_enqueue_style( 'swh-shared', SWH_PLUGIN_URL . 'assets/swh-shared.css', array(), SWH_VERSION );
	wp_enqueue_style( 'swh-admin', SWH_PLUGIN_URL . 'assets/swh-admin.css', array( 'swh-shared' ), SWH_VERSION );
	wp_enqueue_script( 'swh-admin', SWH_PLUGIN_URL . 'assets/swh-admin.js', array(), SWH_VERSION, true );
	wp_localize_script(
		'swh-admin',
		'swhAdmin',
		array(
			'ajaxUrl'        => admin_url( 'admin-ajax.php' ),
			'testEmailNonce' => wp_create_nonce( 'swh_test_email_nonce' ),
			'i18n'           => array(
				/* translators: Placeholder text shown inside the canned response title input field. */
				'cannedTitlePlaceholder' => __( 'Response title...', 'simple-wp-helpdesk' ),
				'cannedTitleAriaLabel'   => __( 'Canned response title', 'simple-wp-helpdesk' ),
				'cannedBodyAriaLabel'    => __( 'Canned response body', 'simple-wp-helpdesk' ),
				'removeLabel'            => __( 'Remove', 'simple-wp-helpdesk' ),
				'testEmailSending'       => __( 'Sending…', 'simple-wp-helpdesk' ),
				/* translators: Shown after a test email is successfully dispatched. */
				'testEmailSuccess'       => __( 'Test email sent successfully.', 'simple-wp-helpdesk' ),
				'testEmailError'         => __( 'Failed to send test email.', 'simple-wp-helpdesk' ),
				'testEmailNetworkError'  => __( 'Network error. Please try again.', 'simple-wp-helpdesk' ),
			),
		)
	);
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
	$integer_opts = array( 'swh_autoclose_days', 'swh_max_upload_size', 'swh_max_upload_count', 'swh_retention_attachments_days', 'swh_retention_tickets_days', 'swh_ticket_page_id', 'swh_token_expiration_days', 'swh_sla_warn_hours', 'swh_sla_breach_hours' );

	// GDPR specific client delete.
	if ( isset( $_POST['swh_gdpr_delete'], $_POST['swh_danger_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( is_string( $_POST['swh_danger_nonce'] ) ? $_POST['swh_danger_nonce'] : '' ) ), 'swh_danger_action' ) ) {
		$gdpr_email = isset( $_POST['swh_gdpr_email'] ) && is_string( $_POST['swh_gdpr_email'] ) ? sanitize_email( wp_unslash( $_POST['swh_gdpr_email'] ) ) : '';
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
	if ( isset( $_POST['swh_purge_tickets'], $_POST['swh_danger_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( is_string( $_POST['swh_danger_nonce'] ) ? $_POST['swh_danger_nonce'] : '' ) ), 'swh_danger_action' ) ) {
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

	if ( isset( $_POST['swh_factory_reset'], $_POST['swh_danger_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( is_string( $_POST['swh_danger_nonce'] ) ? $_POST['swh_danger_nonce'] : '' ) ), 'swh_danger_action' ) ) {
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
	if ( isset( $_POST['swh_save_settings'], $_POST['swh_tools_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( is_string( $_POST['swh_tools_nonce'] ) ? $_POST['swh_tools_nonce'] : '' ) ), 'swh_save_tools_action' ) ) {
		update_option( 'swh_retention_attachments_days', isset( $_POST['swh_retention_attachments_days'] ) && is_scalar( $_POST['swh_retention_attachments_days'] ) ? absint( $_POST['swh_retention_attachments_days'] ) : 0 ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		update_option( 'swh_retention_tickets_days', isset( $_POST['swh_retention_tickets_days'] ) && is_scalar( $_POST['swh_retention_tickets_days'] ) ? absint( $_POST['swh_retention_tickets_days'] ) : 0 ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
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
	if ( isset( $_POST['swh_save_settings'], $_POST['swh_settings_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( is_string( $_POST['swh_settings_nonce'] ) ? $_POST['swh_settings_nonce'] : '' ) ), 'swh_save_settings_action' ) ) {
		if ( ! isset( $_POST['swh_restrict_to_assigned'] ) ) {
			update_option( 'swh_restrict_to_assigned', 'no' );
		}
		$active_tab = isset( $_POST['swh_active_tab'] ) && is_string( $_POST['swh_active_tab'] ) ? sanitize_key( $_POST['swh_active_tab'] ) : 'tab-general';
		// Options excluded from the generic save loop (handled separately or by the Tools form).
		$tools_only = array( 'swh_retention_attachments_days', 'swh_retention_tickets_days', 'swh_delete_on_uninstall', 'swh_canned_responses', 'swh_ticket_templates', 'swh_assignment_rules' );

		foreach ( $options_list as $opt ) {
			if ( in_array( $opt, $tools_only, true ) || ! isset( $_POST[ $opt ] ) ) {
				continue;
			}
			if ( in_array( $opt, $integer_opts, true ) ) {
				$val = is_scalar( $_POST[ $opt ] ) ? absint( $_POST[ $opt ] ) : 0; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			} elseif ( strpos( $opt, '_body' ) !== false ) {
				$val = is_string( $_POST[ $opt ] ) ? wp_kses_post( wp_unslash( $_POST[ $opt ] ) ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			} else {
				$val = is_string( $_POST[ $opt ] ) ? sanitize_text_field( wp_unslash( $_POST[ $opt ] ) ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			}
			update_option( $opt, $val );
		}
		// Save canned responses (structured option, not part of $options_list).
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized in loop below.
		$raw_titles = isset( $_POST['swh_canned_titles'] ) ? (array) wp_unslash( $_POST['swh_canned_titles'] ) : array();
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized in loop below.
		$raw_bodies = isset( $_POST['swh_canned_bodies'] ) ? (array) wp_unslash( $_POST['swh_canned_bodies'] ) : array();
		$canned     = array();
		foreach ( $raw_titles as $ci => $raw_title ) {
			$ctitle = is_string( $raw_title ) ? sanitize_text_field( $raw_title ) : '';
			$cbody  = isset( $raw_bodies[ $ci ] ) && is_string( $raw_bodies[ $ci ] ) ? wp_kses_post( $raw_bodies[ $ci ] ) : '';
			if ( '' !== $ctitle ) {
				$canned[] = array(
					'title' => $ctitle,
					'body'  => $cbody,
				);
			}
		}
		update_option( 'swh_canned_responses', $canned );

		// Save ticket templates (structured option, not part of $options_list).
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized in loop below.
		$raw_tmpl_labels = isset( $_POST['swh_tmpl_labels'] ) ? (array) wp_unslash( $_POST['swh_tmpl_labels'] ) : array();
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized in loop below.
		$raw_tmpl_bodies = isset( $_POST['swh_tmpl_bodies'] ) ? (array) wp_unslash( $_POST['swh_tmpl_bodies'] ) : array();
		$templates       = array();
		foreach ( $raw_tmpl_labels as $ti => $raw_label ) {
			$tlabel = is_string( $raw_label ) ? sanitize_text_field( $raw_label ) : '';
			$tbody  = isset( $raw_tmpl_bodies[ $ti ] ) && is_string( $raw_tmpl_bodies[ $ti ] ) ? sanitize_textarea_field( $raw_tmpl_bodies[ $ti ] ) : '';
			if ( '' !== $tlabel ) {
				$templates[] = array(
					'label' => $tlabel,
					'body'  => $tbody,
				);
			}
		}
		update_option( 'swh_ticket_templates', $templates );

		// Save assignment rules (array of {category_term_id, assignee_user_id}).
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized by absint() in loop below.
		$raw_rule_cats = isset( $_POST['swh_rule_category'] ) ? (array) wp_unslash( $_POST['swh_rule_category'] ) : array();
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized by absint() in loop below.
		$raw_rule_users = isset( $_POST['swh_rule_assignee'] ) ? (array) wp_unslash( $_POST['swh_rule_assignee'] ) : array();
		$rules          = array();
		foreach ( $raw_rule_cats as $ri => $raw_cat ) {
			$cat_id  = is_scalar( $raw_cat ) ? absint( $raw_cat ) : 0;
			$user_id = isset( $raw_rule_users[ $ri ] ) && is_scalar( $raw_rule_users[ $ri ] ) ? absint( $raw_rule_users[ $ri ] ) : 0;
			if ( $cat_id > 0 && $user_id > 0 ) {
				$rules[] = array(
					'category_term_id' => $cat_id,
					'assignee_user_id' => $user_id,
				);
			}
		}
		update_option( 'swh_assignment_rules', $rules );

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
		$notice = sanitize_key( is_string( $_GET['swh_notice'] ) ? $_GET['swh_notice'] : '' );
		if ( 'saved' === $notice ) {
			echo '<div class="updated notice is-dismissible"><p><strong>' . esc_html__( 'Settings saved successfully.', 'simple-wp-helpdesk' ) . '</strong></p></div>';
		} elseif ( 'reset' === $notice ) {
			echo '<div class="updated error notice is-dismissible"><p><strong>' . esc_html__( 'Plugin Factory Reset Complete. All tickets/files purged and settings reverted to default.', 'simple-wp-helpdesk' ) . '</strong></p></div>';
		} elseif ( 'purged' === $notice ) {
			echo '<div class="updated error notice is-dismissible"><p><strong>' . esc_html__( 'All tickets & files have been successfully purged.', 'simple-wp-helpdesk' ) . '</strong></p></div>';
		} elseif ( 'gdpr_done' === $notice ) {
			$count = isset( $_GET['swh_count'] ) && is_scalar( $_GET['swh_count'] ) ? absint( $_GET['swh_count'] ) : 0;
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitize_email + rawurldecode constitutes sanitization.
			$gdpr_email = isset( $_GET['swh_email'] ) && is_string( $_GET['swh_email'] ) ? sanitize_email( rawurldecode( wp_unslash( $_GET['swh_email'] ) ) ) : '';
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
			<button type="button" class="nav-tab" role="tab" id="swh-tab-canned" data-tab="tab-canned" aria-selected="false" aria-controls="tab-canned" tabindex="-1"><?php esc_html_e( 'Canned Responses', 'simple-wp-helpdesk' ); ?></button>
			<button type="button" class="nav-tab" role="tab" id="swh-tab-templates" data-tab="tab-templates" aria-selected="false" aria-controls="tab-templates" tabindex="-1"><?php esc_html_e( 'Templates', 'simple-wp-helpdesk' ); ?></button>
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
					<tr><th scope="row"><?php esc_html_e( 'Auto-Close Days', 'simple-wp-helpdesk' ); ?></th><td><input type="number" name="swh_autoclose_days" value="<?php echo esc_attr( swh_get_string_option( 'swh_autoclose_days', '3' ) ); ?>" style="width:80px;"> <?php esc_html_e( 'days', 'simple-wp-helpdesk' ); ?> <p class="description"><?php esc_html_e( 'If a ticket is Resolved and the user doesn\'t reply in this many days, it automatically closes. Set to 0 to disable.', 'simple-wp-helpdesk' ); ?></p></td></tr>
					<tr><th scope="row"><?php esc_html_e( 'Max File Upload Size', 'simple-wp-helpdesk' ); ?></th><td><input type="number" name="swh_max_upload_size" value="<?php echo esc_attr( swh_get_string_option( 'swh_max_upload_size', '5' ) ); ?>" style="width:80px;"> <?php esc_html_e( 'MB', 'simple-wp-helpdesk' ); ?></td></tr>
					<tr><th scope="row"><?php esc_html_e( 'Max Files Per Upload', 'simple-wp-helpdesk' ); ?></th><td><input type="number" name="swh_max_upload_count" value="<?php echo esc_attr( swh_get_string_option( 'swh_max_upload_count', '5' ) ); ?>" style="width:80px;"> <?php esc_html_e( 'files', 'simple-wp-helpdesk' ); ?> <p class="description"><?php esc_html_e( 'Maximum number of files a user can attach per submission. Set to 0 for unlimited.', 'simple-wp-helpdesk' ); ?></p></td></tr>
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
					<tr><th scope="row"><?php esc_html_e( 'Fallback Alert Email', 'simple-wp-helpdesk' ); ?></th><td><input type="email" name="swh_fallback_email" value="<?php echo esc_attr( swh_get_string_option( 'swh_fallback_email' ) ); ?>" class="regular-text"></td></tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Helpdesk Page', 'simple-wp-helpdesk' ); ?> <br><small>(<?php esc_html_e( 'Client portal destination', 'simple-wp-helpdesk' ); ?>)</small></th>
						<td>
							<?php
							$pages        = get_pages( array( 'post_status' => 'publish' ) );
							$pages        = is_array( $pages ) ? $pages : array();
							$current_page = swh_get_int_option( 'swh_ticket_page_id', 0 );
							?>
							<select name="swh_ticket_page_id">
								<option value="0"><?php echo '-- ' . esc_html__( 'Select a page', 'simple-wp-helpdesk' ) . ' --'; ?></option>
								<?php foreach ( $pages as $page ) : ?>
									<?php
									$shortcode_hints = array();
									foreach ( array(
										'submit_ticket'   => '[submit_ticket]',
										'helpdesk_portal' => '[helpdesk_portal]',
									) as $shortcode => $label ) {
										if ( has_shortcode( $page->post_content, $shortcode ) ) {
											$shortcode_hints[] = $label;
										}
									}
									$page_label = $page->post_title . ( $shortcode_hints ? ' — ' . implode( ' ', $shortcode_hints ) : '' );
									?>
									<option value="<?php echo esc_attr( (string) $page->ID ); ?>" <?php selected( $current_page, $page->ID ); ?>><?php echo esc_html( $page_label ); ?></option>
								<?php endforeach; ?>
							</select>
							<p class="description"><?php esc_html_e( 'The page clients visit to view their ticket. Use the page containing [helpdesk_portal] if you have a dedicated portal page, or the page containing [submit_ticket] if you use a combined layout. All secure portal links will point here.', 'simple-wp-helpdesk' ); ?></p>
						</td>
					</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Portal Link Expiration', 'simple-wp-helpdesk' ); ?></th>
					<td>
						<input type="number" name="swh_token_expiration_days" value="<?php echo esc_attr( swh_get_string_option( 'swh_token_expiration_days', '90' ) ); ?>" style="width:80px;" min="0">
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
				<h3><?php esc_html_e( 'SLA Breach Alerts', 'simple-wp-helpdesk' ); ?></h3>
				<table class="form-table">
					<tr>
						<th scope="row"><?php esc_html_e( 'Warning Threshold', 'simple-wp-helpdesk' ); ?></th>
						<td>
							<input type="number" name="swh_sla_warn_hours" value="<?php echo esc_attr( swh_get_string_option( 'swh_sla_warn_hours', '4' ) ); ?>" style="width:80px;" min="0">
							<?php esc_html_e( 'hours (0 = disabled)', 'simple-wp-helpdesk' ); ?>
							<p class="description"><?php esc_html_e( 'Mark open tickets as "warn" when older than this many hours.', 'simple-wp-helpdesk' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Breach Threshold', 'simple-wp-helpdesk' ); ?></th>
						<td>
							<input type="number" name="swh_sla_breach_hours" value="<?php echo esc_attr( swh_get_string_option( 'swh_sla_breach_hours', '8' ) ); ?>" style="width:80px;" min="0">
							<?php esc_html_e( 'hours (0 = disabled)', 'simple-wp-helpdesk' ); ?>
							<p class="description"><?php esc_html_e( 'Mark open tickets as "breach" and send alert when older than this many hours.', 'simple-wp-helpdesk' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Alert Recipient', 'simple-wp-helpdesk' ); ?></th>
						<td>
							<input type="email" name="swh_sla_notify_email" value="<?php echo esc_attr( swh_get_string_option( 'swh_sla_notify_email' ) ); ?>" class="regular-text">
							<p class="description"><?php esc_html_e( 'Leave blank to use the default admin/assignee email.', 'simple-wp-helpdesk' ); ?></p>
						</td>
					</tr>
				</table>
				<h3><?php esc_html_e( 'Auto-Assignment Rules', 'simple-wp-helpdesk' ); ?></h3>
				<p class="description"><?php esc_html_e( 'When a new ticket is submitted with a matching category, it is automatically assigned to the specified technician. Rules are evaluated in order; the first match wins.', 'simple-wp-helpdesk' ); ?></p>
				<?php
				$assignment_rules = get_option( 'swh_assignment_rules', array() );
				$assignment_rules = is_array( $assignment_rules ) ? $assignment_rules : array();
				$categories       = get_terms(
					array(
						'taxonomy'   => 'helpdesk_category',
						'hide_empty' => false,
					)
				);
				$categories       = is_array( $categories ) ? $categories : array();
				?>
				<table class="widefat" id="swh-rules-table" style="margin-bottom:10px;">
					<thead><tr>
						<th><?php esc_html_e( 'Category', 'simple-wp-helpdesk' ); ?></th>
						<th><?php esc_html_e( 'Assign To', 'simple-wp-helpdesk' ); ?></th>
						<th></th>
					</tr></thead>
					<tbody id="swh-rules-body">
					<?php foreach ( $assignment_rules as $rule ) : ?>
						<?php
						$rule      = is_array( $rule ) ? $rule : array();
						$rule_cat  = isset( $rule['category_term_id'] ) && is_scalar( $rule['category_term_id'] ) ? intval( $rule['category_term_id'] ) : 0;
						$rule_user = isset( $rule['assignee_user_id'] ) && is_scalar( $rule['assignee_user_id'] ) ? intval( $rule['assignee_user_id'] ) : 0;
						?>
						<tr class="swh-rule-item">
							<td>
								<select name="swh_rule_category[]">
									<option value=""><?php esc_html_e( '— Select category —', 'simple-wp-helpdesk' ); ?></option>
									<?php foreach ( $categories as $cat ) : ?>
										<option value="<?php echo esc_attr( (string) $cat->term_id ); ?>" <?php selected( $rule_cat, $cat->term_id ); ?>><?php echo esc_html( $cat->name ); ?></option>
									<?php endforeach; ?>
								</select>
							</td>
							<td>
								<select name="swh_rule_assignee[]">
									<option value=""><?php esc_html_e( '— Select technician —', 'simple-wp-helpdesk' ); ?></option>
									<?php foreach ( $techs as $t ) : ?>
										<option value="<?php echo esc_attr( (string) $t->ID ); ?>" <?php selected( $rule_user, $t->ID ); ?>><?php echo esc_html( $t->display_name ); ?></option>
									<?php endforeach; ?>
								</select>
							</td>
							<td><button type="button" class="button swh-remove-rule"><?php esc_html_e( 'Remove', 'simple-wp-helpdesk' ); ?></button></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
				<button type="button" class="button" id="swh-add-rule"><?php esc_html_e( '+ Add Rule', 'simple-wp-helpdesk' ); ?></button>
				<script>
				(function() {
					var catOptions = 
					<?php
					echo wp_json_encode(
						array_map(
							function ( $c ) {
								return array(
									'id'   => $c->term_id,
									'name' => $c->name,
								);
							},
							$categories
						)
					);
					?>
										;
					var techOptions = 
					<?php
					echo wp_json_encode(
						array_map(
							function ( $t ) {
								return array(
									'id'   => $t->ID,
									'name' => $t->display_name,
								);
							},
							$techs
						)
					);
					?>
										;
					document.getElementById( 'swh-add-rule' ).addEventListener( 'click', function() {
						var tbody = document.getElementById( 'swh-rules-body' );
						var tr = document.createElement( 'tr' );
						tr.className = 'swh-rule-item';
						var tdCat = document.createElement( 'td' );
						var selCat = document.createElement( 'select' );
						selCat.name = 'swh_rule_category[]';
						var optBlank = document.createElement( 'option' );
						optBlank.value = '';
						optBlank.textContent = '<?php echo esc_js( __( '— Select category —', 'simple-wp-helpdesk' ) ); ?>';
						selCat.appendChild( optBlank );
						catOptions.forEach( function( c ) {
							var opt = document.createElement( 'option' );
							opt.value = c.id;
							opt.textContent = c.name;
							selCat.appendChild( opt );
						} );
						tdCat.appendChild( selCat );
						var tdUser = document.createElement( 'td' );
						var selUser = document.createElement( 'select' );
						selUser.name = 'swh_rule_assignee[]';
						var optBlankU = document.createElement( 'option' );
						optBlankU.value = '';
						optBlankU.textContent = '<?php echo esc_js( __( '— Select technician —', 'simple-wp-helpdesk' ) ); ?>';
						selUser.appendChild( optBlankU );
						techOptions.forEach( function( t ) {
							var opt = document.createElement( 'option' );
							opt.value = t.id;
							opt.textContent = t.name;
							selUser.appendChild( opt );
						} );
						tdUser.appendChild( selUser );
						var tdBtn = document.createElement( 'td' );
						var btn = document.createElement( 'button' );
						btn.type = 'button';
						btn.className = 'button swh-remove-rule';
						btn.textContent = '<?php echo esc_js( __( 'Remove', 'simple-wp-helpdesk' ) ); ?>';
						tdBtn.appendChild( btn );
						tr.appendChild( tdCat );
						tr.appendChild( tdUser );
						tr.appendChild( tdBtn );
						tbody.appendChild( tr );
					} );
					document.getElementById( 'swh-rules-body' ).addEventListener( 'click', function( e ) {
						if ( e.target && e.target.classList.contains( 'swh-remove-rule' ) ) {
							e.target.closest( 'tr' ).remove();
						}
					} );
				}());
				</script>
			</div>

			<div id="tab-emails" class="swh-tab-content" role="tabpanel" aria-labelledby="swh-tab-emails" tabindex="0" style="display:none;">
				<p>
					<button type="button" id="swh-test-email-btn" class="button"><?php esc_html_e( 'Send Test Email', 'simple-wp-helpdesk' ); ?></button>
					<span id="swh-test-email-msg" style="margin-left:10px; font-size:13px;" aria-live="polite"></span>
					<span class="description" style="display:block; margin-top:4px; font-size:12px;"><?php esc_html_e( 'Sends a sample "New Ticket" email to your account email address to verify your email settings.', 'simple-wp-helpdesk' ); ?></span>
				</p>
				<hr>
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
					<tr>
						<th scope="row"><?php esc_html_e( 'Reply-by-Email Webhook URL', 'simple-wp-helpdesk' ); ?></th>
						<td>
							<code><?php echo esc_html( rest_url( 'swh/v1/inbound-email' ) ); ?></code>
							<p class="description"><?php esc_html_e( 'Point your email service (Mailgun, SendGrid, Postmark) inbound webhook here. Clients can reply to notification emails and the reply is attached to their ticket.', 'simple-wp-helpdesk' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Webhook Secret', 'simple-wp-helpdesk' ); ?></th>
						<td>
							<input type="password" name="swh_inbound_secret" value="<?php echo esc_attr( swh_get_string_option( 'swh_inbound_secret' ) ); ?>" class="regular-text" autocomplete="off">
							<p class="description"><?php esc_html_e( 'Required. The inbound webhook is disabled when blank. Use a strong random string; your mail provider must send Authorization: Bearer &lt;secret&gt; in each POST request.', 'simple-wp-helpdesk' ); ?></p>
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
					<tr style="background:#fff3cd;"><th scope="row"><?php esc_html_e( 'SLA Breach Alert (Subject)', 'simple-wp-helpdesk' ); ?></th><td><?php swh_field( 'swh_em_admin_sla_breach_sub', $defs ); ?></td></tr>
					<tr style="background:#fff3cd;"><th scope="row"><?php esc_html_e( 'SLA Breach Alert (Body)', 'simple-wp-helpdesk' ); ?></th><td><?php swh_field( 'swh_em_admin_sla_breach_body', $defs, 'textarea' ); ?></td></tr>
				</table>
				<h3><?php esc_html_e( 'Emails Sent to Client (System)', 'simple-wp-helpdesk' ); ?></h3>
				<table class="form-table">
					<tr><th scope="row"><?php esc_html_e( 'Ticket Merged (Subject)', 'simple-wp-helpdesk' ); ?></th><td><?php swh_field( 'swh_em_user_merged_sub', $defs ); ?></td></tr>
					<tr><th scope="row"><?php esc_html_e( 'Ticket Merged (Body)', 'simple-wp-helpdesk' ); ?></th><td><?php swh_field( 'swh_em_user_merged_body', $defs, 'textarea' ); ?></td></tr>
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
				<?php
				$spam_method    = get_option( 'swh_spam_method', 'none' );
				$recaptcha_type = get_option( 'swh_recaptcha_type', 'v2' );
				$is_enterprise  = ( 'enterprise' === $recaptcha_type );
				?>
				<table class="form-table">
					<tr>
						<th scope="row"><?php esc_html_e( 'Spam Prevention', 'simple-wp-helpdesk' ); ?></th>
						<td>
							<select name="swh_spam_method" id="swh_spam_method">
								<option value="none" <?php selected( $spam_method, 'none' ); ?>><?php esc_html_e( 'None', 'simple-wp-helpdesk' ); ?></option>
								<option value="honeypot" <?php selected( $spam_method, 'honeypot' ); ?>><?php esc_html_e( 'Honeypot', 'simple-wp-helpdesk' ); ?></option>
								<option value="recaptcha" <?php selected( $spam_method, 'recaptcha' ); ?>><?php esc_html_e( 'Google reCAPTCHA', 'simple-wp-helpdesk' ); ?></option>
								<option value="turnstile" <?php selected( $spam_method, 'turnstile' ); ?>><?php esc_html_e( 'Cloudflare Turnstile', 'simple-wp-helpdesk' ); ?></option>
							</select>
						</td>
					</tr>
					<tr class="swh-recaptcha-row">
						<th scope="row"><?php esc_html_e( 'reCAPTCHA Type', 'simple-wp-helpdesk' ); ?></th>
						<td>
							<label style="margin-right:15px;"><input type="radio" name="swh_recaptcha_type" value="v2" <?php checked( $recaptcha_type, 'v2' ); ?>> <?php esc_html_e( 'v2 (Checkbox)', 'simple-wp-helpdesk' ); ?></label>
							<label><input type="radio" name="swh_recaptcha_type" value="enterprise" <?php checked( $recaptcha_type, 'enterprise' ); ?>> <?php esc_html_e( 'Enterprise (Score-based)', 'simple-wp-helpdesk' ); ?></label>
						</td>
					</tr>
					<tr class="swh-recaptcha-row">
						<th scope="row"><?php esc_html_e( 'reCAPTCHA Site Key', 'simple-wp-helpdesk' ); ?></th>
						<td><input type="text" name="swh_recaptcha_site_key" value="<?php echo esc_attr( swh_get_string_option( 'swh_recaptcha_site_key' ) ); ?>" class="regular-text"></td>
					</tr>
					<tr class="swh-recaptcha-row swh-recaptcha-v2-row" <?php echo $is_enterprise ? 'style="display:none;"' : ''; ?>>
						<th scope="row"><?php esc_html_e( 'reCAPTCHA Secret Key', 'simple-wp-helpdesk' ); ?></th>
						<td><input type="text" name="swh_recaptcha_secret_key" value="<?php echo esc_attr( swh_get_string_option( 'swh_recaptcha_secret_key' ) ); ?>" class="regular-text"></td>
					</tr>
					<tr class="swh-recaptcha-row swh-recaptcha-enterprise-row" <?php echo $is_enterprise ? '' : 'style="display:none;"'; ?>>
						<th scope="row"><?php esc_html_e( 'reCAPTCHA Project ID', 'simple-wp-helpdesk' ); ?></th>
						<td><input type="text" name="swh_recaptcha_project_id" value="<?php echo esc_attr( swh_get_string_option( 'swh_recaptcha_project_id' ) ); ?>" class="regular-text"></td>
					</tr>
					<tr class="swh-recaptcha-row swh-recaptcha-enterprise-row" <?php echo $is_enterprise ? '' : 'style="display:none;"'; ?>>
						<th scope="row"><?php esc_html_e( 'reCAPTCHA API Key', 'simple-wp-helpdesk' ); ?></th>
						<td><input type="text" name="swh_recaptcha_api_key" value="<?php echo esc_attr( swh_get_string_option( 'swh_recaptcha_api_key' ) ); ?>" class="regular-text"></td>
					</tr>
					<tr class="swh-recaptcha-row swh-recaptcha-enterprise-row" <?php echo $is_enterprise ? '' : 'style="display:none;"'; ?>>
						<th scope="row"><?php esc_html_e( 'Enterprise Score Threshold', 'simple-wp-helpdesk' ); ?></th>
						<td>
							<input type="number" name="swh_recaptcha_threshold" value="<?php echo esc_attr( swh_get_string_option( 'swh_recaptcha_threshold', '0.5' ) ); ?>" class="small-text" min="0" max="1" step="0.1">
							<p class="description"><?php esc_html_e( 'Submissions with a score below this threshold are flagged as spam (0.0 = likely bot, 1.0 = likely human).', 'simple-wp-helpdesk' ); ?></p>
						</td>
					</tr>
					<tr><th scope="row"><?php esc_html_e( 'Turnstile Site Key', 'simple-wp-helpdesk' ); ?></th><td><input type="text" name="swh_turnstile_site_key" value="<?php echo esc_attr( swh_get_string_option( 'swh_turnstile_site_key' ) ); ?>" class="regular-text"></td></tr>
					<tr><th scope="row"><?php esc_html_e( 'Turnstile Secret Key', 'simple-wp-helpdesk' ); ?></th><td><input type="text" name="swh_turnstile_secret_key" value="<?php echo esc_attr( swh_get_string_option( 'swh_turnstile_secret_key' ) ); ?>" class="regular-text"></td></tr>
				</table>
				<script>
				(function() {
					function swhUpdateSpamRows() {
						var method = document.getElementById('swh_spam_method').value;
						document.querySelectorAll('.swh-recaptcha-row').forEach(function(row) {
							row.style.display = ( 'recaptcha' === method ) ? '' : 'none';
						});
						if ( 'recaptcha' === method ) {
							swhUpdateRecaptchaType();
						}
					}
					function swhUpdateRecaptchaType() {
						var isEnterprise = document.querySelector('input[name="swh_recaptcha_type"]:checked') &&
							document.querySelector('input[name="swh_recaptcha_type"]:checked').value === 'enterprise';
						document.querySelectorAll('.swh-recaptcha-enterprise-row').forEach(function(row) {
							row.style.display = isEnterprise ? '' : 'none';
						});
						document.querySelectorAll('.swh-recaptcha-v2-row').forEach(function(row) {
							row.style.display = isEnterprise ? 'none' : '';
						});
					}
					document.getElementById('swh_spam_method').addEventListener('change', swhUpdateSpamRows);
					document.querySelectorAll('input[name="swh_recaptcha_type"]').forEach(function(radio) {
						radio.addEventListener('change', swhUpdateRecaptchaType);
					});
					swhUpdateSpamRows();
				})();
				</script>
			</div>
			<div id="tab-canned" class="swh-tab-content" role="tabpanel" aria-labelledby="swh-tab-canned" tabindex="0" style="display:none;">
				<p class="description"><?php esc_html_e( 'Pre-written reply templates. Select one in the ticket editor to insert it into the reply field.', 'simple-wp-helpdesk' ); ?></p>
				<div id="swh-canned-list">
				<?php
				$canned_responses = get_option( 'swh_canned_responses', array() );
				if ( ! is_array( $canned_responses ) ) {
					$canned_responses = array();
				}
				foreach ( $canned_responses as $canned_item ) :
					?>
					<div class="swh-canned-item" style="display:flex; gap:10px; align-items:flex-start; margin-bottom:10px; background:#f9f9f9; padding:10px; border:1px solid #ddd; border-radius:4px;">
						<div style="flex:1;">
							<input type="text" name="swh_canned_titles[]" value="<?php echo esc_attr( is_array( $canned_item ) && isset( $canned_item['title'] ) && is_string( $canned_item['title'] ) ? $canned_item['title'] : '' ); ?>" class="regular-text" placeholder="<?php esc_attr_e( 'Response title…', 'simple-wp-helpdesk' ); ?>" aria-label="<?php esc_attr_e( 'Canned response title', 'simple-wp-helpdesk' ); ?>" style="width:100%; margin-bottom:6px;">
							<textarea name="swh_canned_bodies[]" rows="3" class="large-text" aria-label="<?php esc_attr_e( 'Canned response body', 'simple-wp-helpdesk' ); ?>" style="width:100%;"><?php echo esc_textarea( is_array( $canned_item ) && isset( $canned_item['body'] ) && is_string( $canned_item['body'] ) ? $canned_item['body'] : '' ); ?></textarea>
						</div>
						<div>
							<button type="button" class="button swh-remove-canned"><?php esc_html_e( 'Remove', 'simple-wp-helpdesk' ); ?></button>
						</div>
					</div>
				<?php endforeach; ?>
				</div>
				<p><button type="button" id="swh-add-canned" class="button"><?php esc_html_e( '+ Add Response', 'simple-wp-helpdesk' ); ?></button></p>
			</div>

			<div id="tab-templates" class="swh-tab-content" role="tabpanel" aria-labelledby="swh-tab-templates" tabindex="0" style="display:none;">
				<p class="description"><?php esc_html_e( 'Pre-configured submission types that pre-fill the ticket description on the frontend form. Clients select a "Request Type" to load the matching template.', 'simple-wp-helpdesk' ); ?></p>
				<div id="swh-tmpl-list">
				<?php
				$ticket_templates = get_option( 'swh_ticket_templates', array() );
				if ( ! is_array( $ticket_templates ) ) {
					$ticket_templates = array();
				}
				foreach ( $ticket_templates as $tmpl_item ) :
					?>
					<div class="swh-tmpl-item" style="display:flex; gap:10px; align-items:flex-start; margin-bottom:10px; background:#f9f9f9; padding:10px; border:1px solid #ddd; border-radius:4px;">
						<div style="flex:1;">
							<input type="text" name="swh_tmpl_labels[]" value="<?php echo esc_attr( is_array( $tmpl_item ) && isset( $tmpl_item['label'] ) && is_string( $tmpl_item['label'] ) ? $tmpl_item['label'] : '' ); ?>" class="regular-text" placeholder="<?php esc_attr_e( 'Request type label…', 'simple-wp-helpdesk' ); ?>" aria-label="<?php esc_attr_e( 'Template label', 'simple-wp-helpdesk' ); ?>" style="width:100%; margin-bottom:6px;">
							<textarea name="swh_tmpl_bodies[]" rows="4" class="large-text" aria-label="<?php esc_attr_e( 'Template description', 'simple-wp-helpdesk' ); ?>" style="width:100%;" placeholder="<?php esc_attr_e( 'Description template text…', 'simple-wp-helpdesk' ); ?>"><?php echo esc_textarea( is_array( $tmpl_item ) && isset( $tmpl_item['body'] ) && is_string( $tmpl_item['body'] ) ? $tmpl_item['body'] : '' ); ?></textarea>
						</div>
						<div>
							<button type="button" class="button swh-remove-tmpl"><?php esc_html_e( 'Remove', 'simple-wp-helpdesk' ); ?></button>
						</div>
					</div>
				<?php endforeach; ?>
				</div>
				<p><button type="button" id="swh-add-tmpl" class="button"><?php esc_html_e( '+ Add Template', 'simple-wp-helpdesk' ); ?></button></p>
				<script>
				(function() {
					var tmplI18n = {
						labelPlaceholder: <?php echo wp_json_encode( __( 'Request type label…', 'simple-wp-helpdesk' ) ); ?>,
						labelAriaLabel:   <?php echo wp_json_encode( __( 'Template label', 'simple-wp-helpdesk' ) ); ?>,
						bodyAriaLabel:    <?php echo wp_json_encode( __( 'Template description', 'simple-wp-helpdesk' ) ); ?>,
						bodyPlaceholder:  <?php echo wp_json_encode( __( 'Description template text…', 'simple-wp-helpdesk' ) ); ?>,
						removeLabel:      <?php echo wp_json_encode( __( 'Remove', 'simple-wp-helpdesk' ) ); ?>,
					};
					function swhCreateTmplItem() {
						var outer = document.createElement('div');
						outer.className = 'swh-tmpl-item';
						outer.style.cssText = 'display:flex; gap:10px; align-items:flex-start; margin-bottom:10px; background:#f9f9f9; padding:10px; border:1px solid #ddd; border-radius:4px;';
						var inner = document.createElement('div');
						inner.style.flex = '1';
						var lbl = document.createElement('input');
						lbl.type = 'text';
						lbl.name = 'swh_tmpl_labels[]';
						lbl.className = 'regular-text';
						lbl.placeholder = tmplI18n.labelPlaceholder;
						lbl.setAttribute('aria-label', tmplI18n.labelAriaLabel);
						lbl.style.cssText = 'width:100%; margin-bottom:6px;';
						var body = document.createElement('textarea');
						body.name = 'swh_tmpl_bodies[]';
						body.rows = 4;
						body.className = 'large-text';
						body.setAttribute('aria-label', tmplI18n.bodyAriaLabel);
						body.style.width = '100%';
						body.placeholder = tmplI18n.bodyPlaceholder;
						inner.appendChild(lbl);
						inner.appendChild(body);
						var btnWrap = document.createElement('div');
						var btn = document.createElement('button');
						btn.type = 'button';
						btn.className = 'button swh-remove-tmpl';
						btn.textContent = tmplI18n.removeLabel;
						btnWrap.appendChild(btn);
						outer.appendChild(inner);
						outer.appendChild(btnWrap);
						return outer;
					}
					document.getElementById('swh-add-tmpl').addEventListener('click', function() {
						document.getElementById('swh-tmpl-list').appendChild(swhCreateTmplItem());
					});
					document.addEventListener('click', function(e) {
						if ( e.target && e.target.classList.contains('swh-remove-tmpl') ) {
							e.target.closest('.swh-tmpl-item').remove();
						}
					});
				})();
				</script>
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
							<input type="number" name="swh_retention_attachments_days" value="<?php echo esc_attr( swh_get_string_option( 'swh_retention_attachments_days', '0' ) ); ?>" style="width:80px;"> <?php esc_html_e( 'days', 'simple-wp-helpdesk' ); ?>
							<p class="description"><?php esc_html_e( 'Automatically delete physical file attachments older than this many days to save server space. Links to the files will be safely removed from the ticket. Set to 0 to disable.', 'simple-wp-helpdesk' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Purge Old Tickets', 'simple-wp-helpdesk' ); ?></th>
						<td>
							<input type="number" name="swh_retention_tickets_days" value="<?php echo esc_attr( swh_get_string_option( 'swh_retention_tickets_days', '0' ) ); ?>" style="width:80px;"> <?php esc_html_e( 'days', 'simple-wp-helpdesk' ); ?>
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

// ==============================================================================
// AJAX: SEND TEST EMAIL
// ==============================================================================

add_action( 'wp_ajax_swh_send_test_email', 'swh_ajax_send_test_email' );
/**
 * Handles the AJAX "Send Test Email" request from the Settings → Email Templates tab.
 *
 * Sends a sample "New Ticket" notification to the requesting admin's own email address
 * (falls back to the site admin_email option) so the current administrator can verify
 * their email configuration is working.
 *
 * @since 3.1.0
 * @return void
 */
function swh_ajax_send_test_email() {
	check_ajax_referer( 'swh_test_email_nonce', 'nonce' );
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( array( 'message' => __( 'Permission denied.', 'simple-wp-helpdesk' ) ), 403 );
	}
	$current_user = wp_get_current_user();
	$admin_email  = ( $current_user instanceof WP_User && is_email( $current_user->user_email ) )
		? $current_user->user_email
		: '';
	if ( ! $admin_email ) {
		$admin_email_raw = get_option( 'admin_email', '' );
		$admin_email     = is_string( $admin_email_raw ) ? $admin_email_raw : '';
	}
	if ( ! is_email( $admin_email ) ) {
		wp_send_json_error( array( 'message' => __( 'No valid email address found for your account.', 'simple-wp-helpdesk' ) ) );
	}
	$defs      = swh_get_defaults();
	$test_data = array(
		'name'           => __( 'Test Client', 'simple-wp-helpdesk' ),
		'email'          => $admin_email,
		'ticket_id'      => 'TKT-0001',
		'title'          => __( 'Test Ticket — email configuration check', 'simple-wp-helpdesk' ),
		'status'         => swh_get_string_option( 'swh_default_status', is_string( $defs['swh_default_status'] ) ? $defs['swh_default_status'] : 'Open' ),
		'priority'       => swh_get_string_option( 'swh_default_priority', is_string( $defs['swh_default_priority'] ) ? $defs['swh_default_priority'] : 'Medium' ),
		'message'        => __( 'This is a test message sent from Simple WP Helpdesk settings to verify email delivery.', 'simple-wp-helpdesk' ),
		'ticket_url'     => home_url( '/' ),
		'admin_url'      => admin_url( 'edit.php?post_type=helpdesk_ticket' ),
		'autoclose_days' => swh_get_int_option( 'swh_autoclose_days', 3 ),
	);
	$result    = swh_send_email( $admin_email, 'swh_em_admin_new_sub', 'swh_em_admin_new_body', $test_data );
	if ( $result ) {
		/* translators: %s: recipient email address */
		wp_send_json_success( array( 'message' => sprintf( __( 'Test email sent to %s.', 'simple-wp-helpdesk' ), $admin_email ) ) );
	} else {
		wp_send_json_error( array( 'message' => __( 'Email dispatch failed. Check your server mail configuration.', 'simple-wp-helpdesk' ) ) );
	}
}
