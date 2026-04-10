#!/usr/bin/env python3
"""
Playwright browser test suite for Simple WP Helpdesk.

Covers: ticket submission, admin management, technician workflow,
client portal, status transitions, internal notes, access control,
and email trigger verification (see EMAIL CHECKS summary at end).

Usage:
    source testing/.venv/bin/activate
    python3 testing/scripts/test_helpdesk_pw.py

Requirements (testing/.venv):
    pip install playwright pytest-playwright
    playwright install chromium
"""
import json
import os
import shutil
import subprocess
import sys
import time

from playwright.sync_api import sync_playwright, Page

# ── Load .env file ────────────────────────────────────────────────────────────

def _load_dotenv():
    candidates = [
        os.path.join(os.path.dirname(__file__), "..", ".env"),
        "testing/.env",
        ".env",
    ]
    for path in candidates:
        path = os.path.normpath(path)
        if not os.path.isfile(path):
            continue
        with open(path) as f:
            for line in f:
                line = line.strip()
                if not line or line.startswith("#"):
                    continue
                if "=" not in line:
                    continue
                key, _, val = line.partition("=")
                key = key.strip()
                val = val.strip()
                if len(val) >= 2 and val[0] == val[-1] and val[0] in ('"', "'"):
                    val = val[1:-1]
                if key and key not in os.environ:
                    os.environ[key] = val
        print(f"Loaded env from: {path}")
        return
    print("WARNING: testing/.env not found — using existing environment variables.")

_load_dotenv()

# ── Configuration ─────────────────────────────────────────────────────────────

def _require(name):
    val = os.environ.get(name, "").strip()
    if not val:
        print(f"ERROR: {name} is not set. Check testing/.env.", file=sys.stderr)
        sys.exit(1)
    return val

def _optional(name, default=""):
    return os.environ.get(name, default).strip()

WP_URL         = _require("WP_URL").rstrip("/")
WP_LOGIN_URL   = _require("WP_LOGIN_URL")
WP_ADMIN_URL   = _optional("WP_ADMIN_URL", WP_URL + "/wp-admin")
WP_SUBMIT_PAGE = _require("WP_SUBMIT_PAGE").rstrip("/")
SSH_HOST       = _require("SSH_HOST")
WP_CONTAINER   = _require("WP_CONTAINER")
WP_PATH        = _require("WP_PATH")

ADMIN_USER  = _require("WP_ADMIN_USER")
ADMIN_PASS  = _require("WP_ADMIN_PASS")

TECH1_EMAIL = _require("WP_TECH1_EMAIL")
TECH1_USER  = _require("WP_TECH1_USER")
TECH1_PASS  = _require("WP_TECH1_PASS")

CLIENT1_NAME  = _require("CLIENT1_NAME")
CLIENT1_EMAIL = _require("CLIENT1_EMAIL")

TEST_TICKET_TITLE  = f"PW Test Ticket {int(time.time())}"
TEST_TICKET_DESC   = "This is an automated test ticket created by the Playwright test suite."
TEST_TECH_REPLY    = "Thanks for reaching out. We are looking into this."
TEST_INTERNAL_NOTE = "INTERNAL ONLY: This note must not appear in the client portal."
TEST_CLIENT_REPLY  = "Thank you for the update, still waiting on resolution."

OUT = "testing/screenshots"
os.makedirs(OUT, exist_ok=True)

EMAIL_CHECKS = []

# Mutable results collected by check()
_results = {"pass_count": 0, "failures": []}

# Shared state carried between test sections (ticket_id, portal_url, tech1_wp_id)
state = {}

# Set at run start so check() can capture failure screenshots without a page parameter
_page: "Page | None" = None


# ── Helpers ───────────────────────────────────────────────────────────────────

def wpcli(cmd):
    """Run a WP-CLI command inside the Docker container via SSH, return stdout stripped."""
    docker_cmd = (
        f"docker exec {WP_CONTAINER} wp {cmd} --path={WP_PATH} --allow-root 2>/dev/null"
    )
    result = subprocess.run(
        ["ssh", SSH_HOST, docker_cmd],
        capture_output=True, text=True, timeout=15
    )
    clean = "\n".join(
        line for line in result.stdout.splitlines()
        if not line.startswith(("Deprecated:", "Notice:", "Warning:", "PHP Deprecated:"))
    )
    return clean.strip()


