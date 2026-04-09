#!/usr/bin/env python3
"""
CDP browser test suite for Simple WP Helpdesk.

Covers: ticket submission, admin management, technician workflow,
client portal, status transitions, internal notes, access control,
and email trigger verification (see EMAIL CHECKS summary at end).

Usage:
    python3 testing/scripts/test_helpdesk.py

    The script auto-loads testing/.env (relative to the repo root or CWD).
    No shell sourcing needed — values with special characters are safe.

Requirements:
    pip install websockets
"""
import asyncio
import base64
import json
import os
import subprocess
import sys
import time
import urllib.request

import websockets

# ── Load .env file ────────────────────────────────────────────────────────────
# Tried in order: repo-root-relative path, then CWD-relative path.
# Values are set only if not already present in the environment.

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
                # Strip surrounding quotes if present
                val = val.strip()
                if len(val) >= 2 and val[0] == val[-1] and val[0] in ('"', "'"):
                    val = val[1:-1]
                if key and key not in os.environ:
                    os.environ[key] = val
        print(f"Loaded env from: {path}")
        return
    print("WARNING: testing/.env not found — using existing environment variables.")

_load_dotenv()

# ── Configuration (from environment) ─────────────────────────────────────────

def _require(name):
    val = os.environ.get(name, "").strip()
    if not val:
        print(f"ERROR: {name} is not set. Check testing/.env.", file=sys.stderr)
        sys.exit(1)
    return val

def _optional(name, default=""):
    return os.environ.get(name, default).strip()

CHROME_HOST   = _optional("CHROME_HOST", "192.168.80.15")
CHROME_PORT   = int(_optional("CHROME_PORT", "9224"))
WP_URL        = _require("WP_URL").rstrip("/")
WP_LOGIN_URL  = _require("WP_LOGIN_URL")
WP_ADMIN_URL  = _optional("WP_ADMIN_URL", WP_URL + "/wp-admin")
WP_SUBMIT_PAGE = _require("WP_SUBMIT_PAGE").rstrip("/")
WP_PORTAL_PAGE = _optional("WP_PORTAL_PAGE", WP_SUBMIT_PAGE)
SSH_HOST      = _require("SSH_HOST")
WP_CONTAINER  = _require("WP_CONTAINER")
WP_PATH       = _require("WP_PATH")

ADMIN_NAME    = _require("WP_ADMIN_NAME")
ADMIN_EMAIL   = _require("WP_ADMIN_EMAIL")
ADMIN_USER    = _require("WP_ADMIN_USER")
ADMIN_PASS    = _require("WP_ADMIN_PASS")

TECH1_NAME    = _require("WP_TECH1_NAME")
TECH1_EMAIL   = _require("WP_TECH1_EMAIL")
TECH1_USER    = _require("WP_TECH1_USER")
TECH1_PASS    = _require("WP_TECH1_PASS")

CLIENT1_NAME  = _require("CLIENT1_NAME")
CLIENT1_EMAIL = _require("CLIENT1_EMAIL")
CLIENT2_NAME  = _optional("CLIENT2_NAME", "Claude Client 2")
CLIENT2_EMAIL = _require("CLIENT2_EMAIL")

# Test data
TEST_TICKET_TITLE   = f"CDP Test Ticket {int(time.time())}"
TEST_TICKET_DESC    = "This is an automated test ticket created by the CDP test suite."
TEST_TECH_REPLY     = "Thanks for reaching out. We are looking into this."
TEST_INTERNAL_NOTE  = "INTERNAL ONLY: This note must not appear in the client portal."
TEST_CLIENT_REPLY   = "Thank you for the update, still waiting on resolution."

OUT = "testing/screenshots"
os.makedirs(OUT, exist_ok=True)

# Collects expected email events for post-run Gmail verification
EMAIL_CHECKS = []


# ── CDP helpers ───────────────────────────────────────────────────────────────

