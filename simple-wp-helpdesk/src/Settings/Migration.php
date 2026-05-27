<?php
declare(strict_types=1);

namespace SWH\Settings;

use function get_option;
use function update_option;
use function version_compare;

/**
 * One-shot settings schema migration from flat `swh_<key>` options to
 * grouped `swh_settings_<group>` arrays. Idempotent via `swh_db_version`.
 *
 * --- Task 3.1 audit findings (2026-05-21) ---
 *
 * portal domain:
 *   No direct get_option('swh_portal_*'/'swh_lookup_*'/'swh_csat_*') call sites
 *   found in admin/includes/frontend. However, 'swh_portal_theme' IS defined
 *   in swh_get_defaults() — migrated as portal.portal_theme.
 *
 * notifications domain:
 *   No direct get_option call sites. No matching keys in swh_get_defaults().
 *   Group NOT seeded — no empty bag created (YAGNI per plan anti-pattern rule).
 *
 * integrations domain:
 *   No direct get_option('swh_inbound_*'/'swh_outbound_*'/'swh_webhook_*') call
 *   sites. However, 'swh_inbound_secret' IS defined in swh_get_defaults() —
 *   migrated as integrations.inbound_secret.
 *
 * --- Rollback policy ---
 *
 * Legacy flat options (swh_<key>) are NOT deleted. They remain as dead reads so
 * a v3.7 rollback still functions. Forward-only; destructive cleanup is future work.
 *
 * @since 4.0.0
 * @see   issue #356
 */
final class Migration {

	private const TARGET_VERSION = '4.1.0';

	/**
	 * Net-new grouped option defaults introduced after the legacy MAP.
	 *
	 * Unlike MAP entries (which migrate a legacy flat option into a group),
	 * these are options that were born grouped — they have no v3.x flat-key
	 * predecessor. The migration seeds them on the v4.0 → v4.1 step so consumer
	 * code can call `swh_get_option( $group, $key )` without a defensive
	 * default at every call site.
	 *
	 * Shape: [ group => [ key => default_value ] ]. Defaults are written ONLY
	 * if the bag does not already contain the key — never overwrites operator
	 * state.
	 *
	 * @since 4.1.0 — `integrations.api_keys` for issue #365 (REST API surface).
	 * @var array<string, array<string, mixed>>
	 */
	private const GROUP_DEFAULTS = array(
		// Issue #365 (v4.1.0): API keys live INSIDE swh_settings_integrations.
		// ApiKeys is the only writer; operator UI ships in v4.1 Phase 6.
		'integrations' => array(
			'api_keys' => array(),
		),
	);