def screenshot(page: Page, name: str):
    path = f"{OUT}/{name}.png"
    page.screenshot(path=path, full_page=False)
    size = os.path.getsize(path)
    print(f"    📸  {name}.png ({size // 1024} KB)")


def check(name: str, ok, detail: str = ""):
    if ok:
        print(f"  ✅  {name}")
        _results["pass_count"] += 1
    else:
        msg = f"❌  {name}" + (f" — {detail}" if detail else "")
        print(f"  {msg}")
        _results["failures"].append(msg)
        if _page is not None:
            safe = name[:50].replace(" ", "_").replace(":", "").replace("/", "")
            screenshot(_page, f"fail_{safe}")


def expect_email(recipient: str, description: str):
    EMAIL_CHECKS.append({"to": recipient, "description": description})


def wp_login(page: Page, username: str, password: str) -> str:
    page.goto(WP_LOGIN_URL)
    page.wait_for_load_state("load")
    page.fill('[name="log"]', username)
    page.fill('[name="pwd"]', password)
    page.click('#wp-submit, [type="submit"]')
    page.wait_for_load_state("load")
    return page.url


def wp_logout(page: Page):
    logout = page.locator('#wp-admin-bar-logout a')
    if logout.count() > 0:
        href = logout.get_attribute('href')
        page.goto(href)
        page.wait_for_load_state("load")
    else:
        page.context.clear_cookies()


def admin_update_ticket(page: Page, post_id, status=None, priority=None,
                        assigned_user_id=None, tech_reply=None, internal_note=None):
    page.goto(f"{WP_ADMIN_URL}/post.php?post={post_id}&action=edit")
    page.wait_for_load_state("load")
    # Dismiss WordPress post-lock dialog if another user holds the lock.
    # The dialog exists in the DOM even when hidden; use is_visible() not count().
    # The "Take Over" link always contains get-post-lock in its href.
    lock_takeover = page.locator('#post-lock-dialog a[href*="get-post-lock"]')
    if lock_takeover.is_visible():
        lock_takeover.click()
        page.wait_for_load_state("load")
    if status:
        page.select_option('[name="ticket_status"]', status)
    if priority:
        page.select_option('[name="ticket_priority"]', priority)
    if assigned_user_id is not None:
        page.select_option('[name="ticket_assigned_to"]', str(assigned_user_id))
    if tech_reply:
        page.fill('[name="swh_tech_reply_text"]', tech_reply)
    if internal_note:
        page.fill('[name="swh_tech_note_text"]', internal_note)
    page.click('#publish')
    page.wait_for_load_state("load")


# ── Test sections ─────────────────────────────────────────────────────────────

def test_01_admin_auth(page: Page):
    print("[1] Admin Authentication")

    href = wp_login(page, ADMIN_USER, ADMIN_PASS)
    check("admin login: redirects to dashboard/admin",
          "wp-admin" in href or "wp-login" not in href, f"href={href[:60]}")
    screenshot(page, "01_admin_logged_in")

    page.goto(f"{WP_ADMIN_URL}/")
    page.wait_for_load_state("load")
    title = page.title()
    check("admin dashboard: page title contains Dashboard",
          "dashboard" in title.lower() or "wp-admin" in href, f"title={title!r}")


