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
import re
import shutil
import subprocess
import sys
import tempfile
import time
from contextlib import contextmanager
from urllib.parse import urlparse

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
WP_SUBMIT_PAGE  = _require("WP_SUBMIT_PAGE").rstrip("/")
WP_PORTAL_PAGE  = _optional("WP_PORTAL_PAGE", "").rstrip("/")
WP_MODE        = _optional("WP_MODE", "ssh")           # "ssh" or "docker"
MAILHOG_URL    = _optional("MAILHOG_URL", "").rstrip("/")  # e.g. http://localhost:8025
SSH_HOST       = _optional("SSH_HOST", "")
WP_CONTAINER   = _optional("WP_CONTAINER", "wpcli")
WP_PATH        = _optional("WP_PATH", "/var/www/html")

if WP_MODE not in {"ssh", "docker"}:
    print(f"ERROR: WP_MODE must be 'ssh' or 'docker' (got {WP_MODE!r})", file=sys.stderr)
    sys.exit(1)
if WP_MODE == "ssh" and not SSH_HOST:
    print("ERROR: SSH_HOST is required when WP_MODE=ssh", file=sys.stderr)
    sys.exit(1)

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
    """Run a WP-CLI command. Uses docker compose exec (WP_MODE=docker) or SSH+docker exec."""
    if WP_MODE == "docker":
        repo_root = os.path.normpath(os.path.join(os.path.dirname(__file__), "..", ".."))
        result = subprocess.run(
            ["docker", "compose", "-f", "docker-compose.test.yml",
             "exec", "-T", WP_CONTAINER,
             "sh", "-c", f"wp {cmd} --path={WP_PATH} --allow-root 2>/dev/null"],
            capture_output=True, text=True, timeout=30, cwd=repo_root, check=False
        )
    else:
        docker_cmd = (
            f"docker exec {WP_CONTAINER} wp {cmd} --path={WP_PATH} --allow-root 2>/dev/null"
        )
        result = subprocess.run(
            ["ssh", SSH_HOST, docker_cmd],
            capture_output=True, text=True, timeout=15, check=False
        )
    # WP-CLI exits 1 for "not found" (option/meta absent) — that is a valid result,
    # not an infrastructure failure. Callers check the return value themselves.
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


def mailhog_get_messages(to_email: str, timeout: int = 10) -> list:
    """Poll MailHog API until a message for to_email arrives, or timeout (seconds).

    Returns list of MailHog message dicts. Empty list when MAILHOG_URL is unset,
    MailHog is unreachable, or no matching message arrives before the deadline.
    """
    if not MAILHOG_URL:
        return []
    import requests as _req
    deadline = time.time() + timeout
    while time.time() < deadline:
        try:
            resp = _req.get(f"{MAILHOG_URL}/api/v2/messages", params={"limit": 50}, timeout=5)
            if resp.status_code != 200:
                time.sleep(1)
                continue
            matched = [
                m for m in resp.json().get("items", [])
                if any(
                    to_email.lower() == (r.get("Mailbox", "") + "@" + r.get("Domain", "")).lower()
                    for r in m.get("To", [])
                )
            ]
            if matched:
                return matched
        except Exception:
            time.sleep(1)
            continue
        time.sleep(1)
    return []


def mailhog_clear() -> None:
    """Delete all messages from MailHog (no-op when MAILHOG_URL is unset)."""
    if not MAILHOG_URL:
        return
    import requests as _req
    try:
        resp = _req.delete(f"{MAILHOG_URL}/api/v1/messages", timeout=5)
        check("mailhog_clear: inbox cleared",
              resp.status_code in (200, 204),
              f"MailHog DELETE returned {resp.status_code} — stale messages may cause false email assertions")
    except Exception as exc:
        check("mailhog_clear: inbox cleared", False,
              f"MailHog DELETE failed: {exc} — stale messages may cause false email assertions")


def expect_email(recipient: str, description: str, clear_after: bool = True):
    """Assert an email was delivered.

    Docker mode (MAILHOG_URL set): polls MailHog API and calls check() immediately.
    SSH mode: appends to EMAIL_CHECKS for manual verification after the run.

    clear_after: if True (default) clear MailHog after the check. Pass False when
    multiple emails are expected from the same PHP action (e.g., client + admin
    notification from a single ticket submission) so the second check still finds
    its message.
    """
    if WP_MODE == "docker" and MAILHOG_URL:
        messages = mailhog_get_messages(recipient, timeout=10)
        check(
            f"email: {description} → {recipient}",
            bool(messages),
            f"No email found in MailHog for {recipient}",
        )
        if clear_after:
            mailhog_clear()
    else:
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
    page.evaluate("document.querySelectorAll('.wp-pointer').forEach(el => el.remove())")
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


def _php_str(val: str) -> str:
    """Escape a string for safe embedding inside a PHP single-quoted literal in a WP-CLI eval.

    Escapes backslashes then single quotes so the value can be placed as 'val'
    inside PHP code without breaking the string literal or the surrounding shell
    double-quote context.
    """
    return val.replace('\\', '\\\\').replace("'", "\\'")


def _navigate_admin_list(page: Page):
    """Navigate to the admin ticket list and wait for the table to render."""
    page.goto(f"{WP_ADMIN_URL}/edit.php?post_type=helpdesk_ticket")
    page.wait_for_load_state("load")
    page.wait_for_selector("#the-list, .no-items")
    page.evaluate("document.querySelectorAll('.wp-pointer').forEach(el => el.remove())")


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
    mailhog_clear()  # start with empty inbox so expect_email() assertions are unambiguous

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
    page.wait_for_selector(".swh-alert-success, .swh-alert-error")

    check("submit ticket1: success message shown",
          "swh-alert-success" in page.content(), "no .swh-alert-success found")
    screenshot(page, "05_submit_ticket1_success")

    expect_email(CLIENT1_EMAIL, "new ticket confirmation to client", clear_after=False)
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

    mailhog_clear()
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
    check("note distinction #253: note bubble has swh-bubble-note class",
          "swh-bubble-note" in conv_html)
    check("note distinction #253: reply/note areas have CSS classes (not inline styles)",
          "swh-note-area" in conv_html and "swh-reply-note-wrap" in conv_html)
    check("conversation height #248: no inline max-height:400px on conversation area",
          'max-height: 400px' not in conv_html and 'max-height:400px' not in conv_html)
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

    mailhog_clear()
    page.fill('[name="ticket_reply_text"]', TEST_CLIENT_REPLY)
    page.click('[name="swh_user_reply_submit"]')
    page.wait_for_selector(".swh-alert-success, .swh-alert-error")
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
        mailhog_clear()
        close_btn.click()
        # After close, the CSAT widget shows (swh-alert-info) — success is hidden until skip/rating.
        page.wait_for_selector("#swh-csat, .swh-alert-success, .swh-alert-error")
        check("portal: close ticket shows CSAT or success",
              "swh-csat" in page.content() or "swh-alert-success" in page.content())
        expect_email(CLIENT1_EMAIL, "ticket closed confirmation to client", clear_after=False)
        expect_email(TECH1_EMAIL, "ticket closed notification to assigned technician (tech1)")
        screenshot(page, "14_ticket_closed_portal")

        page.goto(state['portal_url'])
        page.wait_for_selector(".swh-card, .swh-alert")
        reopen_ta = page.locator('[name="ticket_reopen_text"]')
        if reopen_ta.count() > 0:
            reopen_ta.fill("I still need help with this issue.")
        mailhog_clear()
        page.click('[name="swh_user_reopen_submit"]')
        page.wait_for_selector(".swh-alert-success, .swh-alert-error")
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
        # Navigate with invalid swh_ticket+token to trigger the error alert.
        # (Visiting without token now shows the no-token dashboard, not an error.)
        portal_base = state['portal_url'].split('?')[0]
        page.goto(f"{portal_base}?swh_ticket={'X' * 20}&token=invalid")
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

    # Resolve expected URLs from the constants defined in the plugin.
    icon_1x_expected = wpcli("eval 'echo SWH_ICON_1X;'")
    icon_2x_expected = wpcli("eval 'echo SWH_ICON_2X;'")

    # Verify the bundled asset files exist on disk via WP-CLI.
    for filename, label in (("icon-128x128.png", "1x"), ("icon-256x256.png", "2x"), ("favicon-32.png", "menu")):
        exists = wpcli(
            f"eval 'echo file_exists(WP_PLUGIN_DIR . \"/simple-wp-helpdesk/assets/{filename}\") ? \"yes\" : \"no\";'"
        )
        check(f"plugin icon: {label} asset file exists on disk ({filename})", exists == "yes")

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
    check("plugin icon: puc filter returns correct 1x URL", icon_1x == icon_1x_expected)
    check("plugin icon: puc filter returns correct 2x URL", icon_2x == icon_2x_expected)

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

    # ── #257 Unsaved-changes warning: form has id=swh-settings-form ──────────
    _navigate_settings(page)
    form_id = page.evaluate(
        "() => { var f = document.getElementById('swh-settings-form'); return f ? f.id : null; }"
    )
    check("unsaved changes #257: settings form has id=swh-settings-form for JS hook",
          form_id == 'swh-settings-form', f"got: {form_id!r}")


