# Tasks — default-client-on-activation

## Summary

Small, isolated change inside `plugins/clientes_core/`. Touches exactly
two files: a new static `Init::upgrade()` method on
`plugins/clientes_core/Init.php` (≈ +35 lines including docblock) and a
new `plugins/clientes_core/tests/InitUpgradeTest.php` (≈ +180 lines
covering 4 required scenarios + 2 optional bonus scenarios). No edits
to `base/`, `src/`, `controller/`, `model/`, the core `openspec/` tree,
or any other plugin. Total estimated production + test diff: ≈ 220
lines, well under the 400-line review budget — a single PR is
appropriate, no chained PRs needed.

## Task ordering and dependencies

Tasks MUST be executed in numeric order (T1 → T2 → T3 → T4 → T5 → T6).
The sequence follows strict TDD forward: each test is written before
the production code that makes it pass. T1 lands the first two test
cases and an autoloader seam (red, because `Init::upgrade()` does not
exist yet). T2 implements the seeder and flips T1 green. T3 adds the
two remaining required cases (red → green within the same task, since
the implementation is already in place from T2). T4 adds the optional
bonus cases. T5 runs the verification checklist. T6 is a small
housekeeping step that may be skipped if the project does not require
a version bump for behavioural changes. T5 is the only task that must
NOT introduce new code; it is a pure verification gate.

## Tasks

### T1. Bootstrap test scaffolding for `InitUpgradeTest`

- **Type**: `test`
- **Files touched**:
  - `plugins/clientes_core/tests/InitUpgradeTest.php` (create)
- **Estimated changed lines**: `+90 / -0`
- **Depends on**: none
- **Acceptance criteria**:
  - File exists at the canonical path (`plugins/clientes_core/tests/InitUpgradeTest.php`,
    flat in `tests/`, matching `ClienteModelTest.php` style).
  - Namespace is `Tests\ClientesCore` and class is `InitUpgradeTest`
    extending `PHPUnit\Framework\TestCase`.
  - `setUp()` resets `$GLOBALS['config2']` to `[]` and registers a
    test-only autoloader (or class-alias shim) that resolves
    `\cliente` and `\fs_settings` to in-test fakes. `tearDown()`
    unregisters the autoloader and clears `$GLOBALS['config2']`.
  - Two test methods exist: `emptyTableAndNoFlag_insertsAndSetsFlag`
    and `emptyTableAndFlagAlreadySet_isNoOp`. Both follow the design
    sketch (case 1 and case 2 in `design.md` §Test plan).
  - Running the suite reports both methods as **failing / erroring**
    with a clear `Init::upgrade()` not-defined message. This is the
    "red" state — the failing tests are the point of this task.
- **Spec references**:
  - `default-client-on-activation#Requirement:Default client seed on plugin activation#SHALL #1`
  - `default-client-on-activation#Requirement:Default client seed on plugin activation#SHALL #2`
  - `default-client-on-activation#Requirement:Idempotency and flag persistence#SHALL #1`

### T2. Implement `Init::upgrade()`

- **Type**: `impl`
- **Files touched**:
  - `plugins/clientes_core/Init.php` (modify)
- **Estimated changed lines**: `+37 / -0`
- **Depends on**: T1
- **Acceptance criteria**:
  - A new `use \FSFramework\model\cliente;` import is added to the
    existing `use` block in `plugins/clientes_core/Init.php` (joining
    the `FSEventDispatcher` / `TwigInitEvent` imports). The
    `require_once __DIR__ . '/src/ViewHookRegistry.php';` line and
    the `namespace` declaration are left untouched.
  - A new `public static function upgrade(): void` method is added
    below the existing `init()` method, with a docblock explaining
    it is the activation hook called by
    `fs_plugin_manager::runPluginUpgrade()`. The docblock must state
    that the method is idempotent (flag-gated) and never throws
    (try/catch isolated), so future maintainers recognise the
    contract.
  - The body implements the design sketch exactly: `new \fs_settings()`,
    early-return on the flag, `try { new cliente(); select(...);
    save(); set+save flag; } catch (\Throwable $e) {}`.
  - The existing instance `init()` method, its `use` statements, and
    the `ViewHookRegistry` import are **NOT** modified. A git diff
    must show zero lines changed in those regions.
  - Running the suite now reports all of T1's tests as **passing**
    (green). T3 and T4 tests do not exist yet.