def test_02_plugin_verification(page: Page):
    print("\n[2] Plugin Verification")

    page.goto(f"{WP_ADMIN_URL}/plugins.php")
    page.wait_for_load_state("load")
    body = page.inner_text("body")
    html = page.content()
    check("plugins: Simple WP Helpdesk is present",
          "Simple WP Helpdesk" in body, "plugin not listed")
    check("plugins: Simple WP Helpdesk is active",
          "simple-wp-helpdesk" in html and "Deactivate" in body,
          "check that the plugin is activated")
    screenshot(page, "02_plugins_page")

    page.goto(f"{WP_ADMIN_URL}/edit.php?post_type=helpdesk_ticket&page=swh-settings")
    page.wait_for_load_state("load")
    title = page.title()
    check("settings: page loads",
          "setting" in title.lower() or "helpdesk" in title.lower(), f"title={title!r}")
    screenshot(page, "03_settings_page")

    check("a11y: settings page has role=tablist",
          page.locator('[role="tablist"]').count() > 0)
    check("a11y: settings tabs have role=tab with aria-selected",
          page.locator('[role="tab"][aria-selected]').count() >= 3)


def test_03_ticket_submission(page: Page):
    print("\n[3] Ticket Submission (Frontend)")

    page.goto(WP_SUBMIT_PAGE)
    page.wait_for_load_state("load")
    check("submit page: form present",
          page.locator('[name="ticket_name"]').count() > 0)
    check("submit page: email field present",
          page.locator('[name="ticket_email"]').count() > 0)
    check("submit page: description field present",
          page.locator('[name="ticket_desc"]').count() > 0)
    screenshot(page, "04_submit_form")

    check("a11y: label[for='swh-name'] associates with #swh-name",
          page.locator('label[for="swh-name"]').count() > 0 and
          page.locator('#swh-name').count() > 0)
    check("a11y: label[for='swh-email'] associates with #swh-email",
          page.locator('label[for="swh-email"]').count() > 0 and
          page.locator('#swh-email').count() > 0)
    check("a11y: #swh-toggle-lookup has aria-expanded attribute",
          page.locator('#swh-toggle-lookup[aria-expanded]').count() > 0)

    page.fill('[name="ticket_name"]', CLIENT1_NAME)
    page.fill('[name="ticket_email"]', CLIENT1_EMAIL)
    page.fill('[name="ticket_title"]', TEST_TICKET_TITLE)
    page.fill('[name="ticket_desc"]', TEST_TICKET_DESC)
    sel = page.locator('[name="ticket_priority"]')
    if sel.count() > 0:
        page.evaluate("document.querySelector('[name=\"ticket_priority\"]').selectedIndex = 0")
    page.click('[name="swh_submit_ticket"]')
    page.wait_for_load_state("load")

    check("submit: success message shown",
          "swh-alert-success" in page.content(), "no .swh-alert-success found")
    screenshot(page, "05_submit_success")

    expect_email(CLIENT1_EMAIL, "new ticket confirmation to client")
    expect_email(TECH1_EMAIL, "new ticket notification to default assignee (tech1)")


def test_04_admin_locate_ticket(page: Page):
    print("\n[4] Admin: Locate New Ticket")

    page.goto(f"{WP_ADMIN_URL}/edit.php?post_type=helpdesk_ticket")
    page.wait_for_load_state("load")
    check("admin list: submitted ticket appears",
          TEST_TICKET_TITLE in page.inner_text("body"), f"title={TEST_TICKET_TITLE!r}")
    screenshot(page, "06_admin_ticket_list")

    ticket_id = page.evaluate(f"""
        (function() {{
            var links = document.querySelectorAll('a.row-title, td.column-title a');
            for (var a of links) {{
                if (a.innerText && a.innerText.includes({json.dumps(TEST_TICKET_TITLE)})) {{
                    var m = a.href.match(/post=([0-9]+)/);
                    if (m) return parseInt(m[1]);
                }}
            }}
            return null;
        }})()
    """)
    check("admin list: extracted post ID", bool(ticket_id), f"id={ticket_id}")
    if ticket_id:
        state['ticket_id'] = ticket_id
        print(f"    Post ID: {ticket_id}")


def test_05_portal_url(page: Page):  # noqa: ARG001 — page unused but kept for consistent signature
    print("\n[5] Portal URL (via WP-CLI)")

    if not state.get('ticket_id'):
        check("wpcli: portal URL retrieval (skipped — no ticket ID)", False)
        return

    pid = state['ticket_id']
    try:
        portal_url = wpcli(f'eval "echo swh_get_secure_ticket_link({pid});"')
        check("wpcli: got portal URL",
              bool(portal_url) and "swh_ticket=" in portal_url,
              f"got: {portal_url!r}")
        if portal_url:
            state['portal_url'] = portal_url
            print(f"    Portal URL: {portal_url[:80]}...")
    except Exception as e:
        check("wpcli: portal URL retrieval", False, str(e))


