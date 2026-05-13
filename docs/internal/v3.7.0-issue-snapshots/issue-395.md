# Issue #395: Performance baseline — capture timings before v4.0 inbox redesign

**Labels:** dx
**Milestone:** v3.7.0 — v4 Foundations

## Body

v4.0's inbox ([#349](https://github.com/seanmousseau/Simple-WP-Helpdesk/issues/349)) claims \"500+ tickets without lag.\" Lag vs what? Capture numbers now so v4.0 has a regression baseline.

### Scenarios to benchmark

1. **Admin ticket list page load** — at 100, 500, 1000 tickets (TTFB + DOMContentLoaded)
2. **Settings save round-trip** — full \`swh_options\` write + redirect (TTFB)
3. **\`swh_sla_check_event\` cron run** — at 100, 500 open tickets (wall clock)
4. **\`swh_report_kpi_data()\` cold + warm** (transient miss vs hit)
5. **Portal ticket view (admin token)** — TTFB
6. **Submission form POST** — full POST → redirect to portal (TTFB)
7. **REST inbound webhook** — bench under \`siege\` at 10/30/60 RPS

### Method

- Seed via WP-CLI script \`testing/scripts/seed_perf.php\` (parameterized count)
- Run via Playwright with \`browser_take_screenshot\` performance traces OR via curl \`-w\` format strings for server-side timing
- Capture: median, p95, p99
- Run on Docker stack (\`make e2e-docker\`) for reproducibility
- Output to \`docs/internal/performance-baseline.md\` with date + commit SHA

### Acceptance

- [ ] Seed script committed
- [ ] Bench script committed (\`testing/scripts/bench.sh\` or similar)
- [ ] Baseline numbers committed to \`docs/internal/performance-baseline.md\`
- [ ] Numbers re-runnable on demand (\`make bench\` target — optional but nice)
- [ ] v4.0 release process updated to require re-run + comparison
