#!/usr/bin/env python3
"""
Playwright browser test suite for Simple WP Helpdesk.

Covers: ticket submission, admin management, technician workflow,
client portal, status transitions, internal notes, access control,
bulk actions, canned responses, file attachments, portal token security,
XSS escaping, rate limiting, and email trigger verification.

Running with pytest (recommended):
    source testing/.venv/bin/activate
    pytest testing/scripts/test_helpdesk_pw.py -v              # full suite
    pytest testing/scripts/test_helpdesk_pw.py -m smoke        # quick pre-push
    pytest testing/scripts/test_helpdesk_pw.py -m security     # security checks only
    pytest testing/scripts/test_helpdesk_pw.py -m slow         # attachment/CDN tests
    pytest testing/scripts/test_helpdesk_pw.py -k "test_03"    # single section
    pytest testing/scripts/test_helpdesk_pw.py --headed        # visible browser
    pytest testing/scripts/test_helpdesk_pw.py --slowmo 500    # slow-motion debug

Standalone (legacy):
    python3 testing/scripts/test_helpdesk_pw.py [--section N ...] [--headed] [--slow-mo MS]

Requirements (testing/.venv):
    pip install -r testing/requirements.txt
    playwright install chromium
"""
import json
import os
import shutil
import subprocess
import sys
import tempfile
import time
from contextlib import contextmanager

import pytest
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

TECH2_USER  = _require("WP_TECH2_USER")
TECH2_PASS  = _require("WP_TECH2_PASS")

CLIENT1_NAME  = _require("CLIENT1_NAME")
CLIENT1_EMAIL = _require("CLIENT1_EMAIL")

CLIENT2_NAME  = _require("CLIENT2_NAME")
CLIENT2_EMAIL = _require("CLIENT2_EMAIL")

_ts = int(time.time())
TEST_TICKET_TITLE  = f"PW Test Ticket {_ts}"
TEST_TICKET_DESC   = "This is an automated test ticket created by the Playwright test suite."
TEST_TECH_REPLY    = "Thanks for reaching out. We are looking into this."
TEST_INTERNAL_NOTE = "INTERNAL ONLY: This note must not appear in the client portal."
TEST_CLIENT_REPLY  = "Thank you for the update, still waiting on resolution."

TEST_TICKET2_TITLE = f"PW Test Ticket2 {_ts}"
TEST_TICKET2_DESC  = "Second automated test ticket used for bulk-action and multi-user tests."

OUT = "testing/screenshots"
os.makedirs(OUT, exist_ok=True)

EMAIL_CHECKS = []

# Mutable results collected by check() / skip()
_results = {"pass_count": 0, "failures": [], "skipped": []}

# Shared state carried between test sections (ticket_id, portal_url, etc.)
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


def skip(name: str, reason: str = ""):
    msg = f"⏭  {name}" + (f" — {reason}" if reason else "")
    print(f"  {msg}")
    _results["skipped"].append(msg)


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


@contextmanager
def as_user(page: Page, username: str, password: str):
    """Log out any current session, log in as username, and guarantee logout on exit."""
    wp_logout(page)
    wp_login(page, username, password)
    try:
        yield
    finally:
        wp_logout(page)


def admin_update_ticket(page: Page, post_id, status=None, priority=None,
                        assigned_user_id=None, tech_reply=None, internal_note=None):
    page.goto(f"{WP_ADMIN_URL}/post.php?post={post_id}&action=edit")
    page.wait_for_load_state("load")
    page.wait_for_selector("#publish")
    # Dismiss WordPress post-lock dialog if another user holds the lock.
    # The dialog exists in the DOM even when hidden; use is_visible() not count().
    lock_takeover = page.locator('#post-lock-dialog a[href*="get-post-lock"]')
    if lock_takeover.is_visible():
        lock_takeover.click()
        page.wait_for_load_state("load")
        page.wait_for_selector("#publish")
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


def _clear_rate_limits():
    """Delete all swh_rl_* options and flush object cache so back-to-back submissions succeed."""
    wpcli(
        "eval 'global $wpdb; "
        "$wpdb->query(\"DELETE FROM {$wpdb->options} WHERE option_name LIKE \\\"swh_rl_%\\\"\");'"
    )
    wpcli("cache flush")  # swh_is_rate_limited() uses get_option() which checks object cache first


def _navigate_admin_list(page: Page):
    """Navigate to the admin ticket list and wait for the table to render."""
    page.goto(f"{WP_ADMIN_URL}/edit.php?post_type=helpdesk_ticket")
    page.wait_for_load_state("load")
    page.wait_for_selector("#the-list, .no-items")


def _navigate_settings(page: Page):
    """Navigate to the SWH settings page and wait for the tab bar to render."""
    page.goto(f"{WP_ADMIN_URL}/edit.php?post_type=helpdesk_ticket&page=swh-settings")
    page.wait_for_load_state("load")
    page.wait_for_selector('[role="tablist"]')
    # Dismiss any WP admin pointers that could intercept button clicks
    page.evaluate("document.querySelectorAll('.wp-pointer').forEach(el => el.remove())")


def _navigate_ticket_editor(page: Page, post_id):
    """Navigate to the ticket editor and wait for it to fully initialise."""
    page.goto(f"{WP_ADMIN_URL}/post.php?post={post_id}&action=edit")
    page.wait_for_load_state("load")
    page.wait_for_selector("#publish")


# ── Test sections ─────────────────────────────────────────────────────────────

