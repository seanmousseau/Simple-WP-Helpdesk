# tests/Integration/

Integration tests that run against a **real WordPress test installation**
via [wp-phpunit](https://packagist.org/packages/wp-phpunit/wp-phpunit).
Unlike `tests/Unit/` (which stubs WP functions via WP-Mock), tests here
exercise:

- The actual REST server (`rest_do_request`), including
  `permission_callback` enforcement
- Real auth state (`wp_set_current_user`, factory-created users, role
  hierarchy)
- Real database joins, `WP_Query` `meta_query` semantics, transient locks
- The full request lifecycle, response headers, and capability inheritance

This is the "PR 7" layer from the testing-overhaul migration plan
(`docs/internal/test-coverage-matrix.md`).

## What's in vs. what stays in `tests/Unit/`

| Goal | Suite |
|---|---|
| Pin a load-bearing source pattern (no WP needed) | `tests/Unit/Invariants/` |
| Assert a REST envelope shape under happy/error inputs | `tests/Unit/Rest/Contracts/` (mocked, fast) |
| Assert a controller method's pure logic with mocked WP fns | `tests/Unit/` |
| **Assert `permission_callback` rejects an unauthorised user** | `tests/Integration/Rest/` |
| **Assert real auth (cookie/app password) is honoured** | `tests/Integration/` |
| **Assert a `WP_Query` meta_query joins the right rows** | `tests/Integration/` |
| **Assert concurrent writes hit the optimistic-lock guard** | `tests/Integration/` |

Mocked tests are still the bulk of the suite — they're fast and catch most
regressions. Integration tests cover the gap mocks structurally can't reach.

## Operator setup (one-time)

You need a local MySQL/MariaDB the test framework can throw away on every
run. **Never point at a real database** — the bootstrap drops it.

```bash
# 1. Install dev deps (gets wp-phpunit + yoast/phpunit-polyfills)
composer install --dev

# 2. Provision the test DB and the wp-tests-config.php
bin/install-wp-tests.sh <db_name> <db_user> <db_pass> [db_host]

# Example with a local MySQL on default port:
bin/install-wp-tests.sh swh_phpunit_test root '' 127.0.0.1:3306
```

The installer:

- Creates (and drops if exists) the test database
- Writes `tests/Integration/wp-tests-config.php` — **gitignored**, contains
  DB credentials. Re-run the installer to regenerate.

## Running

```bash
make test-integration
```

Failure modes the target catches with clear messages:

- `wp-tests-config.php` missing → run `bin/install-wp-tests.sh` first
- `vendor/wp-phpunit/wp-phpunit` missing → run `composer install --dev`

Or invoke phpunit directly:

```bash
vendor/bin/phpunit --configuration phpunit-integration.xml
```

## Writing tests

```php
namespace SWH\Tests\Integration\Rest;

class MyTest extends \WP_UnitTestCase {
    public function test_something(): void {
        $admin = $this->factory()->user->create( array( 'role' => 'administrator' ) );
        wp_set_current_user( $admin );

        $request = new \WP_REST_Request( 'GET', '/swh/v1/tickets' );
        $response = rest_do_request( $request );

        $this->assertEquals( 200, $response->get_status() );
    }
}
```

Group integration tests with PHPDoc `@group integration` so they're
selectable independently if needed.

## CI integration

Not yet. The current `Test Integration` workflow plan (PR 7.1+) needs:

- A MySQL service container in the workflow YAML
- `bin/install-wp-tests.sh` invocation as a CI step
- Cache management for `vendor/wp-phpunit` between runs

Until that PR lands, integration tests run locally (and as part of the RC
extended-test runbook). They are **NOT** in the per-push gate; the per-push
gate stays Unit + Invariants (fast).

## Why not move ALL REST tests here?

Speed. The mocked Contract tests run in ~50ms — every PR pays that cost.
Integration tests run in ~30–60 seconds because they boot WordPress, install
the test DB schema, and create the test admin on every PHPUnit run. We pay
that cost only for the tests where mocking can't model the truth.