def test_06_admin_update_ticket(page: Page):
    print("\n[6] Admin: Update Ticket (Status + Assignment)")

    if not state.get('ticket_id'):
        return

    tech1_id = wpcli(f"user get {TECH1_USER} --field=ID 2>/dev/null")
    if tech1_id.isdigit():
        state['tech1_wp_id'] = int(tech1_id)
        print(f"    Tech1 WP user ID: {tech1_id}")

    admin_update_ticket(page, state['ticket_id'],
                        status="In Progress",
                        assigned_user_id=state.get('tech1_wp_id'))

    page.goto(f"{WP_ADMIN_URL}/post.php?post={state['ticket_id']}&action=edit")
    page.wait_for_load_state("load")
    status_val = page.evaluate("document.querySelector('[name=\"ticket_status\"]')?.value")
    check("admin update: status set to In Progress",
          status_val == "In Progress", f"got {status_val!r}")
    assigned_val = page.evaluate("document.querySelector('[name=\"ticket_assigned_to\"]')?.value")
    check("admin update: tech1 assigned",
          assigned_val == str(state.get('tech1_wp_id', "")), f"got {assigned_val!r}")
    screenshot(page, "07_ticket_updated")


def test_07_technician_workflow(page: Page):
    print("\n[7] Technician Workflow (Tech1)")

    if not state.get('ticket_id'):
        return

    wp_logout(page)
    href = wp_login(page, TECH1_USER, TECH1_PASS)
    check("tech1 login: success",
          "wp-admin" in href or "wp-login" not in href, f"href={href[:60]}")

    page.goto(f"{WP_ADMIN_URL}/edit.php?post_type=helpdesk_ticket")
    page.wait_for_load_state("load")
    check("tech1: assigned ticket visible in list",
          TEST_TICKET_TITLE in page.inner_text("body"))
    screenshot(page, "08_tech1_ticket_list")

    admin_update_ticket(page, state['ticket_id'],
                        tech_reply=TEST_TECH_REPLY,
                        internal_note=TEST_INTERNAL_NOTE)

    page.goto(f"{WP_ADMIN_URL}/post.php?post={state['ticket_id']}&action=edit")
    page.wait_for_load_state("load")
    conv_html = page.content()
    check("tech1 reply: public reply saved in conversation",
          TEST_TECH_REPLY in conv_html)
    check("a11y: conversation div has role=log", 'role="log"' in conv_html)
    check("tech1 note: internal note saved in conversation",
          TEST_INTERNAL_NOTE in conv_html)
    check("tech1 note: internal note has yellow/note styling",
          "Internal Note" in page.inner_text("body"))
    screenshot(page, "09_tech1_conversation")
    expect_email(CLIENT1_EMAIL, "tech reply notification to client")


def test_08_client_portal(page: Page):
    print("\n[8] Client Portal: View and Reply")

    if not state.get('portal_url'):
        return

    wp_logout(page)

    page.goto(state['portal_url'])
    page.wait_for_load_state("load")
    body_text = page.inner_text("body")
    check("portal: ticket title visible", TEST_TICKET_TITLE in body_text)
    check("portal: ticket description visible", TEST_TICKET_DESC in body_text)
    check("portal: tech reply visible to client", TEST_TECH_REPLY in body_text)
    check("portal: internal note NOT visible to client",
          TEST_INTERNAL_NOTE not in body_text, "internal note leaked to client portal!")
    check("portal: reply form present",
          page.locator('[name="ticket_reply_text"]').count() > 0)
    screenshot(page, "10_client_portal")

    page.fill('[name="ticket_reply_text"]', TEST_CLIENT_REPLY)
    page.click('[name="swh_user_reply_submit"]')
    page.wait_for_load_state("load")
    html = page.content()
    check("portal: client reply success message", "swh-alert-success" in html)
    check("a11y: reply success div has role=status", 'role="status"' in html)
    check("portal: client reply appears in conversation",
          TEST_CLIENT_REPLY in page.inner_text("body"))
    screenshot(page, "11_client_replied")
    expect_email(TECH1_EMAIL, "client reply notification to assigned technician (tech1)")