@pytest.mark.smoke
def test_01_admin_auth(page: Page):
    print("[1] Admin Authentication")

    wp_login(page, ADMIN_USER, ADMIN_PASS)
    screenshot(page, "01_admin_logged_in")

    # Navigate explicitly to confirm the session cookie is accepted
    page.goto(f"{WP_ADMIN_URL}/")
    page.wait_for_load_state("load")
    final_url = page.url
    title = page.title()
    check("admin login: session active — lands on dashboard",
          "wp-admin" in final_url and "wp-login" not in final_url,
          f"url={final_url[:80]}")
    check("admin dashboard: page title contains Dashboard",
          "dashboard" in title.lower(), f"title={title!r}")


@pytest.mark.smoke
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

    _navigate_settings(page)
    title = page.title()
    check("settings: page loads",
          "setting" in title.lower() or "helpdesk" in title.lower(), f"title={title!r}")
    screenshot(page, "03_settings_page")

    check("a11y: settings page has role=tablist",
          page.locator('[role="tablist"]').count() > 0)
    check("a11y: settings tabs have role=tab with aria-selected",
          page.locator('[role="tab"][aria-selected]').count() >= 3)


@pytest.mark.smoke
def test_03_ticket_submission(page: Page):
    print("\n[3] Ticket Submission (Frontend — two tickets)")

    # ── Ticket 1 (CLIENT1) ────────────────────────────────────────────────────

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

    check("submit ticket1: success message shown",
          "swh-alert-success" in page.content(), "no .swh-alert-success found")
    screenshot(page, "05_submit_ticket1_success")

    expect_email(CLIENT1_EMAIL, "new ticket confirmation to client")
    expect_email(TECH1_EMAIL, "new ticket notification to default assignee (tech1)")

    # ── Ticket 2 (CLIENT2) — clear rate limit first ───────────────────────────

    _clear_rate_limits()

    page.goto(WP_SUBMIT_PAGE)
    page.wait_for_load_state("load")

    page.fill('[name="ticket_name"]', CLIENT2_NAME)
    page.fill('[name="ticket_email"]', CLIENT2_EMAIL)
    page.fill('[name="ticket_title"]', TEST_TICKET2_TITLE)
    page.fill('[name="ticket_desc"]', TEST_TICKET2_DESC)
    page.click('[name="swh_submit_ticket"]')
    page.wait_for_load_state("load")

    check("submit ticket2: success message shown",
          "swh-alert-success" in page.content(), "no .swh-alert-success found")
    screenshot(page, "05b_submit_ticket2_success")


@pytest.mark.smoke
def test_04_admin_locate_ticket(page: Page):
    print("\n[4] Admin: Locate New Tickets")

    wp_login(page, ADMIN_USER, ADMIN_PASS)
    _navigate_admin_list(page)
    body = page.inner_text("body")
    check("admin list: ticket1 appears",
          TEST_TICKET_TITLE in body, f"title={TEST_TICKET_TITLE!r}")
    check("admin list: ticket2 appears",
          TEST_TICKET2_TITLE in body, f"title={TEST_TICKET2_TITLE!r}")
    screenshot(page, "06_admin_ticket_list")

    def _extract_id(title):
        return page.evaluate(f"""
            (function() {{
                var links = document.querySelectorAll('a.row-title, td.column-title a');
                for (var a of links) {{
                    if (a.innerText && a.innerText.includes({json.dumps(title)})) {{
                        var m = a.href.match(/post=([0-9]+)/);
                        if (m) return parseInt(m[1]);
                    }}
                }}
                return null;
            }})()
        """)

    ticket_id = _extract_id(TEST_TICKET_TITLE)
    check("admin list: extracted post ID for ticket1", bool(ticket_id), f"id={ticket_id}")
    if ticket_id:
        state['ticket_id'] = ticket_id
        print(f"    Ticket1 ID: {ticket_id}")

    ticket2_id = _extract_id(TEST_TICKET2_TITLE)
    check("admin list: extracted post ID for ticket2", bool(ticket2_id), f"id={ticket2_id}")
    if ticket2_id:
        state['ticket2_id'] = ticket2_id
        print(f"    Ticket2 ID: {ticket2_id}")


def test_05_portal_url(page: Page):
    print("\n[5] Portal URL (via WP-CLI)")

    if not state.get('ticket_id'):
        skip("wpcli: portal URL retrieval", "no ticket_id in state")
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
        skip("admin update ticket", "no ticket_id in state")
        return

    tech1_id = wpcli(f"user get {TECH1_USER} --field=ID 2>/dev/null")
    if tech1_id.isdigit():
        state['tech1_wp_id'] = int(tech1_id)
        print(f"    Tech1 WP user ID: {tech1_id}")

    admin_update_ticket(page, state['ticket_id'],
                        status="In Progress",
                        assigned_user_id=state.get('tech1_wp_id'))

    _navigate_ticket_editor(page, state['ticket_id'])
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
        skip("tech1 workflow", "no ticket_id in state")
        return

    wp_logout(page)
    href = wp_login(page, TECH1_USER, TECH1_PASS)
    check("tech1 login: success",
          "wp-admin" in href or "wp-login" not in href, f"href={href[:60]}")

    _navigate_admin_list(page)
    check("tech1: assigned ticket visible in list",
          TEST_TICKET_TITLE in page.inner_text("body"))
    screenshot(page, "08_tech1_ticket_list")

    admin_update_ticket(page, state['ticket_id'],
                        tech_reply=TEST_TECH_REPLY,
                        internal_note=TEST_INTERNAL_NOTE)

    _navigate_ticket_editor(page, state['ticket_id'])
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
        skip("client portal", "no portal_url in state")
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
        skip("admin verify reply", "no ticket_id in state")
        return

    _navigate_ticket_editor(page, state['ticket_id'])
    conv_text = page.inner_text("body")
    check("admin: client reply visible in admin conversation",
          TEST_CLIENT_REPLY in conv_text)
    check("admin: shows 'Client' label for client reply", "Client" in conv_text)

    admin_update_ticket(page, state['ticket_id'], status="Resolved")
    _navigate_ticket_editor(page, state['ticket_id'])
    status_val = page.evaluate("document.querySelector('[name=\"ticket_status\"]')?.value")
    check("admin: ticket status set to Resolved",
          status_val == "Resolved", f"got {status_val!r}")
    screenshot(page, "12_ticket_resolved")