- **Spec references**:
  - `default-client-on-activation#Requirement:Default client seed on plugin activation#SHALL #1`
  - `default-client-on-activation#Requirement:Default client seed on plugin activation#SHALL #2`
  - `default-client-on-activation#Requirement:Default client seed on plugin activation#SHALL #3`
  - `default-client-on-activation#Requirement:Default client seed on plugin activation#SHALL #4`
  - `default-client-on-activation#Requirement:Default client seed on plugin activation#SHALL #5`
  - `default-client-on-activation#Requirement:Default client seed on plugin activation#SHALL #6`
  - `default-client-on-activation#Requirement:Default client seed on plugin activation#SHALL #7`
  - `default-client-on-activation#Requirement:Default client seed on plugin activation#SHALL #8`
  - `default-client-on-activation#Requirement:Idempotency and flag persistence#SHALL #1`
  - `default-client-on-activation#Requirement:Idempotency and flag persistence#SHALL #2`
  - `default-client-on-activation#Requirement:Idempotency and flag persistence#SHALL #3`
  - `default-client-on-activation#Requirement:Idempotency and flag persistence#SHALL #4`
  - `default-client-on-activation#Requirement:Idempotency and flag persistence#SHALL #5`
  - `default-client-on-activation#Requirement:Idempotency and flag persistence#SHALL #6`

### T3. Add the remaining two required test cases

- **Type**: `test`
- **Files touched**:
  - `plugins/clientes_core/tests/InitUpgradeTest.php` (modify)
- **Estimated changed lines**: `+70 / -0`
- **Depends on**: T2
- **Acceptance criteria**:
  - Two new test methods are added: `nonEmptyTableAndNoFlag_skipsInsertButSetsFlag`
    (case 3 in the design) and `dbErrorDuringSave_isSwallowed` (case
    4). Each follows the design's mock-strategy pattern.
  - Case 3 stubs `\cliente::db->select(...)` to return `[['x' => 1]]`
    and asserts `save()` is **not** called and the flag is set to
    `'1'`.
  - Case 4 stubs `\cliente::db->select(...)` to return `[]` and
    stubs `\cliente::save()` to throw a `\RuntimeException('boom')`.
    It asserts the call does not re-throw (no test failure) and that
    the flag is **not** set (so the next activation can retry).
  - All four required cases (T1 + T3) now pass. No existing
    `clientes_core` test has regressed.
- **Spec references**:
  - `default-client-on-activation#Requirement:Default client seed on plugin activation#SHALL #4`
  - `default-client-on-activation#Requirement:Default client seed on plugin activation#SHALL #5`
  - `default-client-on-activation#Requirement:Default client seed on plugin activation#SHALL #6`
  - `default-client-on-activation#Requirement:Default client seed on plugin activation#SHALL #8`
  - `default-client-on-activation#Requirement:Idempotency and flag persistence#SHALL #6`

### T4. Add the two optional bonus test cases

- **Type**: `test`
- **Files touched**:
  - `plugins/clientes_core/tests/InitUpgradeTest.php` (modify)
- **Estimated changed lines**: `+70 / -0`
- **Depends on**: T3
- **Acceptance criteria**:
  - Two optional bonus methods are added:
    `saveFailure_doesNotCallSettingsSave` (case 5) and
    `settingsSaveFailure_isSwallowed` (case 6) per `design.md` §Test
    plan.
  - Case 5 is a variant of T3's case 4: it asserts that
    `settings.save()` is **not** called when the `cliente::save()`
    throws before the flag is set.
  - Case 6 stubs `\fs_settings::save()` to return `false` (or throw)
    while everything else succeeds. It asserts `Init::upgrade()` does
    not re-throw and that the in-memory flag value is `'1'` (set
    via `set()` even though the disk write failed).
  - All four required cases plus the two bonus cases pass. Full
    `clientes_core` suite still green.
- **Spec references**:
  - `default-client-on-activation#Requirement:Idempotency and flag persistence#SHALL #5`
  - `default-client-on-activation#Requirement:Idempotency and flag persistence#SHALL #6`

### T5. Run verification checklist