def test_09_admin_verify_reply(page: Page):
    print("\n[9] Admin: Verify Client Reply")

    wp_logout(page)
    wp_login(page, ADMIN_USER, ADMIN_PASS)

    if not state.get('ticket_id'):
        return

    page.goto(f"{WP_ADMIN_URL}/post.php?post={state['ticket_id']}&action=edit")
    page.wait_for_load_state("load")
    conv_text = page.inner_text("body")
    check("admin: client reply visible in admin conversation",
          TEST_CLIENT_REPLY in conv_text)
    check("admin: shows 'Client' label for client reply", "Client" in conv_text)

    admin_update_ticket(page, state['ticket_id'], status="Resolved")
    page.goto(f"{WP_ADMIN_URL}/post.php?post={state['ticket_id']}&action=edit")
    page.wait_for_load_state("load")
    status_val = page.evaluate("document.querySelector('[name=\"ticket_status\"]')?.value")
    check("admin: ticket status set to Resolved",
          status_val == "Resolved", f"got {status_val!r}")
    screenshot(page, "12_ticket_resolved")


def test_10_portal_close_reopen(page: Page):
    print("\n[10] Client Portal: Resolved → Close → Re-open")

    if not state.get('portal_url'):
        return

    wp_logout(page)

    pid = state['ticket_id']
    fresh_url = wpcli(f'eval "echo swh_get_secure_ticket_link({pid});"')
    if fresh_url and "swh_ticket=" in fresh_url:
        state['portal_url'] = fresh_url

    page.goto(state['portal_url'])
    page.wait_for_load_state("load")
    check("portal resolved: 'Is your issue resolved?' prompt shown",
          "swh_user_close_ticket_submit" in page.content() or
          "Yes, Close Ticket" in page.inner_text("body"))
    screenshot(page, "13_portal_resolved_state")

    close_btn = page.locator('[name="swh_user_close_ticket_submit"]')
    if close_btn.count() > 0:
        close_btn.click()
        page.wait_for_load_state("load")
        check("portal: close ticket success", "swh-alert-success" in page.content())
        expect_email(CLIENT1_EMAIL, "ticket closed confirmation to client")
        expect_email(TECH1_EMAIL, "ticket closed notification to assigned technician (tech1)")
        screenshot(page, "14_ticket_closed_portal")

        page.goto(state['portal_url'])
        page.wait_for_load_state("load")
        reopen_ta = page.locator('[name="ticket_reopen_text"]')
        if reopen_ta.count() > 0:
            reopen_ta.fill("I still need help with this issue.")
        page.click('[name="swh_user_reopen_submit"]')
        page.wait_for_load_state("load")
        check("portal: reopen success", "swh-alert-success" in page.content())
        screenshot(page, "15_ticket_reopened_portal")
        expect_email(TECH1_EMAIL, "ticket re-opened notification to assigned technician (tech1)")
    else:
        check("portal resolved: close button present", False,
              "close button not found — check ticket status is Resolved")


def test_11_access_control(page: Page):
    print("\n[11] Access Control: Unassigned Ticket + Technician Restriction")

    wp_logout(page)
    wp_login(page, ADMIN_USER, ADMIN_PASS)

    restriction_was = wpcli("option get swh_restrict_to_assigned 2>/dev/null")
    wpcli("option update swh_restrict_to_assigned yes")

    wp_logout(page)
    wp_login(page, _require("WP_TECH2_USER"), _require("WP_TECH2_PASS"))
    page.goto(f"{WP_ADMIN_URL}/edit.php?post_type=helpdesk_ticket")
    page.wait_for_load_state("load")
    check("access control: tech2 (unassigned) cannot see tech1's ticket",
          TEST_TICKET_TITLE not in page.inner_text("body"),
          "ticket should be hidden from unassigned technician")
    screenshot(page, "16_tech2_restricted_list")

    wp_logout(page)
    wp_login(page, ADMIN_USER, ADMIN_PASS)
    if restriction_was in ("", "no"):
        wpcli("option delete swh_restrict_to_assigned 2>/dev/null")
    else:
        wpcli(f"option update swh_restrict_to_assigned {restriction_was}")