def test_10_portal_close_reopen(page: Page):
    print("\n[10] Client Portal: Resolved → Close → Re-open")

    if not state.get('portal_url'):
        skip("portal close/reopen", "no portal_url in state")
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

    restriction_was = wpcli("option get swh_restrict_to_assigned 2>/dev/null")
    wpcli("option update swh_restrict_to_assigned yes")

    with as_user(page, TECH2_USER, TECH2_PASS):
        _navigate_admin_list(page)
        check("access control: tech2 (unassigned) cannot see tech1's ticket",
              TEST_TICKET_TITLE not in page.inner_text("body"),
              "ticket should be hidden from unassigned technician")
        screenshot(page, "16_tech2_restricted_list")

    wp_login(page, ADMIN_USER, ADMIN_PASS)
    if restriction_was in ("", "no"):
        wpcli("option delete swh_restrict_to_assigned 2>/dev/null")
    else:
        wpcli(f"option update swh_restrict_to_assigned {restriction_was}")


def test_12_ticket_list_filters(page: Page):
    print("\n[12] Admin: Ticket List Filters")

    _navigate_admin_list(page)
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
    _navigate_settings(page)
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
        _navigate_ticket_editor(page, state['ticket_id'])
        ed_html = page.content()
        check("a11y: ticket status field has label association",
              'for="swh-status"' in ed_html and 'id="swh-status"' in ed_html)
        check("a11y: ticket editor conversation has role=log", 'role="log"' in ed_html)

    wp_logout(page)


@pytest.mark.slow
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


@pytest.mark.security
def test_16_honeypot_spam(page: Page):
    print("\n[16] Anti-Spam: Honeypot Rejection")

    # Enable honeypot for this test regardless of the site's current setting
    original_spam_method = wpcli("option get swh_spam_method 2>/dev/null") or "none"
    wpcli("option update swh_spam_method honeypot")
    wpcli("cache flush")  # clear object cache so the updated option is served immediately

    before_count = wpcli("post list --post_type=helpdesk_ticket --post_status=any --format=count")

    wp_logout(page)
    # Append a unique query param so full-page caches serve a fresh page
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


# ── New sections ──────────────────────────────────────────────────────────────

@pytest.mark.security
def test_17_form_validation(page: Page):
    print("\n[17] Form Validation — Missing Required Fields")  # closes issue #193

    _clear_rate_limits()
    before_count = wpcli("post list --post_type=helpdesk_ticket --post_status=any --format=count")

    page.goto(WP_SUBMIT_PAGE)
    page.wait_for_load_state("load")

    # Submit completely empty form — strip HTML5 required attrs first so the
    # POST reaches the server and triggers server-side validation
    with page.expect_navigation(wait_until="load"):
        page.evaluate(
            "document.querySelectorAll('[required]').forEach(el => el.removeAttribute('required'));"
            "document.querySelector('[name=\"swh_submit_ticket\"]').click()"
        )

    html = page.content()
    check("validation: error shown for empty submission",
          "swh-alert-error" in html or "swh-alert-danger" in html,
          "no error element found")
    check("validation: no success shown for empty submission",
          "swh-alert-success" not in html)

    after_count = wpcli("post list --post_type=helpdesk_ticket --post_status=any --format=count")
    check("validation: no ticket created on empty submit",
          before_count == after_count, f"before={before_count} after={after_count}")
    screenshot(page, "19_form_validation_error")


def test_18_settings_persistence(page: Page):
    print("\n[18] Settings Persistence — General + Email Tab")  # closes issues #198, #204

    wp_login(page, ADMIN_USER, ADMIN_PASS)

    # ── General tab: autoclose_days ───────────────────────────────────────────

    _navigate_settings(page)
    original_autoclose = page.input_value('[name="swh_autoclose_days"]') or "3"
    page.fill('[name="swh_autoclose_days"]', "7")
    page.locator('[name="swh_save_settings"]').first.click()
    page.wait_for_load_state("load")
    _navigate_settings(page)
    check("settings: autoclose_days persists after save",
          page.input_value('[name="swh_autoclose_days"]') == "7")

    page.fill('[name="swh_autoclose_days"]', original_autoclose)
    page.locator('[name="swh_save_settings"]').first.click()
    page.wait_for_load_state("load")
    screenshot(page, "20_settings_general_persisted")

    # ── Email tab: new-ticket subject template ────────────────────────────────

    UNIQUE_SUB = f"[TEST-{int(time.time())}] {{title}}"

    _navigate_settings(page)
    page.locator('#swh-tab-emails').click()
    page.wait_for_selector('[name="swh_em_user_new_sub"]')
    original_sub = page.input_value('[name="swh_em_user_new_sub"]')
    page.fill('[name="swh_em_user_new_sub"]', UNIQUE_SUB)
    page.locator('[name="swh_save_settings"]').first.click()
    page.wait_for_load_state("load")

    _navigate_settings(page)
    page.locator('#swh-tab-emails').click()
    page.wait_for_selector('[name="swh_em_user_new_sub"]')
    check("email settings: subject template persists after save",
          page.input_value('[name="swh_em_user_new_sub"]') == UNIQUE_SUB)

    page.fill('[name="swh_em_user_new_sub"]', original_sub)
    page.locator('[name="swh_save_settings"]').first.click()
    page.wait_for_load_state("load")
    screenshot(page, "21_settings_email_persisted")


