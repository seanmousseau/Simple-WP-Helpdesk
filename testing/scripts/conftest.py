"""
pytest configuration for the Simple WP Helpdesk test suite.

Provides:
- .env loading before collection
- Session-scoped browser page (shared login state across all test functions)
- Pre-suite option reset (clears dirty state from prior failed runs)
- Soft-fail integration: check() failures inside a test are surfaced as pytest failures
- EMAIL CHECKS summary appended to the terminal report
"""
import os

import pytest


# ── .env loading ──────────────────────────────────────────────────────────────

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
        return


def pytest_configure(config):
    _load_dotenv()
    config.addinivalue_line(
        "markers",
        "smoke: quick pre-push sanity check — auth, plugin verify, ticket submit, locate"
    )
    config.addinivalue_line(
        "markers",
        "security: security-focused checks — spam, input validation, tokens, XSS, access control, rate limiting"
    )
    config.addinivalue_line(
        "markers",
        "slow: slow tests that hit external services or do file I/O — CDN icon check, file attachments"
    )


# ── Session-scoped page fixture ───────────────────────────────────────────────
# Override pytest-playwright's default function-scoped page so all test
# functions share a single browser session (login cookies persist across tests).

@pytest.fixture(scope="session")
def page(browser):
    context = browser.new_context(viewport={"width": 1440, "height": 900})
    pg = context.new_page()
    yield pg
    context.close()


# ── Pre-suite setup ───────────────────────────────────────────────────────────

@pytest.fixture(scope="session", autouse=True)
def _suite_init(page, request):
    """Wire _page into the test module and reset dirty options before the suite."""
    import test_helpdesk_pw as t
    t._page = page

    # Reset option that may be dirtied by a prior failed technician workflow test.
    # Use eval so delete_option() always exits 0 (wp option delete exits 1 if absent).
    t.wpcli("eval 'delete_option(\"swh_restrict_to_assigned\");'")

    def _cleanup():
        """Trash tickets created during the run and log out — runs even on KeyboardInterrupt."""
        for key in ("ticket_id", "ticket2_id", "xss_ticket_id"):
            pid = t.state.get(key)
            if pid:
                try:
                    t.wpcli(f"post delete {pid} --force 2>/dev/null")
                except Exception:
                    pass
        try:
            t.wp_logout(page)
        except Exception:
            pass

    request.addfinalizer(_cleanup)


# ── Soft-fail: surface check() failures as pytest failures ────────────────────
# Each test function runs to completion (all check() calls evaluated), then the
# fixture fails the test if any new failures were recorded during that function.

@pytest.fixture(autouse=True)
def _section_fail_on_check():
    import test_helpdesk_pw as t
    before = len(t._results["failures"])
    yield
    new_failures = t._results["failures"][before:]
    if new_failures:
        pytest.fail("\n".join(new_failures), pytrace=False)


# ── EMAIL CHECKS summary ──────────────────────────────────────────────────────

def pytest_terminal_summary(terminalreporter, exitstatus, config):
    try:
        import test_helpdesk_pw as t
    except ImportError:
        return
    if not t.EMAIL_CHECKS:
        return
    terminalreporter.write_sep("=", "EMAIL CHECKS — verify via Gmail MCP")
    terminalreporter.write_line(
        f"  (Search window: roughly the past {len(t.EMAIL_CHECKS) * 2} minutes)\n"
    )
    for i, ec in enumerate(t.EMAIL_CHECKS, 1):
        terminalreporter.write_line(f"  [{i:02d}] To: {ec['to']}")
        terminalreporter.write_line(f"        {ec['description']}")
