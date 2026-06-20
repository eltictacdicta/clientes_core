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