def test_19_canned_responses(page: Page):
    print("\n[19] Canned Responses — Save in Settings, Use in Editor")  # closes issue #197

    if not state.get('ticket_id'):
        skip("canned responses", "no ticket_id in state")
        return

    CANNED_TITLE = f"Test Canned {int(time.time())}"
    CANNED_BODY  = "This is the automated canned response body for testing."

    wp_login(page, ADMIN_USER, ADMIN_PASS)

    # ── Add a canned response in settings ────────────────────────────────────

    _navigate_settings(page)
    page.locator('#swh-tab-canned').click()
    page.wait_for_selector('#swh-add-canned')
    page.locator('#swh-add-canned').click()
    page.wait_for_selector('.swh-canned-item:last-child [name="swh_canned_titles[]"]')

    last_title = page.locator('.swh-canned-item [name="swh_canned_titles[]"]').last
    last_body  = page.locator('.swh-canned-item [name="swh_canned_bodies[]"]').last
    last_title.fill(CANNED_TITLE)
    last_body.fill(CANNED_BODY)
    page.locator('[name="swh_save_settings"]').first.click()
    page.wait_for_load_state("load")

    # ── Verify persistence ────────────────────────────────────────────────────

    _navigate_settings(page)
    page.locator('#swh-tab-canned').click()
    page.wait_for_selector('#swh-canned-list')
    # input values are not part of innerText — check via DOM value property
    title_found = page.evaluate(
        f"Array.from(document.querySelectorAll('.swh-canned-item [name=\"swh_canned_titles[]\"]'))"
        f".some(el => el.value === {json.dumps(CANNED_TITLE)})"
    )
    check("canned responses: saved title persists in settings", title_found)
    screenshot(page, "22_canned_responses_settings")

    # ── Verify dropdown in ticket editor and insert ───────────────────────────

    _navigate_ticket_editor(page, state['ticket_id'])
    canned_sel = page.locator('#swh-canned-select')
    check("canned responses: dropdown present in ticket editor", canned_sel.count() > 0)

    if canned_sel.count() > 0:
        option_found = page.evaluate(
            f"Array.from(document.querySelectorAll('#swh-canned-select option'))"
            f".some(o => o.text === {json.dumps(CANNED_TITLE)})"
        )
        check("canned responses: our entry appears in ticket editor dropdown", option_found)

        if option_found:
            page.select_option('#swh-canned-select', label=CANNED_TITLE)
            page.locator('#swh-canned-insert').click()
            page.wait_for_timeout(300)
            reply_val = page.input_value('[name="swh_tech_reply_text"]')
            check("canned responses: insert populates reply textarea",
                  CANNED_BODY in reply_val, f"got: {reply_val[:80]!r}")
    screenshot(page, "23_canned_response_insert")

    # ── Cleanup: remove the canned response ──────────────────────────────────

    _navigate_settings(page)
    page.locator('#swh-tab-canned').click()
    page.wait_for_selector('#swh-canned-list')
    for item in page.locator('.swh-canned-item').all():
        if CANNED_TITLE in item.inner_text():
            item.locator('.swh-remove-canned').click()
            break
    page.locator('[name="swh_save_settings"]').first.click()
    page.wait_for_load_state("load")


def test_20_bulk_status_change(page: Page):
    print("\n[20] Bulk Status Change")  # closes issue #196

    if not state.get('ticket_id') or not state.get('ticket2_id'):
        skip("bulk status change", "need both ticket_id and ticket2_id in state")
        return

    wp_login(page, ADMIN_USER, ADMIN_PASS)
    _navigate_admin_list(page)

    # Check both ticket checkboxes
    for post_id in (state['ticket_id'], state['ticket2_id']):
        cb = page.locator(f'input[type="checkbox"][value="{post_id}"]')
        if cb.count() > 0:
            cb.check()

    checked = page.evaluate(
        'document.querySelectorAll("#the-list input[type=checkbox]:checked").length'
    )
    check("bulk: both ticket checkboxes checked", checked >= 2, f"got {checked}")

    # sanitize_title('In Progress') → 'in-progress', so action key is swh_status_in-progress
    page.select_option('#bulk-action-selector-top', 'swh_status_in-progress')
    page.locator('#doaction').click()
    page.wait_for_load_state("load")
    page.wait_for_selector("#the-list, .no-items")

    notice_loc = page.locator('.updated.notice, .notice-success')
    notice = notice_loc.inner_text() if notice_loc.count() > 0 else ""
    check("bulk: success notice shown",
          "ticket(s) updated" in notice or "swh_bulk_updated" in page.url,
          f"notice: {notice[:100]!r}")
    check("bulk: notice references In Progress",
          "In Progress" in notice or "In+Progress" in page.url,
          f"notice: {notice[:100]!r}")
    screenshot(page, "24_bulk_status_applied")

    # Confirm via WP-CLI (meta key has underscore prefix: _ticket_status)
    t1_status = wpcli(f"eval 'echo get_post_meta({state['ticket_id']}, \"_ticket_status\", true);'")
    t2_status = wpcli(f"eval 'echo get_post_meta({state['ticket2_id']}, \"_ticket_status\", true);'")
    check("bulk: ticket1 status is In Progress (WP-CLI)",
          t1_status == "In Progress", f"got {t1_status!r}")
    check("bulk: ticket2 status is In Progress (WP-CLI)",
          t2_status == "In Progress", f"got {t2_status!r}")


