# Performance Baseline

This document captures wall-clock performance numbers for the plugin's
critical paths. v4.0's inbox redesign
([#349](https://github.com/seanmousseau/Simple-WP-Helpdesk/issues/349)) and
other v4.x features must not regress these numbers without explicit
acknowledgement in the PR description.

Originating issue: [#395](https://github.com/seanmousseau/Simple-WP-Helpdesk/issues/395).

## Methodology

- **Stack:** local `docker-compose.test.yml` (WordPress + MySQL 8 + MailHog)
- **WordPress image:** `wordpress:php8.2-apache`
- **Seeded via:** `wp eval-file testing/scripts/seed_perf.php <count>`
  - Seeded tickets are tagged `_swh_perf_seed=1` so they can be bulk-deleted
    between runs. RNG is seeded (`mt_srand(42)`) so the spread of statuses,
    priorities, ages, and CC counts is reproducible across runs.
- **Driven via:** `testing/scripts/bench.sh` — 10 reps per scenario; reports
  median, p95, p99 in milliseconds.
- **Captured:** `curl -w '%{time_total}'` for HTTP scenarios (TTFB+body, no
  client-side rendering); `microtime(true)` wrapped around `wp cron event run`
  / `wp eval` for CLI scenarios.
- **Run with:** `make bench` (override count with `COUNT=500 make bench`).
- **Hardware (baseline run below):** macOS, Apple Silicon, Docker Desktop on
  the developer's local workstation. Numbers will shift on CI hardware — use
  this baseline for **trend** comparison on the same host, not as an absolute
  SLO.

## Baseline (v3.7.0, commit `b6ca0a5`)

Run date: 2026-05-13 (UTC), `COUNT=100`, `REPS=10`.

| Scenario | Count | Median (ms) | p95 (ms) | p99 (ms) |
|---|---|---|---|---|
| Admin ticket list page load | 100 | 142 | 152 | 152 |
| Settings save round-trip | n/a | 122 | 131 | 131 |
| `swh_sla_check_event` cron | ~100 open\* | 558 | 605 | 605 |
| `swh_report_kpi_data()` cold | n/a | 1 | 8 | 8 |
| `swh_report_kpi_data()` warm | n/a | 1 | 1 | 1 |
| Portal ticket view | n/a | 116 | 123 | 123 |
| Submission form POST | n/a | 113 | 119 | 119 |
| REST inbound webhook (curl loop) | n/a | 98 | 103 | 103 |

\* Seeded tickets are distributed across `Open / In Progress / Resolved /
Closed` (mt_rand with seed 42). The SLA cron iterates the subset matching the
`swh_sla_open_statuses` filter — roughly half the seeded set at COUNT=100.

### Observations

- **Admin list at 100 tickets** is fast (~142ms median). v4.0's inbox should
  not regress this; if React rendering adds >50ms here, justify in the PR.
- **SLA cron** is the slowest path by ~4×. It is also the only background
  scenario the user does not feel directly. Worth re-measuring at COUNT=500
  and COUNT=1000 — if it grows super-linearly, that's a v4.x optimization
  target.
- **KPI reporting** is sub-millisecond on this dataset because every ticket
  fits in a single indexed query. At COUNT=1000 with many comments per ticket
  this could change meaningfully; capture cold vs warm at higher counts to
  confirm the transient cache is doing real work.
- **REST inbound webhook** at ~100ms median is the single-request floor.
  Concurrency/throughput under `siege` is **not** captured here — see Gaps.

## Gaps

These were deliberately deferred from the v3.7.0 Phase 7 baseline run. Re-run
to fill them in:

- **COUNT=500 and COUNT=1000** runs not yet captured. Run with
  `COUNT=500 make bench` and `COUNT=1000 make bench` and append rows to the
  table above (or replace the COUNT column to reflect each run).
- **`siege` / `k6` throughput numbers for the REST inbound webhook** at
  10/30/60 RPS are not captured. The current scenario uses a sequential
  `curl` loop, which measures only single-request latency, not concurrent
  throughput. Install `siege` or `k6` on the host (or in a sidecar container)
  and re-run scenario 7 to fill this in.
- **DOMContentLoaded / client-side render timings** for the admin list are
  not captured. `curl -w` only measures server response. For
  client-perceived numbers, drive the scenario through Playwright with
  `page.evaluate(() => performance.timing)` or the Performance Observer API.
- **First-paint / largest-contentful-paint** for the portal/submission form
  are not captured for the same reason.

## How to re-run

```bash
# Start stack + install WP + activate plugin (one-time per session)
docker compose -f docker-compose.test.yml up -d db wordpress wpcli mailhog
bash docker/setup-test-wp.sh

# Run benchmark (default COUNT=100, REPS=10)
make bench

# Higher counts
COUNT=500 make bench
COUNT=1000 make bench

# Capture to a file for diffing against this baseline
make bench > /tmp/bench-$(date +%Y%m%d).out
```

Between runs, clean seeded tickets if you want to re-seed from a clean slate:

```bash
docker compose -f docker-compose.test.yml exec -T wpcli \
  wp --allow-root --path=/var/www/html post delete \
  $(docker compose -f docker-compose.test.yml exec -T wpcli \
    wp --allow-root --path=/var/www/html post list \
    --post_type=helpdesk_ticket --meta_key=_swh_perf_seed --meta_value=1 \
    --format=ids) --force
```