- **Type**: `verify`
- **Files touched**: none
- **Estimated changed lines**: `+0 / -0`
- **Depends on**: T1, T2, T3, T4
- **Acceptance criteria**:
  - `ddev exec php vendor/bin/phpunit -c plugins/clientes_core/phpunit.xml`
    reports 0 failures, 0 errors. The new `InitUpgradeTest` file is
    listed in the test output with the expected number of tests
    (4 required + 2 bonus = 6).
  - `ddev exec php vendor/bin/phpunit --testsuite Plugins` (root
    discovery) reports the new file is auto-discovered under
    `plugins/clientes_core/tests/`. 0 failures, 0 errors.
  - `ddev exec php vendor/bin/phpunit` (full root suite) reports 0
    new regressions versus the pre-change baseline.
  - Manual smoke 1: on a fresh DB, activate `clientes_core` and
    confirm `SELECT * FROM clientes;` shows exactly one row with
    `nombre = 'Cliente por defecto'`. Confirm
    `tmp/{FS_TMP_NAME}config2.ini` contains
    `clientes_core_default_seeded = '1';`.
  - Manual smoke 2: deactivate, reactivate, confirm
    `SELECT COUNT(*) FROM clientes;` is still 1.
- **Spec references**:
  - `default-client-on-activation#Requirement:Default client seed on plugin activation#SHALL #1`
  - `default-client-on-activation#Requirement:Idempotency and flag persistence#SHALL #4`

### T6. Bump `plugins/clientes_core/fsframework.ini` version

- **Type**: `docs`
- **Files touched**:
  - `plugins/clientes_core/fsframework.ini` (modify)