def test_21_tech2_workflow(page: Page):
    print("\n[21] Tech2 Own-Ticket Workflow")  # closes issue #201

    if not state.get('ticket2_id'):
        skip("tech2 workflow", "no ticket2_id in state")
        return

    wp_login(page, ADMIN_USER, ADMIN_PASS)

    tech2_id = wpcli(f"user get {TECH2_USER} --field=ID 2>/dev/null")
    if tech2_id.isdigit():
        state['tech2_wp_id'] = int(tech2_id)
        print(f"    Tech2 WP user ID: {tech2_id}")

    admin_update_ticket(page, state['ticket2_id'],
                        status="In Progress",
                        assigned_user_id=state.get('tech2_wp_id'))

    TECH2_REPLY = "Tech2 is handling this second test ticket."
    TECH2_NOTE  = "TECH2 INTERNAL: private note from tech2."

    with as_user(page, TECH2_USER, TECH2_PASS):
        _navigate_admin_list(page)
        check("tech2: assigned ticket2 visible in list",
              TEST_TICKET2_TITLE in page.inner_text("body"))
        screenshot(page, "25_tech2_ticket_list")

        admin_update_ticket(page, state['ticket2_id'],
                            tech_reply=TECH2_REPLY,
                            internal_note=TECH2_NOTE)

        _navigate_ticket_editor(page, state['ticket2_id'])
        conv_html = page.content()
        check("tech2 reply: public reply saved in conversation", TECH2_REPLY in conv_html)
        check("tech2 note: internal note saved in conversation", TECH2_NOTE in conv_html)
        screenshot(page, "26_tech2_conversation")

    wp_login(page, ADMIN_USER, ADMIN_PASS)
    _navigate_ticket_editor(page, state['ticket2_id'])
    check("admin: tech2 reply visible in admin view", TECH2_REPLY in page.inner_text("body"))


def test_22_admin_search_and_filters(page: Page):
    print("\n[22] Admin: Ticket Search + Priority/Status Filters")  # closes issues #205, #199

    wp_login(page, ADMIN_USER, ADMIN_PASS)

    # ── Ticket search ─────────────────────────────────────────────────────────

    _navigate_admin_list(page)
    # Search for a unique substring of ticket1's title (not present in ticket2's)
    search_term = TEST_TICKET_TITLE.replace("2", "")[:30]
    page.fill('[name="s"]', search_term)
    page.locator('#search-submit').click()
    page.wait_for_load_state("load")
    page.wait_for_selector("#the-list, .no-items")

    results_text = page.inner_text("body")
    check("search: ticket1 found by title keyword",
          TEST_TICKET_TITLE in results_text)
    # NOTE: WordPress word-level LIKE search means "Ticket" matches both ticket titles;
    # negative assertion for ticket2 is unreliable and intentionally omitted.
    screenshot(page, "27_admin_search_results")

    # ── Status filter (URL-driven) ────────────────────────────────────────────

    page.goto(
        f"{WP_ADMIN_URL}/edit.php?post_type=helpdesk_ticket&swh_status_filter=In+Progress"
    )
    page.wait_for_load_state("load")
    page.wait_for_selector("#the-list, .no-items")
    html = page.content()
    check("filter: status filter page loads without error",
          "wp-die" not in html and "Fatal error" not in html)
    screenshot(page, "28_status_filter")

    # ── Priority filter (URL-driven) ──────────────────────────────────────────

    page.goto(
        f"{WP_ADMIN_URL}/edit.php?post_type=helpdesk_ticket&swh_priority_filter=Medium"
    )
    page.wait_for_load_state("load")
    page.wait_for_selector("#the-list, .no-items")
    html2 = page.content()
    check("filter: priority filter page loads without error",
          "wp-die" not in html2 and "Fatal error" not in html2)
    screenshot(page, "29_priority_filter")