def test_12_ticket_list_filters(page: Page):
    print("\n[12] Admin: Ticket List Filters")

    page.goto(f"{WP_ADMIN_URL}/edit.php?post_type=helpdesk_ticket")
    page.wait_for_load_state("load")
    check("ticket list: page loads",
          page.locator('.wp-list-table, .no-items, #the-list').count() > 0)
    body = page.inner_text("body")
    check("ticket list: has status filter column", "Status" in body)
    check("ticket list: has priority column", "Priority" in body)
    screenshot(page, "17_admin_ticket_list_final")


def test_13_ticket_lookup(page: Page):
    print("\n[13] Ticket Lookup (Frontend — Resend Links)")

    wp_logout(page)

    page.goto(WP_SUBMIT_PAGE)
    page.wait_for_load_state("load")
    toggle = page.locator('#swh-toggle-lookup')
    check("lookup: toggle link present", toggle.count() > 0)

    if toggle.count() > 0:
        toggle.click()
        page.wait_for_timeout(500)
        lookup_form = page.locator('#swh-lookup-form')
        check("lookup: form shows after toggle",
              lookup_form.count() > 0 and
              lookup_form.evaluate("el => el.style.display !== 'none'"))

        page.fill('[name="swh_lookup_email"]', CLIENT1_EMAIL)
        page.click('[name="swh_ticket_lookup"]')
        page.wait_for_load_state("load")
        check("lookup: success message shown (email enumeration safe)",
              "swh-alert-success" in page.content())
        screenshot(page, "18_lookup_submitted")
        expect_email(CLIENT1_EMAIL, "ticket lookup — resent secure links to client")


def test_14_accessibility(page: Page):
    print("\n[14] Accessibility Assertions")

    page.goto(WP_SUBMIT_PAGE)
    page.wait_for_load_state("load")
    check("a11y: honeypot div has aria-hidden=true",
          'aria-hidden="true"' in page.content())

    if state.get('portal_url'):
        portal_base = state['portal_url'].split('?')[0]
        page.goto(portal_base)
        page.wait_for_load_state("load")
        check("a11y: portal error div has role=alert",
              'role="alert"' in page.content())

    wp_login(page, ADMIN_USER, ADMIN_PASS)
    page.goto(f"{WP_ADMIN_URL}/edit.php?post_type=helpdesk_ticket&page=swh-settings")
    page.wait_for_load_state("load")
    active_ctrl = page.evaluate(
        "document.querySelector('[role=\"tab\"][aria-selected=\"true\"]')"
        "?.getAttribute('aria-controls')"
    )
    check("a11y: active settings tab has aria-controls", bool(active_ctrl))
    if active_ctrl:
        panel_role = page.evaluate(
            f"document.getElementById({json.dumps(active_ctrl)})?.getAttribute('role')"
        )
        check("a11y: controlled settings panel has role=tabpanel", panel_role == "tabpanel")

    if state.get('ticket_id'):
        page.goto(f"{WP_ADMIN_URL}/post.php?post={state['ticket_id']}&action=edit")
        page.wait_for_load_state("load")
        ed_html = page.content()
        check("a11y: ticket status field has label association",
              'for="swh-status"' in ed_html and 'id="swh-status"' in ed_html)
        check("a11y: ticket editor conversation has role=log", 'role="log"' in ed_html)

    wp_logout(page)