	/**
	 * Map of legacy flat option name → [ group, key ] in the new schema.
	 *
	 * Derived from swh_get_defaults() (helpers.php:19-121) cross-referenced with
	 * swh_get_option() call-site groups across admin/, includes/, and frontend/.
	 *
	 * SLA-specific options (swh_sla_warn_hours, swh_sla_breach_hours,
	 * swh_sla_notify_email) are migrated to the `tools` group below.
	 *
	 * Installer version flags (swh_comment_type_v2, swh_tech_caps_v2) are
	 * intentionally NOT migrated — they're internal idempotency markers set
	 * by class-installer.php at activation/upgrade time, never user-facing
	 * settings. They remain as flat top-level options.
	 *
	 * @var array<string, array{0:string,1:string}>
	 */
	private const MAP = array(
		// General group.
		'swh_default_status'             => array( 'general', 'default_status' ),
		'swh_resolved_status'            => array( 'general', 'resolved_status' ),
		'swh_closed_status'              => array( 'general', 'closed_status' ),
		'swh_reopened_status'            => array( 'general', 'reopened_status' ),
		'swh_ticket_statuses'            => array( 'general', 'ticket_statuses' ),
		'swh_default_priority'           => array( 'general', 'default_priority' ),
		'swh_ticket_priorities'          => array( 'general', 'ticket_priorities' ),
		'swh_autoclose_days'             => array( 'general', 'autoclose_days' ),
		'swh_max_upload_size'            => array( 'general', 'max_upload_size' ),
		'swh_max_upload_count'           => array( 'general', 'max_upload_count' ),
		// General: user-facing messages.
		'swh_msg_success_new'            => array( 'general', 'msg_success_new' ),
		'swh_msg_success_reply'          => array( 'general', 'msg_success_reply' ),
		'swh_msg_success_reopen'         => array( 'general', 'msg_success_reopen' ),
		'swh_msg_success_closed'         => array( 'general', 'msg_success_closed' ),
		'swh_msg_err_spam'               => array( 'general', 'msg_err_spam' ),
		'swh_msg_err_missing'            => array( 'general', 'msg_err_missing' ),
		'swh_msg_err_invalid'            => array( 'general', 'msg_err_invalid' ),
		'swh_msg_err_expired'            => array( 'general', 'msg_err_expired' ),
		'swh_msg_success_lookup'         => array( 'general', 'msg_success_lookup' ),

		// Email group.
		'swh_email_format'               => array( 'email', 'email_format' ),
		'swh_email_logo_url'             => array( 'email', 'email_logo_url' ),
		// Email templates — user-facing.
		'swh_em_user_new_sub'            => array( 'email', 'em_user_new_sub' ),
		'swh_em_user_new_body'           => array( 'email', 'em_user_new_body' ),
		'swh_em_user_reply_sub'          => array( 'email', 'em_user_reply_sub' ),
		'swh_em_user_reply_body'         => array( 'email', 'em_user_reply_body' ),
		'swh_em_user_status_sub'         => array( 'email', 'em_user_status_sub' ),
		'swh_em_user_status_body'        => array( 'email', 'em_user_status_body' ),
		'swh_em_user_reply_status_sub'   => array( 'email', 'em_user_reply_status_sub' ),
		'swh_em_user_reply_status_body'  => array( 'email', 'em_user_reply_status_body' ),
		'swh_em_user_resolved_sub'       => array( 'email', 'em_user_resolved_sub' ),
		'swh_em_user_resolved_body'      => array( 'email', 'em_user_resolved_body' ),
		'swh_em_user_reopen_sub'         => array( 'email', 'em_user_reopen_sub' ),
		'swh_em_user_reopen_body'        => array( 'email', 'em_user_reopen_body' ),
		'swh_em_user_autoclose_sub'      => array( 'email', 'em_user_autoclose_sub' ),
		'swh_em_user_autoclose_body'     => array( 'email', 'em_user_autoclose_body' ),
		'swh_em_user_closed_sub'         => array( 'email', 'em_user_closed_sub' ),
		'swh_em_user_closed_body'        => array( 'email', 'em_user_closed_body' ),
		'swh_em_user_lookup_sub'         => array( 'email', 'em_user_lookup_sub' ),
		'swh_em_user_lookup_body'        => array( 'email', 'em_user_lookup_body' ),
		'swh_em_user_merged_sub'         => array( 'email', 'em_user_merged_sub' ),
		'swh_em_user_merged_body'        => array( 'email', 'em_user_merged_body' ),
		// Email templates — admin-facing.
		'swh_em_admin_new_sub'           => array( 'email', 'em_admin_new_sub' ),
		'swh_em_admin_new_body'          => array( 'email', 'em_admin_new_body' ),
		'swh_em_admin_reply_sub'         => array( 'email', 'em_admin_reply_sub' ),
		'swh_em_admin_reply_body'        => array( 'email', 'em_admin_reply_body' ),
		'swh_em_admin_reopen_sub'        => array( 'email', 'em_admin_reopen_sub' ),
		'swh_em_admin_reopen_body'       => array( 'email', 'em_admin_reopen_body' ),
		'swh_em_admin_closed_sub'        => array( 'email', 'em_admin_closed_sub' ),
		'swh_em_admin_closed_body'       => array( 'email', 'em_admin_closed_body' ),
		'swh_em_assigned_sub'            => array( 'email', 'em_assigned_sub' ),
		'swh_em_assigned_body'           => array( 'email', 'em_assigned_body' ),
		'swh_em_admin_sla_breach_sub'    => array( 'email', 'em_admin_sla_breach_sub' ),
		'swh_em_admin_sla_breach_body'   => array( 'email', 'em_admin_sla_breach_body' ),

		// Portal group. Task 3.1: no direct get_option call sites; swh_portal_theme
		// exists in swh_get_defaults() — seeded here so the bag is populated.
		'swh_portal_theme'               => array( 'portal', 'portal_theme' ),
		'swh_admin_theme'                => array( 'portal', 'admin_theme' ),
		// CSAT settings (v4.0.3 #471) — operator controls for the post-close widget.
		'swh_csat_enabled'               => array( 'portal', 'csat_enabled' ),
		'swh_csat_prompt'                => array( 'portal', 'csat_prompt' ),
		'swh_csat_thanks'                => array( 'portal', 'csat_thanks' ),
		'swh_csat_skip_label'            => array( 'portal', 'csat_skip_label' ),

		// Tools group.
		'swh_spam_method'                => array( 'tools', 'spam_method' ),
		'swh_recaptcha_site_key'         => array( 'tools', 'recaptcha_site_key' ),
		'swh_recaptcha_secret_key'       => array( 'tools', 'recaptcha_secret_key' ),
		'swh_recaptcha_type'             => array( 'tools', 'recaptcha_type' ),
		'swh_recaptcha_project_id'       => array( 'tools', 'recaptcha_project_id' ),
		'swh_recaptcha_api_key'          => array( 'tools', 'recaptcha_api_key' ),
		'swh_recaptcha_threshold'        => array( 'tools', 'recaptcha_threshold' ),
		'swh_turnstile_site_key'         => array( 'tools', 'turnstile_site_key' ),
		'swh_turnstile_secret_key'       => array( 'tools', 'turnstile_secret_key' ),
		'swh_retention_attachments_days' => array( 'tools', 'retention_attachments_days' ),
		'swh_retention_tickets_days'     => array( 'tools', 'retention_tickets_days' ),
		'swh_delete_on_uninstall'        => array( 'tools', 'delete_on_uninstall' ),
		'swh_canned_responses'           => array( 'tools', 'canned_responses' ),
		'swh_ticket_templates'           => array( 'tools', 'ticket_templates' ),
		'swh_sla_warn_hours'             => array( 'tools', 'sla_warn_hours' ),
		'swh_sla_breach_hours'           => array( 'tools', 'sla_breach_hours' ),
		'swh_sla_notify_email'           => array( 'tools', 'sla_notify_email' ),

		// Routing group.
		'swh_default_assignee'           => array( 'routing', 'default_assignee' ),
		'swh_fallback_email'             => array( 'routing', 'fallback_email' ),
		'swh_ticket_page_id'             => array( 'routing', 'ticket_page_id' ),
		'swh_token_expiration_days'      => array( 'routing', 'token_expiration_days' ),
		'swh_restrict_to_assigned'       => array( 'routing', 'restrict_to_assigned' ),
		'swh_use_violet_theme'           => array( 'general', 'use_violet_theme' ),
		'swh_assignment_rules'           => array( 'routing', 'assignment_rules' ),

		// Integrations group. Task 3.1: no direct get_option call sites;
		// swh_inbound_secret exists in swh_get_defaults() — seeded here.
		'swh_inbound_secret'             => array( 'integrations', 'inbound_secret' ),

		// Notifications group: Task 3.1 audit found zero direct get_option call
		// sites AND no matching keys in swh_get_defaults() for notify/email-flag
		// keys. Group NOT seeded — no empty bag created per plan anti-pattern rule.
	);

