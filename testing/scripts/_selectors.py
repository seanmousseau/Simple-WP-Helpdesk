"""
Semantic-selector helper library for Playwright E2E tests.

This module exists to make the v4.0.0 lesson stick: selector-coupled tests
"fixed" themselves to match UI changes instead of failing when the UI
broke (#421 — test_59, test_69, test_70, test_71 all updated reactively
during the v4.0.0 cycle). Every E2E test going forward should locate
elements via semantic primitives (role, label, name attribute,
human-visible text, `data-testid`) — never via CSS classes that the
React render layer can re-name freely.

What "semantic" means here, ranked best-to-worst:

  1. ARIA role + accessible name (best — survives markup rewrites)
       page.get_by_role("button", name="Save changes")
  2. Form-field name attribute (semantic — the HTML name is the field's
     contract with the server, won't change without a corresponding
     backend change)
       page.locator('input[name="swh_inbound_secret"]')
  3. Label association
       page.get_by_label("Default priority")
  4. Visible text
       page.get_by_text("All", exact=True)
  5. data-testid / data-ticket-id (last resort, but stable — add one to
     the markup if no other anchor is suitable)
       page.locator('[data-ticket-id="42"]')

What's BANNED:

  - CSS class selectors that reflect React render output. Class names
    like `.swh-inbox-row`, `.css-1xyz`, `.MuiButton-root` are subject
    to change without notice and tie tests to implementation details
    rather than user-visible behavior.
  - XPath that walks the DOM tree by structure (e.g. `//div[2]/span`).
  - Locating by visual position ("the third button").

Exception: a CSS class is acceptable if it's also documented as a stable
hook in `docs/internal/component-inventory.md` AND the test explicitly
references that documentation. The component inventory is the single
source of truth for which classes can be relied on.

Usage:

    from _selectors import inbox_root, ticket_row, settings_tab, ...

    inbox_root(page).wait_for(timeout=10_000)
    ticket_row(page, ticket_id=42).get_by_role("checkbox").check()
"""

from __future__ import annotations

# This module is imported only by E2E tests that already require Playwright.
# Letting the import fail loudly is the right behaviour — if Playwright isn't
# installed, the test stack is broken in a way we want to surface immediately,
# not paper over with string-stubs.
import re

from playwright.sync_api import Page, Locator


# ---------- Admin inbox ----------

def inbox_root(page: Page) -> Locator:
    """
    The React mount point for the admin inbox.

    Until the plugin migrates to a stable container role/landmark, the mount
    point's id (`#swh-inbox-root`) is the documented public anchor — see
    `docs/internal/component-inventory.md`. If you find yourself wanting to
    locate something inside the inbox, START here and chain.
    """
    return page.locator("#swh-inbox-root")


def ticket_row(page: Page, ticket_id: int | str) -> Locator:
    """
    A specific ticket row by id. `data-ticket-id` is added by the React
    renderer specifically to be E2E-stable — see TicketRow.jsx.
    """
    return page.locator(f'[data-ticket-id="{ticket_id}"]')


def ticket_row_by_subject(page: Page, subject: str) -> Locator:
    """
    A ticket row matched by the visible subject text. Use this when you've
    just created a ticket via wp-cli and need to find it by title.
    """
    return inbox_root(page).get_by_role("row").filter(has_text=subject)


def bulk_bar(page: Page) -> Locator:
    """The selection toolbar that appears when one or more rows are checked."""
    return page.get_by_role("toolbar", name="Bulk actions")


# ---------- Drawer ----------

def drawer(page: Page) -> Locator:
    """
    The slide-over ticket drawer (open state). aria-label is dynamic:
    `Ticket #N` when a ticket is loaded, `Ticket drawer` while loading.
    """
    return page.get_by_role("dialog", name=re.compile(r"^Ticket( #\d+| drawer)$"))


def drawer_title(page: Page) -> Locator:
    """The drawer's title region (renders the ticket subject)."""
    return drawer(page).get_by_role("heading", level=2)


def drawer_status_select(page: Page) -> Locator:
    """Drawer status dropdown."""
    return drawer(page).get_by_label("Status")


def drawer_assignee_select(page: Page) -> Locator:
    """Drawer assignee dropdown."""
    return drawer(page).get_by_label("Assignee")


def drawer_reply_box(page: Page) -> Locator:
    """Drawer reply textarea."""
    return drawer(page).get_by_label("Reply")


def drawer_send_button(page: Page) -> Locator:
    return drawer(page).get_by_role("button", name="Send reply")


# ---------- Cmd+K palette ----------

def palette(page: Page) -> Locator:
    return page.get_by_role("dialog", name="Command palette")


def palette_input(page: Page) -> Locator:
    return palette(page).get_by_role("searchbox")


def palette_result(page: Page, text: str) -> Locator:
    return palette(page).get_by_role("option").filter(has_text=text)


# ---------- Settings ----------

def settings_tab(page: Page, tab_name: str) -> Locator:
    """
    Settings tab by accessible name. tab_name is the visible tab label
    ("General", "Email", "Portal", "Routing", "Tools", "Anti-Spam", "Integrations").
    """
    return page.get_by_role("tab", name=tab_name)


def settings_form_field(page: Page, name: str) -> Locator:
    """
    Settings form input by its `name` attribute. The HTML name is the
    server-side contract — if it changes, the backend reader changes too,
    so this is a stable anchor.

    Example: settings_form_field(page, "swh_use_violet_theme").check()
    """
    return page.locator(f'[name="{name}"]')


def settings_save_button(page: Page) -> Locator:
    """The Save Changes button on any settings tab."""
    return page.get_by_role("button", name="Save Changes")


# ---------- Portal v2 ----------

def portal_root(page: Page) -> Locator:
    """Portal v2 wrapper. `id="swh-portal-app"` is the documented hook."""
    return page.locator("#swh-portal-app")


def portal_thread(page: Page) -> Locator:
    """The conversation log. Has role='log' aria-label='Conversation thread'."""
    return portal_root(page).get_by_role("log")


def portal_message(page: Page, author: str = "") -> Locator:
    """
    Message bubbles in the thread. `data-message-author` is added by the
    PHP template specifically as an E2E-stable hook (values: 'customer' or
    'staff'). Pass author="" to match any message.
    """
    if author:
        return portal_thread(page).locator(f'[data-message-author="{author}"]')
    return portal_thread(page).locator("[data-message-author]")


def portal_reply_box(page: Page) -> Locator:
    return portal_root(page).get_by_role("textbox", name="Reply")


def portal_send_button(page: Page) -> Locator:
    return portal_root(page).get_by_role("button", name="Send reply")


def portal_menu_trigger(page: Page) -> Locator:
    """The ⋮ ticket-actions menu trigger."""
    return portal_root(page).get_by_role("button", name="Ticket actions")


def portal_close_button(page: Page) -> Locator:
    return portal_root(page).get_by_role("button", name="Close ticket")


def portal_reopen_button(page: Page) -> Locator:
    return portal_root(page).get_by_role("button", name="Reopen ticket")


# ---------- Generic ----------

def submit_button(page: Page, label: str) -> Locator:
    """A submit button by visible label."""
    return page.get_by_role("button", name=label)


def notice(page: Page, text: str) -> Locator:
    """A WP admin notice or toast containing the given text."""
    return page.get_by_role("status").filter(has_text=text)
