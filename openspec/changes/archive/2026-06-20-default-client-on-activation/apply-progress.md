# Apply Progress — default-client-on-activation

> Change: `plugins/clientes_core/openspec/changes/default-client-on-activation/`
> Date: 2026-06-20
> Author: sdd-apply (FSFramework, MiniMax M3)

## Commit strategy decision

The repo's recent history includes a "red" commit (`test(ventas_clientes): add
dispatch regression test (red)`), so the project explicitly allows TDD red
commits on the main branch. Following the plan in `tasks.md` §Work units and
commits, this change is split into **3 atomic commits**:

1. `test(clientes_core): scaffold InitUpgradeTest with first two scenarios` (RED)
2. `feat(clientes_core): seed default cliente on activation via Init::upgrade()` (GREEN)
3. `chore(clientes_core): bump plugin version to 2` (housekeeping)

## T1 — Bootstrap test scaffolding (RED verified)

- **Files created**:
  - `plugins/clientes_core/tests/Fixtures/InitUpgradeFakes.php` (fake `cliente` and `fs_settings`)
  - `plugins/clientes_core/tests/InitUpgradeTest.php` (cases 1 + 2, RED)
- **Files modified**:
  - `plugins/clientes_core/phpunit.xml` — added `processIsolation="true"`.
    Required for the autoloader-based fake injection to work in a multi-test
    file suite; without it, `ClienteModelTest::setUp()` eagerly requires the
    production `clientes_core/model/core/cliente.php`, and once that class is
    loaded PHP does not allow another file to redefine the same FQCN. The
    prepended autoloader can only intercept a *first-time* load.
- **Verification command**:
  ```
  ddev exec php vendor/bin/phpunit -c plugins/clientes_core/phpunit.xml
  ```
- **Result**:
  - 32 pre-existing tests pass.
  - 2 new tests in `InitUpgradeTest` error with:
    `Error: Call to undefined method FSFramework\Plugins\clientes_core\Init::upgrade()`
  - Suite-level: `Tests: 34, Assertions: 68, Errors: 2`.
  - Exit code 2.
- **RED confirmed** ✅

## T2 — Implement `Init::upgrade()` (GREEN)

- **Files modified**:
  - `plugins/clientes_core/Init.php` — added `use FSFramework\model\cliente;` and
    new `public static function upgrade(): void` method (no change to existing
    `init()` or its Twig registration).
- **Verification command**:
  ```
  ddev exec php vendor/bin/phpunit -c plugins/clientes_core/phpunit.xml
  ```
- **Result**: `Tests: 34, Assertions: 76, PHPUnit Deprecations: 3` — all pass.
- **GREEN confirmed** ✅

## T3 — Add required test cases 3 and 4 (GREEN)

- **Files modified**:
  - `plugins/clientes_core/tests/InitUpgradeTest.php` — added
    `test_is_noop_when_table_nonempty_and_sets_flag` and
    `test_swallows_db_error_during_save`.
- **Verification command**:
  ```
  ddev exec php vendor/bin/phpunit -c plugins/clientes_core/phpunit.xml
  ```
- **Result**: `Tests: 36, Assertions: 84, PHPUnit Deprecations: 5` — all pass.
- **GREEN confirmed** ✅

## T4 — Add bonus test cases 5 and 6 (GREEN)

- **Files modified**:
  - `plugins/clientes_core/tests/InitUpgradeTest.php` — added
    `test_cold_start_auto_creates_table` and `test_sets_flag_via_set_and_save`.
- **Verification command**:
  ```
  ddev exec php vendor/bin/phpunit -c plugins/clientes_core/phpunit.xml
  ```
- **Result**: `Tests: 38, Assertions: 90, PHPUnit Deprecations: 7` — all pass.
- **GREEN confirmed** ✅

## T5 — Verify plugin suite + root suite

| Command | Exit | Result |
|---------|------|--------|
| `ddev exec php vendor/bin/phpunit -c plugins/clientes_core/phpunit.xml` | 0 | `Tests: 38, Assertions: 90` (all pass) |
| `ddev exec php vendor/bin/phpunit --testsuite Plugins` | 1 | `Tests: 289, Assertions: 589, Failures: 1, Skipped: 1`. The single failure is `CsrfTokenTest::expiredTokenIsRejected` in `plugins/system_updater/` — **pre-existing** (verified via `git diff HEAD~1 -- plugins/system_updater/` shows zero changes; the test was failing before this change). |
| `ddev exec php vendor/bin/phpunit` (full root) | 1 | `Tests: 714, Assertions: 1591, Failures: 1, Skipped: 14`. Same single pre-existing CSRF failure. **No new regressions.** |

- `--list-tests` for the Plugins suite confirms the new `InitUpgradeTest` is
  discovered automatically (`plugins/*/tests/*Test.php` glob), with all 6 new
  test methods listed:
  ```
  - Tests\ClientesCore\InitUpgradeTest::test_seeds_default_client_when_table_empty_and_flag_unset
  - Tests\ClientesCore\InitUpgradeTest::test_is_noop_when_flag_already_set
  - Tests\ClientesCore\InitUpgradeTest::test_is_noop_when_table_nonempty_and_sets_flag
  - Tests\ClientesCore\InitUpgradeTest::test_swallows_db_error_during_save
  - Tests\ClientesCore\InitUpgradeTest::test_cold_start_auto_creates_table
  - Tests\ClientesCore\InitUpgradeTest::test_sets_flag_via_set_and_save
  ```

## T6 — Version bump

- **Files modified**:
  - `plugins/clientes_core/fsframework.ini` — `version = 1` → `version = 2`.
- **Rationale**: the archived `fix-backup-worker-recovery` change followed the
  same convention (Task 5 in that change's `tasks.md` bumped the plugin's
  `fsframework.ini` on a behavioural change). The new `Init::upgrade()` is a
  behavioural change observable at activation, so the version bump applies.
- **Verification**: re-read `plugins/clientes_core/fsframework.ini` to confirm
  `version = 2`; all other fields unchanged.

## Commits

| # | SHA | Message |
|---|-----|---------|
| 1 | `c5bc4b6e` | `test(clientes_core): scaffold InitUpgradeTest with first two scenarios` |
| 2 | _pending_ | `feat(clientes_core): seed default cliente on activation via Init::upgrade()` |
| 3 | _pending_ | `chore(clientes_core): bump plugin version to 2` |

## Pass 2 — CRITICAL-1 fix (2026-06-20)

> **Origin**: `verify-report.md` → CRITICAL-1 (protected `$db` access
> from `Init::upgrade()` line 70). Original 3 apply commits
> (`c5bc4b6e`, `3133ba34`, `749823a8`) remain. This pass adds a new
> fix commit on top.
>
> **Chosen fix (J1 — clean architecture)**: add a public method
> `cliente::table_has_rows(): bool` to the model; refactor
> `Init::upgrade()` to call that method instead of reaching for
> `$cliente->db` directly.

### Commit strategy decision

A single atomic commit is chosen for the fix:

> `fix(clientes_core): use public cliente::table_has_rows() to fix protected $db access`

Rationale (per `work-unit-commits` skill):

- One clear purpose: "stop reaching for protected $db from outside
  the class". The repo still makes sense after applying only this
  commit (the seeder works, the new test passes, the fake matches,
  the SDD is consistent).
- The new public method and its test are co-introduced in the same
  commit (RED → GREEN for the new test, GREEN for the existing 6
  `InitUpgradeTest` cases after the refactor).
- The fake update, the Init.php refactor, and the SDD artifact
  updates are tightly coupled: any subset leaves the repo in an
  inconsistent state (e.g. Init.php calls a method the fake does not
  have, or the spec mandates behaviour the code does not implement).
- Estimated diff: ≈ 130 lines (≈ 13 prod + ≈ 25 new tests + ≈ 50
  fake refactor + ≈ 42 SDD docs), well under the 400-line review
  budget.

### T7.A — Add `cliente::table_has_rows()` to the model (RED → GREEN)

- **Files modified**:
  - `plugins/clientes_core/model/core/cliente.php` — added public
    `table_has_rows(): bool` method (10 lines including docblock).
- **Verification command (RED — before model change)**:
  ```
  ddev exec php vendor/bin/phpunit -c plugins/clientes_core/phpunit.xml --filter TableHasRows
  ```
- **Result (RED)**: `Tests: 3, Assertions: 0, Errors: 3`. All three
  new tests fail with
  `Error: Call to undefined method FSFramework\model\cliente@anonymous::table_has_rows()`.
  RED confirmed ✅.
- **Verification command (GREEN — after model change)**:
  ```
  ddev exec php vendor/bin/phpunit -c plugins/clientes_core/phpunit.xml --filter TableHasRows
  ```
- **Result (GREEN)**: `Tests: 3, Assertions: 4, PHPUnit Deprecations: 1`.
  All three new tests pass. GREEN confirmed ✅.

### T7.B — Unit test for `cliente::table_has_rows()` (already in T7.A RED)

The three new test methods added to
`plugins/clientes_core/tests/ClienteModelTest.php`:

- `testTableHasRowsReturnsTrueWhenNonEmpty`
- `testTableHasRowsReturnsFalseWhenEmpty`
- `testTableHasRowsQueriesTheClientesTable`

Each exercises one branch of the method (returns `true` on
non-empty, returns `false` on empty, captures the SQL to confirm it
targets `$this->table_name`). The fourth assertion (4 across 3
tests) covers the SQL contract — guards against a future refactor
that hardcodes a different table.

### T7.C — Refactor `Init::upgrade()` to use the new method

- **Files modified**:
  - `plugins/clientes_core/Init.php` — replaced
    `$rows = $cliente->db->select("SELECT 1 FROM clientes LIMIT 1");
    if (empty($rows)) { ... }` with
    `if (!$cliente->table_has_rows()) { ... }`. The docblock and
    the `try/catch` are unchanged. The now-unused `$rows` variable
    is gone.
- **Verification command**:
  ```
  ddev exec php vendor/bin/phpunit -c plugins/clientes_core/phpunit.xml
  ```
- **Result**: `Tests: 41, Assertions: 95, PHPUnit Deprecations: 7` —
  all pass. (38 pre-existing + 3 new in `ClienteModelTest` for
  `table_has_rows()`; the refactor did not break the existing 6
  `InitUpgradeTest` cases because the fake now exposes a public
  `table_has_rows()` too — see T7.D.)
- GREEN confirmed ✅.

### T7.D — Update the test fake and the existing 6 InitUpgradeTest cases

- **Files modified**:
  - `plugins/clientes_core/tests/Fixtures/InitUpgradeFakes.php` —
    removed the inline `$db` stub's `select()`-return seam (the
    fake's db stub now just returns `[]`); added
    `public static ?bool $table_has_rows_result`,
    `public static int $table_has_rows_calls`, and a public
    `table_has_rows(): bool` method on the fake that increments
    the counter and returns the configured value. Updated
    `resetStatic()` to clear the new state.
  - `plugins/clientes_core/tests/InitUpgradeTest.php` — replaced
    the old `\FSFramework\model\cliente::$selectResult` / `$selectCalls`
    usage with `\FSFramework\model\cliente::$table_has_rows_result` /
    `$table_has_rows_calls` across all 6 cases. Updated the
    docblock to reflect the new seam.
- **Verification command**:
  ```
  ddev exec php vendor/bin/phpunit -c plugins/clientes_core/phpunit.xml
  ```
- **Result**: `Tests: 41, Assertions: 95, PHPUnit Deprecations: 7` —
  all pass. The 6 `InitUpgradeTest` cases still pass with the new
  fake API; the 32 pre-existing tests still pass; the 3 new
  `ClienteModelTest` tests still pass. GREEN confirmed ✅.

### T7.E — Update the SDD artifacts

- **Files modified**:
  - `plugins/clientes_core/openspec/changes/default-client-on-activation/specs/clientes/spec.md` —
    replaced the SQL-string SHALL with the new
    `table_has_rows()`-method SHALL; added a new SHALL mandating
    that the `cliente` model expose the public method; added two
    new Scenarios ("Seeder uses the public `table_has_rows()` API"
    and "`cliente::table_has_rows()` is public and table-portable").
  - `plugins/clientes_core/openspec/changes/default-client-on-activation/design.md` —
    updated the `cliente` model API table (added the new method
    row), replaced the "Counting rows" subsection to describe the
    public method, updated the implementation sketch to use
    `$cliente->table_has_rows()`, and added Risk #11
    ("Method visibility regression" + mitigation = unit test
    canary).
  - `plugins/clientes_core/openspec/changes/default-client-on-activation/tasks.md` —
    added the T7 entry at the end with the six sub-tasks
    (T7.A, T7.B, T7.C, T7.D, T7.E, T7.F) and the spec references.
  - `plugins/clientes_core/openspec/changes/default-client-on-activation/apply-progress.md` —
    this section.

### T7.F — Re-run the automated test suites

| Command | Exit | Result |
|---------|------|--------|
| `ddev exec php vendor/bin/phpunit -c plugins/clientes_core/phpunit.xml` | 0 | `Tests: 41, Assertions: 95, PHPUnit Deprecations: 7` (all pass; 32 pre-existing + 3 new in `ClienteModelTest` + 6 in `InitUpgradeTest`) |
| `ddev exec php vendor/bin/phpunit --testsuite Plugins` | 1 | `Tests: 292, Assertions: 594, Failures: 1, Skipped: 1`. The single failure is the pre-existing `CsrfTokenTest::expiredTokenIsRejected` in `plugins/system_updater/` — NOT a regression from this fix (zero files under `plugins/system_updater/` were touched by the fix; the failure was present before T7 and is documented in `verify-report.md` §"Pre-existing out-of-scope observations"). |
| `ddev exec php vendor/bin/phpunit` (full root) | 1 | `Tests: 717, Assertions: 1596, Failures: 1, Skipped: 14`. Same single pre-existing CSRF failure. **No new regressions.** |

### Live smokes

NOT re-run in this apply pass — that is the next phase
(`sdd-verify`) per the orchestrator's explicit instruction
("`H. Do NOT re-run the live smoke checks`"). The next phase
must re-run Smoke #1, #2, #3, #4 on the ddev DB to confirm
that the seeder now actually inserts the row and persists the
flag (the pre-fix smokes failed at lines 70 of `Init.php`;
post-fix, the new method `table_has_rows()` does the SELECT
through the model's protected `$db` legally, so the seeder
should reach the `$cliente->save()` and `$settings->set()` /
`save()` calls as designed).

### Files changed in Pass 2

| Path | Action | T7 sub-task |
|------|--------|------------|
| `plugins/clientes_core/model/core/cliente.php` | modified (new public method + docblock) | T7.A |
| `plugins/clientes_core/tests/ClienteModelTest.php` | modified (3 new test methods + helper) | T7.B |
| `plugins/clientes_core/Init.php` | modified (refactor of `upgrade()` body) | T7.C |
| `plugins/clientes_core/tests/Fixtures/InitUpgradeFakes.php` | modified (new public `table_has_rows()` + state) | T7.D |
| `plugins/clientes_core/tests/InitUpgradeTest.php` | modified (use new fake API across 6 cases) | T7.D |
| `plugins/clientes_core/openspec/changes/default-client-on-activation/specs/clientes/spec.md` | modified (new SHALLs + 2 new Scenarios) | T7.E |
| `plugins/clientes_core/openspec/changes/default-client-on-activation/design.md` | modified (API table, sketch, Risks) | T7.E |
| `plugins/clientes_core/openspec/changes/default-client-on-activation/tasks.md` | modified (T7 entry) | T7.E |
| `plugins/clientes_core/openspec/changes/default-client-on-activation/apply-progress.md` | modified (this section) | T7.E |

### Pre-existing failures observed in T7.F

| Test | File | Status |
|---|---|---|
| `CsrfTokenTest::expiredTokenIsRejected` | `plugins/system_updater/tests/CsrfTokenTest.php:83` | Pre-existing, out-of-scope, NOT a regression introduced by T7. Same failure as in `apply-progress.md` §T5 and `verify-report.md` §"Pre-existing out-of-scope observations". |

### Notes for the next phase (sdd-verify)

- **Re-run the four live smoke checks** (Smoke #1 cold start,
  Smoke #2 re-activation no-op, Smoke #3 non-empty install, Smoke
  #4 DB error during seed). All four should now PASS — the
  post-fix seeder calls `$cliente->table_has_rows()` which is
  legal from outside the class, so the seeder reaches the
  `$cliente->save()` and `$settings->set()` / `save()` calls as
  designed.
- The unit test for `cliente::table_has_rows()` is the new
  visibility-regression canary. If a future refactor removes
  the public method or makes it `protected`, the 3 tests in
  `ClienteModelTest` will fail. CI must remain green.
- The `CsrfTokenTest::expiredTokenIsRejected` failure in
  `plugins/system_updater/` remains pre-existing and unrelated.

## Files changed

| Path | Action |
|------|--------|
| `plugins/clientes_core/tests/Fixtures/InitUpgradeFakes.php` | created (T1) |
| `plugins/clientes_core/tests/InitUpgradeTest.php` | created (T1), extended (T2/T3/T4) |
| `plugins/clientes_core/phpunit.xml` | modified (T1 — `processIsolation="true"`) |
| `plugins/clientes_core/Init.php` | modified (T2 — new `upgrade()` method) |
| `plugins/clientes_core/fsframework.ini` | modified (T6 — version bump) |

## Notes for the next phase (sdd-verify)

- The `CsrfTokenTest::expiredTokenIsRejected` failure in
  `plugins/system_updater/` is pre-existing and unrelated to this change. It
  should be reported as an out-of-scope observation, not a regression.
- Per-test process overhead from `processIsolation="true"` adds ~0.1s per
  test (3.7s total for the 38-test plugin suite, vs 0.04s without). Acceptable
  for a 38-test suite, but a future maintainer who finds this too slow could
  revisit the test-seam design (e.g. a class_alias-based global symbol
  override that does not require process isolation) — see `design.md` §Test
  plan / Mocking strategy for the unresolved seam discussion.
- Manual smoke checks (cold start, idempotent re-activation, non-empty
  install, activation-survives-DB-error) listed in `tasks.md` §Verification
  checklist require a live DB and were not executed in this apply phase. The
  verify phase should run at least smoke #1 (cold start) on the ddev DB.