@pytest.mark.slow
def test_23_file_attachments(page: Page):
    print("\n[23] File Attachments — Upload, Proxy, and Type Restriction")  # closes issue #195

    wp_login(page, ADMIN_USER, ADMIN_PASS)
    _clear_rate_limits()
    wp_logout(page)

    tmp_txt = None
    tmp_php = None
    try:
        # ── Part 1: Submit a ticket with a valid .txt attachment ──────────────

        with tempfile.NamedTemporaryFile(suffix=".txt", delete=False, mode="w") as tf:
            tf.write("Simple WP Helpdesk attachment test file.\n")
            tmp_txt = tf.name

        page.goto(WP_SUBMIT_PAGE)
        page.wait_for_load_state("load")
        page.fill('[name="ticket_name"]', CLIENT1_NAME)
        page.fill('[name="ticket_email"]', CLIENT1_EMAIL)
        page.fill('[name="ticket_title"]', f"Attachment Test {int(time.time())}")
        page.fill('[name="ticket_desc"]', "Testing file attachment upload.")
        page.set_input_files('[name="ticket_attachments[]"]', tmp_txt)
        page.click('[name="swh_submit_ticket"]')
        page.wait_for_load_state("load")

        check("attachment: submission with .txt attachment succeeds",
              "swh-alert-success" in page.content())
        screenshot(page, "30_attachment_submitted")

        # Locate the most recently created ticket that has _ticket_attachments meta
        attach_ids = wpcli(
            "post list --post_type=helpdesk_ticket --post_status=any "
            "--meta_key=_ticket_attachments --orderby=ID --order=DESC --format=ids"
        ).split()
        attach_id = attach_ids[0] if attach_ids else None
        check("attachment: ticket with _ticket_attachments meta exists",
              bool(attach_id), "no ticket found with attachment meta")

        if attach_id:
            state['attach_ticket_id'] = int(attach_id)
            proxy_url = wpcli(
                f"eval '$atts = get_post_meta({attach_id}, \"_ticket_attachments\", true); "
                f"echo is_array($atts) && !empty($atts) "
                f"? swh_get_file_proxy_url($atts[0], {attach_id}) : \"\";'"
            )
            check("attachment: proxy URL generated",
                  bool(proxy_url) and "swh_file=" in proxy_url,
                  f"got: {proxy_url!r}")

            if proxy_url:
                wp_login(page, ADMIN_USER, ADMIN_PASS)
                resp = page.request.get(proxy_url)
                check("attachment: proxy serves file with HTTP 200",
                      resp.status == 200, f"got HTTP {resp.status}")
                screenshot(page, "31_attachment_proxy")
                wp_logout(page)

        # ── Part 2: Disallowed .php file bypassing the accept attribute ───────

        with tempfile.NamedTemporaryFile(suffix=".php", delete=False, mode="w") as pf:
            pf.write("<?php echo 'bad'; ?>")
            tmp_php = pf.name

        _clear_rate_limits()
        page.goto(WP_SUBMIT_PAGE)
        page.wait_for_load_state("load")
        page.fill('[name="ticket_name"]', CLIENT1_NAME)
        page.fill('[name="ticket_email"]', CLIENT1_EMAIL)
        page.fill('[name="ticket_title"]', "PHP Upload Bypass Test")
        page.fill('[name="ticket_desc"]', "Attempting to bypass .php restriction.")
        # Remove browser-side accept restriction to let the server block it
        page.evaluate(
            "var el = document.querySelector('[name=\"ticket_attachments[]\"]');"
            "if (el) el.removeAttribute('accept');"
        )
        page.set_input_files('[name="ticket_attachments[]"]', tmp_php)
        page.click('[name="swh_submit_ticket"]')
        page.wait_for_load_state("load")

        # Regardless of whether the form accepted or rejected the whole submission,
        # the .php filename must NOT appear in any ticket's attachment meta.
        latest_ids = wpcli(
            "post list --post_type=helpdesk_ticket --post_status=any "
            "--orderby=ID --order=DESC --format=ids"
        ).split()
        php_stored = False
        for pid in latest_ids[:5]:
            meta_val = wpcli(f"post meta get {pid} _ticket_attachments")
            if ".php" in meta_val:
                php_stored = True
                break
        check("attachment: .php file not stored in ticket attachment meta", not php_stored)
        screenshot(page, "32_php_upload_blocked")

    finally:
        for tmp in (tmp_txt, tmp_php):
            if tmp:
                try:
                    os.unlink(tmp)
                except OSError:
                    pass


@pytest.mark.security
def test_24_portal_token_security(page: Page):
    print("\n[24] Portal Token Security — Invalid + Expired Token")  # closes issue #200

    if not state.get('ticket_id'):
        skip("portal token security", "no ticket_id in state")
        return

    wp_logout(page)

    # ── Invalid / tampered token ──────────────────────────────────────────────

    # Use the portal page base URL + a clearly invalid token query
    portal_base = state['portal_url'].split('?')[0] if state.get('portal_url') else WP_SUBMIT_PAGE
    tampered = f"{portal_base}?swh_ticket={'A' * 40}"
    page.goto(tampered)
    page.wait_for_load_state("load")
    html = page.content()
    check("portal security: invalid token shows an error element",
          "swh-alert-error" in html or 'role="alert"' in html,
          "no alert element found for invalid token")
    screenshot(page, "33_portal_invalid_token")

    # ── Expired token ─────────────────────────────────────────────────────────

    pid = state['ticket_id']
    old_created = wpcli(f"post meta get {pid} _ticket_token_created")
    past_ts = int(time.time()) - (100 * 86400)  # 100 days ago
    wpcli(f"post meta update {pid} _ticket_token_created {past_ts}")

    try:
        fresh_url = wpcli(f'eval "echo swh_get_secure_ticket_link({pid});"')
        if fresh_url and "swh_ticket=" in fresh_url:
            page.goto(fresh_url)
            page.wait_for_load_state("load")
            exp_html = page.content()
            check("portal security: expired token shows an error element",
                  "swh-alert-error" in exp_html or 'role="alert"' in exp_html or
                  "expired" in exp_html.lower(),
                  "no alert element found for expired token")
            screenshot(page, "34_portal_expired_token")
        else:
            skip("portal security: expired token test", "could not get portal URL")
    finally:
        # Restore token created time so subsequent portal tests work
        restore_ts = old_created if old_created else str(int(time.time()))
        wpcli(f"post meta update {pid} _ticket_token_created {restore_ts}")


@pytest.mark.security
def test_25_xss_escaping(page: Page):
    print("\n[25] XSS Escaping — Script Tags in Ticket Fields")  # closes issue #203

    XSS_TITLE = f'<script>window.__xss_fired=1</script>XSS Test {int(time.time())}'
    XSS_DESC  = '<img src=x onerror="window.__xss2=1">XSS description payload.'

    wp_login(page, ADMIN_USER, ADMIN_PASS)

    # Create the XSS test ticket directly via WP-CLI to avoid rate limiting
    xss_id = wpcli(
        f"post create --post_type=helpdesk_ticket --post_status=publish "
        f"--post_title={json.dumps(XSS_TITLE)} "
        f"--post_content={json.dumps(XSS_DESC)} "
        f"--porcelain"
    )
    check("xss: test ticket created via WP-CLI", xss_id.isdigit(), f"got: {xss_id!r}")

    if not xss_id.isdigit():
        return

    state['xss_ticket_id'] = int(xss_id)
    wpcli(f"post meta update {xss_id} ticket_status Open")

    # ── Admin ticket list ─────────────────────────────────────────────────────

    _navigate_admin_list(page)
    xss_fired = page.evaluate("!!window.__xss_fired")
    list_html  = page.content()
    check("xss: script tag did not execute in admin list", not xss_fired,
          "window.__xss_fired was set — unescaped script execution!")
    check("xss: script tag escaped as HTML entity in admin list",
          "&lt;script&gt;" in list_html,
          "raw <script> tag in page HTML")
    screenshot(page, "35_xss_admin_list")

    # ── Admin ticket editor ───────────────────────────────────────────────────

    _navigate_ticket_editor(page, xss_id)
    xss_fired2 = page.evaluate("!!window.__xss_fired")
    xss2_fired = page.evaluate("!!window.__xss2")
    check("xss: script tag did not execute in ticket editor", not xss_fired2)
    check("xss: img onerror did not fire in ticket editor", not xss2_fired)
    screenshot(page, "36_xss_ticket_editor")

    # Cleanup: permanently delete via WP-CLI
    wpcli(f"post delete {xss_id} --force 2>/dev/null")
    state.pop('xss_ticket_id', None)