	/**
	 * Run the migration. Idempotent — exits immediately if already at TARGET_VERSION.
	 *
	 * Called on `plugins_loaded` at priority 5 so grouped options are available
	 * before any other plugin code reads settings.
	 *
	 * @return void
	 */
	public function run(): void {
		$current = get_option( 'swh_db_version', '0' );
		if ( ! is_string( $current ) ) {
			$current = '0';
		}
		if ( version_compare( $current, self::TARGET_VERSION, '>=' ) ) {
			return;
		}

		$bags = array();
		foreach ( self::MAP as $flat_key => [ $group, $new_key ] ) {
			$value = get_option( $flat_key, null );
			if ( null === $value ) {
				continue; // Option not set — don't write null into the group bag.
			}
			$bags[ $group ]           ??= array();
			$bags[ $group ][ $new_key ] = $value;
		}

		// Fold net-new grouped defaults into the bags ALONGSIDE legacy-derived
		// values. Non-destructive: a default is applied only when the key is
		// not already set by the MAP loop AND not already present on a fresh
		// read of the existing option. This preserves operator state for sites
		// that previously stored a value via a code path other than the MAP.
		foreach ( self::GROUP_DEFAULTS as $group => $defaults ) {
			$existing = get_option( 'swh_settings_' . $group, array() );
			if ( ! is_array( $existing ) ) {
				$existing = array();
			}
			$bags[ $group ] ??= array();
			foreach ( $defaults as $key => $default_value ) {
				if ( array_key_exists( $key, $bags[ $group ] ) ) {
					continue; // Already populated from a legacy MAP entry.
				}
				if ( array_key_exists( $key, $existing ) ) {
					// Operator already has a stored value — keep it. Preserve
					// it in the bag we are about to write so we don't blow it
					// away with the other group writes.
					$bags[ $group ][ $key ] = $existing[ $key ];
					continue;
				}
				$bags[ $group ][ $key ] = $default_value;
			}
		}

		foreach ( $bags as $group => $bag ) {
			update_option( 'swh_settings_' . $group, $bag );
		}

		update_option( 'swh_db_version', self::TARGET_VERSION );
	}

	/**
	 * Look up the group + new-key tuple for a legacy flat option name.
	 *
	 * Used at write sites that received a flat-keyed POST field (e.g. the
	 * generic admin/class-settings.php save loop) to dispatch the write into
	 * `swh_set_option( $group, $new_key, $value )`. Returns null for flat keys
	 * that are NOT part of the migrated set (e.g. internal installer flags
	 * `swh_comment_type_v2` / `swh_tech_caps_v2`) — callers should fall back
	 * to `update_option()` for those.
	 *
	 * @param string $flat_key Legacy option name (e.g. 'swh_default_status').
	 * @return array{0:string,1:string}|null [ $group, $new_key ] or null if unmapped.
	 */
	public static function group_for( string $flat_key ): ?array {
		return self::MAP[ $flat_key ] ?? null;
	}
}
