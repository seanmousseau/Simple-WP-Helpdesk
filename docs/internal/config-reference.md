# Configuration Reference

**Audience:** operators and contributors.

## Source of truth

`swh_get_defaults()` at `simple-wp-helpdesk/includes/helpers.php:19-121`. This document reflects that function. **If they disagree, the function wins** — fix the doc.

Options are seeded on first admin page load (`add_option()` loop at `class-installer.php:112-114`). The static cache in `swh_get_defaults()` makes per-request reads cheap.

## Reading options correctly

Use `swh_get_option( $group, $key, $default )` (defined at `helpers.php:139-142`):

```php
$autoclose_days = swh_get_option( 'general', 'autoclose_days', 3 );
$assignee       = swh_get_option( 'routing', 'default_assignee' );
$rules          = swh_get_option( 'routing', 'assignment_rules', array() );
```

The `$key` argument is the option name **without** the `swh_` prefix. The `$group` is advisory in v3.7 (intentionally ignored — see invariant in `api-contract.md`); v4.0 (#356) will consume it for the schema split, so always pass the correct group at call time.

For typed reads use `swh_get_string_option()` / `swh_get_int_option()` (`helpers.php:494-509`) — both wrap `get_option()` with a `is_scalar` guard for PHPStan L9 compliance.

## Settings groups (v3.7+ taxonomy)

The group argument is one of: `general`, `email`, `portal`, `notifications`, `tools`, `routing`, `integrations`. The same option can belong to multiple groups conceptually — pass the group that matches the caller's context.

### General

| Option key | Default | Type | Admin UI tab | Purpose |
|---|---|---|---|---|
| `swh_ticket_priorities` | `Low, Medium, High` | comma-string | General | Comma-separated priority labels. Filter: `swh_ticket_priorities`. |
| `swh_default_priority` | `Medium` | string | General | Default priority for new tickets. |
| `swh_ticket_statuses` | `Open, In Progress, Resolved, Closed` | comma-string | General | Comma-separated status labels. Filter: `swh_ticket_statuses`. |
| `swh_default_status` | `Open` | string | General | Default status on new submission. |
| `swh_resolved_status` | `Resolved` | string | General | Status considered "resolved" — used by the auto-close cron. |
| `swh_closed_status` | `Closed` | string | General | Status considered "closed" — used by status transition logic. |
| `swh_reopened_status` | `Open` | string | General | Status set when a client reopens a closed ticket. |
| `swh_autoclose_days` | `3` | int | General | Days a `Resolved` ticket waits before auto-close. Filter: `swh_autoclose_threshold`. |
| `swh_max_upload_size` | `5` | int (MB) | General | Per-file upload size limit. |
| `swh_max_upload_count` | `5` | int | General | Per-submission upload count limit. |

### Assignment & Routing

| Option key | Default | Type | Admin UI tab | Purpose |
|---|---|---|---|---|
| `swh_default_assignee` | `''` | int (user id) or empty | Assignment & Routing | Fallback assignee when no rule matches. |
| `swh_fallback_email` | `''` | string (email) | Assignment & Routing | Email address that receives admin notifications when no assignee is set. |
| `swh_ticket_page_id` | `0` | int (post id) | Assignment & Routing | Page hosting `[helpdesk_portal]`. `0` falls back to `_ticket_url` post meta. |
| `swh_token_expiration_days` | `90` | int | Assignment & Routing | Portal token TTL in days. `0` disables expiration. |
| `swh_restrict_to_assigned` | `no` | `yes`/`no` | Assignment & Routing | Technicians see only assigned tickets in admin list and via `load-post.php`. See invariant #13. |
| `swh_assignment_rules` | `[]` | array of `{category_term_id, assignee_user_id}` | Assignment & Routing | First matching rule wins; falls back to `swh_default_assignee`. |

### Email Format

| Option key | Default | Type | Admin UI tab | Purpose |
|---|---|---|---|---|
| `swh_email_format` | `html` | `html`/`plain` | Email Templates | Send HTML-wrapped or plain emails. |
| `swh_email_logo_url` | `''` | string (URL) | Email Templates | Logo shown in the HTML email header. Falls back to `get_site_icon_url(48)`. |

### Anti-Spam

| Option key | Default | Type | Admin UI tab | Purpose |
|---|---|---|---|---|
| `swh_spam_method` | `honeypot` | `honeypot`/`recaptcha`/`turnstile` | Anti-Spam | Active anti-spam method. |
| `swh_recaptcha_site_key` | `''` | string | Anti-Spam | Site key. |
| `swh_recaptcha_secret_key` | `''` | string | Anti-Spam | Secret (v2 / v3). |
| `swh_recaptcha_type` | `v2` | `v2`/`enterprise` | Anti-Spam | reCAPTCHA flavor. |
| `swh_recaptcha_project_id` | `''` | string | Anti-Spam | Enterprise project ID. |
| `swh_recaptcha_api_key` | `''` | string | Anti-Spam | Enterprise API key. |
| `swh_recaptcha_threshold` | `0.5` | float-as-string | Anti-Spam | Enterprise score threshold (0.0 to 1.0). |
| `swh_turnstile_site_key` | `''` | string | Anti-Spam | Cloudflare Turnstile site key. |
| `swh_turnstile_secret_key` | `''` | string | Anti-Spam | Cloudflare Turnstile secret. |

Misconfigured credentials fail **closed** — see `security-model.md`.

### Data Retention & Tools

These belong to the **Tools** form (separate nonce — see `design-document.md` invariant #9).

| Option key | Default | Type | Admin UI tab | Purpose |
|---|---|---|---|---|
| `swh_retention_attachments_days` | `0` | int | Tools | Attachment retention window. `0` disables. |
| `swh_retention_tickets_days` | `0` | int | Tools | Ticket retention window. `0` disables. |
| `swh_delete_on_uninstall` | `no` | `yes`/`no` | Tools | When `yes`, uninstall wipes all options, posts, files, and the Technician role. |

### Email Templates

14 subject/body pairs. Templates support `{placeholder}` substitution and `{if key}…{endif key}` conditional blocks (see `swh_parse_template` filter).

| Option key | Default | Purpose |
|---|---|---|
| `swh_em_user_new_sub` / `_body` | (see `swh_get_defaults()`) | Sent to the client on new ticket creation. |
| `swh_em_user_reply_sub` / `_body` | … | Sent to client when staff replies. |
| `swh_em_user_status_sub` / `_body` | … | Sent to client on status-only change. |
| `swh_em_user_reply_status_sub` / `_body` | … | Sent to client on combined reply + status change. |
| `swh_em_user_resolved_sub` / `_body` | … | Sent to client when ticket is marked resolved. |
| `swh_em_user_reopen_sub` / `_body` | … | Sent to client when ticket is reopened. |
| `swh_em_user_autoclose_sub` / `_body` | … | Sent to client on auto-close. |
| `swh_em_user_closed_sub` / `_body` | … | Sent to client when they close their own ticket via the portal. |
| `swh_em_admin_new_sub` / `_body` | … | Sent to admin/assignee on new ticket. |
| `swh_em_admin_reply_sub` / `_body` | … | Sent to admin/assignee on client reply. |
| `swh_em_admin_reopen_sub` / `_body` | … | Sent to admin/assignee on client reopen. |
| `swh_em_admin_closed_sub` / `_body` | … | Sent to admin/assignee when client closes. |
| `swh_em_assigned_sub` / `_body` | … | Sent to the new assignee on assignment change. |
| `swh_em_user_lookup_sub` / `_body` | … | Sent in response to the lookup form (open tickets list). |
| `swh_em_admin_sla_breach_sub` / `_body` | … | Digest sent when SLA cron finds breaches. |
| `swh_em_user_merged_sub` / `_body` | … | Sent to source-ticket client when their ticket is merged into a target. |

**These defaults are operator-editable content and are intentionally not translated** — see `coding-guide.md` "i18n".

### Messages (client-facing strings)

| Option key | Default | Purpose |
|---|---|---|
| `swh_msg_success_new` | `Your ticket has been submitted successfully! …` | After new submission. |
| `swh_msg_success_reply` | `Your reply has been added.` | After portal reply. |
| `swh_msg_success_reopen` | `Your ticket has been successfully re-opened. …` | After portal reopen. |
| `swh_msg_success_closed` | `Your ticket has been successfully closed.` | After portal close. |
| `swh_msg_err_spam` | `Anti-spam verification failed. Please try again.` | When `swh_check_antispam()` rejects. |
| `swh_msg_err_missing` | `Please fill in all required fields.` | When required fields are missing. |
| `swh_msg_err_invalid` | `Invalid or expired ticket link.` | When token compare fails. |
| `swh_msg_err_expired` | `This ticket link has expired. …` | When `swh_is_token_expired()` returns true. |
| `swh_msg_success_lookup` | `If we have tickets on file for that email …` | Lookup form (deliberately ambiguous — no email enumeration). |

### Canned Responses / Templates

| Option key | Default | Type | Purpose |
|---|---|---|---|
| `swh_canned_responses` | `[]` | array | Saved reply templates available in the ticket editor. |
| `swh_ticket_templates` | `[]` | array | Pre-configured submission types with pre-filled descriptions. |

### SLA

| Option key | Default | Type | Purpose |
|---|---|---|---|
| `swh_sla_warn_hours` | `4` | string (numeric) | Warn threshold in hours. |
| `swh_sla_breach_hours` | `8` | string (numeric) | Breach threshold in hours. |
| `swh_sla_notify_email` | `''` | string (email) | Recipient of the SLA breach digest. |

### Integrations

| Option key | Default | Type | Purpose |
|---|---|---|---|
| `swh_inbound_secret` | `''` | string | Bearer token required by the inbound-email REST endpoint. Empty disables the endpoint (every call fails auth). |

### Frontend Portal Appearance

| Option key | Default | Type | Purpose |
|---|---|---|---|
| `swh_portal_theme` | `auto` | `auto`/`light` | `auto` follows `prefers-color-scheme`; `light` forces light mode via `data-swh-theme="light"` on `.swh-helpdesk-wrapper`. |

## Excluded from defaults but managed

| Option | Source of truth |
|---|---|
| `swh_db_version` | `class-installer.php:147`. Used by `swh_run_upgrade_routine()` to gate upgrade work. Not in `swh_get_all_option_keys()`. |
| `swh_comment_type_v2` | One-time migration flag (set at `class-installer.php:104`; deleted at uninstall). |
| `swh_tech_caps_v2` | One-time migration flag (set at `class-installer.php:176`; deleted at uninstall). |
| `swh_rl_<hash>` | Rate-limit locks. Bulk-deleted by `LIKE 'swh\_rl\_%'` at uninstall (`class-installer.php:228`). |

## Override pattern (wp-config constants)

`grep -nE "defined\(\s*'SWH_" simple-wp-helpdesk/` reveals only the bootstrap constants (`SWH_VERSION`, `SWH_PLUGIN_DIR`, `SWH_PLUGIN_URL`, `SWH_PLUGIN_FILE`, and the icon URL constants). **No setting can currently be overridden via `wp-config.php` constants.** All options are admin-UI only. If constant overrides are added (e.g. for the inbound secret), document them here and treat the change as MINOR.

## Adding a new option

1. Add the key with its default to `swh_get_defaults()` at `simple-wp-helpdesk/includes/helpers.php`.
2. Pick the correct group (`general`, `email`, `portal`, `notifications`, `tools`, `routing`, `integrations`). The group is advisory in v3.7 but consumed in v4.0.
3. Render and persist the field in the matching tab of `admin/class-settings.php`. If it is destructive, put it on the **Tools** form (separate nonce).
4. If a write path other than the settings form touches it, document that path in the PR.
5. Update this doc. Update `data-dictionary.md` if the new option is structured (array / JSON).
6. Add a Playwright section under `testing-guide.md` if the option has user-visible effect.

## Removed options

None recorded in the v3.x line. When an option is removed:

- Document it here with: removed in version, reason, migration notes.
- Add a `delete_option()` in `swh_run_upgrade_routine()`.
- Bump MAJOR per `api-contract.md`.

| Option | Removed in | Reason | Migration |
|---|---|---|---|
| _none yet_ | | | |

## Update protocol

Update this doc when:
- An option is added, removed, or has its default changed in `swh_get_defaults()` — keep in lock-step.
- A new settings group is introduced.
- A constant-override path is added.
- A migration removes or renames an option — record it in the "Removed options" table.