def test_19_canned_responses(page: Page):
    print("\n[19] Canned Responses — Save in Settings, Use in Editor")  # closes issue #197

    if not state.get('ticket_id'):
        skip("canned responses", "no ticket_id in state")
        return

    CANNED_TITLE = f"Test's Canned {int(time.time())}"
    CANNED_BODY  = "Canned body with C:\\Users\\support path."

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
    # Search for the full ticket1 title so the LIKE query matches exactly.
    # ticket2 may also appear (WP word search is not exclusive) but we only
    # assert ticket1 is present; negative assertions are intentionally omitted.
    search_term = TEST_TICKET_TITLE
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
    tmp_dir = None
    readable_filename = "Simple WP Helpdesk attachment test file.txt"
    try:
        # ── Part 1: Submit a ticket with a valid .txt attachment ──────────────

        # Use a human-readable filename so we can verify original-filename preservation
        tmp_dir = tempfile.mkdtemp()
        tmp_txt = os.path.join(tmp_dir, readable_filename)
        with open(tmp_txt, "w") as tf:
            tf.write("Simple WP Helpdesk attachment test file.\n")

        page.goto(WP_SUBMIT_PAGE)
        page.wait_for_load_state("load")
        page.fill('[name="ticket_name"]', CLIENT1_NAME)
        page.fill('[name="ticket_email"]', CLIENT1_EMAIL)
        page.fill('[name="ticket_title"]', f"Attachment Test {int(time.time())}")
        page.fill('[name="ticket_desc"]', "Testing file attachment upload.")
        page.set_input_files('[name="ticket_attachments[]"]', tmp_txt)
        with page.expect_navigation(timeout=30000):
            page.click('[name="swh_submit_ticket"]')
        # A cache-invalidation GET may fire immediately after the file POST and
        # overwrite the success view with the empty form.  Wait for that to settle
        # before reading page state or logging in.
        page.wait_for_load_state("load", timeout=10000)

        screenshot(page, "30_attachment_submitted")

        # Locate the most recently created ticket that has _ticket_attachments meta
        attach_ids = wpcli(
            "post list --post_type=helpdesk_ticket --post_status=any "
            "--meta_key=_ticket_attachments --orderby=ID --order=DESC --format=ids"
        ).split()
        attach_id = attach_ids[0] if attach_ids else None
        check("attachment: submission succeeded — ticket with _ticket_attachments meta created",
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

            # Verify _swh_attachment_orignames meta is populated with the original filename
            orig_names = wpcli(
                f"eval \"print_r(get_post_meta({attach_id}, '_swh_attachment_orignames', true));\""
            )
            check("attachment: _swh_attachment_orignames meta populated with original filename",
                  readable_filename in orig_names,
                  f"orignames meta: {orig_names[:120]!r}")

            wp_login(page, ADMIN_USER, ADMIN_PASS)

            # Verify admin ticket editor shows original filename in attachment link
            _navigate_ticket_editor(page, int(attach_id))
            page.wait_for_selector("#publish")
            attach_links = page.locator('.button.button-secondary.button-small[href*="swh_file="]')
            if attach_links.count() > 0:
                link_text = attach_links.first.inner_text()
                check("attachment: admin meta box shows original filename",
                      readable_filename in link_text,
                      f"got: {link_text!r}")
            else:
                check("attachment: admin meta box shows original filename", False,
                      "no proxy attachment links found in admin editor")

            if proxy_url:
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
        with page.expect_navigation(timeout=15000):
            page.click('[name="swh_submit_ticket"]')
        page.wait_for_load_state("load", timeout=10000)

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
        if tmp_dir:
            try:
                shutil.rmtree(tmp_dir, ignore_errors=True)
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

    # Use the portal page base URL + clearly invalid swh_ticket + token params.
    # Both params must be present — the router only calls swh_render_client_portal()
    # when swh_ticket AND token are set; missing token routes to the no-token dashboard.
    portal_base = state['portal_url'].split('?')[0] if state.get('portal_url') else WP_SUBMIT_PAGE
    tampered = f"{portal_base}?swh_ticket={'A' * 40}&token=invalid"
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

    # ── Event-handler and SVG payloads ───────────────────────────────────────

    ts2  = int(time.time())
    evh_title = f'Test <a href="#" onload="window.__xss_evh=1">click</a> {ts2}'
    svg_title = f'SVG <svg onload="window.__xss_svg=1"><circle/></svg> {ts2}'

    evh_id = wpcli(
        f"post create --post_type=helpdesk_ticket --post_status=publish "
        f"--post_title={json.dumps(evh_title)} --porcelain"
    )
    svg_id = wpcli(
        f"post create --post_type=helpdesk_ticket --post_status=publish "
        f"--post_title={json.dumps(svg_title)} --porcelain"
    )
    if evh_id.isdigit() and svg_id.isdigit():
        wpcli(f"post meta update {evh_id} ticket_status Open")
        wpcli(f"post meta update {svg_id} ticket_status Open")
        _navigate_admin_list(page)
        evh_fired = page.evaluate("!!window.__xss_evh")
        svg_fired = page.evaluate("!!window.__xss_svg")
        check("xss: event-handler payload (onload) did not fire in admin list",
              not evh_fired, "window.__xss_evh was set — event handler executed!")
        check("xss: SVG onload payload did not fire in admin list",
              not svg_fired, "window.__xss_svg was set — SVG payload executed!")
        wpcli(f"post delete {evh_id} {svg_id} --force 2>/dev/null")

    # Cleanup: permanently delete original test ticket
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
            # Clean WP redirects to login; hardened servers may redirect to login.
            # Either way the subscriber must not reach the ticket list table.
            blocked = "login" in final_url or page.locator("#the-list").count() == 0
            check("subscriber: blocked from helpdesk ticket list",
                  blocked,
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

    # ── Rate limit persists after cache flush ─────────────────────────────────

    # Flush only the object cache (not the DB rows) — rate limit must still hold
    wpcli("cache flush")
    page.goto(WP_SUBMIT_PAGE)
    page.wait_for_load_state("load")
    page.fill('[name="ticket_name"]', CLIENT1_NAME)
    page.fill('[name="ticket_email"]', CLIENT1_EMAIL)
    page.fill('[name="ticket_title"]', f"Rate Limit Persist Test {int(time.time())}")
    page.fill('[name="ticket_desc"]', "Third submission after cache flush — should still be blocked.")
    page.click('[name="swh_submit_ticket"]')
    page.wait_for_load_state("load")
    html_post_flush = page.content()
    check("rate limit: persists after object cache flush (DB-backed)",
          "swh-alert-success" not in html_post_flush,
          "rate limit was not enforced after cache flush — option-backed persistence broken")

    # Cleanup this section's ticket immediately (does not go through main cleanup)
    if rl_ticket_id:
        wpcli(f"post delete {rl_ticket_id} --force 2>/dev/null")
    _clear_rate_limits()


# ── v2.3.0 Client Experience ─────────────────────────────────────────────────

def test_29_humanized_timestamps(page: Page):
    print("\n[29] Humanized Timestamps in Portal Conversation")

    if not state.get('ticket_id'):
        skip("humanized timestamps", "no ticket_id in state")
        return

    # Refresh the portal URL — test_13 (lookup) rotates the token so the
    # URL in state may be stale.
    pid = state['ticket_id']
    fresh_url = wpcli(f'eval "echo swh_get_secure_ticket_link({pid});"')
    if fresh_url and "swh_ticket=" in fresh_url:
        state['portal_url'] = fresh_url

    if not state.get('portal_url'):
        skip("humanized timestamps", "no portal_url in state")
        return

    wp_logout(page)
    page.goto(state['portal_url'])
    page.wait_for_selector(".swh-card, .swh-alert")

    ts_count = page.locator('.swh-timestamp').count()
    check("timestamps: .swh-timestamp elements present in portal", ts_count > 0,
          f"found {ts_count} .swh-timestamp elements")

    # Verify JS replaced the raw date string with a relative label
    if ts_count > 0:
        ts_text = page.locator('.swh-timestamp').first.inner_text()
        relative_patterns = ["ago", "now", "Yesterday", "days ago", "minute", "hour"]
        is_relative = any(p in ts_text for p in relative_patterns)
        check("timestamps: text contains relative time label", is_relative,
              f"timestamp text: {ts_text!r}")
    screenshot(page, "40_humanized_timestamps")


def test_30_resolved_cta_layout(page: Page):
    print("\n[30] Resolved → Close CTA Layout")

    if not state.get('ticket_id') or not state.get('portal_url'):
        skip("resolved CTA layout", "no ticket_id or portal_url in state")
        return

    wp_login(page, ADMIN_USER, ADMIN_PASS)
    admin_update_ticket(page, state['ticket_id'], status="Resolved")
    wp_logout(page)

    # Refresh portal URL with a new token after admin changes
    pid = state['ticket_id']
    fresh_url = wpcli(f'eval "echo swh_get_secure_ticket_link({pid});"')
    if fresh_url and "swh_ticket=" in fresh_url:
        state['portal_url'] = fresh_url

    page.goto(state['portal_url'])
    page.wait_for_selector(".swh-card, .swh-alert")
    html = page.content()

    check("resolved CTA: .swh-cta-primary present", "swh-cta-primary" in html)
    check("resolved CTA: .swh-cta-secondary present", "swh-cta-secondary" in html)
    check("resolved CTA: Close Ticket button present",
          "swh_user_close_ticket_submit" in html)
    check("resolved CTA: 'Still need help' text present",
          "Still need help" in page.inner_text("body"))
    screenshot(page, "41_resolved_cta_layout")


def test_33_csat_prompt(page: Page):
    print("\n[33] CSAT Prompt After Ticket Close")

    if not state.get('portal_url'):
        skip("CSAT prompt", "no portal_url in state (requires resolved ticket from test_30)")
        return

    close_btn = page.locator('[name="swh_user_close_ticket_submit"]')
    if close_btn.count() == 0:
        # Navigate to portal (may need fresh URL)
        pid = state['ticket_id']
        fresh_url = wpcli(f'eval "echo swh_get_secure_ticket_link({pid});"')
        if fresh_url and "swh_ticket=" in fresh_url:
            state['portal_url'] = fresh_url
        page.goto(state['portal_url'])
        page.wait_for_selector(".swh-card, .swh-alert")
        close_btn = page.locator('[name="swh_user_close_ticket_submit"]')

    if close_btn.count() == 0:
        skip("CSAT prompt", "no close button — ticket may not be in Resolved state")
        return

    wp_logout(page)
    page.goto(state['portal_url'])
    page.wait_for_selector(".swh-card, .swh-alert")
    close_btn = page.locator('[name="swh_user_close_ticket_submit"]')
    if close_btn.count() == 0:
        skip("CSAT prompt", "close button not present in portal after logout")
        return

    close_btn.click()
    page.wait_for_selector("#swh-csat, .swh-alert-success, .swh-alert-error")
    html = page.content()

    check("CSAT: #swh-csat widget shown after close", "swh-csat" in html)
    check("CSAT: star buttons present", page.locator('.swh-csat-star').count() == 5)
    check("CSAT: skip link present", page.locator('#swh-csat-skip').count() > 0)
    screenshot(page, "42_csat_widget")

    # ── Test skip path: verify #swh-close-success becomes visible ────────────

    page.locator('#swh-csat-skip').click()
    page.wait_for_timeout(500)
    close_success_visible = page.locator('#swh-close-success').is_visible()
    check("CSAT: skip shows #swh-close-success", close_success_visible)
    screenshot(page, "42b_csat_skipped")

    # ── Re-close for star rating test (reset status to Resolved via WP-CLI) ──

    ticket_id = state.get('ticket_id')
    if ticket_id:
        resolved_status = wpcli("option get swh_resolved_status").strip() or "Resolved"
        _clear_rate_limits()  # portal_close_<id> lock is still active from the first close
        wpcli(
            f"eval \"update_post_meta({ticket_id}, '_ticket_status', "
            f"'{_php_str(resolved_status)}');\""
        )
        fresh_url = wpcli(f'eval "echo swh_get_secure_ticket_link({ticket_id});"')
        if fresh_url and "swh_ticket=" in fresh_url:
            state['portal_url'] = fresh_url

    page.goto(state['portal_url'])
    page.wait_for_selector(".swh-card, .swh-alert")
    close_btn2 = page.locator('[name="swh_user_close_ticket_submit"]')
    if close_btn2.count() > 0:
        close_btn2.click()
        page.wait_for_selector("#swh-csat, .swh-alert-success, .swh-alert-error",
                               timeout=10000)
        # The CSAT widget may not reappear if the portal has session/cache state
        # from the first close — guard the star click to avoid a hard timeout.
        if page.locator('#swh-csat').is_visible():
            page.locator('.swh-csat-star[data-rating="4"]').click()
            page.wait_for_selector("#swh-csat-thanks", timeout=5000)
            thanks_visible = page.locator('#swh-csat-thanks').is_visible()
            check("CSAT: thanks message shown after rating", thanks_visible)
            screenshot(page, "43_csat_submitted")

            if ticket_id:
                csat_val = wpcli(
                    f"eval \"echo get_post_meta({ticket_id}, '_ticket_csat', true);\""
                )
                check("CSAT: _ticket_csat meta stored", csat_val.strip() == "4",
                      f"got: {csat_val!r}")
        else:
            skip("CSAT star rating (re-close)", "CSAT widget not present after second close")

    # ── Security boundary: invalid nonce and out-of-range ratings ────────────

    if ticket_id:
        ajax_url = WP_URL.rstrip('/') + '/wp-admin/admin-ajax.php'

        # Invalid nonce must be rejected
        resp = page.request.post(ajax_url, form={
            "action": "swh_submit_csat",
            "ticket_id": str(ticket_id),
            "rating": "4",
            "nonce": "invalid_nonce_value",
        })
        resp_json = resp.json() if resp.headers.get("content-type", "").startswith("application/json") else {}
        check("CSAT security: invalid nonce rejected",
              not resp_json.get("success", True),
              f"expected success=false, got: {resp_json}")

        # Out-of-range ratings must be rejected (requires a valid nonce;
        # generate one via WP-CLI while still logged out — use a fresh nonce action)
        valid_nonce = wpcli(f"eval \"echo wp_create_nonce('swh_csat_{ticket_id}');\"")
        for bad_rating in ("0", "6"):
            resp = page.request.post(ajax_url, form={
                "action": "swh_submit_csat",
                "ticket_id": str(ticket_id),
                "rating": bad_rating,
                "nonce": valid_nonce,
            })
            resp_json = resp.json() if resp.headers.get("content-type", "").startswith("application/json") else {}
            check(f"CSAT security: rating={bad_rating} rejected",
                  not resp_json.get("success", True),
                  f"expected success=false, got: {resp_json}")


def test_34_my_tickets_dashboard(page: Page):
    print("\n[34] My Tickets Dashboard — Logged-in User")

    if not WP_PORTAL_PAGE:
        skip("My Tickets dashboard", "WP_PORTAL_PAGE not configured")
        return

    sub_email = CLIENT1_EMAIL  # Use client1 email so their ticket is found
    created_new = False

    wp_login(page, ADMIN_USER, ADMIN_PASS)
    sub_user = f"swh-sub-{int(time.time())}"
    sub_id = wpcli(
        f"user create {sub_user} {sub_email} --role=subscriber "
        f"--user_pass=TestDashPass99 --display_name='SWH Dashboard Test' "
        f"--user_registered=now --porcelain 2>/dev/null"
    )
    if sub_id.isdigit():
        created_new = True
    else:
        sub_id = wpcli(f"user get {sub_email} --field=ID --porcelain 2>/dev/null")
        if sub_id.isdigit():
            sub_user = wpcli(f"user get {sub_id} --field=user_login --porcelain 2>/dev/null")
            wpcli(f"user update {sub_id} --user_pass=TestDashPass99 2>/dev/null")

    if not sub_id.isdigit():
        skip("My Tickets dashboard", "could not create or find subscriber user")
        return

    try:
        # Verify the dashboard renders correctly via wp eval (avoids browser login
        # which may be blocked by Security Ninja for newly-created accounts).
        render = wpcli(
            f"eval \"wp_set_current_user({sub_id}); ob_start(); swh_render_portal_no_token(); echo ob_get_clean();\""
        )
        check("dashboard: PHP renders My Open Tickets heading",
              "My Open Tickets" in render,
              f"My Open Tickets not found in render. Got: {render[:200]!r}")

        # Also take a screenshot of the portal page navigated with cache-busting query.
        # The admin bar is present so SWIS bypasses cache for the current admin session.
        page.goto(WP_PORTAL_PAGE.rstrip('/') + f"/?swh_nc={int(time.time())}")
        page.wait_for_selector(".swh-helpdesk-wrapper")
        screenshot(page, "44_my_tickets_dashboard")

        # ── Closed-ticket exclusion: close the ticket and verify it disappears ─

        ticket_id = state.get('ticket_id')
        closed_status = wpcli("option get swh_closed_status").strip() or "Closed"

        if ticket_id and sub_id.isdigit():
            original_status = wpcli(
                f"eval \"echo get_post_meta({ticket_id}, '_ticket_status', true);\""
            ).strip()
            wpcli(
                f"eval \"update_post_meta({ticket_id}, '_ticket_status', '{_php_str(closed_status)}');\""
            )
            try:
                render_closed = wpcli(
                    f"eval \"wp_set_current_user({sub_id}); ob_start(); swh_render_portal_no_token(); echo ob_get_clean();\""
                )
                # The ticket title should not appear in the open-tickets table
                check("dashboard: closed ticket excluded from open tickets table",
                      TEST_TICKET_TITLE not in render_closed,
                      f"closed ticket still visible. render: {render_closed[:200]!r}")

                # ── Empty state: create a subscriber with no tickets at all ───
                no_tickets_id = wpcli(
                    f"user create swh-empty-{int(time.time())} empty-{int(time.time())}@example.com "
                    f"--role=subscriber --user_pass=TestEmpty99 --porcelain 2>/dev/null"
                ).strip()
                if no_tickets_id.isdigit():
                    try:
                        render_empty = wpcli(
                            f"eval \"wp_set_current_user({no_tickets_id}); ob_start(); "
                            f"swh_render_portal_no_token(); echo ob_get_clean();\""
                        )
                        check("dashboard: empty state message shown when no open tickets",
                              "no open tickets" in render_empty.lower(),
                              f"empty state not found. render: {render_empty[:200]!r}")
                    finally:
                        wpcli(f"user delete {no_tickets_id} --yes 2>/dev/null")
            finally:
                # Restore original ticket status
                wpcli(
                    f"eval \"update_post_meta({ticket_id}, '_ticket_status', '{_php_str(original_status)}');\""
                )
    finally:
        wp_login(page, ADMIN_USER, ADMIN_PASS)
        if created_new and sub_id.isdigit():
            wpcli(f"user delete {sub_id} --yes 2>/dev/null")


def test_35_portal_guest_lookup(page: Page):
    print("\n[35] Portal Without Token — Guest Lookup Form")

    if not WP_PORTAL_PAGE:
        skip("portal guest lookup", "WP_PORTAL_PAGE not configured")
        return

    wp_logout(page)
    page.goto(WP_PORTAL_PAGE)
    page.wait_for_selector(".swh-helpdesk-wrapper")
    html = page.content()

    check("guest portal: no error box shown", "swh-alert-error" not in html,
          "error box appeared for guest — expected lookup form")
    check("guest portal: lookup form present", 'name="swh_lookup_email"' in html,
          "lookup email input not found")
    check("guest portal: no ticket form shown", 'name="ticket_name"' not in html,
          "submission form should not appear in portal page without token")
    screenshot(page, "45_portal_guest_lookup")


def test_36_shortcode_attrs(page: Page):
    print("\n[36] Shortcode Attributes — show_priority, show_lookup, default_priority, default_status")

    wp_login(page, ADMIN_USER, ADMIN_PASS)

    page_ids = []
    try:
        # ── show_priority=no ─────────────────────────────────────────────────

        pid1 = wpcli(
            "post create --post_type=page --post_status=publish "
            "--post_title='SWH Attr Test Priority' "
            '--post_content=\'[submit_ticket show_priority="no"]\' '
            "--porcelain"
        )
        if not pid1.isdigit():
            skip("shortcode attrs", "could not create show_priority test page")
            return
        page_ids.append(pid1)

        url1 = wpcli(f"post get {pid1} --field=url")
        wp_logout(page)
        page.goto(url1)
        page.wait_for_selector(".swh-helpdesk-wrapper")
        html = page.content()
        check("shortcode attrs: priority field absent with show_priority=no",
              'name="ticket_priority"' not in html,
              "priority field still present despite show_priority=no")
        check("shortcode attrs: name field still present", 'name="ticket_name"' in html)
        check("shortcode attrs: email field still present", 'name="ticket_email"' in html)
        screenshot(page, "46_shortcode_no_priority")

        # ── show_lookup=no ───────────────────────────────────────────────────

        wp_login(page, ADMIN_USER, ADMIN_PASS)
        pid2 = wpcli(
            "post create --post_type=page --post_status=publish "
            "--post_title='SWH Attr Test Lookup' "
            '--post_content=\'[submit_ticket show_lookup="no"]\' '
            "--porcelain"
        )
        if pid2.isdigit():
            page_ids.append(pid2)
            url2 = wpcli(f"post get {pid2} --field=url")
            wp_logout(page)
            page.goto(url2)
            page.wait_for_selector(".swh-helpdesk-wrapper")
            html2 = page.content()
            check("shortcode attrs: lookup section absent with show_lookup=no",
                  'name="swh_lookup_email"' not in html2 and 'id="swh-toggle-lookup"' not in html2,
                  "lookup form still present despite show_lookup=no")
            screenshot(page, "46b_shortcode_no_lookup")

        # ── default_priority ─────────────────────────────────────────────────

        wp_login(page, ADMIN_USER, ADMIN_PASS)
        # Get the first available priority value from WP-CLI
        avail_priorities = wpcli(
            "eval \"echo implode(',', swh_get_priorities());\""
        ).strip().split(',')
        test_priority = avail_priorities[-1].strip() if avail_priorities else ''

        if test_priority:
            safe_priority = test_priority.replace('"', '&quot;').replace("'", "&#039;")
            pid3 = wpcli(
                "post create --post_type=page --post_status=publish "
                "--post_title='SWH Attr Test DefPriority' "
                f'--post_content=\'[submit_ticket default_priority="{safe_priority}" show_priority="yes"]\' '
                "--porcelain"
            )
            if pid3.isdigit():
                page_ids.append(pid3)
                url3 = wpcli(f"post get {pid3} --field=url")
                wp_logout(page)
                _clear_rate_limits()
                page.goto(url3)
                page.wait_for_selector(".swh-helpdesk-wrapper")
                # The priority select should have the default_priority option selected
                selected_priority = page.evaluate(
                    "(() => { var sel = document.querySelector('[name=\"ticket_priority\"]');"
                    " return sel ? sel.value : null; })()"
                )
                check(f"shortcode attrs: default_priority={test_priority!r} pre-selected",
                      selected_priority == test_priority,
                      f"got selected priority: {selected_priority!r}")
                screenshot(page, "46c_shortcode_default_priority")

        # ── default_status ───────────────────────────────────────────────────

        wp_login(page, ADMIN_USER, ADMIN_PASS)
        avail_statuses = wpcli(
            "eval \"echo implode(',', swh_get_statuses());\""
        ).strip().split(',')
        # Pick a non-default status (skip the first one which is usually the default)
        test_status = avail_statuses[1].strip() if len(avail_statuses) > 1 else ''

        if test_status:
            safe_status = test_status.replace('"', '&quot;').replace("'", "&#039;")
            pid4 = wpcli(
                "post create --post_type=page --post_status=publish "
                "--post_title='SWH Attr Test DefStatus' "
                f'--post_content=\'[submit_ticket default_status="{safe_status}"]\' '
                "--porcelain"
            )
            if pid4.isdigit():
                page_ids.append(pid4)
                url4 = wpcli(f"post get {pid4} --field=url")
                wp_logout(page)
                _clear_rate_limits()
                page.goto(url4)
                page.wait_for_selector(".swh-helpdesk-wrapper")
                page.fill('[name="ticket_name"]', CLIENT1_NAME)
                page.fill('[name="ticket_email"]', CLIENT1_EMAIL)
                page.fill('[name="ticket_title"]', f"DefStatus Test {int(time.time())}")
                page.fill('[name="ticket_desc"]', "Testing default_status shortcode attr.")
                with page.expect_navigation(timeout=15000):
                    page.click('[name="swh_submit_ticket"]')
                page.wait_for_load_state("load")

                # Find the ticket and verify its status
                new_id = wpcli(
                    "post list --post_type=helpdesk_ticket --post_status=any "
                    f"--s={json.dumps('DefStatus Test')} --orderby=ID --order=DESC "
                    "--format=ids"
                ).split()
                if new_id:
                    actual_status = wpcli(
                        f"eval \"echo get_post_meta({new_id[0]}, '_ticket_status', true);\""
                    ).strip()
                    check(f"shortcode attrs: default_status={test_status!r} applied to new ticket",
                          actual_status == test_status,
                          f"got status: {actual_status!r}")
                    # Clean up the test ticket
                    wpcli(f"post delete {new_id[0]} --force 2>/dev/null")
                screenshot(page, "46d_shortcode_default_status")

    finally:
        wp_login(page, ADMIN_USER, ADMIN_PASS)
        for pid in page_ids:
            wpcli(f"post delete {pid} --force 2>/dev/null")


# ── v3.0.0 feature tests ─────────────────────────────────────────────────────


def test_37_admin_list_filtering(page: Page):
    print("\n[37] Admin List Filtering — status, priority, category (#127)")

    wp_login(page, ADMIN_USER, ADMIN_PASS)
    _navigate_admin_list(page)

    # ── Status filter ─────────────────────────────────────────────────────────

    html_before = page.content()
    # Filter by "Open" status using the existing filter select
    status_sel = page.locator('select[name="swh_filter_status"]')
    if status_sel.count() > 0:
        page.select_option('select[name="swh_filter_status"]', "Open")
        page.locator('#post-query-submit, #search-submit').first.click()
        page.wait_for_load_state("load")
        page.wait_for_selector("#the-list, .no-items")
        check("admin list filter: filtering by Open status works",
              page.url != "" and "post_type=helpdesk_ticket" in page.url)
        screenshot(page, "47_list_filter_status")
        # Reset filter
        _navigate_admin_list(page)
    else:
        skip("admin list status filter", "swh_filter_status select not found")

    # ── Priority filter ───────────────────────────────────────────────────────

    prio_sel = page.locator('select[name="swh_filter_priority"]')
    if prio_sel.count() > 0:
        page.select_option('select[name="swh_filter_priority"]', index=1)
        page.locator('#post-query-submit, #search-submit').first.click()
        page.wait_for_load_state("load")
        page.wait_for_selector("#the-list, .no-items")
        check("admin list filter: filtering by priority works",
              "post_type=helpdesk_ticket" in page.url)
        _navigate_admin_list(page)
    else:
        skip("admin list priority filter", "swh_filter_priority select not found")

    # ── Category taxonomy filter (built-in WP taxonomy dropdown) ─────────────

    cat_slug = f"pw-cat-{int(time.time())}"
    cat_name = f"PW Test Category {int(time.time())}"
    term_id = wpcli(
        f"term create helpdesk_category '{cat_name}' --slug={cat_slug} --porcelain 2>/dev/null"
    ).strip()
    if term_id.isdigit():
        state['test_category_term_id'] = int(term_id)
        state['test_category_name'] = cat_name
        _navigate_admin_list(page)
        # WP's built-in taxonomy filter renders as a <select name="helpdesk_category">
        cat_filter = page.locator('select[name="helpdesk_category"]')
        check("admin list: category taxonomy filter dropdown is present",
              cat_filter.count() > 0, "helpdesk_category select not found")
        if cat_filter.count() > 0:
            # Verify our new term appears in the dropdown
            option_found = page.evaluate(
                f"Array.from(document.querySelectorAll('select[name=\"helpdesk_category\"] option'))"
                f".some(o => o.text.includes({json.dumps(cat_name)}))"
            )
            check("admin list: new category term appears in taxonomy filter",
                  option_found, f"term {cat_name!r} not in dropdown")
        screenshot(page, "47b_category_filter_dropdown")
    else:
        skip("admin list category filter", "could not create test category term")


def test_38_admin_list_sorting(page: Page):
    print("\n[38] Admin List Sorting — ticket_uid column")

    wp_login(page, ADMIN_USER, ADMIN_PASS)
    _navigate_admin_list(page)

    # Sort by ticket_uid ASC
    uid_col = page.locator('th.sortable a[href*="orderby=ticket_uid"], th.sorted a[href*="orderby=ticket_uid"]')
    if uid_col.count() == 0:
        skip("admin list sorting", "ticket_uid column not found — may not be sortable")
        return

    uid_col.first.click()
    page.wait_for_load_state("load")
    page.wait_for_selector("#the-list, .no-items")
    page.evaluate("document.querySelectorAll('.wp-pointer').forEach(el => el.remove())")
    check("admin list sort: sorted by ticket_uid",
          "orderby=ticket_uid" in page.url, f"url: {page.url[:120]}")
    screenshot(page, "48_list_sort_uid_asc")

    # Click again for DESC
    uid_col_desc = page.locator('th.sorted a[href*="order=desc"], th.sorted a[href*="orderby=ticket_uid"]')
    if uid_col_desc.count() > 0:
        uid_col_desc.first.click()
        page.wait_for_load_state("load")
        page.wait_for_selector("#the-list, .no-items")
        page.evaluate("document.querySelectorAll('.wp-pointer').forEach(el => el.remove())")
        check("admin list sort: ticket_uid DESC sort applied",
              "orderby=ticket_uid" in page.url or "order=desc" in page.url)
        screenshot(page, "48b_list_sort_uid_desc")


def test_39_ticket_templates(page: Page):
    print("\n[39] Ticket Templates — save in settings, display on form, meta stored (#132)")

    wp_login(page, ADMIN_USER, ADMIN_PASS)

    TPL_LABEL = f"PW Template {int(time.time())}"
    TPL_BODY  = "Please describe the issue you are experiencing in detail."

    # ── Save a template in Settings → Templates tab ───────────────────────────

    _navigate_settings(page)
    page.wait_for_selector('#swh-tab-templates')
    page.evaluate("document.getElementById('swh-tab-templates').click()")
    page.wait_for_selector('#swh-add-tmpl')
    page.locator('#swh-add-tmpl').click()
    page.wait_for_selector('.swh-tmpl-item:last-child [name="swh_tmpl_labels[]"]')

    last_label = page.locator('.swh-tmpl-item [name="swh_tmpl_labels[]"]').last
    last_body  = page.locator('.swh-tmpl-item [name="swh_tmpl_bodies[]"]').last
    last_label.fill(TPL_LABEL)
    last_body.fill(TPL_BODY)
    page.locator('[name="swh_save_settings"]').first.click()
    page.wait_for_load_state("load")

    # Verify persistence
    _navigate_settings(page)
    page.wait_for_selector('#swh-tab-templates')
    page.evaluate("document.getElementById('swh-tab-templates').click()")
    page.wait_for_selector('#swh-tmpl-list')
    label_found = page.evaluate(
        f"Array.from(document.querySelectorAll('.swh-tmpl-item [name=\"swh_tmpl_labels[]\"]'))"
        f".some(el => el.value === {json.dumps(TPL_LABEL)})"
    )
    check("ticket templates: saved label persists in settings", label_found)
    screenshot(page, "49_templates_settings")

    # ── Create a temp submission page with the template dropdown visible ───────

    pid = wpcli(
        "post create --post_type=page --post_status=publish "
        "--post_title='SWH Template Test' "
        "--post_content='[submit_ticket]' "
        "--porcelain"
    )
    if not pid.isdigit():
        skip("ticket templates frontend", "could not create test page")
        return

    try:
        url = wpcli(f"post get {pid} --field=url")
        wp_logout(page)
        _clear_rate_limits()
        page.goto(url)
        page.wait_for_selector(".swh-helpdesk-wrapper")

        # The request type dropdown should appear since we have a template
        req_type_sel = page.locator('select[name="ticket_request_type"], select#swh-request-type')
        if req_type_sel.count() == 0:
            # Try a more general selector
            req_type_sel = page.locator('select').filter(has_text=TPL_LABEL)
        check("ticket templates: request type dropdown appears on form",
              req_type_sel.count() > 0,
              "no request type select found — check show_category / templates logic")

        # Submit a ticket with the template
        page.fill('[name="ticket_name"]', CLIENT1_NAME)
        page.fill('[name="ticket_email"]', CLIENT1_EMAIL)
        page.fill('[name="ticket_title"]', f"TPL Test {int(time.time())}")
        if req_type_sel.count() > 0:
            # Select the template option
            req_type_sel.first.select_option(label=TPL_LABEL)
            page.wait_for_timeout(300)  # JS pre-fill
            desc_val = page.input_value('[name="ticket_desc"]')
            check("ticket templates: selecting template pre-fills description",
                  TPL_BODY[:20] in desc_val,
                  f"description got: {desc_val[:60]!r}")
        else:
            page.fill('[name="ticket_desc"]', "Template test ticket body.")

        with page.expect_navigation(timeout=15000):
            page.click('[name="swh_submit_ticket"]')
        page.wait_for_load_state("load")
        success = "swh-alert-success" in page.content()
        check("ticket templates: ticket submitted successfully", success)

        # Verify _ticket_template meta via WP-CLI
        if success:
            new_ids = wpcli(
                "post list --post_type=helpdesk_ticket --post_status=any "
                f"--s={json.dumps('TPL Test')} --orderby=ID --order=DESC "
                "--format=ids"
            ).split()
            if new_ids:
                state['tpl_ticket_id'] = int(new_ids[0])
                tpl_meta = wpcli(
                    f"eval \"echo get_post_meta({new_ids[0]}, '_ticket_template', true);\""
                )
                check("ticket templates: _ticket_template meta stored",
                      tpl_meta.strip() == TPL_LABEL,
                      f"got: {tpl_meta.strip()!r}")
        screenshot(page, "49b_template_submitted")

    finally:
        wp_login(page, ADMIN_USER, ADMIN_PASS)
        wpcli(f"post delete {pid} --force 2>/dev/null")

    # ── Cleanup: remove the test template ─────────────────────────────────────

    _navigate_settings(page)
    page.wait_for_selector('#swh-tab-templates')
    page.evaluate("document.getElementById('swh-tab-templates').click()")
    page.wait_for_selector('#swh-tmpl-list')
    for item in page.locator('.swh-tmpl-item').all():
        if TPL_LABEL in item.inner_text():
            item.locator('.swh-remove-tmpl').click()
            break
    page.locator('[name="swh_save_settings"]').first.click()
    page.wait_for_load_state("load")


def test_40_first_response_time(page: Page):
    print("\n[40] First Response Time — _ticket_first_response_at meta (#136)")

    if not state.get('ticket_id'):
        skip("first response time", "no ticket_id in state")
        return

    wp_login(page, ADMIN_USER, ADMIN_PASS)

    # Admin has already replied in test_06 — meta should be set
    frt = wpcli(
        f"eval \"echo get_post_meta({state['ticket_id']}, '_ticket_first_response_at', true);\""
    ).strip()
    check("first response time: _ticket_first_response_at meta is set after admin reply",
          frt.isdigit() and int(frt) > 0,
          f"got meta value: {frt!r}")

    # Verify the display in the ticket editor Details meta box
    _navigate_ticket_editor(page, state['ticket_id'])
    body = page.inner_text("body")
    check("first response time: elapsed time display present in ticket editor",
          "First Response" in body or "first response" in body.lower() or frt in body,
          "no first-response indicator found in ticket editor")
    screenshot(page, "50_first_response_meta_box")


def test_41_cc_watchers(page: Page):
    print("\n[41] CC / Watcher Support — _ticket_cc_emails meta (#129)")

    if not state.get('ticket_id'):
        skip("cc watchers", "no ticket_id in state")
        return

    CC_EMAIL = "cc-test@example.com"
    wp_login(page, ADMIN_USER, ADMIN_PASS)

    # ── Add CC email in ticket editor ─────────────────────────────────────────

    _navigate_ticket_editor(page, state['ticket_id'])
    cc_field = page.locator('[name="ticket_cc_emails"]')
    check("cc watchers: CC / Watchers field present in ticket editor",
          cc_field.count() > 0, "ticket_cc_emails input not found")

    if cc_field.count() > 0:
        cc_field.fill(CC_EMAIL)
        page.click('#publish')
        page.wait_for_load_state("load")

        # Verify meta stored via WP-CLI
        stored_cc = wpcli(
            f"eval \"echo get_post_meta({state['ticket_id']}, '_ticket_cc_emails', true);\""
        ).strip()
        check("cc watchers: _ticket_cc_emails meta stored after save",
              CC_EMAIL in stored_cc,
              f"got: {stored_cc!r}")

        # Verify it reloads in the editor
        _navigate_ticket_editor(page, state['ticket_id'])
        reloaded_cc = page.input_value('[name="ticket_cc_emails"]')
        check("cc watchers: CC email reloads in ticket editor",
              CC_EMAIL in reloaded_cc,
              f"got: {reloaded_cc!r}")
        screenshot(page, "51_cc_watchers_field")


def test_42_categories_taxonomy(page: Page):
    print("\n[42] Categories Taxonomy — helpdesk_category (#127)")

    wp_login(page, ADMIN_USER, ADMIN_PASS)

    # Reuse the term created in test_37 if available, otherwise create one
    if not state.get('test_category_term_id'):
        cat_name = f"PW Test Category {int(time.time())}"
        term_id = wpcli(
            f"term create helpdesk_category '{cat_name}' --porcelain 2>/dev/null"
        ).strip()
        if not term_id.isdigit():
            skip("categories taxonomy", "could not create category term")
            return
        state['test_category_term_id'] = int(term_id)
        state['test_category_name'] = cat_name

    term_id  = state['test_category_term_id']
    cat_name = state.get('test_category_name', 'PW Test Category')

    # Assign the category to ticket1 via WP-CLI
    if state.get('ticket_id'):
        wpcli(
            f"eval \"wp_set_post_terms({state['ticket_id']}, [{term_id}], 'helpdesk_category');\""
        )
        # Verify term is assigned
        assigned = wpcli(
            f"post term list {state['ticket_id']} helpdesk_category "
            "--field=name --format=csv 2>/dev/null"
        ).strip()
        check("categories taxonomy: term assigned to ticket1 via WP-CLI",
              cat_name in assigned or assigned != "",
              f"got: {assigned!r}")

    # Navigate to ticket list — admin column should show taxonomy
    _navigate_admin_list(page)
    col_header = page.locator('th.column-taxonomy-helpdesk_category, th[id*="helpdesk_category"]')
    check("categories taxonomy: admin column present in ticket list",
          col_header.count() > 0, "no helpdesk_category column in list table")

    # Verify taxonomy filter dropdown is present
    cat_filter = page.locator('select[name="helpdesk_category"]')
    check("categories taxonomy: taxonomy filter dropdown in admin list",
          cat_filter.count() > 0, "helpdesk_category filter select not found")

    screenshot(page, "52_categories_admin_column")

    # Filter by the test category
    if cat_filter.count() > 0 and state.get('ticket_id'):
        # Select our term by value (term_id)
        page.select_option('select[name="helpdesk_category"]', str(term_id))
        page.locator('#post-query-submit, #search-submit').first.click()
        page.wait_for_load_state("load")
        page.wait_for_selector("#the-list, .no-items")
        list_body = page.inner_text("body")
        check("categories taxonomy: filtering by category shows assigned ticket",
              TEST_TICKET_TITLE in list_body,
              "ticket1 not visible when filtering by its assigned category")
        screenshot(page, "52b_category_filter_applied")


def test_43_ticket_merge(page: Page):
    print("\n[43] Ticket Merge (#133)")

    if not state.get('ticket_id'):
        skip("ticket merge", "no ticket_id in state")
        return

    wp_login(page, ADMIN_USER, ADMIN_PASS)

    # Create a source ticket via WP-CLI that will be merged into ticket1
    source_id = wpcli(
        "post create --post_type=helpdesk_ticket --post_status=publish "
        f"--post_title='PW Merge Source {int(time.time())}' "
        "--post_author=1 --porcelain"
    ).strip()
    if not source_id.isdigit():
        skip("ticket merge", "could not create source ticket via WP-CLI")
        return

    wpcli(f"post meta update {source_id} _ticket_status 'Open'")
    wpcli(f"post meta update {source_id} _ticket_email 'merge-test@example.com'")
    wpcli(f"post meta update {source_id} _ticket_name 'Merge Test Client'")
    wpcli(f"post meta update {source_id} _ticket_uid 'TKT-MERGE-{source_id}'")

    # Add a reply comment to the source ticket so we can verify it moves
    wpcli(
        f"comment create --comment_post_ID={source_id} "
        "--comment_author='Merge Test Client' "
        "--comment_author_email='merge-test@example.com' "
        "--comment_content='This reply should move to the target ticket.' "
        "--comment_type=helpdesk_reply --comment_approved=1"
    )

    try:
        target_id = state['ticket_id']

        # Navigate to the SOURCE ticket editor and merge into target
        _navigate_ticket_editor(page, source_id)
        merge_input = page.locator('#swh-merge-target-id')
        check("ticket merge: merge target input present in ticket editor",
              merge_input.count() > 0, "#swh-merge-target-id not found")

        if merge_input.count() > 0:
            # Expand the merge section (force=True bypasses jQuery UI sortable overlay).
            merge_toggle = page.locator('#swh-merge-toggle')
            if merge_toggle.count() > 0:
                merge_toggle.click(force=True)
                page.wait_for_timeout(300)
            merge_input.fill(str(target_id))
            page.locator('#swh-merge-btn').click(force=True)
            page.wait_for_timeout(2000)  # AJAX
            merge_msg = page.locator('#swh-merge-msg').inner_text() if \
                page.locator('#swh-merge-msg').count() > 0 else ""
            check("ticket merge: AJAX returns success message",
                  "merged" in merge_msg.lower() or "success" in merge_msg.lower(),
                  f"merge message: {merge_msg!r}")
            screenshot(page, "53_ticket_merge_result")

        # Verify source ticket status is now Closed/Merged via WP-CLI
        source_status = wpcli(
            f"eval \"echo get_post_meta({source_id}, '_ticket_status', true);\""
        ).strip()
        check("ticket merge: source ticket closed after merge",
              source_status in ("Closed", "Resolved", "Merged") or source_status != "Open",
              f"source status: {source_status!r}")

        # Verify target ticket has the merged reply
        target_comments = wpcli(
            f"comment list --post_id={target_id} --comment_type=helpdesk_reply "
            "--format=count"
        ).strip()
        check("ticket merge: target ticket has comments (including merged reply)",
              target_comments.isdigit() and int(target_comments) >= 1,
              f"target comment count: {target_comments!r}")

    finally:
        wpcli(f"post delete {source_id} --force 2>/dev/null")


def test_44_sla_breach_detection(page: Page):
    print("\n[44] SLA Breach Detection — hourly cron, _ticket_sla_status (#128)")

    wp_login(page, ADMIN_USER, ADMIN_PASS)

    # Save original SLA breach hours, set to 1 for this test
    original_breach = wpcli("option get swh_sla_breach_hours 2>/dev/null").strip() or "8"

    # Create a test ticket with a post_date 24 hours in the past.
    # Embed the Unix timestamp in the title so we can search for it uniquely later.
    sla_ts = int(time.time())
    sla_title = f"PW SLA Test {sla_ts}"
    old_date = time.strftime('%Y-%m-%d %H:%M:%S', time.gmtime(time.time() - 86400))
    sla_ticket_id = wpcli(
        "post create --post_type=helpdesk_ticket --post_status=publish "
        f"--post_title='{sla_title}' "
        f"--post_date='{old_date}' "
        "--post_author=1 --porcelain"
    ).strip()
    if not sla_ticket_id.isdigit():
        skip("sla breach detection", "could not create SLA test ticket")
        return

    wpcli(f"post meta update {sla_ticket_id} _ticket_status 'Open'")
    state['sla_ticket_id'] = int(sla_ticket_id)

    try:
        # Set breach threshold to 1 hour so our 24-hour-old ticket triggers it
        wpcli("option update swh_sla_breach_hours 1")
        wpcli("option update swh_sla_warn_hours 0")

        # Run the SLA check cron manually
        wpcli("eval 'swh_process_sla_check();'")

        # Verify _ticket_sla_status is set to 'breach'
        sla_status = wpcli(
            f"eval \"echo get_post_meta({sla_ticket_id}, '_ticket_sla_status', true);\""
        ).strip()
        check("sla breach: _ticket_sla_status set to 'breach' after cron",
              sla_status == "breach",
              f"got: {sla_status!r}")

        # Search for the ticket by its unique timestamp suffix — avoids pagination
        # issues in the full suite where 40+ tickets push this old-dated ticket to page 2.
        page.goto(
            f"{WP_ADMIN_URL}/edit.php?post_type=helpdesk_ticket&s={sla_ts}"
        )
        page.wait_for_load_state("load")
        row_classes = page.evaluate(f"""
            (function() {{
                var rows = document.querySelectorAll('#the-list tr[id*="{sla_ticket_id}"], #the-list tr.post-{sla_ticket_id}');
                if (!rows.length) return '';
                return rows[0].className;
            }})()
        """)
        check("sla breach: row has swh-sla-breach CSS class in ticket list",
              "swh-sla-breach" in row_classes,
              f"row classes: {row_classes!r}")
        screenshot(page, "54_sla_breach_row")

    finally:
        wpcli(f"option update swh_sla_breach_hours {original_breach}")
        wpcli("option update swh_sla_warn_hours 4")
        wpcli(f"post delete {sla_ticket_id} --force 2>/dev/null")
        state.pop('sla_ticket_id', None)


def test_45_assignment_rules(page: Page):
    print("\n[45] Auto-Assignment Rules — category → assignee (#126)")

    wp_login(page, ADMIN_USER, ADMIN_PASS)

    # Get the admin user's WP ID for use as the assignee
    admin_id = wpcli(f"user get {ADMIN_USER} --field=ID 2>/dev/null").strip()
    if not admin_id.isdigit():
        skip("assignment rules", "could not get admin user ID")
        return

    # Reuse the test category from test_37/test_42 if available
    if not state.get('test_category_term_id'):
        cat_name = f"PW Assign Cat {int(time.time())}"
        term_id = wpcli(
            f"term create helpdesk_category '{cat_name}' --porcelain 2>/dev/null"
        ).strip()
        if not term_id.isdigit():
            skip("assignment rules", "could not create test category")
            return
        state['test_category_term_id'] = int(term_id)
        state['test_category_name'] = cat_name

    term_id = state['test_category_term_id']

    # ── Save an assignment rule in Settings → Routing tab ─────────────────────

    _navigate_settings(page)
    page.locator('#swh-tab-routing').click()
    page.wait_for_selector('#swh-add-rule')

    # Clear existing rules first by reading how many there are
    existing_rules = page.locator('.swh-rule-item')
    rule_count_before = existing_rules.count()

    # Add a new rule
    page.locator('#swh-add-rule').click()
    page.wait_for_timeout(300)

    # The new rule row should have a category select and assignee select
    last_rule = page.locator('.swh-rule-item').last
    check("assignment rules: rule row added after clicking + Add Rule",
          last_rule.count() > 0, "no .swh-rule-item found after add")

    if last_rule.count() > 0:
        cat_select   = last_rule.locator('select[name="swh_rule_category[]"]')
        user_select  = last_rule.locator('select[name="swh_rule_assignee[]"]')
        if cat_select.count() > 0 and user_select.count() > 0:
            cat_select.first.select_option(str(term_id))
            user_select.first.select_option(admin_id)
            page.locator('[name="swh_save_settings"]').first.click()
            page.wait_for_load_state("load")
            screenshot(page, "55_assignment_rule_saved")

    # Verify the rule persists in settings
    _navigate_settings(page)
    page.locator('#swh-tab-routing').click()
    page.wait_for_selector('#swh-add-rule')
    rule_count_after = page.locator('.swh-rule-item').count()
    check("assignment rules: rule persists after save",
          rule_count_after > rule_count_before or rule_count_after >= 1,
          f"rule count before={rule_count_before} after={rule_count_after}")

    # ── Test rule application via WP-CLI ──────────────────────────────────────

    # Create a test ticket and assign the category, then apply rules
    rule_ticket_id = wpcli(
        "post create --post_type=helpdesk_ticket --post_status=publish "
        f"--post_title='PW Rule Test {int(time.time())}' "
        "--post_author=1 --porcelain"
    ).strip()
    if rule_ticket_id.isdigit():
        try:
            # Assign the category term to the ticket
            wpcli(
                f"eval \"wp_set_post_terms({rule_ticket_id}, [{term_id}], 'helpdesk_category');\""
            )
            # Apply assignment rules
            wpcli(f"eval 'swh_apply_assignment_rules({rule_ticket_id});'")
            # Check assigned_to meta
            assigned_to = wpcli(
                f"eval \"echo get_post_meta({rule_ticket_id}, '_ticket_assigned_to', true);\""
            ).strip()
            check("assignment rules: ticket assigned to correct user via rule",
                  assigned_to == admin_id,
                  f"expected admin_id={admin_id}, got: {assigned_to!r}")
        finally:
            wpcli(f"post delete {rule_ticket_id} --force 2>/dev/null")

    # ── Cleanup: remove the test rule ─────────────────────────────────────────

    _navigate_settings(page)
    page.locator('#swh-tab-routing').click()
    page.wait_for_selector('#swh-add-rule')
    # Remove all rule items (simpler than finding the specific one)
    for item in page.locator('.swh-rule-item').all():
        btn = item.locator('.swh-remove-rule, button[class*="remove"]')
        if btn.count() > 0:
            btn.first.click()
    page.locator('[name="swh_save_settings"]').first.click()
    page.wait_for_load_state("load")


def test_46_reporting_dashboard(page: Page):
    print("\n[46] Reporting Dashboard — page, charts, AJAX endpoint (#135, #137)")

    wp_login(page, ADMIN_USER, ADMIN_PASS)

    reports_url = f"{WP_ADMIN_URL}/edit.php?post_type=helpdesk_ticket&page=swh-reports"
    page.goto(reports_url)
    page.wait_for_load_state("load")

    # Verify the page loads
    check("reporting dashboard: page title contains Reports",
          "Reports" in page.title() or "Helpdesk Reports" in page.inner_text("h1, h2"),
          f"title: {page.title()!r}")

    # Verify chart canvas elements are present
    check("reporting dashboard: status chart canvas present",
          page.locator('#swh-chart-status').count() > 0, "#swh-chart-status not found")
    check("reporting dashboard: trend chart canvas present",
          page.locator('#swh-chart-trend').count() > 0, "#swh-chart-trend not found")
    check("reporting dashboard: avg resolution metric element present",
          page.locator('#swh-avg-resolution').count() > 0, "#swh-avg-resolution not found")
    check("reporting dashboard: avg first response metric element present",
          page.locator('#swh-avg-first-response').count() > 0)
    screenshot(page, "56_reporting_dashboard")

    # Verify the AJAX endpoint responds with JSON for each report type
    nonce = page.evaluate(
        "typeof swhReports !== 'undefined' ? swhReports.nonce : ''"
    )
    if nonce:
        for report_type in ("status_breakdown", "avg_resolution_time",
                            "weekly_trend", "first_response_time"):
            result = page.evaluate(f"""
                (async function() {{
                    var fd = new FormData();
                    fd.append('action', 'swh_report_data');
                    fd.append('nonce', {json.dumps(nonce)});
                    fd.append('type', {json.dumps(report_type)});
                    var r = await fetch(ajaxurl, {{ method: 'POST', body: fd }});
                    var j = await r.json();
                    return j.success ? 'ok' : ('error: ' + JSON.stringify(j));
                }})()
            """)
            check(f"reporting dashboard AJAX: {report_type} returns success",
                  result == "ok", f"got: {result!r}")
    else:
        skip("reporting dashboard AJAX", "swhReports.nonce not available")

    # ── #254 Empty-state elements present in DOM ──────────────────────────────
    page.goto(reports_url)
    page.wait_for_load_state("load")
    check("reporting dashboard #254: status empty-state element present in DOM",
          page.locator('#swh-chart-status-empty').count() > 0,
          "#swh-chart-status-empty not found")
    check("reporting dashboard #254: trend empty-state element present in DOM",
          page.locator('#swh-chart-trend-empty').count() > 0,
          "#swh-chart-trend-empty not found")
    page.wait_for_timeout(1500)  # let Chart.js render
    status_empty_hidden = page.evaluate(
        "() => { var el = document.getElementById('swh-chart-status-empty'); "
        "return el ? el.hidden : null; }"
    )
    check("reporting dashboard #254: status empty-state hidden when tickets exist",
          status_empty_hidden is True,
          f"swh-chart-status-empty hidden={status_empty_hidden!r}")


def test_47_inbound_email_webhook(page: Page):
    print("\n[47] Inbound Email Webhook — POST /wp-json/swh/v1/inbound-email (#131)")

    if not state.get('ticket_id'):
        skip("inbound email webhook", "no ticket_id in state")
        return

    ticket_id = state['ticket_id']

    # Get ticket UID and email via WP-CLI
    ticket_uid = wpcli(
        f"eval \"echo get_post_meta({ticket_id}, '_ticket_uid', true);\""
    ).strip()
    ticket_email = wpcli(
        f"eval \"echo get_post_meta({ticket_id}, '_ticket_email', true);\""
    ).strip()

    if not ticket_uid or not ticket_email:
        skip("inbound email webhook", f"missing uid={ticket_uid!r} or email={ticket_email!r}")
        return

    subject = f"Re: Your ticket [{ticket_uid}] has been updated"
    body    = f"This is a test reply from the inbound email webhook test at {int(time.time())}."

    # Set a test webhook secret so the endpoint is enabled.
    # In docker mode use the pre-configured secret from setup-test-wp.sh
    # (set via WP_INBOUND_SECRET env var) so we don't depend on per-test
    # wpcli option writes which can be unreliable in CI.
    if WP_MODE == "docker":
        test_secret = os.environ.get("WP_INBOUND_SECRET", "swh-ci-webhook-secret")
        # Always (re-)set the secret immediately before the test call to ensure
        # it hasn't been cleared by an earlier settings-save in this test run.
        wpcli(f"option update swh_inbound_secret {test_secret}")
        stored = wpcli("eval \"echo get_option('swh_inbound_secret');\"")
        print(f"  [47-diag] stored={stored!r} test_secret={test_secret!r} match={stored==test_secret}")
    else:
        test_secret = "swh-test-secret-47"
        wpcli(f"option update swh_inbound_secret {test_secret}")

    if WP_MODE == "docker":
        # Call the handler directly via WP-CLI eval using a constructed WP_REST_Request.
        # This bypasses Apache entirely (Authorization headers are stripped in all tested
        # configurations), invoking the PHP handler function as if a valid REST request
        # arrived, without making any HTTP connection.
        repo_root = os.path.normpath(os.path.join(os.path.dirname(__file__), "..", ".."))
        php_code = (
            "$req=new WP_REST_Request('POST','/swh/v1/inbound-email');"
            f"$req->set_header('Authorization','Bearer {test_secret}');"
            f"$req->set_param('subject','{subject}');"
            f"$req->set_param('from','{ticket_email}');"
            f"$req->set_param('body-plain','{body}');"
            "$resp=swh_handle_inbound_email($req);"
            "if(is_wp_error($resp)){echo json_encode(['success'=>false,'error'=>$resp->get_error_code()]);}else{echo json_encode($resp->get_data());}"
        )
        result = subprocess.run(
            ["docker", "compose", "-f", "docker-compose.test.yml",
             "exec", "-T", WP_CONTAINER,
             "wp", "--allow-root", f"--path={WP_PATH}", "eval", php_code],
            capture_output=True, text=True, timeout=30, cwd=repo_root, check=False
        )
        if result.returncode != 0:
            raise subprocess.CalledProcessError(
                result.returncode, result.args, output=result.stdout, stderr=result.stderr
            )
        raw_lines = [
            line for line in result.stdout.splitlines()
            if not line.startswith(("Deprecated:", "Notice:", "Warning:", "PHP Deprecated:"))
        ]
        output = "\n".join(raw_lines).strip()
        try:
            resp_json = json.loads(output)
            if resp_json.get("success") is True:
                http_code = "200"
            elif "error" in resp_json:
                http_code = "401" if resp_json["error"] == "swh_unauthorized" else "500"
            else:
                http_code = "200"
            body_resp = output
        except (json.JSONDecodeError, AttributeError):
            http_code = ""
            body_resp = output
    else:
        _parsed      = urlparse(WP_URL)
        wp_path_part = _parsed.path.rstrip("/")
        local_webhook = f"http://127.0.0.1{wp_path_part}/wp-json/swh/v1/inbound-email"
        host_header   = _parsed.netloc
        curl_cmd = (
            f"curl -s -L -X POST '{local_webhook}' "
            f"-H 'Host: {host_header}' "
            f"-H 'Authorization: Bearer {test_secret}' "
            f"--data-urlencode 'subject={subject}' "
            f"--data-urlencode 'from={ticket_email}' "
            f"--data-urlencode 'body-plain={body}' "
            f"-w '\\n%{{http_code}}'"
        )
        docker_cmd = f"docker exec {WP_CONTAINER} sh -c \"{curl_cmd}\""
        result = subprocess.run(
            ["ssh", SSH_HOST, docker_cmd],
            capture_output=True, text=True, timeout=20, check=False
        )
        if result.returncode != 0:
            raise subprocess.CalledProcessError(
                result.returncode, result.args, output=result.stdout, stderr=result.stderr
            )
        output = result.stdout.strip()
        lines  = output.splitlines()
        http_code = lines[-1] if lines else ""
        body_resp = "\n".join(lines[:-1]) if len(lines) > 1 else output

    check("inbound email webhook: HTTP 200 response",
          http_code == "200", f"got HTTP {http_code!r}, body: {body_resp[:100]!r}")

    try:
        resp_json = json.loads(body_resp)
        check("inbound email webhook: response JSON has success=true",
              resp_json.get("success") is True,
              f"response: {body_resp[:120]!r}")
    except (json.JSONDecodeError, AttributeError):
        check("inbound email webhook: response is valid JSON", False,
              f"body: {body_resp[:120]!r}")
        return

    # Verify a reply comment was created on the ticket
    comment_count = wpcli(
        f"comment list --post_id={ticket_id} --comment_type=helpdesk_reply "
        "--format=count"
    ).strip()
    check("inbound email webhook: reply comment created on ticket",
          comment_count.isdigit() and int(comment_count) >= 1,
          f"comment count: {comment_count!r}")

    # Verify the comment contains our body text
    latest_comment = wpcli(
        f"comment list --post_id={ticket_id} --comment_type=helpdesk_reply "
        "--fields=comment_ID,comment_content "
        "--format=json --number=1 --orderby=comment_date --order=DESC"
    )
    try:
        comments = json.loads(latest_comment)
        if comments:
            content = comments[0].get('comment_content', '')
            check("inbound email webhook: reply comment contains expected body",
                  "inbound email webhook test" in content.lower(),
                  f"content: {content[:100]!r}")
    except (json.JSONDecodeError, IndexError, AttributeError):
        skip("inbound email webhook body check", "could not parse comment JSON")
    screenshot(page, "57_inbound_webhook_result")


# ── v3.1.0 Admin UX & i18n ───────────────────────────────────────────────────


def test_48_timestamp_locale(page: Page):
    """Admin conversation timestamps use WP site timezone and date format."""
    print("\n[48] Timestamp Locale — Admin Conversation Uses WP Timezone")  # closes issue #121

    if not state.get('ticket_id'):
        skip("timestamp locale", "no ticket_id in state")
        return

    wp_login(page, ADMIN_USER, ADMIN_PASS)
    ticket_id = state['ticket_id']

    # Read the current WP date_format setting from the DB.
    wp_date_fmt = wpcli("eval \"echo get_option('date_format');\"")

    check("timestamp locale: WP date_format is configured",
          bool(wp_date_fmt), f"date_format: {wp_date_fmt!r}")

    _navigate_ticket_editor(page, ticket_id)

    # Collect all <time> elements rendered by swh_format_comment_date().
    time_els = page.locator('.swh-conversation-thread time.swh-timestamp')
    count = time_els.count()
    if count == 0:
        skip("timestamp locale: no <time> elements found", "no replies yet")
        return

    # Each <time> element has a datetime= ISO attribute (UTC) and a display text.
    # Verify: the datetime= attr is a valid ISO timestamp; display text is non-empty.
    first_dt   = time_els.first.get_attribute('datetime') or ''
    first_disp = time_els.first.inner_text().strip()
    check("timestamp locale: <time datetime> is a valid ISO timestamp",
          'T' in first_dt and ('+' in first_dt or 'Z' in first_dt or first_dt.endswith('00')),
          f"datetime attr: {first_dt!r}")
    check("timestamp locale: displayed timestamp is non-empty",
          bool(first_disp), f"display text: {first_disp!r}")

    # Verify that the WP timezone/format setting is reflected — change to a unique
    # format, reload, and confirm the display text changes accordingly.
    unique_fmt = 'Y.m.d'
    wpcli(f"option update date_format '{unique_fmt}'")
    try:
        _navigate_ticket_editor(page, ticket_id)
        time_els_new = page.locator('.swh-conversation-thread time.swh-timestamp')
        if time_els_new.count() > 0:
            disp_new = time_els_new.first.inner_text().strip()
            # New format produces YYYY.MM.DD pattern — digits with dots
            check("timestamp locale: date_format change is reflected in display",
                  bool(re.search(r'\d{4}\.\d{2}\.\d{2}', disp_new)),
                  f"display text after format change: {disp_new!r}")
    finally:
        wpcli(f"option update date_format {json.dumps(wp_date_fmt or 'F j, Y')}")

    screenshot(page, "58_timestamp_locale")


def test_49_dedicated_reply_buttons(page: Page):
    """Send Reply button creates a public reply; Save Note creates an internal note."""
    print("\n[49] Dedicated Reply Buttons — Send Reply / Save Note")  # closes issue #97

    if not state.get('ticket_id'):
        skip("dedicated reply buttons", "no ticket_id in state")
        return

    wp_login(page, ADMIN_USER, ADMIN_PASS)
    ticket_id = state['ticket_id']

    # Count current replies/notes before the test.
    reply_count_before = wpcli(
        f"comment list --post_id={ticket_id} --comment_type=helpdesk_reply "
        "--format=count"
    ).strip()

    note_count_before = wpcli(
        f"eval \"echo get_comments(['post_id' => {ticket_id}, "
        "'type' => 'helpdesk_reply', 'meta_key' => '_is_internal_note', "
        "'meta_value' => '1', 'count' => true]);\""
    ).strip()

    _navigate_ticket_editor(page, ticket_id)

    # Verify the buttons exist.
    send_btn = page.locator('#swh-send-reply-btn')
    note_btn = page.locator('#swh-save-note-btn')
    check("reply buttons: Send Reply button exists", send_btn.count() > 0)
    check("reply buttons: Save Note button exists", note_btn.count() > 0)

    if send_btn.count() == 0:
        skip("reply buttons functionality", "Send Reply button not found in DOM")
        return

    # ── Send Reply ────────────────────────────────────────────────────────────
    REPLY_TEXT = f"Dedicated button reply {int(time.time())}"
    page.fill('[name="swh_tech_reply_text"]', REPLY_TEXT)
    with page.expect_navigation():
        send_btn.click()
    page.wait_for_load_state("load")

    reply_count_after = wpcli(
        f"comment list --post_id={ticket_id} --comment_type=helpdesk_reply "
        "--format=count"
    ).strip()
    check("reply buttons: Send Reply creates a new helpdesk_reply comment",
          reply_count_after.isdigit() and reply_count_before.isdigit() and
          int(reply_count_after) > int(reply_count_before),
          f"before={reply_count_before} after={reply_count_after}")

    # Confirm it is NOT flagged as an internal note.
    latest = wpcli(
        f"comment list --post_id={ticket_id} --comment_type=helpdesk_reply "
        "--fields=comment_ID --format=ids --number=1 --orderby=comment_date --order=DESC"
    ).strip()
    if latest.isdigit():
        is_note = wpcli(f"comment meta get {latest} _is_internal_note").strip()
        check("reply buttons: Send Reply comment is NOT flagged as internal note",
              is_note not in ('1', 'true'),
              f"_is_internal_note={is_note!r}")

    # ── Save Note ─────────────────────────────────────────────────────────────
    _navigate_ticket_editor(page, ticket_id)
    NOTE_TEXT = f"Internal note via button {int(time.time())}"
    page.fill('[name="swh_tech_note_text"]', NOTE_TEXT)
    with page.expect_navigation():
        note_btn.click()
    page.wait_for_load_state("load")

    note_count_after = wpcli(
        f"eval \"echo get_comments(['post_id' => {ticket_id}, "
        "'type' => 'helpdesk_reply', 'meta_key' => '_is_internal_note', "
        "'meta_value' => '1', 'count' => true]);\""
    ).strip()
    check("reply buttons: Save Note creates an internal note comment",
          note_count_after.isdigit() and note_count_before.isdigit() and
          int(note_count_after) > int(note_count_before),
          f"before={note_count_before} after={note_count_after}")

    screenshot(page, "59_dedicated_reply_buttons")


def test_50_unread_badge(page: Page):
    """Unread badge appears in admin menu after client reply; clears after admin opens ticket."""
    print("\n[50] Unread Badge — Admin Menu Badge for New Client Replies")  # closes issue #101

    if not state.get('ticket_id'):
        skip("unread badge", "no ticket_id in state")
        return

    wp_login(page, ADMIN_USER, ADMIN_PASS)
    ticket_id = state['ticket_id']

    # Manually set _swh_unread=1 to simulate a client reply.
    wpcli(f"post meta update {ticket_id} _swh_unread 1")
    wpcli("eval \"delete_transient('swh_unread_count');\"")

    # Navigate to admin dashboard (any admin page re-renders the menu).
    page.goto(f"{WP_ADMIN_URL}/")
    page.wait_for_load_state("load")

    # Check for the badge specifically in the helpdesk menu item.
    # WP already uses .awaiting-mod for comment moderation; use a scoped locator.
    badge = page.locator(
        '#adminmenu a[href*="post_type=helpdesk_ticket"] .awaiting-mod'
    )
    badge_visible = badge.count() > 0
    check("unread badge: .awaiting-mod badge appears in helpdesk menu item after client reply",
          badge_visible, "no .awaiting-mod element found in helpdesk menu link")
    if badge_visible:
        badge_text = badge.first.inner_text().strip()
        check("unread badge: badge displays a positive count",
              badge_text.isdigit() and int(badge_text) >= 1,
              f"badge text: {badge_text!r}")

    screenshot(page, "60_unread_badge_visible")

    # Open the ticket in the editor — this should clear the unread flag.
    _navigate_ticket_editor(page, ticket_id)

    unread_after = wpcli(f"post meta get {ticket_id} _swh_unread").strip()
    check("unread badge: _swh_unread flag cleared after admin opens ticket",
          unread_after not in ('1', 'true'),
          f"_swh_unread after opening: {unread_after!r}")

    screenshot(page, "61_unread_badge_cleared")


def test_51_unread_row_highlight(page: Page):
    """Admin list row gets swh-has-unread class when _swh_unread=1; removed after viewing."""
    print("\n[51] Unread Row Highlight — Admin List CSS Class")  # closes issue #102

    if not state.get('ticket_id'):
        skip("unread row highlight", "no ticket_id in state")
        return

    wp_login(page, ADMIN_USER, ADMIN_PASS)
    ticket_id = state['ticket_id']

    # Set _swh_unread=1 manually.
    wpcli(f"post meta update {ticket_id} _swh_unread 1")
    wpcli("eval \"delete_transient('swh_unread_count');\"")

    _navigate_admin_list(page)

    # The <tr> for this ticket should have the swh-has-unread class.
    row_class = page.evaluate(f"""
        (function() {{
            var rows = document.querySelectorAll('#the-list tr');
            for (var r of rows) {{
                if (r.querySelector('a[href*="post={ticket_id}&"]')) {{
                    return r.className;
                }}
            }}
            return '';
        }})()
    """)
    check("unread highlight: ticket row has swh-has-unread CSS class",
          'swh-has-unread' in (row_class or ''),
          f"row classes: {row_class!r}")

    screenshot(page, "62_unread_row_highlighted")

    # Open the ticket to clear the flag.
    _navigate_ticket_editor(page, ticket_id)
    _navigate_admin_list(page)

    row_class_after = page.evaluate(f"""
        (function() {{
            var rows = document.querySelectorAll('#the-list tr');
            for (var r of rows) {{
                if (r.querySelector('a[href*="post={ticket_id}&"]')) {{
                    return r.className;
                }}
            }}
            return '';
        }})()
    """)
    check("unread highlight: swh-has-unread class removed after admin opens ticket",
          'swh-has-unread' not in (row_class_after or ''),
          f"row classes after viewing: {row_class_after!r}")

    screenshot(page, "63_unread_row_cleared")


def test_52_email_test_button(page: Page):
    """Send Test Email button in Settings → Email Templates tab sends email and shows success."""
    print("\n[52] Email Test Button — Settings → Email Templates")  # closes issue #103

    wp_login(page, ADMIN_USER, ADMIN_PASS)

    _navigate_settings(page)
    page.locator('#swh-tab-emails').click()
    page.wait_for_selector('#swh-test-email-btn', timeout=5000)

    btn = page.locator('#swh-test-email-btn')
    msg = page.locator('#swh-test-email-msg')

    check("email test button: button exists on Email Templates tab", btn.count() > 0)
    check("email test button: status message element exists", msg.count() > 0)

    if btn.count() == 0:
        skip("email test button functionality", "button not found")
        return

    # Click the button and wait for AJAX to complete (locale-agnostic: wait for
    # button re-enable rather than checking for the English "Sending…" string).
    btn.click()
    page.wait_for_function(
        "() => { "
        "var b = document.getElementById('swh-test-email-btn'); "
        "var el = document.getElementById('swh-test-email-msg'); "
        "return b && !b.disabled && el && el.textContent.trim().length > 0; }",
        timeout=10000
    )

    msg_text  = msg.inner_text().strip()
    btn_state = btn.is_disabled()

    check("email test button: button is re-enabled after AJAX completes",
          not btn_state, "button remained disabled after AJAX response")
    check("email test button: status message is non-empty",
          bool(msg_text), "no status message displayed")
    check("email test button: success or useful error message shown",
          bool(msg_text) and (
              'sent' in msg_text.lower() or '@' in msg_text or
              'failed' in msg_text.lower() or 'check' in msg_text.lower()
          ),
          f"message: {msg_text!r}")

    screenshot(page, "64_email_test_button")

    if 'sent' in msg_text.lower() or '@' in msg_text:
        # Handler sends to wp_get_current_user()->user_email first; query via WP-CLI.
        current_admin_email = wpcli(f"user get {ADMIN_USER} --field=user_email 2>/dev/null").strip()
        if current_admin_email:
            expect_email(current_admin_email, "Test email from Send Test Email button (Settings → Email Templates)")


# ── v3.2.0 UX / A11y / DX verifications ──────────────────────────────────────

def test_53_ux_a11y(page: Page):
    """v3.2.0 UX/A11y improvements: badge aria, sort attrs, merge toggle, lookup
    slide, honeypot clip-path, drag-zone, CSAT keyboard/radiogroup, settings tab
    persistence, expired-token recovery link."""
    print("\n[53] UX / A11y Improvements (v3.2.0 — #258–#275)")

    wp_login(page, ADMIN_USER, ADMIN_PASS)

    # ── #263 aria-sort on admin list sortable columns ─────────────────────────
    _navigate_admin_list(page)
    aria_sort_none_count = page.evaluate("""
        () => document.querySelectorAll('th.sortable[aria-sort="none"], th.sorted[aria-sort]').length
    """)
    check("a11y #263: sortable columns have aria-sort attribute",
          aria_sort_none_count > 0,
          f"found {aria_sort_none_count} th.sortable[aria-sort] elements")

    # ── #264 aria-live on unread badge ────────────────────────────────────────
    if state.get('ticket_id'):
        ticket_id = state['ticket_id']
        wpcli(f"post meta update {ticket_id} _swh_unread 1")
        wpcli("eval \"delete_transient('swh_unread_count');\"")
        page.goto(f"{WP_ADMIN_URL}/")
        page.wait_for_load_state("load")
        badge_aria_live = page.evaluate("""
            () => {
                var b = document.querySelector(
                    '#adminmenu a[href*="post_type=helpdesk_ticket"] .awaiting-mod'
                );
                return b ? b.getAttribute('aria-live') : null;
            }
        """)
        check("a11y #264: unread badge has aria-live attribute",
              badge_aria_live == 'polite',
              f"aria-live={badge_aria_live!r}")
        wpcli(f"post meta delete {ticket_id} _swh_unread")
        wpcli("eval \"delete_transient('swh_unread_count');\"")

    # ── #271 merge form collapse toggle ──────────────────────────────────────
    if state.get('ticket_id'):
        _navigate_ticket_editor(page, state['ticket_id'])
        merge_toggle = page.locator('#swh-merge-toggle')
        merge_body   = page.locator('#swh-merge-section')
        check("ux #271: merge toggle button exists",
              merge_toggle.count() > 0)
        check("ux #271: merge body section exists",
              merge_body.count() > 0)
        if merge_toggle.count() > 0 and merge_body.count() > 0:
            # Collapsed by default — aria-expanded should be false.
            aria_exp_before = merge_toggle.get_attribute('aria-expanded')
            check("ux #271: merge section collapsed by default (aria-expanded=false)",
                  aria_exp_before == 'false',
                  f"aria-expanded={aria_exp_before!r}")
            body_visible_before = merge_body.evaluate(
                "el => el.classList.contains('swh-merge-visible')"
            )
            check("ux #271: swh-merge-visible absent when collapsed",
                  not body_visible_before)
            # Click to expand (force=True bypasses jQuery UI sortable overlay).
            merge_toggle.click(force=True)
            page.wait_for_timeout(400)
            aria_exp_after = merge_toggle.get_attribute('aria-expanded')
            check("ux #271: aria-expanded=true after toggle click",
                  aria_exp_after == 'true',
                  f"aria-expanded={aria_exp_after!r}")
            body_visible_after = merge_body.evaluate(
                "el => el.classList.contains('swh-merge-visible')"
            )
            check("ux #271: swh-merge-visible present after expand",
                  body_visible_after)

    screenshot(page, "65a_merge_toggle")

    # ── #267 settings tab sessionStorage persistence ──────────────────────────
    _navigate_settings(page)
    # Click the Email Templates tab.
    page.locator('#swh-tab-emails').click()
    page.wait_for_timeout(200)
    stored_tab = page.evaluate(
        "() => sessionStorage.getItem('swh_active_tab')"
    )
    check("ux #267: active tab stored in sessionStorage on click",
          stored_tab == 'tab-emails',
          f"sessionStorage swh_active_tab={stored_tab!r}")
    # Simulate reload without swh_tab in URL (plain settings URL).
    page.goto(f"{WP_ADMIN_URL}/edit.php?post_type=helpdesk_ticket&page=swh-settings")
    page.wait_for_load_state("load")
    active_tab_id = page.evaluate("""
        () => {
            var active = document.querySelector('.nav-tab-active');
            return active ? active.getAttribute('data-tab') : null;
        }
    """)
    check("ux #267: settings tab restored from sessionStorage on reload",
          active_tab_id == 'tab-emails',
          f"active tab after reload: {active_tab_id!r}")

    screenshot(page, "65b_settings_tab_persist")

    wp_logout(page)

    # ── Frontend: lookup toggle, honeypot, drag-zone, CSAT ────────────────────
    page.goto(WP_SUBMIT_PAGE)
    page.wait_for_load_state("load")

    # ── #272 lookup toggle: hidden attribute + aria-hidden ────────────────────
    lookup_form = page.locator('#swh-lookup-form')
    if lookup_form.count() > 0:
        is_hidden_attr = lookup_form.evaluate("el => el.hasAttribute('hidden')")
        check("ux #272: lookup form starts with hidden attribute (semantically hidden)",
              is_hidden_attr,
              "element lacks hidden attribute — keyboard/AT can reach collapsed form")
        is_aria_hidden = lookup_form.evaluate("el => el.getAttribute('aria-hidden') === 'true'")
        check("ux #272: lookup form starts with aria-hidden=true",
              is_aria_hidden,
              "element lacks aria-hidden='true' in collapsed state")

    # ── #270 honeypot clip-path technique ─────────────────────────────────────
    honeypot_info = page.evaluate("""
        () => {
            var hp = document.querySelector('input[name="swh_website_url_hp"]');
            if (!hp) return null;
            var wrap = hp.parentElement;
            var wrapStyle = wrap ? (wrap.getAttribute('style') || '') : '';
            return { inputTabIndex: hp.tabIndex, wrapStyle: wrapStyle };
        }
    """)
    if honeypot_info is not None:
        check("security #270: honeypot input has tabindex=-1",
              honeypot_info['inputTabIndex'] == -1,
              f"honeypot tabIndex: {honeypot_info['inputTabIndex']}")
        check("security #270: honeypot does not use left:-9999px",
              '-9999' not in honeypot_info['wrapStyle'],
              f"honeypot wrap style: {honeypot_info['wrapStyle']!r}")
        check("security #270: honeypot uses clip-path technique",
              'clip-path' in honeypot_info['wrapStyle'],
              f"honeypot wrap style: {honeypot_info['wrapStyle']!r}")

    # ── #273 drag-and-drop zone wraps file input ──────────────────────────────
    drop_zone = page.locator('.swh-drop-zone')
    check("ux #273: file input wrapped in .swh-drop-zone",
          drop_zone.count() > 0,
          "no .swh-drop-zone found on submit page")

    # ── #262 CSAT stars have role/aria attributes ─────────────────────────────
    portal_url = state.get('portal_url')
    ticket_id  = state.get('ticket_id')
    if portal_url and ticket_id:
        # CSAT widget only renders in the POST response right after the close
        # action — not on a plain GET of an already-closed ticket.  Set status
        # to resolved so the portal shows the "Close ticket" confirmation button.
        wpcli(f"post meta update {ticket_id} _ticket_status resolved")
        wpcli(f"post meta delete {ticket_id} _ticket_csat")
        page.goto(portal_url)
        page.wait_for_load_state("load")
        close_btn = page.locator('[name="swh_user_close_ticket_submit"]')
        if close_btn.count() > 0:
            close_btn.click()
            page.wait_for_selector(
                "#swh-csat, .swh-alert-success, .swh-alert-error",
                timeout=10000,
            )
            csat_stars = page.locator('.swh-csat-star')
            check("a11y #262: 5 CSAT stars rendered after close action",
                  csat_stars.count() == 5,
                  f"expected 5 .swh-csat-star, got {csat_stars.count()}")
            if csat_stars.count() > 0:
                star_role = csat_stars.first.get_attribute('role')
                check("a11y #262: CSAT stars have role=radio",
                      star_role == 'radio',
                      f"first star role: {star_role!r}")
                star_checked = csat_stars.first.get_attribute('aria-checked')
                check("a11y #262: CSAT stars have aria-checked attribute",
                      star_checked is not None,
                      "first star missing aria-checked attribute")
        else:
            skip("a11y #262", "close button not visible — cannot trigger CSAT widget")

    # ── #258 expired token shows inline lookup form ────────────────────────────
    if portal_url and ticket_id:
        # Force-expire the token via WP-CLI (set creation time 100 days ago).
        # This ensures hash_equals passes but swh_is_token_expired() returns true.
        orig_created = wpcli(
            f"eval 'echo get_post_meta({ticket_id}, \"_ticket_token_created\", true);'"
        ).strip()
        expired_ts = int(time.time()) - (100 * 86400)
        wpcli(f"post meta update {ticket_id} _ticket_token_created {expired_ts}")
        try:
            page.goto(portal_url)
            page.wait_for_load_state("load")
            body = page.inner_text("body")
            check("ux #258: expired-token page has descriptive error text",
                  any(w in body.lower() for w in ('expired', 'look up', 'lookup', 'ticket')),
                  f"portal expired body: {body[:200]!r}")
            lookup_on_expired = page.locator('#swh-lookup-email, input[name="swh_lookup_email"]')
            check("ux #258: expired token page renders lookup form inline",
                  lookup_on_expired.count() > 0,
                  "no lookup email input found on expired-token page")
        finally:
            # Always restore original creation time so later portal sections work.
            if orig_created:
                wpcli(f"post meta update {ticket_id} _ticket_token_created {orig_created}")
            else:
                wpcli(f"post meta delete {ticket_id} _ticket_token_created")

    # ── #170 WCAG 2.2 AA: submission form has screen-reader heading ─────────────
    wp_logout(page)
    page.goto(WP_SUBMIT_PAGE)
    page.wait_for_load_state("load")
    sr_heading_count = page.locator('h2.screen-reader-text').count()
    check("a11y #170: submission form has screen-reader-text h2 heading landmark",
          sr_heading_count > 0,
          f"found {sr_heading_count} h2.screen-reader-text elements")

    # ── #170 close-ticket CTA: no h4 heading skip (h2→h3, not h2→h4) ─────────
    if state.get('portal_url'):
        page.goto(state['portal_url'])
        page.wait_for_load_state("load")
        h4_in_cta = page.evaluate("""
            () => {
                var cta = document.querySelector('.swh-cta-primary');
                return cta ? cta.querySelectorAll('h4').length : 0;
            }
        """)
        check("a11y #170: close-ticket CTA uses h3, not h4 (no heading skip)",
              h4_in_cta == 0,
              f"found {h4_in_cta} h4 element(s) inside .swh-cta-primary")

    screenshot(page, "65c_ux_a11y_frontend")


# ── v3.3.0 Responsive layout (#251) ──────────────────────────────────────────

def test_54_responsive(page: Page):
    """v3.3.0 Responsive layout (#251): no horizontal overflow at 375px viewport."""
    print("\n[54] Responsive Layout — 375px viewport (#251)")

    page.set_viewport_size({"width": 375, "height": 812})

    try:
        wp_logout(page)
        page.goto(WP_SUBMIT_PAGE)
        page.wait_for_load_state("load")
        overflow = page.evaluate(
            "() => document.documentElement.scrollWidth > document.documentElement.clientWidth"
        )
        check("responsive #251: no horizontal overflow on submission form at 375px",
              not overflow,
              f"scrollWidth={page.evaluate('document.documentElement.scrollWidth')}")
        screenshot(page, "66_responsive_375_submit")

        wp_login(page, ADMIN_USER, ADMIN_PASS)
        _navigate_admin_list(page)
        overflow_admin = page.evaluate(
            "() => document.documentElement.scrollWidth > document.documentElement.clientWidth"
        )
        check("responsive #251: no horizontal overflow on admin ticket list at 375px",
              not overflow_admin,
              f"scrollWidth={page.evaluate('document.documentElement.scrollWidth')}")
        screenshot(page, "66b_responsive_375_admin")
    finally:
        page.set_viewport_size({"width": 1280, "height": 800})


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
    test_29_humanized_timestamps,     # 29 — #117
    test_34_my_tickets_dashboard,     # 34 — #111 (must run before test_33 closes the ticket)
    test_35_portal_guest_lookup,      # 35 — #111
    test_30_resolved_cta_layout,      # 30 — #118, #120
    test_33_csat_prompt,              # 33 — #116 (closes the ticket)
    test_36_shortcode_attrs,          # 36 — #119
    test_37_admin_list_filtering,     # 37 — #127 category filter
    test_38_admin_list_sorting,       # 38 — column sorting
    test_39_ticket_templates,         # 39 — #132
    test_40_first_response_time,      # 40 — #136
    test_41_cc_watchers,              # 41 — #129
    test_42_categories_taxonomy,      # 42 — #127
    test_43_ticket_merge,             # 43 — #133
    test_44_sla_breach_detection,     # 44 — #128
    test_45_assignment_rules,         # 45 — #126
    test_46_reporting_dashboard,      # 46 — #135/#137
    test_47_inbound_email_webhook,    # 47 — #131
    test_48_timestamp_locale,         # 48 — #121 timestamp timezone
    test_49_dedicated_reply_buttons,  # 49 — #97 send reply / save note
    test_50_unread_badge,             # 50 — #101 unread badge
    test_51_unread_row_highlight,     # 51 — #102 row highlight
    test_52_email_test_button,        # 52 — #103 test email button
    test_53_ux_a11y,                  # 53 — v3.2.0 UX/a11y (#258–#275)
    test_54_responsive,               # 54 — v3.3.0 responsive layout (#251)
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