@pytest.mark.security
def test_26_subscriber_access_control(page: Page):
    print("\n[26] Subscriber Role — Cannot Access Helpdesk Admin")  # closes issue #202

    wp_login(page, ADMIN_USER, ADMIN_PASS)

    sub_user  = f"swh-sub-{int(time.time())}"
    sub_pass  = "TestPass123!XYZ"
    sub_email = f"{sub_user}@test-swh.invalid"

    sub_id = wpcli(
        f"user create {sub_user} {sub_email} --role=subscriber "
        f"--user_pass={sub_pass} --display_name='SWH Subscriber Test' --porcelain"
    )
    check("subscriber: WP user created via WP-CLI", sub_id.isdigit(), f"got: {sub_id!r}")

    if not sub_id.isdigit():
        skip("subscriber access control checks", "could not create test user")
        return

    try:
        with as_user(page, sub_user, sub_pass):
            # Attempt to access ticket list
            page.goto(f"{WP_ADMIN_URL}/edit.php?post_type=helpdesk_ticket")
            page.wait_for_load_state("load")
            final_url = page.url
            check("subscriber: redirected to login page from helpdesk ticket list",
                  "login" in final_url,
                  f"url={final_url[:80]}")

            # Attempt to open the ticket editor directly
            if state.get('ticket_id'):
                page.goto(
                    f"{WP_ADMIN_URL}/post.php?post={state['ticket_id']}&action=edit"
                )
                page.wait_for_load_state("load")
                editor_html = page.content()
                check("subscriber: ticket editor not accessible",
                      "ticket_status" not in editor_html,
                      "ticket_status meta field visible to subscriber")
            screenshot(page, "37_subscriber_access_denied")
    finally:
        wp_login(page, ADMIN_USER, ADMIN_PASS)
        wpcli(f"user delete {sub_id} --yes 2>/dev/null")


# ── Rate limiting ─────────────────────────────────────────────────────────────

@pytest.mark.security
def test_27_rate_limiting(page: Page):
    print("\n[27] Rate Limiting — Rapid Submission Blocked")

    _clear_rate_limits()
    before_count = wpcli("post list --post_type=helpdesk_ticket --post_status=any --format=count")

    wp_logout(page)
    page.goto(WP_SUBMIT_PAGE)
    page.wait_for_load_state("load")

    # First submission — should succeed and arm the rate limit
    page.fill('[name="ticket_name"]', CLIENT1_NAME)
    page.fill('[name="ticket_email"]', CLIENT1_EMAIL)
    page.fill('[name="ticket_title"]', f"Rate Limit Test {int(time.time())}")
    page.fill('[name="ticket_desc"]', "First submission to arm the rate limit.")
    page.click('[name="swh_submit_ticket"]')
    page.wait_for_load_state("load")

    check("rate limit: first submission succeeds", "swh-alert-success" in page.content())

    after_first = wpcli("post list --post_type=helpdesk_ticket --post_status=any --format=count")
    check("rate limit: first submission creates a ticket",
          int(after_first or 0) > int(before_count or 0),
          f"before={before_count} after={after_first}")

    # Grab the new ticket ID for immediate cleanup
    rl_ids = wpcli(
        "post list --post_type=helpdesk_ticket --post_status=any "
        "--orderby=ID --order=DESC --format=ids"
    ).split()
    rl_ticket_id = rl_ids[0] if rl_ids else None

    # Second submission immediately — 30-second TTL should block it
    page.goto(WP_SUBMIT_PAGE)
    page.wait_for_load_state("load")
    page.fill('[name="ticket_name"]', CLIENT1_NAME)
    page.fill('[name="ticket_email"]', CLIENT1_EMAIL)
    page.fill('[name="ticket_title"]', f"Rate Limit Test 2 {int(time.time())}")
    page.fill('[name="ticket_desc"]', "Second submission — should be blocked by rate limit.")
    page.click('[name="swh_submit_ticket"]')
    page.wait_for_load_state("load")

    html = page.content()
    check("rate limit: second rapid submission is rejected",
          "swh-alert-success" not in html,
          "rate limit not working — second submission succeeded")
    check("rate limit: error/info message shown for blocked submission",
          "swh-alert-error" in html or "swh-alert-info" in html or "swh-alert-warning" in html)

    after_second = wpcli("post list --post_type=helpdesk_ticket --post_status=any --format=count")
    check("rate limit: no second ticket created",
          after_second == after_first,
          f"before={after_first} after={after_second}")
    screenshot(page, "38_rate_limit_rejected")

    # Cleanup this section's ticket immediately (does not go through main cleanup)
    if rl_ticket_id:
        wpcli(f"post delete {rl_ticket_id} --force 2>/dev/null")
    _clear_rate_limits()


# ── Cleanup ───────────────────────────────────────────────────────────────────