def cdp_http(path):
    url = f"http://{CHROME_HOST}:{CHROME_PORT}{path}"
    with urllib.request.urlopen(url, timeout=5) as r:  # nosemgrep -- local CDP endpoint, not user-controlled
        return json.loads(r.read())


def wpcli(cmd):
    """Run a WP-CLI command inside the Docker container via SSH, return stdout stripped.

    Equivalent to:
      ssh SSH_HOST docker exec WP_CONTAINER runuser --user www-data -- wp CMD --path=WP_PATH

    PHP Deprecated/Notice lines are stripped from stdout before returning.
    """
    docker_cmd = (
        f"docker exec {WP_CONTAINER} runuser --user www-data -- "
        f"wp {cmd} --path={WP_PATH} 2>/dev/null"
    )
    result = subprocess.run(
        ["ssh", SSH_HOST, docker_cmd],
        capture_output=True, text=True, timeout=15
    )
    # Strip PHP Deprecated/Notice/Warning lines that WP-CLI emits to stdout
    clean = "\n".join(
        line for line in result.stdout.splitlines()
        if not line.startswith(("Deprecated:", "Notice:", "Warning:", "PHP Deprecated:"))
    )
    return clean.strip()


async def run():
    version    = cdp_http("/json/version")
    browser_ws = version["webSocketDebuggerUrl"]
    print(f"Browser : {version['Browser']}")
    print(f"WP site : {WP_URL}\n")

    async with websockets.connect(browser_ws, max_size=10_000_000) as bws:
        _bid = 0
        async def browser_send(method, params=None):
            nonlocal _bid; _bid += 1
            await bws.send(json.dumps({"id": _bid, "method": method, "params": params or {}}))
            while True:
                data = json.loads(await asyncio.wait_for(bws.recv(), timeout=10))
                if data.get("id") == _bid:
                    return data

        r = await browser_send("Target.createTarget", {"url": "about:blank"})
        target_id = r["result"]["targetId"]

    page_ws = f"ws://{CHROME_HOST}:{CHROME_PORT}/devtools/page/{target_id}"  # nosemgrep -- local Chrome CDP, wss not applicable
    async with websockets.connect(page_ws, max_size=10_000_000) as ws:
        _id = 0
        pass_count = 0
        failures = []
        state = {}  # ticket_id, portal_url, tech1_user_id carried between sections

        async def send(method, params=None):
            nonlocal _id; _id += 1
            await ws.send(json.dumps({"id": _id, "method": method, "params": params or {}}))
            while True:
                data = json.loads(await asyncio.wait_for(ws.recv(), timeout=20))
                if data.get("id") == _id:
                    return data

        async def navigate(url, wait=2.5):
            await send("Page.navigate", {"url": url})
            await asyncio.sleep(wait)

        async def js(expr):
            r = await send("Runtime.evaluate",
                           {"expression": expr, "returnByValue": True, "awaitPromise": True})
            return r.get("result", {}).get("result", {}).get("value")

        async def screenshot(name):
            r    = await send("Page.captureScreenshot", {"format": "png"})
            data = base64.b64decode(r["result"]["data"])
            path = f"{OUT}/{name}.png"
            with open(path, "wb") as f:
                f.write(data)
            print(f"    📸  {name}.png ({len(data)//1024} KB)")

        def check(name, ok, detail=""):
            nonlocal pass_count
            if ok:
                print(f"  ✅  {name}")
                pass_count += 1
            else:
                msg = f"❌  {name}" + (f" — {detail}" if detail else "")
                print(f"  {msg}")
                failures.append(msg)

        def expect_email(recipient, description):
            """Record an email we expect to have been sent (verified post-run via Gmail)."""
            EMAIL_CHECKS.append({"to": recipient, "description": description})

        async def wp_login(username, password, wait=3.0):
            """Login to WordPress via the custom login page."""
            await navigate(WP_LOGIN_URL, wait=3.0)
            await js(f"""
                document.querySelector('[name="log"]').value = {json.dumps(username)};
                document.querySelector('[name="pwd"]').value = {json.dumps(password)};
                document.querySelector('#wp-submit, [type="submit"]').click();
            """)
            await asyncio.sleep(wait)
            return await js("location.href") or ""

        async def wp_logout():
            """Log out of WordPress via WP-admin bar nonce URL."""
            nonce_url = await js("""
                (function() {
                    var a = document.querySelector('#wp-admin-bar-logout a');
                    return a ? a.href : null;
                })()
            """)
            if nonce_url:
                await navigate(nonce_url, wait=2.0)
            else:
                # Fallback: navigate to login and clear cookies
                await send("Network.clearBrowserCookies")
                await asyncio.sleep(0.5)

        async def admin_update_ticket(post_id, status=None, priority=None,
                                      assigned_user_id=None, tech_reply=None,
                                      internal_note=None):
            """Open the ticket edit page, set fields, and click Update."""
            url = f"{WP_ADMIN_URL}/post.php?post={post_id}&action=edit"
            await navigate(url, wait=2.5)
            if status:
                await js(f"document.querySelector('[name=\"ticket_status\"]').value = {json.dumps(status)};")
            if priority:
                await js(f"document.querySelector('[name=\"ticket_priority\"]').value = {json.dumps(priority)};")
            if assigned_user_id is not None:
                await js(f"document.querySelector('[name=\"ticket_assigned_to\"]').value = {json.dumps(str(assigned_user_id))};")
            if tech_reply:
                await js(f"document.querySelector('[name=\"swh_tech_reply_text\"]').value = {json.dumps(tech_reply)};")
            if internal_note:
                await js(f"document.querySelector('[name=\"swh_tech_note_text\"]').value = {json.dumps(internal_note)};")
            # Click the Update / Publish button
            await js("document.querySelector('#publish').click();")
            await asyncio.sleep(3.0)

        await send("Page.enable")
        await send("Runtime.enable")
        await send("Network.enable")
        await send("Emulation.setDeviceMetricsOverride",
                   {"width": 1440, "height": 900, "deviceScaleFactor": 1, "mobile": False})

        # Reset any options that a previous failed run may have left dirty
        wpcli("option delete swh_restrict_to_assigned 2>/dev/null || true")

        # ── [1] Admin Authentication ───────────────────────────────────────────
        print("[1] Admin Authentication")

        href = await wp_login(ADMIN_USER, ADMIN_PASS)
        check("admin login: redirects to dashboard/admin",
              "wp-admin" in href or "wp-login" not in href, f"href={href[:60]}")
        await screenshot("01_admin_logged_in")

        # Verify we can reach the admin dashboard
        await navigate(f"{WP_ADMIN_URL}/", wait=2.0)
        title = await js("document.title") or ""
        check("admin dashboard: page title contains Dashboard",
              "dashboard" in title.lower() or "wp-admin" in href, f"title={title!r}")

        # ── [2] Plugin Verification ────────────────────────────────────────────
        print("\n[2] Plugin Verification")

        await navigate(f"{WP_ADMIN_URL}/plugins.php", wait=2.5)
        body = await js("document.body.innerText") or ""
        check("plugins: Simple WP Helpdesk is present",
              "Simple WP Helpdesk" in body, "plugin not listed")
        check("plugins: Simple WP Helpdesk is active",
              "simple-wp-helpdesk" in (await js("document.body.innerHTML") or "") and
              "Deactivate" in body,
              "check that the plugin is activated")
        await screenshot("02_plugins_page")

        # Settings page
        await navigate(f"{WP_ADMIN_URL}/edit.php?post_type=helpdesk_ticket&page=swh-settings", wait=2.0)
        title = await js("document.title") or ""
        check("settings: page loads",
              "setting" in title.lower() or "helpdesk" in title.lower(), f"title={title!r}")
        await screenshot("03_settings_page")

        # A11y: settings ARIA tablist
        check("a11y: settings page has role=tablist",
              await js("!!document.querySelector('[role=\"tablist\"]')"))
        check("a11y: settings tabs have role=tab with aria-selected",
              await js("document.querySelectorAll('[role=\"tab\"][aria-selected]').length >= 3"))

        # ── [3] Ticket Submission (Frontend) ──────────────────────────────────
        print("\n[3] Ticket Submission (Frontend)")

        await navigate(WP_SUBMIT_PAGE, wait=5.0)
        check("submit page: form present",
              await js("!!document.querySelector('[name=\"ticket_name\"]')"))
        check("submit page: email field present",
              await js("!!document.querySelector('[name=\"ticket_email\"]')"))
        check("submit page: description field present",
              await js("!!document.querySelector('[name=\"ticket_desc\"]')"))
        await screenshot("04_submit_form")

        # A11y: form label associations and aria attributes
        check("a11y: label[for='swh-name'] associates with #swh-name",
              await js("!!document.querySelector('label[for=\"swh-name\"]') && !!document.querySelector('#swh-name')"))
        check("a11y: label[for='swh-email'] associates with #swh-email",
              await js("!!document.querySelector('label[for=\"swh-email\"]') && !!document.querySelector('#swh-email')"))
        check("a11y: #swh-toggle-lookup has aria-expanded attribute",
              await js("document.querySelector('#swh-toggle-lookup')?.hasAttribute('aria-expanded') ?? false"))

        # Fill and submit the form
        await js(f"""
            document.querySelector('[name="ticket_name"]').value    = {json.dumps(CLIENT1_NAME)};
            document.querySelector('[name="ticket_email"]').value   = {json.dumps(CLIENT1_EMAIL)};
            document.querySelector('[name="ticket_title"]').value   = {json.dumps(TEST_TICKET_TITLE)};
            document.querySelector('[name="ticket_desc"]').value    = {json.dumps(TEST_TICKET_DESC)};
        """)
        # Set priority to Normal (or whatever's second in the list)
        await js("""
            var sel = document.querySelector('[name="ticket_priority"]');
            if (sel && sel.options.length > 0) sel.selectedIndex = 0;
        """)
        await js("document.querySelector('[name=\"swh_submit_ticket\"]').click();")
        await asyncio.sleep(3.5)

        body = await js("document.body.innerText") or ""
        check("submit: success message shown",
              "swh-alert-success" in (await js("document.body.innerHTML") or ""),
              "no .swh-alert-success found")
        await screenshot("05_submit_success")

        expect_email(CLIENT1_EMAIL,  "new ticket confirmation to client")
        expect_email(TECH1_EMAIL, "new ticket notification to default assignee (tech1)")

        # ── [4] Admin: Find and Open Ticket ───────────────────────────────────
        print("\n[4] Admin: Locate New Ticket")

        await navigate(f"{WP_ADMIN_URL}/edit.php?post_type=helpdesk_ticket", wait=2.5)
        body = await js("document.body.innerText") or ""
        check("admin list: submitted ticket appears",
              TEST_TICKET_TITLE in body, f"title={TEST_TICKET_TITLE!r}")
        await screenshot("06_admin_ticket_list")

        # Extract post ID from the row's edit link
        ticket_id = await js(f"""
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
        check("admin list: extracted post ID",
              bool(ticket_id), f"id={ticket_id}")
        if ticket_id:
            state['ticket_id'] = ticket_id
            print(f"    Post ID: {ticket_id}")

        # ── [5] Get Portal URL via WP-CLI ──────────────────────────────────────
        print("\n[5] Portal URL (via WP-CLI)")

        portal_url = None
        if state.get('ticket_id'):
            pid = state['ticket_id']
            try:
                portal_url = wpcli(
                    f"eval \"echo swh_get_secure_ticket_link({pid});\""
                )
                check("wpcli: got portal URL",
                      bool(portal_url) and "swh_ticket=" in portal_url,
                      f"got: {portal_url!r}")
                if portal_url:
                    state['portal_url'] = portal_url
                    print(f"    Portal URL: {portal_url[:80]}...")
            except Exception as e:
                check("wpcli: portal URL retrieval", False, str(e))
        else:
            check("wpcli: portal URL retrieval (skipped — no ticket ID)", False)

        # ── [6] Admin: Status Change and Technician Assignment ────────────────
        print("\n[6] Admin: Update Ticket (Status + Assignment)")

        if state.get('ticket_id'):
            # Find Tech1's WP user ID
            tech1_id = wpcli(f"user get {TECH1_USER} --field=ID 2>/dev/null")
            if tech1_id.isdigit():
                state['tech1_wp_id'] = int(tech1_id)
                print(f"    Tech1 WP user ID: {tech1_id}")

            await admin_update_ticket(
                state['ticket_id'],
                status="In Progress",
                assigned_user_id=state.get('tech1_wp_id'),
            )

            # Verify save
            await navigate(f"{WP_ADMIN_URL}/post.php?post={state['ticket_id']}&action=edit", wait=2.5)
            status_val = await js("document.querySelector('[name=\"ticket_status\"]')?.value")
            check("admin update: status set to In Progress",
                  status_val == "In Progress", f"got {status_val!r}")
            assigned_val = await js("document.querySelector('[name=\"ticket_assigned_to\"]')?.value")
            check("admin update: tech1 assigned",
                  assigned_val == str(state.get('tech1_wp_id', "")),
                  f"got {assigned_val!r}")
            await screenshot("07_ticket_updated")

        # ── [7] Technician Workflow ────────────────────────────────────────────
        print("\n[7] Technician Workflow (Tech1)")

        if state.get('ticket_id'):
            # Log out admin, log in as tech1
            await wp_logout()
            await asyncio.sleep(1.0)
            href = await wp_login(TECH1_USER, TECH1_PASS)
            check("tech1 login: success",
                  "wp-admin" in href or "wp-login" not in href, f"href={href[:60]}")

            # Tech can see the ticket in admin list
            await navigate(f"{WP_ADMIN_URL}/edit.php?post_type=helpdesk_ticket", wait=2.5)
            body = await js("document.body.innerText") or ""
            check("tech1: assigned ticket visible in list",
                  TEST_TICKET_TITLE in body)
            await screenshot("08_tech1_ticket_list")

            # Tech opens ticket and adds public reply + internal note
            await admin_update_ticket(
                state['ticket_id'],
                tech_reply=TEST_TECH_REPLY,
                internal_note=TEST_INTERNAL_NOTE,
            )

            # Verify both were saved in conversation
            await navigate(f"{WP_ADMIN_URL}/post.php?post={state['ticket_id']}&action=edit", wait=2.5)
            conv_html = await js("document.body.innerHTML") or ""
            check("tech1 reply: public reply saved in conversation",
                  TEST_TECH_REPLY in conv_html)
            check("a11y: conversation div has role=log",
                  'role="log"' in conv_html)
            check("tech1 note: internal note saved in conversation",
                  TEST_INTERNAL_NOTE in conv_html)
            check("tech1 note: internal note has yellow/note styling",
                  "Internal Note" in (await js("document.body.innerText") or ""))
            await screenshot("09_tech1_conversation")
            expect_email(CLIENT1_EMAIL, "tech reply notification to client")

        # ── [8] Client Portal: View Ticket ────────────────────────────────────
        print("\n[8] Client Portal: View and Reply")

        if state.get('portal_url'):
            # Log out tech (portal is public, no WP login needed)
            await wp_logout()
            await asyncio.sleep(1.0)

            await navigate(state['portal_url'], wait=5.0)
            body_text = await js("document.body.innerText") or ""
            check("portal: ticket title visible",
                  TEST_TICKET_TITLE in body_text)
            check("portal: ticket description visible",
                  TEST_TICKET_DESC in body_text)
            check("portal: tech reply visible to client",
                  TEST_TECH_REPLY in body_text)
            check("portal: internal note NOT visible to client",
                  TEST_INTERNAL_NOTE not in body_text,
                  "internal note leaked to client portal!")
            check("portal: reply form present",
                  await js("!!document.querySelector('[name=\"ticket_reply_text\"]')"))
            await screenshot("10_client_portal")

            # Client sends a reply
            await js(f"""
                document.querySelector('[name="ticket_reply_text"]').value = {json.dumps(TEST_CLIENT_REPLY)};
            """)
            await js("document.querySelector('[name=\"swh_user_reply_submit\"]').click();")
            await asyncio.sleep(3.0)
            body_text = await js("document.body.innerText") or ""
            check("portal: client reply success message",
                  "swh-alert-success" in (await js("document.body.innerHTML") or ""))
            check("a11y: reply success div has role=status",
                  'role="status"' in (await js("document.body.innerHTML") or ""))
            check("portal: client reply appears in conversation",
                  TEST_CLIENT_REPLY in body_text)
            await screenshot("11_client_replied")
            expect_email(TECH1_EMAIL, "client reply notification to assigned technician (tech1)")

        # ── [9] Admin: Verify Client Reply + Send Tech Response ───────────────
        print("\n[9] Admin: Verify Client Reply")

        await wp_logout()
        await asyncio.sleep(0.5)
        href = await wp_login(ADMIN_USER, ADMIN_PASS)

        if state.get('ticket_id'):
            await navigate(f"{WP_ADMIN_URL}/post.php?post={state['ticket_id']}&action=edit", wait=2.5)
            conv_text = await js("document.body.innerText") or ""
            check("admin: client reply visible in admin conversation",
                  TEST_CLIENT_REPLY in conv_text)
            check("admin: shows 'Client' label for client reply",
                  "Client" in conv_text)

            # Admin marks ticket Resolved
            await admin_update_ticket(state['ticket_id'], status="Resolved")
            await navigate(f"{WP_ADMIN_URL}/post.php?post={state['ticket_id']}&action=edit", wait=2.5)
            status_val = await js("document.querySelector('[name=\"ticket_status\"]')?.value")
            check("admin: ticket status set to Resolved",
                  status_val == "Resolved", f"got {status_val!r}")
            await screenshot("12_ticket_resolved")

        # ── [10] Client Portal: Close and Re-open ─────────────────────────────
        print("\n[10] Client Portal: Resolved → Close → Re-open")

        if state.get('portal_url'):
            await wp_logout()
            await asyncio.sleep(0.5)

            # Re-fetch portal URL since token may have changed
            pid = state['ticket_id']
            fresh_url = wpcli(f"eval \"echo swh_get_secure_ticket_link({pid});\"")
            if fresh_url and "swh_ticket=" in fresh_url:
                state['portal_url'] = fresh_url

            await navigate(state['portal_url'], wait=5.0)
            check("portal resolved: 'Is your issue resolved?' prompt shown",
                  "swh_user_close_ticket_submit" in (await js("document.body.innerHTML") or "") or
                  "Yes, Close Ticket" in (await js("document.body.innerText") or ""))
            await screenshot("13_portal_resolved_state")

            # Close the ticket
            close_btn = await js("!!document.querySelector('[name=\"swh_user_close_ticket_submit\"]')")
            if close_btn:
                await js("document.querySelector('[name=\"swh_user_close_ticket_submit\"]').click();")
                await asyncio.sleep(3.0)
                body_text = await js("document.body.innerText") or ""
                check("portal: close ticket success",
                      "swh-alert-success" in (await js("document.body.innerHTML") or ""))
                expect_email(CLIENT1_EMAIL, "ticket closed confirmation to client")
                expect_email(TECH1_EMAIL, "ticket closed notification to assigned technician (tech1)")
                await screenshot("14_ticket_closed_portal")

                # Re-open the ticket
                await navigate(state['portal_url'], wait=5.0)
                reopen_text = "I still need help with this issue."
                await js(f"""
                    var ta = document.querySelector('[name="ticket_reopen_text"]');
                    if (ta) ta.value = {json.dumps(reopen_text)};
                """)
                await js("document.querySelector('[name=\"swh_user_reopen_submit\"]').click();")
                await asyncio.sleep(3.0)
                check("portal: reopen success",
                      "swh-alert-success" in (await js("document.body.innerHTML") or ""))
                await screenshot("15_ticket_reopened_portal")
                expect_email(TECH1_EMAIL, "ticket re-opened notification to assigned technician (tech1)")
            else:
                check("portal resolved: close button present", False,
                      "close button not found — check ticket status is Resolved")

        # ── [11] Access Control: Technician Restriction ───────────────────────
        print("\n[11] Access Control: Unassigned Ticket + Technician Restriction")

        await wp_logout()
        await asyncio.sleep(0.5)
        href = await wp_login(ADMIN_USER, ADMIN_PASS)

        # Enable technician restriction setting
        restriction_was = wpcli("option get swh_restrict_to_assigned 2>/dev/null")
        wpcli("option update swh_restrict_to_assigned yes")

        # Verify tech2 (not assigned) cannot see test ticket in admin list
        await wp_logout()
        await asyncio.sleep(0.5)
        await wp_login(_require("WP_TECH2_USER"), _require("WP_TECH2_PASS"))
        await navigate(f"{WP_ADMIN_URL}/edit.php?post_type=helpdesk_ticket", wait=2.5)
        body = await js("document.body.innerText") or ""
        check("access control: tech2 (unassigned) cannot see tech1's ticket",
              TEST_TICKET_TITLE not in body,
              "ticket should be hidden from unassigned technician")
        await screenshot("16_tech2_restricted_list")

        # Restore original setting
        await wp_logout()
        await asyncio.sleep(0.5)
        await wp_login(ADMIN_USER, ADMIN_PASS)
        if restriction_was in ("", "no"):
            wpcli("option delete swh_restrict_to_assigned 2>/dev/null")
        else:
            wpcli(f"option update swh_restrict_to_assigned {restriction_was}")

        # ── [12] Admin: Ticket List Filters ───────────────────────────────────
        print("\n[12] Admin: Ticket List Filters")

        await navigate(f"{WP_ADMIN_URL}/edit.php?post_type=helpdesk_ticket", wait=2.5)
        check("ticket list: page loads",
              await js("!!document.querySelector('.wp-list-table, .no-items, #the-list')"))
        check("ticket list: has status filter column",
              "Status" in (await js("document.body.innerText") or ""))
        check("ticket list: has priority column",
              "Priority" in (await js("document.body.innerText") or ""))
        await screenshot("17_admin_ticket_list_final")

        # ── [13] Ticket Lookup (Resend Links) ─────────────────────────────────
        print("\n[13] Ticket Lookup (Frontend — Resend Links)")

        await wp_logout()
        await asyncio.sleep(0.5)

        await navigate(WP_SUBMIT_PAGE, wait=5.0)
        toggle_exists = await js("!!document.querySelector('#swh-toggle-lookup')")
        check("lookup: toggle link present", bool(toggle_exists))

        if toggle_exists:
            await js("document.querySelector('#swh-toggle-lookup').click();")
            await asyncio.sleep(0.5)
            lookup_form_visible = await js(
                "document.querySelector('#swh-lookup-form')?.style?.display !== 'none'"
            )
            check("lookup: form shows after toggle", bool(lookup_form_visible))

            await js(f"""
                document.querySelector('[name="swh_lookup_email"]').value = {json.dumps(CLIENT1_EMAIL)};
            """)
            await js("document.querySelector('[name=\"swh_ticket_lookup\"]').click();")
            await asyncio.sleep(3.0)
            check("lookup: success message shown (email enumeration safe)",
                  "swh-alert-success" in (await js("document.body.innerHTML") or ""))
            await screenshot("18_lookup_submitted")
            expect_email(CLIENT1_EMAIL, "ticket lookup — resent secure links to client")

        # ── [14] Accessibility Assertions ─────────────────────────────────────────
        print("\n[14] Accessibility Assertions")

        # Frontend submit form (logged out) — check honeypot aria-hidden
        await navigate(WP_SUBMIT_PAGE, wait=5.0)
        page_html = await js("document.body.innerHTML") or ""
        check("a11y: honeypot div has aria-hidden=true",
              'aria-hidden="true"' in page_html)

        # Navigate to portal page without a token → always renders role=alert error div
        if state.get('portal_url'):
            portal_base = state['portal_url'].split('?')[0]
            await navigate(portal_base, wait=3.0)
            portal_err_html = await js("document.body.innerHTML") or ""
            check("a11y: portal error div has role=alert",
                  'role="alert"' in portal_err_html)

        # Admin settings: active tab controls a tabpanel
        await wp_login(ADMIN_USER, ADMIN_PASS)
        await navigate(f"{WP_ADMIN_URL}/edit.php?post_type=helpdesk_ticket&page=swh-settings", wait=2.0)
        active_ctrl = await js(
            "document.querySelector('[role=\"tab\"][aria-selected=\"true\"]')?.getAttribute('aria-controls')"
        )
        check("a11y: active settings tab has aria-controls",
              bool(active_ctrl))
        if active_ctrl:
            panel_role = await js(
                f"document.getElementById({json.dumps(active_ctrl)})?.getAttribute('role')"
            )
            check("a11y: controlled settings panel has role=tabpanel",
                  panel_role == "tabpanel")

        # Admin ticket editor: label associations and role=log
        if state.get('ticket_id'):
            await navigate(
                f"{WP_ADMIN_URL}/post.php?post={state['ticket_id']}&action=edit", wait=2.5
            )
            ed_html = await js("document.body.innerHTML") or ""
            check("a11y: ticket status field has label association",
                  'for="swh-status"' in ed_html and 'id="swh-status"' in ed_html)
            check("a11y: ticket editor conversation has role=log",
                  'role="log"' in ed_html)

        await wp_logout()
        await asyncio.sleep(0.5)

        # ── [15] Cleanup ──────────────────────────────────────────────────────────
        print("\n[15] Cleanup")

        await wp_login(ADMIN_USER, ADMIN_PASS)
        await navigate(f"{WP_ADMIN_URL}/edit.php?post_type=helpdesk_ticket", wait=2.5)

        # Click the "Trash" row-action link for the test ticket.
        # WP renders these inside .row-actions; they're in the DOM even without hover.
        trash_href = await js(f"""
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
            await navigate(trash_href, wait=2.5)
            body = await js("document.body.innerText") or ""
            check("cleanup: ticket moved to trash",
                  "moved to the Trash" in body or "Undo" in body)
        else:
            check("cleanup: trash link found for test ticket", False,
                  "row not found — ticket may have already been deleted or title mismatch")

        # Confirm it's gone from the main (non-trash) list
        await navigate(f"{WP_ADMIN_URL}/edit.php?post_type=helpdesk_ticket", wait=2.5)
        body = await js("document.body.innerText") or ""
        check("cleanup: ticket no longer in active list",
              TEST_TICKET_TITLE not in body)

        await wp_logout()
        await screenshot("19_final_state")

        # ── Summary ────────────────────────────────────────────────────────────
        total = pass_count + len(failures)
        print(f"\n{'='*62}")
        if failures:
            print(f"❌  {len(failures)}/{total} FAILURE(S):")
            for f in failures:
                print(f"    • {f}")
        else:
            print(f"✅  ALL {pass_count} CHECKS PASSED")
        print(f"    Screenshots: {OUT}/")

        # ── Email Verification Checklist ───────────────────────────────────────
        print(f"\n{'='*62}")
        print("📧  EMAIL CHECKS — verify via Gmail MCP:")
        print(f"    (Search window: roughly the past {len(EMAIL_CHECKS) * 2} minutes)")
        for i, ec in enumerate(EMAIL_CHECKS, 1):
            print(f"    [{i:02d}] To: {ec['to']}")
            print(f"          {ec['description']}")

        if failures:
            sys.exit(1)


asyncio.run(run())