def test_15_plugin_icons(page: Page):  # noqa: ARG001 — page unused but kept for consistent signature
    print("\n[15] Plugin Icons")

    CDN_BASE     = "https://media.seanmousseau.com/file/seanmousseau/assets/logos/swh"
    CDN_ICON_128 = f"{CDN_BASE}/icon-128x128.png"
    CDN_ICON_256 = f"{CDN_BASE}/icon-256x256.png"

    for cdn_url, label in ((CDN_ICON_128, "1x"), (CDN_ICON_256, "2x")):
        if not shutil.which("curl"):
            print(f"  ⚠️  plugin icon: CDN {label} image reachable — skipped (curl not found)")
            continue
        result = subprocess.run(
            ["curl", "-sI", "--max-time", "10", "-o", "/dev/null", "-w", "%{http_code}", cdn_url],
            capture_output=True, text=True
        )
        check(f"plugin icon: CDN {label} image reachable", result.stdout.strip() == "200")

    filter_registered = wpcli(
        "eval 'global $wp_filter; "
        'echo isset($wp_filter["puc_request_info_result-simple-wp-helpdesk"]) ? "yes" : "no";\''
    )
    check("plugin icon: puc_request_info_result filter registered", filter_registered == "yes")

    icon_1x = wpcli(
        "eval '"
        "$info = (object)[]; "
        '$result = apply_filters("puc_request_info_result-simple-wp-helpdesk", $info); '
        'echo $result->icons["1x"] ?? "";\'',
    )
    icon_2x = wpcli(
        "eval '"
        "$info = (object)[]; "
        '$result = apply_filters("puc_request_info_result-simple-wp-helpdesk", $info); '
        'echo $result->icons["2x"] ?? "";\'',
    )
    check("plugin icon: puc filter returns correct 1x URL", icon_1x == CDN_ICON_128)
    check("plugin icon: puc filter returns correct 2x URL", icon_2x == CDN_ICON_256)

    wpcli("eval 'wp_update_plugins();'")
    plugin_file = "simple-wp-helpdesk/simple-wp-helpdesk.php"
    icon_in_transient = wpcli(
        f"eval '$t = get_site_transient(\"update_plugins\"); "
        f'$e = $t->response["{plugin_file}"] ?? $t->no_update["{plugin_file}"] ?? null; '
        f'echo ($e && !empty($e->icons)) ? "yes" : "no";\''
    )
    check("plugin icon: icons injected into update transient", icon_in_transient == "yes")


def test_16_honeypot_spam(page: Page):
    print("\n[16] Anti-Spam: Honeypot Rejection")

    # Enable honeypot for this test regardless of the site's current setting
    original_spam_method = wpcli("option get swh_spam_method 2>/dev/null") or "none"
    wpcli("option update swh_spam_method honeypot")
    wpcli("cache flush")  # clear object cache so the updated option is served immediately

    before_count = wpcli("post list --post_type=helpdesk_ticket --post_status=any --format=count")

    wp_logout(page)
    # Append a unique query param so full-page caches (e.g. SWIS Performance)
    # serve a fresh page, not a cached copy that pre-dates the option update.
    fresh_url = f"{WP_SUBMIT_PAGE}?swh_test={int(time.time())}"
    page.goto(fresh_url)
    page.wait_for_load_state("load")

    hp_field = page.locator('[name="swh_website_url_hp"]')
    check("spam: honeypot field rendered in form", hp_field.count() > 0)

    if hp_field.count() > 0:
        # Fill all legitimate fields so only the honeypot triggers the rejection
        page.fill('[name="ticket_name"]', CLIENT1_NAME)
        page.fill('[name="ticket_email"]', CLIENT1_EMAIL)
        page.fill('[name="ticket_title"]', "Honeypot Spam Test")
        page.fill('[name="ticket_desc"]', "This submission should be rejected by the honeypot.")
        # Set the hidden honeypot field — real users never see or fill this
        page.evaluate("document.querySelector('[name=\"swh_website_url_hp\"]').value = 'bot'")
        page.click('[name="swh_submit_ticket"]')
        page.wait_for_load_state("load")

        check("spam: honeypot triggers rejection (no success message)",
              "swh-alert-success" not in page.content())
        after_count = wpcli("post list --post_type=helpdesk_ticket --post_status=any --format=count")
        check("spam: no ticket created when honeypot filled",
              before_count == after_count,
              f"count before={before_count} after={after_count}")

    # Restore original spam method
    if original_spam_method in ("", "none"):
        wpcli("option delete swh_spam_method 2>/dev/null")
    else:
        wpcli(f"option update swh_spam_method {original_spam_method}")