def test_28_cleanup(page: Page):
    print("\n[28] Cleanup")

    wp_login(page, ADMIN_USER, ADMIN_PASS)
    _navigate_admin_list(page)

    def _trash_by_title(title: str, label: str):
        trash_href = page.evaluate(f"""
            (function() {{
                for (var row of document.querySelectorAll('#the-list tr')) {{
                    var a = row.querySelector('.column-title .row-title, .column-title a');
                    if (a && a.innerText.includes({json.dumps(title)})) {{
                        var t = row.querySelector(
                            '.row-actions .trash a, .row-actions a[href*="action=trash"]'
                        );
                        return t ? t.href : null;
                    }}
                }}
                return null;
            }})()
        """)
        if trash_href:
            page.goto(trash_href)
            page.wait_for_load_state("load")
            body = page.inner_text("body")
            check(f"cleanup: {label} moved to trash",
                  "moved to the Trash" in body or "Undo" in body)
        else:
            check(f"cleanup: trash link found for {label}", False,
                  "row not found — may already be deleted")
        _navigate_admin_list(page)

    _trash_by_title(TEST_TICKET_TITLE, "ticket1")
    _trash_by_title(TEST_TICKET2_TITLE, "ticket2")

    # Attachment test ticket — delete permanently via WP-CLI (no need to trash it)
    if state.get('attach_ticket_id'):
        wpcli(f"post delete {state['attach_ticket_id']} --force 2>/dev/null")

    _navigate_admin_list(page)
    list_text = page.inner_text("body")
    check("cleanup: ticket1 no longer in active list",
          TEST_TICKET_TITLE not in list_text)
    check("cleanup: ticket2 no longer in active list",
          TEST_TICKET2_TITLE not in list_text)

    wp_logout(page)
    screenshot(page, "39_final_state")


# ── Section registry ──────────────────────────────────────────────────────────

SECTIONS = [
    test_01_admin_auth,               # 1
    test_02_plugin_verification,      # 2
    test_03_ticket_submission,        # 3  — submits ticket1 + ticket2
    test_04_admin_locate_ticket,      # 4  — locates both tickets
    test_05_portal_url,               # 5
    test_06_admin_update_ticket,      # 6
    test_07_technician_workflow,      # 7
    test_08_client_portal,            # 8
    test_09_admin_verify_reply,       # 9
    test_10_portal_close_reopen,      # 10
    test_11_access_control,           # 11
    test_12_ticket_list_filters,      # 12
    test_13_ticket_lookup,            # 13
    test_14_accessibility,            # 14
    test_15_plugin_icons,             # 15
    test_16_honeypot_spam,            # 16
    test_17_form_validation,          # 17 — #193
    test_18_settings_persistence,     # 18 — #198, #204
    test_19_canned_responses,         # 19 — #197
    test_20_bulk_status_change,       # 20 — #196
    test_21_tech2_workflow,           # 21 — #201
    test_22_admin_search_and_filters, # 22 — #205, #199
    test_23_file_attachments,         # 23 — #195
    test_24_portal_token_security,    # 24 — #200
    test_25_xss_escaping,             # 25 — #203
    test_26_subscriber_access_control,# 26 — #202
    test_27_rate_limiting,            # 27 — rate limiting
    test_28_cleanup,                  # 28 — always last
]


def _print_summary():
    pass_count = _results["pass_count"]
    failures   = _results["failures"]
    skipped    = _results["skipped"]
    total      = pass_count + len(failures)

    print(f"\n{'='*62}")
    if failures:
        print(f"❌  {len(failures)}/{total} FAILURE(S):")
        for f in failures:
            print(f"    • {f}")
    else:
        print(f"✅  ALL {pass_count} CHECKS PASSED")
    if skipped:
        print(f"⏭   {len(skipped)} SKIPPED:")
        for s in skipped:
            print(f"    • {s}")
    print(f"    Screenshots: {OUT}/")

    print(f"\n{'='*62}")
    print("📧  EMAIL CHECKS — verify via Gmail MCP:")
    print(f"    (Search window: roughly the past {len(EMAIL_CHECKS) * 2} minutes)")
    for i, ec in enumerate(EMAIL_CHECKS, 1):
        print(f"    [{i:02d}] To: {ec['to']}")
        print(f"          {ec['description']}")

    if failures:
        sys.exit(1)


# ── Entry point (standalone / legacy) ────────────────────────────────────────
# When run as a script, delegates to pytest so marks, hooks, and fixtures all work.
# For direct pytest invocation see the docstring at the top of this file.

if __name__ == "__main__":
    import argparse as _argparse

    _parser = _argparse.ArgumentParser(description="Simple WP Helpdesk test suite")
    _parser.add_argument('--section', type=int, nargs='+', metavar='N',
                         help='Run only these section numbers (maps to pytest -k)')
    _parser.add_argument('--headed', action='store_true',
                         help='Headed browser (maps to pytest --headed)')
    _parser.add_argument('--slow-mo', type=int, default=0, metavar='MS',
                         help='Slow-motion ms (maps to pytest --slowmo)')
    _parser.add_argument('-m', '--mark', default='', metavar='EXPR',
                         help='Mark expression, e.g. smoke, security (maps to pytest -m)')
    _args = _parser.parse_args()

    _pytest_argv = [__file__, "-v", "--tb=short"]
    if _args.headed or _args.slow_mo:
        _pytest_argv.append("--headed")
    if _args.slow_mo:
        _pytest_argv.extend(["--slowmo", str(_args.slow_mo)])
    if _args.section:
        _pytest_argv.extend(["-k", " or ".join(f"test_{n:02d}" for n in _args.section)])
    if _args.mark:
        _pytest_argv.extend(["-m", _args.mark])

    sys.exit(pytest.main(_pytest_argv))
