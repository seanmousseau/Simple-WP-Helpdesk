# tests/Integration/

Reserved for future tests that need a real WordPress bootstrap (`wp-phpunit`)
rather than the WP-Mock stubs in `tests/Unit/`.

**Why empty today.** PR 2 of the testing overhaul (see
`docs/internal/test-coverage-matrix.md`) delivers REST contract assertions at
the WP-Mock layer because they catch the v4.0.0 envelope regressions without
requiring a full WordPress test installation. A wp-phpunit bootstrap is
deferred to a later PR if integration scope grows beyond what mocks can model
(REST request lifecycle, capability inheritance, multi-table joins).

**When to add a test here instead of `tests/Unit/`.**

- The behavior under test inherently requires a real DB (cross-table joins,
  WP_Query meta_query semantics, capability inheritance through roles).
- The mock surface for a function approaches its real implementation (sign that
  WP-Mock is no longer pulling its weight).
- The test must exercise the WP REST server's auth/nonce/permission_callback
  pipeline end-to-end (rather than calling the controller method directly).

Until that day, this directory is a stub.