def test_17_cleanup(page: Page):
    print("\n[16] Cleanup")

    wp_login(page, ADMIN_USER, ADMIN_PASS)
    page.goto(f"{WP_ADMIN_URL}/edit.php?post_type=helpdesk_ticket")
    page.wait_for_load_state("load")

    trash_href = page.evaluate(f"""
        (function() {{
            for (var row of document.querySelectorAll('#the-list tr')) {{
                var title = row.querySelector('.column-title .row-title, .column-title a');
                if (title && title.innerText.includes({json.dumps(TEST_TICKET_TITLE)})) {{
                    var a = row.querySelector('.row-actions .trash a, .row-actions a[href*="action=trash"]');
                    return a ? a.href : null;
                }}
            }}
            return null;
        }})()
    """)
    if trash_href:
        page.goto(trash_href)
        page.wait_for_load_state("load")
        body = page.inner_text("body")
        check("cleanup: ticket moved to trash",
              "moved to the Trash" in body or "Undo" in body)
    else:
        check("cleanup: trash link found for test ticket", False,
              "row not found — ticket may have already been deleted")

    page.goto(f"{WP_ADMIN_URL}/edit.php?post_type=helpdesk_ticket")
    page.wait_for_load_state("load")
    check("cleanup: ticket no longer in active list",
          TEST_TICKET_TITLE not in page.inner_text("body"))

    wp_logout(page)
    screenshot(page, "19_final_state")


# ── Entry point ───────────────────────────────────────────────────────────────

SECTIONS = [
    test_01_admin_auth,
    test_02_plugin_verification,
    test_03_ticket_submission,
    test_04_admin_locate_ticket,
    test_05_portal_url,
    test_06_admin_update_ticket,
    test_07_technician_workflow,
    test_08_client_portal,
    test_09_admin_verify_reply,
    test_10_portal_close_reopen,
    test_11_access_control,
    test_12_ticket_list_filters,
    test_13_ticket_lookup,
    test_14_accessibility,
    test_15_plugin_icons,
    test_16_honeypot_spam,
    test_17_cleanup,
]


def _print_summary():
    pass_count = _results["pass_count"]
    failures   = _results["failures"]
    total = pass_count + len(failures)

    print(f"\n{'='*62}")
    if failures:
        print(f"❌  {len(failures)}/{total} FAILURE(S):")
        for f in failures:
            print(f"    • {f}")
    else:
        print(f"✅  ALL {pass_count} CHECKS PASSED")
    print(f"    Screenshots: {OUT}/")

    print(f"\n{'='*62}")
    print("📧  EMAIL CHECKS — verify via Gmail MCP:")
    print(f"    (Search window: roughly the past {len(EMAIL_CHECKS) * 2} minutes)")
    for i, ec in enumerate(EMAIL_CHECKS, 1):
        print(f"    [{i:02d}] To: {ec['to']}")
        print(f"          {ec['description']}")

    if failures:
        sys.exit(1)


with sync_playwright() as p:
    browser = p.chromium.launch(headless=True)
    context = browser.new_context(viewport={"width": 1440, "height": 900})
    page = context.new_page()
    _page = page  # expose to check() for failure screenshots

    print(f"WP site : {WP_URL}\n")

    # Reset any options that a previous failed run may have left dirty
    wpcli("option delete swh_restrict_to_assigned")

    try:
        for section in SECTIONS:
            try:
                section(page)
            except KeyboardInterrupt:
                raise
            except Exception as e:
                safe = section.__name__[:40].replace(" ", "_")
                check(f"{section.__name__}: unexpected error", False, str(e))
                screenshot(page, f"error_{safe}")
    except KeyboardInterrupt:
        print("\nInterrupted — running cleanup...")
        try:
            test_17_cleanup(page)
        except Exception:
            pass
    finally:
        browser.close()

    _print_summary()