- **Estimated changed lines**: `+1 / -1`
- **Depends on**: T5
- **Acceptance criteria**:
  - The `version = 1` line in `plugins/clientes_core/fsframework.ini`
    is bumped to `version = 2`. The other lines (`description`,
    `min_version`, `author`, `author_url`, `require`) are left
    unchanged.
  - Rationale: the archived `fix-backup-worker-recovery` change
    followed the same convention (Task 5 in that change's `tasks.md`
    bumped the plugin's `fsframework.ini` on a behavioural change).
  - If a future maintainer disagrees with this convention for
    `clientes_core` specifically, this task is the one to skip —
    note the decision in the `verify-report.md` of the change.
- **Spec references**: none (housekeeping; no SHALls in the delta
  spec cover version metadata).

### T7. Fix protected $db access bug surfaced by verify phase (CRITICAL-1)

- **Type**: `fix`
- **Origin**: surfaced by
  `verify-report.md` → `CRITICAL-1 — Runtime fatal in
  Init::upgrade() line 70 (protected $db access)`. The original
  implementation read `$cliente->db->select(...)` from outside
  the `cliente`/`fs_model` class hierarchy, but `$db` is
  `protected` (`base/fs_model.php:69`); PHP throws
  `Error: Cannot access protected property` and the seeder's
  `try/catch` swallows the error. Unit tests passed only because
  the test fake's `$db` is `public`, masking the production
  bug.
- **Chosen fix (J1 — clean architecture)**: add a public method
  `cliente::table_has_rows(): bool` to the model; refactor
  `Init::upgrade()` to call that method instead of reaching for
  `$cliente->db` directly. Update the spec, tests, fake, design,
  and tasks to reflect the new pattern.
- **Sub-tasks**:
  - **T7.A — Add `cliente::table_has_rows()` to the model**
    (`plugins/clientes_core/model/core/cliente.php`). Public,
    `bool` return type, uses `$this->table_name` (not the literal
    string), docblock matches the file's style. Strict TDD:
    RED → GREEN.
  - **T7.B — Add a unit test for the new method** in
    `plugins/clientes_core/tests/ClienteModelTest.php`. Stubs
    `$this->db` (anonymous class with `select()` returning
    controlled results). Asserts `true` on non-empty, `false` on
    empty, and that the SQL targets the table name. Strict TDD:
    RED → GREEN.
  - **T7.C — Refactor `Init::upgrade()`** in
    `plugins/clientes_core/Init.php` to call
    `$cliente->table_has_rows()` instead of the buggy
    `$cliente->db->select(...)`. Delete the now-unused `$rows`
    variable. Keep the docblock and the `try/catch`.
  - **T7.D — Update the test fake** in
    `plugins/clientes_core/tests/Fixtures/InitUpgradeFakes.php`
    so the `cliente` fake exposes a public
    `table_has_rows(): bool` method (controlled by a new
    `public static ?bool $table_has_rows_result`). Update the
    existing `InitUpgradeTest` cases to set the new property
    instead of the old `$selectResult` /
    `$selectCalls`. Keep `save()`-throwing and the rest of the
    observation surface.
  - **T7.E — Update the SDD artifacts**: delta spec (replace the
    SQL string SHALL with the new method SHALL; add a new
    `table_has_rows()` public method SHALL; add two new
    scenarios), design (update the `cliente` API table, the
    implementation sketch, the Risks-and-mitigations row), tasks
    (this entry). Also append a "Pass 2 — CRITICAL-1 fix" section
    to `apply-progress.md`.
  - **T7.F — Re-run the automated test suites** (plugin suite
    in isolation, root `Plugins` suite, full root suite). Capture
    exit codes and test counts. The only failure allowed is the
    pre-existing `CsrfTokenTest::expiredTokenIsRejected` in
    `plugins/system_updater/` (NOT a regression introduced by
    this fix). Do NOT re-run the live smoke checks — that is the
    next phase (`sdd-verify`).
- **Files touched**:
  - `plugins/clientes_core/model/core/cliente.php` (modify)
  - `plugins/clientes_core/tests/ClienteModelTest.php` (modify)
  - `plugins/clientes_core/Init.php` (modify)
  - `plugins/clientes_core/tests/Fixtures/InitUpgradeFakes.php` (modify)
  - `plugins/clientes_core/tests/InitUpgradeTest.php` (modify)
  - `plugins/clientes_core/openspec/changes/default-client-on-activation/specs/clientes/spec.md` (modify)
  - `plugins/clientes_core/openspec/changes/default-client-on-activation/design.md` (modify)
  - `plugins/clientes_core/openspec/changes/default-client-on-activation/tasks.md` (modify — this entry)
  - `plugins/clientes_core/openspec/changes/default-client-on-activation/apply-progress.md` (modify)
- **Depends on**: T1, T2, T3, T4, T5, T6 (the original 3-commit apply)
- **Acceptance criteria**:
  - The four required scenarios from the delta spec are now
    satisfied at runtime (verified via the live smokes in the
    next phase, not in this apply pass).
  - The plugin suite passes with the new test for
    `table_has_rows()` and the refactored seeder.
  - The fake is updated to expose the new method and the
    existing 6 `InitUpgradeTest` cases still pass.
  - The delta spec mandates the new public method; the design
    describes it; the task list records the fix.
  - `apply-progress.md` records the Pass-2 entries with
    verification commands and exit codes.
- **Spec references**:
  - `default-client-on-activation#Requirement:Default client seed on plugin activation#SHALL #5` (now: `table_has_rows()`)
  - `default-client-on-activation#Requirement:Default client seed on plugin activation#Scenario: Seeder uses the public table_has_rows() API` (new)
  - `default-client-on-activation#Requirement:Default client seed on plugin activation#Scenario: cliente::table_has_rows() is public and table-portable` (new)

## Work units and commits

Following the `work-unit-commits` skill, the change splits cleanly
into **3 atomic commits**. Each commit is independently revertable
and leaves the test suite green.

| # | Conventional commit | Files | Approx. lines | Story |
|---|---|---|---|---|
| 1 | `feat(clientes_core): seed default cliente on plugin activation` | `Init.php` (+37), `tests/InitUpgradeTest.php` (+160 with 4 required cases) | ≈ +200 | Adds the new `Init::upgrade()` seeder together with the four required test scenarios. The TDD red/green cycle for cases 1-4 happens within the commit (test file written, implementation written, suite green before commit). |
| 2 | `test(clientes_core): extend InitUpgradeTest with bonus edge cases` | `tests/InitUpgradeTest.php` (+70) | ≈ +70 | Adds the two optional bonus cases (`saveFailure_doesNotCallSettingsSave`, `settingsSaveFailure_isSwallowed`). The repo gains test coverage for the two failure-isolation edge cases the proposal left as optional. |
| 3 | `chore(clientes_core): bump plugin version to 2` | `fsframework.ini` (+1 / -1) | ≈ +1 | Version bump per the project's plugin-version convention. Single-line change; could be folded into commit 1 if the reviewer prefers a single PR-friendly diff. |

**Ordering rationale (work-unit-commits checklist)**:

- Commit 1: has one clear purpose (the seeder + its core test
  coverage). The repo still makes sense after applying only this
  commit (test suite is green; the seeder is a complete,
  no-regrets feature). Tests and code are in the same commit, per
  the work-unit rule "keep tests with code". Rollback reverts the
  feature cleanly without removing unrelated work.
- Commit 2: pure test coverage extension. The repo still makes sense
  after applying only this commit (test suite still green; the
  production code is unchanged; reviewer is reading test code only).
  Rollback reverts the bonus tests without removing the seeder.
- Commit 3: housekeeping. Rollback reverts the version metadata
  without touching the seeder or its tests.

The split keeps each commit under ≈ 200 lines (soft limit from
work-unit-commits, well below the 400-line review budget).

## Review workload forecast

| Field | Value |
|---|---|
| Total estimated changed lines (production + tests + docs) | ≈ 220 lines (≈ 37 production + ≈ 180 tests + 1 docs) |
| Within 400-line review budget | **Yes** — net diff is ≈ 220 lines |
| Chained PRs recommended | **No** — the change is small, internally scoped, and has a clear single-purpose story. A single PR is appropriate. |
| Proposed commit count | **3** (see above) |
| Delivery strategy note | The preflight declared `delivery_strategy: ask-always`, so the orchestrator will confirm the single-PR shape with the user before `sdd-apply` proceeds. My forecast: single PR, no chaining, no need for a `size:exception` request. |

## Verification checklist

After all implementation tasks are complete, run the following in
order. Each command must exit 0 before the change is considered done.

```bash
# 1. Plugin suite in isolation
ddev exec php vendor/bin/phpunit -c plugins/clientes_core/phpunit.xml

# 2. Root discovery — confirms the new test file is picked up by the
#    <testsuite name="Plugins"> in the root phpunit.xml
ddev exec php vendor/bin/phpunit --testsuite Plugins

# 3. Full root suite — regression gate for the rest of the framework
ddev exec php vendor/bin/phpunit
```

**Manual smoke checks** (in a `ddev` shell, with the plugin installed
but a fresh DB):

1. **Cold start**:
   - Confirm `clientes` is empty (or does not exist) and
     `clientes_core_default_seeded` is not in
     `tmp/{FS_TMP_NAME}config2.ini`.
   - Activate `clientes_core` from `index.php?page=admin_plugins`.
   - `SELECT * FROM clientes;` → exactly one row, `nombre = 'Cliente por defecto'`.
   - `grep clientes_core_default_seeded tmp/{FS_TMP_NAME}config2.ini`
     → `clientes_core_default_seeded = '1';`.
2. **Idempotent re-activation**:
   - With the seeded row and flag, deactivate and reactivate the
     plugin.
   - `SELECT COUNT(*) FROM clientes;` → still 1.
   - Flag value still `'1'`.
3. **Non-empty install**:
   - Remove the flag from `tmp/{FS_TMP_NAME}config2.ini`.
   - Insert a row manually (e.g. `INSERT INTO clientes ... VALUES ('000099', 'Test', ...)`).
   - Deactivate and reactivate.
   - `SELECT COUNT(*) FROM clientes;` → still 1 (the manual row, not
     a seed). Flag is now `'1'`.
4. **Activation survives a DB error**:
   - Drop the `clientes` table but keep the flag unset.
   - Activate the plugin — the admin UI must show it as enabled
     (the seeder's try/catch swallows the error).
   - The flag must **not** be set, so a re-attempt is possible on
     the next activation.

## Out of scope for this change

No new translation key, no UI flash, no log line on seed success
(the literal `'Cliente por defecto'` is a DB seed value, not a UI
string). No new model, no new DB table, no schema change to
`clientes.xml`. No settings UI to view or reset the flag. No
`delete_*` event listener for clients — the seeder is a one-shot
bootstrap, not a re-runnable migration. No changes to the `cliente`
model itself (`test()`, `save()`, `get_new_codigo()` are consumed
as-is). No seeding of other domain defaults (default article, default
supplier, etc.). No edits in the core `openspec/` tree — the change
is 100% internal to `plugins/clientes_core/`.
