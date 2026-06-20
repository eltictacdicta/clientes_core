# Verify Report — default-client-on-activation

> **Change**: `plugins/clientes_core/openspec/changes/default-client-on-activation/`
> **Date**: 2026-06-20
> **Mode**: Strict TDD (apply-progress reports TDD cycle evidence; all 6 unit tests pass)
> **Verifier**: sdd-verify sub-agent (FSFramework, MiniMax M3)

---

## Status

**FAIL** — one CRITICAL finding blocks archive.

The implementation of `Init::upgrade()` contains a **runtime fatal error** that the
unit test suite does not catch: line 70 reads `$cliente->db->select(...)` from
outside the `cliente`/`fs_model` class hierarchy, and `$db` is declared
`protected` in `base/fs_model.php:69`. PHP throws
`Error: Cannot access protected property FSFramework\model\cliente::$db`, the
seeder's `try/catch` swallows it, and the seed **silently never runs in
production**. All four required scenarios from the delta spec are unsatisfied at
runtime; only the unit test environment (with a public `$db` on the fake)
passes.

Archive is blocked. The orchestrator should bounce this back to
`sdd-apply` (or an apply-equivalent fix) to replace the protected property
access with a production-safe equivalent (recommended: a public
`cliente::table_has_rows()` method, or instantiate a global `fs_db2` and run
the SELECT on the production code path that the seeder actually consumes).

---

## Artifacts verified

- [x] `plugins/clientes_core/openspec/changes/default-client-on-activation/proposal.md` (read end-to-end, 200 lines)
- [x] `plugins/clientes_core/openspec/changes/default-client-on-activation/specs/clientes/spec.md` (delta spec, 210 lines)
- [x] `plugins/clientes_core/openspec/changes/default-client-on-activation/design.md` (820 lines)
- [x] `plugins/clientes_core/openspec/changes/default-client-on-activation/tasks.md` (299 lines)
- [x] `plugins/clientes_core/openspec/changes/default-client-on-activation/apply-progress.md` (143 lines)
- [x] `plugins/clientes_core/Init.php` (read end-to-end, 159 lines)
- [x] `plugins/clientes_core/tests/InitUpgradeTest.php` (read end-to-end, 319 lines)
- [x] `plugins/clientes_core/tests/Fixtures/InitUpgradeFakes.php` (read end-to-end, 178 lines)
- [x] `plugins/clientes_core/phpunit.xml` (read end-to-end, 26 lines)
- [x] `plugins/clientes_core/fsframework.ini` (read end-to-end, 6 lines)
- [x] `plugins/clientes_core/openspec/config.yaml` (read end-to-end, 68 lines)
- [x] `plugins/clientes_core/openspec/specs/clientes/spec.md` (canonical stub, 16 lines — confirmed stub, merge pending at archive)
- [x] `base/fs_plugin_manager.php` lines 640-655 (read, confirmed `runPluginUpgrade` calls `Init::upgrade()` statically)
- [x] `base/fs_model.php` lines 60-130 (read, confirmed `$db` is `protected`)

---

## SHALL coverage

The delta spec has **2 ADDED Requirements** and **0 MODIFIED/REMOVED
Requirements**. The 12 SHALL statements are listed below; "covered" means the
implementation source includes a line that implements the SHALL.

| # | SHALL (paraphrased) | Source line | Runtime? |
|---|---|---|---|
| 1 | The system SHALL, on first activation, ensure exactly one client named "Cliente por defecto" exists | `Init.php:61-82` (whole method) | **NO** — method body throws |
| 2 | The seeder SHALL use the static `Init::upgrade()` hook in `plugins/clientes_core/Init.php` | `Init.php:61` | YES — `public static function upgrade(): void` |
| 3 | The seeder MUST reference `\FSFramework\model\cliente` via `use` and instantiate `new cliente()` (not the global alias) | `Init.php:26,69` | YES — `use FSFramework\model\cliente;` and `new cliente()` |
| 4 | The seeder SHALL be a no-op if the `clientes_core_default_seeded` flag is set | `Init.php:64-66` | YES (verified by live smoke #2) |
| 5 | The seeder SHALL use `$cliente->db->select("SELECT 1 FROM clientes LIMIT 1")` and `empty()` to detect a non-empty table | `Init.php:70-71` | **NO** — `$cliente->db` is `protected`; throws `Error` at runtime |
| 6 | On a successful seed, the seeder SHALL set the flag to `'1'` in `fs_settings` and persist via `fs_settings::save()` | `Init.php:76-77` | **NO** — never reached because the select() above throws first |
| 7 | The seeder SHALL set only `nombre='Cliente por defecto'`; all other fields use the model constructor defaults | `Init.php:72` (only `nombre` assigned) | YES (would be correct if reached) |
| 8 | The seeder body SHALL be wrapped in `try { ... } catch (\Throwable $e) { /* swallow */ }` | `Init.php:68,78-81` | YES — but this also swallows the property-access error and masks the bug |
| 9 | The flag SHALL be read from and written to `fs_settings` (legacy settings store) | `Init.php:63,76-77` | YES |
| 10 | The flag key SHALL be `clientes_core_default_seeded`; the value SHALL be the string `'1'` | `Init.php:64,76` | YES (matches design + spec) |
| 11 | The flag SHALL persist across reboots via `fs_settings::save()` writing to `tmp/{FS_TMP_NAME}config2.ini` | `Init.php:77` (delegated to `fs_settings`) | YES (verified by smoke reads of `tmp/d8d62598c5dff38f1b48/config2.ini`) |
| 12 | `fs_settings::save()` SHALL be called with no argument and SHALL NOT assume ownership of unrelated keys | `Init.php:77` (no argument) | YES |

**Coverage**: 8/12 SHALList satisfied at runtime, 4/12 partially or completely
unsatisfied due to the property-access bug. The flag short-circuit branch
(SHALL #4) and the `try/catch` (SHALL #8) work; everything that touches
`$cliente->db` or follows it does not.

---

## Scenario coverage

| # | Required scenario | Unit test | Live smoke | Runtime? |
|---|---|---|---|---|
| 1 | "Empty install triggers the seed" (cold start) | `test_seeds_default_client_when_table_empty_and_flag_unset` (InitUpgradeTest.php:120) | Smoke #1 (this run) | **FAIL** at runtime — flag NOT set, row NOT inserted |
| 2 | "Non-empty install skips the insert and still sets the flag" | `test_is_noop_when_table_nonempty_and_sets_flag` (InitUpgradeTest.php:186) | Smoke #3 (this run) | **FAIL** at runtime — flag NOT set (manual row preserved, but the spec also requires the flag to be set) |
| 3 | "Re-activation after deactivation is a no-op" | `test_is_noop_when_flag_already_set` (InitUpgradeTest.php:158) | Smoke #2 (this run) | PASS (trivially — when the flag IS set, the early-return works; but the flag never gets set in production, so this branch is never reached) |
| 4 | "DB error during seed does not break activation" | `test_swallows_db_error_during_save` (InitUpgradeTest.php:214) | Smoke #4 (this run) | PASS (trivially — the property-access error is swallowed the same way) |

**Bonus scenarios** (not required by the spec, but covered by the test suite):

| # | Bonus scenario | Test | Runtime? |
|---|---|---|---|
| 5 | "Cold start auto-creates table" | `test_cold_start_auto_creates_table` (InitUpgradeTest.php:255) | FAILS to exercise the real table-create path (fake's constructor skips the real `fs_model::__construct`) — see Findings SUGGESTION-1 |
| 6 | "set() and save() called in correct order" | `test_sets_flag_via_set_and_save` (InitUpgradeTest.php:286) | Passes because the test fake is the one being called; in production the line above it (`$cliente->db->select`) throws first, so the order check is never reached on the real path |

The unit tests pass (38/38 in the plugin suite, 6/6 in the new
`InitUpgradeTest`) but **do not exercise the real production path** because
the test fake's `public $db` (InitUpgradeFakes.php:73) sidesteps the
visibility check that the real `fs_model` enforces. The live smokes run via
ddev confirm that every scenario that depends on actually reaching
`$cliente->save()` or the `set()` after the `select()` FAILS at runtime.

---

## TDD compliance (Strict TDD mode)

`apply-progress.md` includes the TDD Cycle Evidence per task (T1 RED → T2
GREEN → T3 GREEN → T4 GREEN, all in the plugin suite). Per
`strict-tdd-verify.md` §5a:

| Check | Result | Notes |
|---|---|---|
| TDD Evidence reported | ✅ | `apply-progress.md` §T1–T4 have explicit RED/GREEN markers |
| All tasks have tests | ✅ | T1 created the test file, T2–T4 added 4+2 cases |
| RED confirmed (tests exist) | ✅ | T1 errors: `Init::upgrade() does not exist` |
| GREEN confirmed (tests pass) | ✅ for the fakes; **❌ for the real production path** | The unit tests use a public-`$db` fake; the real `fs_model` is protected. See CRITICAL-1 |
| Triangulation adequate | ✅ | 4 required + 2 bonus scenarios, all distinct |
| Safety Net for modified files | ✅ | `Init.php` was modified, not created; the test scaffolding was built in T1 before the implementation in T2 |
| Assertion quality | ⚠️ | The bonus test `test_cold_start_auto_creates_table` (InitUpgradeTest.php:255) does not actually test the table-creation path; it only asserts that the fake's constructor was called and `save()` was called afterwards. The real `fs_model::__construct → check_table` path is never exercised. See SUGGESTION-1. |

**TDD Compliance**: 6/7 checks passed. The unit test GREEN is real for the
fake; the live runtime GREEN is missing.

---

## Automated test results

Run on `ddev` against the current branch (`749823a8` at HEAD):

### Command 1 — plugin suite in isolation

```
$ ddev exec php vendor/bin/phpunit -c plugins/clientes_core/phpunit.xml
......................................                            38 / 38 (100%)

Time: 00:04.332, Memory: 4.00 MB
OK, but there were issues!
Tests: 38, Assertions: 90, PHPUnit Deprecations: 7.
```

- **Exit code**: 0
- **Tests**: 38/38 pass (32 pre-existing + 6 new in `InitUpgradeTest`)
- **Deprecations**: 7 (all from the test code; informational, not failures)

### Command 2 — root discovery (`Plugins` suite)

```
$ ddev exec php vendor/bin/phpunit --testsuite Plugins
...............................................................  63 / 289 ( 21%)
............................................................... 126 / 289 ( 43%)
............................................................... 189 / 289 ( 65%)
............................................................... 252 / 289 ( 87%)
.........F....................S......                           289 / 289 (100%)

There was 1 failure:
1) CsrfTokenTest::expiredTokenIsRejected
Failed asserting that true is false.
/var/www/html/plugins/system_updater/tests/CsrfTokenTest.php:83

Tests: 289, Assertions: 589, Failures: 1, Skipped: 1.
```

- **Exit code**: 1
- **Tests**: 288/289 pass, 1 failure, 1 skipped
- **Failure**: `CsrfTokenTest::expiredTokenIsRejected` in
  `plugins/system_updater/tests/CsrfTokenTest.php:83` — **pre-existing,
  out-of-scope** (no files under `plugins/system_updater/` were touched by
  any of the 3 apply commits; `git log --stat c5bc4b6e^..749823a8 -- plugins/system_updater/`
  shows no changes; the failure was present before this change and is
  documented in `apply-progress.md` §T5).

### Command 3 — full root suite

```
$ ddev exec php vendor/bin/phpunit
... [StealthMode lines, PasswordHasherService lines] ...
......................S.................................SSSSSSS 378 / 714 ( 52%)
SSSS.....................................[StealthMode] ...
[StealthMode] ...
... 714 / 714 (100%)

There was 1 failure:
1) CsrfTokenTest::expiredTokenIsRejected
Failed asserting that true is false.
/var/www/html/plugins/system_updater/tests/CsrfTokenTest.php:83

Tests: 714, Assertions: 1591, Failures: 1, Skipped: 14.
```

- **Exit code**: 1
- **Tests**: 713/714 pass, 1 failure, 14 skipped
- **Failure**: same `CsrfTokenTest::expiredTokenIsRejected` (pre-existing)

### Regression analysis

| Layer | New failures from this change? |
|---|---|
| `plugins/clientes_core` (38 tests) | None — all 6 new tests pass; 32 pre-existing still pass |
| `Plugins` suite (289 tests) | None — same single pre-existing CSRF failure as in `apply-progress.md` |
| Full root (714 tests) | None — same single pre-existing CSRF failure |

**No new regressions at the unit test level** because the test fakes bypass
the production visibility check. The bug only manifests when the real
`fs_model::db` is involved, which is exactly what the live smokes exercise.

---

## Live smoke results

All four smokes were run against the live ddev MariaDB 10.11 instance, with
`clientes_core` active in the plugin list. The script used a full
`Kernel::boot()` + autoloader registration to mimic the runtime path
(`runPluginUpgrade` calls `Init::upgrade()` from inside an active request).

### Smoke #1 — Cold start (empty table, no flag) — **FAIL**

**Setup**:

```bash
ddev exec mysql -h db -u db -pdb db -e "DELETE FROM clientes;"
# clear the flag in $GLOBALS and on disk
unset $GLOBALS['config2']['clientes_core_default_seeded']
# remove the line from tmp/d8d62598c5dff38f1b48/config2.ini
```

**Run**:

```php
\FSFramework\Plugins\clientes_core\Init::upgrade();
```

**Observed output** (state dump before vs after):

```
[STATE before smoke1] flag='NOT_SET'
[STATE before smoke1] clientes count: 0
Calling Init::upgrade()...
[STATE after smoke1]  flag='NOT_SET'
[STATE after smoke1]  clientes count: 0
Flag on disk: NOT_SET
```

**Verdict**: **FAIL**. The spec requires exactly one row inserted and the
flag set to `'1'`. Neither happened. The seeder silently swallowed the
`Error: Cannot access protected property FSFramework\model\cliente::$db`
thrown at `Init.php:70` and returned.

### Smoke #2 — Re-activation with flag set (no-op) — **PASS (vacuous)**

**Setup**: `clientes` empty, flag set to `'1'` both in `$GLOBALS` and on
disk.

**Run**: `Init::upgrade()`.

**Observed output**:

```
[STATE before smoke2 (flag set)] flag='1'
[STATE before smoke2 (flag set)] clientes count: 0
Calling Init::upgrade() again...
[STATE after smoke2] flag='1'
[STATE after smoke2] clientes count: 0
```

**Verdict**: **PASS in isolation, but vacuous** — the early-return on
`Init.php:64-66` is correct, but the flag is never set in real usage
(see Smoke #1), so this branch is never reached through the natural
flow. The test is still useful as a regression check on the
short-circuit itself.

### Smoke #3 — Non-empty install (manual row, no flag) — **FAIL**

**Setup**: `DELETE FROM clientes; INSERT INTO clientes (codcliente, nombre, …)
VALUES ('000099', 'Existing Client', …);`; flag cleared.

**Run**: `Init::upgrade()`.

**Observed output**:

```
[STATE before smoke3 (manual row, no flag)] flag='NOT_SET'
[STATE before smoke3 (manual row, no flag)] clientes count: 1
  - codcliente=000099 nombre=Existing Client
Calling Init::upgrade()...
[STATE after smoke3] flag='NOT_SET'
[STATE after smoke3] clientes count: 1
  - codcliente=000099 nombre=Existing Client
Flag on disk: NOT_SET
```

**Verdict**: **FAIL**. The spec requires no new row inserted (✓ — manual
row preserved) **and** the flag set to `'1'` (✗ — flag NOT set). The
seeder failed at the same line as Smoke #1 and never reached the
`settings->set()`.

### Smoke #4 — DB error during save — **PASS (vacuous)**

**Setup**: `DELETE FROM clientes;`; flag cleared.

**Run**: `Init::upgrade()`.

**Observed output**:

```
[STATE before smoke4 (empty table, no flag)] flag='NOT_SET'
[STATE before smoke4 (empty table, no flag)] clientes count: 0
Calling Init::upgrade()...
[STATE after smoke4 (should be: no row, no flag, no crash)] flag='NOT_SET'
[STATE after smoke4 (should be: no row, no flag, no crash)] clientes count: 0
Flag on disk: NOT_SET
```

**Verdict**: **PASS in the narrow sense** — the seeder did not crash and
activation can continue. **FAIL in the broader sense** — the swallowed
error here is the property-access `Error`, not a real DB error. The
seeder's `try/catch` correctly isolated *something*, but that something
is the wrong thing: it is the seeder's own bug being masked as a benign
DB failure. A real DB error during `$cliente->save()` (e.g., a dropped
table) is not separately exercised by the smoke because the code never
gets there.

### Smoke summary

| Smoke | Spec scenario | Verdict | Why |
|---|---|---|---|
| #1 | Empty install triggers the seed | **FAIL** | `$cliente->db` access throws; row not inserted, flag not set |
| #2 | Re-activation after deactivation is a no-op | PASS | Early-return on flag works in isolation; vacuous because flag is never set in production |
| #3 | Non-empty install skips insert, sets flag | **FAIL** | Same property-access error before the flag write |
| #4 | DB error during seed does not break activation | PASS | try/catch swallows the error (but it's the wrong error) |

---

## Findings

### CRITICAL

- **CRITICAL-1 — Runtime fatal in `Init::upgrade()` line 70 (protected `$db` access)**

  `plugins/clientes_core/Init.php:70` reads:
  ```php
  $rows = $cliente->db->select("SELECT 1 FROM clientes LIMIT 1");
  ```
  `$db` is declared `protected` in `base/fs_model.php:69`. `Init` does not
  extend `cliente` or `fs_model`, so PHP raises
  `Error: Cannot access protected property FSFramework\model\cliente::$db`
  at runtime. The seeder's `try { ... } catch (\Throwable $e) {}`
  (`Init.php:68,78-81`) swallows the error silently and the seed never
  runs. Verified at runtime via live ddev smokes #1, #3, #4.

  **Impact**: The four required scenarios from the delta spec
  (`specs/clientes/spec.md` §ADDED Requirements → "Default client seed on
  plugin activation") are unsatisfied at runtime. The flag is never
  persisted, the row is never inserted, and the plugin appears to behave
  as if the seeder does not exist.

  **Why unit tests don't catch it**: `tests/Fixtures/InitUpgradeFakes.php:73`
  declares `public $db` on the fake, sidestepping the visibility check.
  The fakes are only loaded under `processIsolation="true"` (see
  `phpunit.xml:7`), so the real `fs_model::db` is never touched by the
  test. This is a true production-vs-test mismatch; the unit test GREEN
  is not a runtime GREEN.

  **Root cause in the design**:
  `design.md:218-220` notes that `$db` is `protected` while the
  surrounding text and `specs/clientes/spec.md:42-44` mandate
  `$cliente->db->select(...)`. The design is internally inconsistent
  and the implementation faithfully reproduced the inconsistency.

  **Fix options for the orchestrator** (any one is sufficient; recommend
  the first for minimal blast radius):

  1. **Add a public method on the `cliente` model**, e.g.
     `cliente::table_has_rows(): bool` that wraps the
     `SELECT 1 ... LIMIT 1` query and is callable from outside the
     class. Then `Init::upgrade()` calls `$cliente->table_has_rows()`
     instead of `$cliente->db->select(...)`. The model's `db` access is
     then legal because it is inside the class hierarchy. This requires
     editing `plugins/clientes_core/model/core/cliente.php` and bumping
     `fsframework.ini` again (e.g. to 3).
  2. **Use a global `fs_db2` instance** in `Init::upgrade()` instead of
     routing through the model. Match the pattern in
     `base/config2.php`/`base/fs_db2.php` where `$db = new fs_db2()` is
     the standard CLI seam. The seeder then does
     `$db = new \fs_db2(); $rows = $db->select("SELECT 1 FROM clientes LIMIT 1");`
     and `$cliente = new cliente();` purely for the
     `save()` side effect. This is closer to the design's "opaque
     global" intent and avoids touching the model.
  3. **Make `fs_model::$db` public**. Rejected — broadens the
     visibility of a core base class; touches files outside the
     plugin; violates the rule that this change is 100% internal to
     `clientes_core/`.

  After the fix, the live smokes must be re-run and must show the
  `Cliente por defecto` row inserted and the flag persisted before
  the change can be archived.

### WARNING

- **WARNING-1 — Bonus test `test_cold_start_auto_creates_table` does not
  exercise the real table-creation path**

  The test asserts that the fake's `__construct` was called and that
  `save()` was called afterwards. The real `fs_model::__construct →
  check_table` path is not exercised because the fake skips the
  parent constructor. The test name is misleading. Recommendation:
  rename to `test_cold_start_skips_table_check_in_fake` or
  `test_cold_start_does_not_double_create_table`, and add a separate
  live smoke (already in the design's `tasks.md` §Verification
  checklist) for the real table-creation path. Not blocking for this
  change because the table-creation path is covered by the
  `apply-progress.md` smoke (and the live Smoke #1 above, which
  incidentally confirms the table exists because the cliente
  constructor was reached before the property-access error).

### SUGGESTION

- **SUGGESTION-1 — Document the processIsolation trade-off in the design**

  The plugin's `phpunit.xml` sets `processIsolation="true"` (line 7) to
  make the autoloader-based fake injection work. `apply-progress.md` notes
  a 3.7s overhead for the 38-test suite vs 0.04s without. The design
  flagged this in §"Pragmatic resolution" but never closed on a
  long-term approach (e.g., a `class_alias`-based global override that
  does not require process isolation). Future maintainers who find the
  overhead too high will not find a documented alternative. Consider
  adding a follow-up task to revisit the test seam.

- **SUGGESTION-2 — Add a non-test smoke to the apply phase**

  The `apply-progress.md` §"Notes for the next phase (sdd-verify)" already
  flags that the manual smoke checks were not run in apply. The current
  change proves that not running them masks a CRITICAL bug. The apply
  phase contract for changes that touch DB-touching code should require
  at least Smoke #1 (cold start) on the live DB before commit.

---

## Pre-existing out-of-scope observations

The following failure was observed in the full root suite (`ddev exec php
vendor/bin/phpunit`) and the `Plugins` suite during this verify run. It is
**NOT** a regression introduced by this change:

| Test | File | Failure line | Observed in this run? | Bisect? |
|---|---|---|---|---|
| `CsrfTokenTest::expiredTokenIsRejected` | `plugins/system_updater/tests/CsrfTokenTest.php` | line 83 (`Failed asserting that true is false.`) | Yes, in `Plugins` suite (1/289 failed) and root suite (1/714 failed) | Not bisected — `git log --stat c5bc4b6e^..749823a8 -- plugins/system_updater/` shows zero files changed in this range, so the failure is structurally pre-existing. The same failure was also observed and classified as "pre-existing out-of-scope" in `apply-progress.md` §T5. |

No new failures are observed.

---

## File presence and isolation

- **3 apply commits present** (from `git log --oneline -3`):
  - `c5bc4b6e` `test(clientes_core): scaffold InitUpgradeTest with first two scenarios`
  - `3133ba34` `feat(clientes_core): seed default cliente on activation via Init::upgrade()`
  - `749823a8` `chore(clientes_core): bump plugin version to 2`
- **5 SDD artifacts present** in `plugins/clientes_core/openspec/changes/default-client-on-activation/`:
  - `proposal.md` (14 KB)
  - `specs/clientes/spec.md` (the delta spec)
  - `design.md` (38 KB)
  - `tasks.md` (16 KB)
  - `apply-progress.md` (7 KB)
  - **5th artifact (this report)**: `verify-report.md` (this file)
- **`config.yaml`** present at `plugins/clientes_core/openspec/config.yaml`
  (3.2 KB; ownership `plugin-local`, `change_root` and `archive_root` correctly
  point inside the plugin).
- **Core `openspec/` NOT touched** by the 3 apply commits. `git log --name-only
  c5bc4b6e^..749823a8 -- openspec/` returns zero files. The pre-existing
  changes in the core `openspec/changes/migrate-catalog-domain-to-catalogo-core/`
  (deletions) and the new `openspec/changes/archive/2026-06-18-migrate-catalog-domain-to-catalogo-core/`
  (archive) are unrelated to this change — they belong to a different SDD
  cycle.
- **Pre-existing out-of-tree changes** in the working tree that are NOT part
  of this change:
  - `M .opencode/skills/fsframework-plugin-scaffold/SKILL.md` — last touched
    by commit `5debe1b9` (pre-`c5bc4b6e`); pre-existing.
  - `D openspec/changes/migrate-catalog-domain-to-catalogo-core/*` and
    `?? openspec/changes/archive/2026-06-18-migrate-catalog-domain-to-catalogo-core/`
    — also pre-existing, belong to a different change.

The only files **modified or created** by the 3 apply commits are:
- `plugins/clientes_core/Init.php` (modified, +42 lines)
- `plugins/clientes_core/tests/InitUpgradeTest.php` (created, 319 lines)
- `plugins/clientes_core/tests/Fixtures/InitUpgradeFakes.php` (created, 178 lines)
- `plugins/clientes_core/phpunit.xml` (modified, +2 lines for `processIsolation="true"`)
- `plugins/clientes_core/fsframework.ini` (modified, +1 / -1)
- `plugins/clientes_core/openspec/changes/default-client-on-activation/apply-progress.md`
  (created, 143 lines)

**Isolation**: ✅ 100% inside `plugins/clientes_core/`. The core
`openspec/`, `base/`, `src/`, `controller/`, and other plugins are
untouched by the 3 apply commits.

---

## Security review

Per the `fsframework-security-review` skill, audited the new code
(`Init.php` lines 24-82, `tests/InitUpgradeTest.php` entire file,
`tests/Fixtures/InitUpgradeFakes.php` entire file).

| Category | Finding | Severity |
|---|---|---|
| SQL injection | The only SQL string is the literal `"SELECT 1 FROM clientes LIMIT 1"`. No user input flows into it. | PASS |
| XSS | Not applicable — no output. | N/A |
| CSRF | Not applicable — no form handling. | N/A |
| Open redirect | Not applicable. | N/A |
| Hardcoded credentials / secrets | Only literal is `'Cliente por defecto'`. No passwords, no API keys, no tokens. | PASS |
| Unsafe file operations | Not applicable. | N/A |
| Information disclosure via error messages | The `try/catch` swallows all `\Throwable`s silently. PASS in the narrow sense (no stack trace exposed) but **the silent failure is the primary risk** (CRITICAL-1): a real DB error during activation will not be visible to the operator. Trade-off is documented in the design and accepted. | PASS (intentional) |
| Idempotency / safety | The flag-gated approach (`clientes_core_default_seeded`) is the right safety net, and the key is correctly namespaced with the `clientes_core_` prefix to avoid collisions with other plugins. | PASS |
| Static method called from framework context | Verified `base/fs_plugin_manager.php:651`: `$initClass::upgrade()` is called as a static, no instance, matching the `public static function upgrade(): void` signature. | PASS |

**Security review**: No new security vulnerabilities introduced. The
silent error swallow is a debuggability concern, not a security one (no
data is leaked, no privilege is granted).

---

## Archive readiness

**Status: NOT READY for archive.**

This change cannot be archived. CRITICAL-1 must be fixed and the live
smokes re-run end-to-end (Smoke #1 must show the row inserted and the
flag persisted on disk; Smoke #3 must show the flag set) before archive.

When the orchestrator re-runs `sdd-apply` to fix CRITICAL-1 and the
fix is verified by re-running this verify phase, the archive step will
need to:

1. Re-run the live smokes to confirm the fix.
2. Re-run the full root suite to confirm no new regressions.
3. Re-write or amend this `verify-report.md` with a Status of `PASS` or
   `PASS with observations`.
4. Then perform the standard archive steps per
   `plugins/clientes_core/openspec/config.yaml`:
   - Move the change dir to
     `plugins/clientes_core/openspec/changes/archive/2026-06-20-default-client-on-activation/`
   - Merge the delta spec into
     `plugins/clientes_core/openspec/specs/clientes/spec.md` (currently
     a 16-line stub; the delta's two ADDED Requirements need to be
     incorporated)
   - Create an `archive-report.md` mirroring this report's
     `Status` + `Findings` sections plus a closing summary
   - Verify no entries in core `openspec/changes/{name}/` for this
     change name (already true; nothing to do)
5. The new `archive-report.md` will also note that this is a
   post-verify fix (the original implementation was broken at runtime
   per CRITICAL-1; the fix-and-re-verify cycle happened before archive).

---

## Relevant files

- `plugins/clientes_core/Init.php:61-82` — the broken `upgrade()` method
- `plugins/clientes_core/Init.php:70` — the line that throws at runtime
- `plugins/clientes_core/Init.php:68,78-81` — the `try/catch` that masks the error
- `plugins/clientes_core/tests/Fixtures/InitUpgradeFakes.php:73` — the `public $db` that masks the bug in tests
- `plugins/clientes_core/phpunit.xml:7` — `processIsolation="true"` that hides the real `fs_model` from the test
- `base/fs_model.php:69` — `protected $db;` declaration that triggers the bug
- `base/fs_plugin_manager.php:643-655` — `runPluginUpgrade` calling `Init::upgrade()` (read-only reference; correct)
- `plugins/clientes_core/openspec/changes/default-client-on-activation/specs/clientes/spec.md:42-44` — delta spec line that mandated the broken access pattern
- `plugins/clientes_core/openspec/changes/default-client-on-activation/design.md:218-220,564-676` — design notes that documented the visibility but did not close on a fix

---

## Pass 2 — Re-verify after CRITICAL-1 fix (commit f8497cf8)

> **Date**: 2026-06-20
> **Verifier**: sdd-verify sub-agent (FSFramework, MiniMax M3)
> **Mode**: Strict TDD (apply-progress §T7 reports TDD cycle evidence)
> **Scope**: Re-run all 4 live smoke checks against the post-fix code at
> commit `f8497cf8` and re-run the automated test suites. The previous
> Pass 1 found CRITICAL-1 (protected `$db` access) and could not archive.
> This Pass 2 confirms the fix works at runtime.

### Status

**PASS with observations** — CRITICAL-1 is fully resolved; the seeder
works at runtime. One WARNING and one SUGGESTION are noted below. None
block archive.

### CRITICAL-1 resolution

**RESOLVED.** The new public method
`\FSFramework\model\cliente::table_has_rows()` is defined at
`plugins/clientes_core/model/core/cliente.php:290-294`:

```php
public function table_has_rows(): bool
{
    $rows = $this->db->select("SELECT 1 FROM " . $this->table_name . " LIMIT 1;");
    return !empty($rows);
}
```

The refactored seeder at `plugins/clientes_core/Init.php:69-73` now
calls the new public method:

```php
$cliente = new cliente();
if (!$cliente->table_has_rows()) {
    $cliente->nombre = 'Cliente por defecto';
    $cliente->save();
}
```

The protected `$db` access pattern that caused CRITICAL-1 is gone
from `Init::upgrade()`. Live smoke #1 below proves the seeder now
inserts a row, sets the flag, and persists both to the live MariaDB
10.11 instance and to `tmp/d8d62598c5dff38f1b48/config2.ini`.

### Automated test results (Pass 2)

| # | Command | Exit | Result | Notes |
|---|---------|------|--------|-------|
| 1 | `ddev exec php vendor/bin/phpunit -c plugins/clientes_core/phpunit.xml` | 0 | `Tests: 41, Assertions: 95, PHPUnit Deprecations: 7` | All 41 pass. 32 pre-existing + 6 refactored `InitUpgradeTest` + 3 new `ClienteModelTest::testTableHasRows*` cases. |
| 2 | `ddev exec php vendor/bin/phpunit --testsuite Plugins` | 1 | `Tests: 292, Assertions: 594, Failures: 1, Skipped: 1` | The single failure is `CsrfTokenTest::expiredTokenIsRejected` in `plugins/system_updater/tests/CsrfTokenTest.php:83` — **pre-existing, out-of-scope**. `git log --name-only c5bc4b6e^..f8497cf8 -- plugins/system_updater/` shows zero files changed in this range. The failure was present before this change and is documented in the original verify report §"Pre-existing out-of-scope observations" and in `apply-progress.md` §T5 + §T7.F. |
| 3 | `ddev exec php vendor/bin/phpunit` (full root) | 1 | `Tests: 717, Assertions: 1596, Failures: 1, Skipped: 14` | Same single pre-existing CSRF failure. **No new regressions.** |

**Regression analysis**: identical to Pass 1. The only failure observed
in the full suite is the pre-existing `CsrfTokenTest::expiredTokenIsRejected`,
which is in `plugins/system_updater/` (an unrelated plugin) and was
failing before this change started. No new failures in
`plugins/clientes_core/`, no new failures anywhere in the root suite.

### Live smoke results (Pass 2)

All four smokes were run against the live ddev MariaDB 10.11 instance
with `clientes_core` in the active plugin list
(`tmp/d8d62598c5dff38f1b48/enabled_plugins.list`).

**Smoke harness (final, reused across all 4 smokes)**: a temp script
in `tmp/` that loads the production-equivalent autoloader chain —
`config.php` → `vendor/autoload.php` → `base/fs_autoload.php` →
`base/fs_secret_migrator.php` → `base/config2.php` →
`base/fs_model_autoloader.php` → `\fs_model_autoloader::register(false)`
→ `plugins/clientes_core/Init.php`. The `fs_model_autoloader`
registration is **required** to mimic the production request flow
(`index.php:84` calls `fs_schema::selfHealCoreTables()` which
registers it). Without it, `new cliente()` fails at autoload time
because the legacy `fs_autoload` namespace map has a case-mismatch
bug (uppercase `FSFramework\\Model\\` vs. lowercase `FSFramework\\model\\`)
and the seeder's `try/catch` silently swallows the missing-class
Error. With the full bootstrap the seeder runs correctly end-to-end.
This is a documented pre-existing autoloader quirk, not a regression
introduced by this change; see **WARNING-1** below for context.

#### Smoke #1 — Cold start (empty table, no flag) — **PASS**

**Commands run**:

```bash
# Clean state
ddev exec mysql -h db -u db -pdb db -e "DELETE FROM clientes;"
ddev exec php /var/www/html/tmp/smoke_harness.php reset_flag

# Pre-state inspection
echo "ini: $(grep clientes_core_default_seeded tmp/d8d62598c5dff38f1b48/config2.ini || echo '(none)')"
# (no flag in ini)

# Run the seeder
ddev exec php /var/www/html/tmp/smoke_harness.php seed

# Post-state inspection
ddev exec mysql -h db -u db -pdb db -e "SELECT codcliente, nombre FROM clientes;"
grep clientes_core_default_seeded tmp/d8d62598c5dff38f1b48/config2.ini
```

**DB state before**: `clientes` table empty (0 rows). Flag not set in
`tmp/d8d62598c5dff38f1b48/config2.ini` and not in `$GLOBALS['config2']`.

**Action**: Called `\FSFramework\Plugins\clientes_core\Init::upgrade()`
directly via the smoke harness (which loads the production bootstrap
chain, so the autoloader resolves `\FSFramework\model\cliente`).

**DB state after**: `clientes` table has exactly one row
`codcliente='000001', nombre='Cliente por defecto'`. Flag
`clientes_core_default_seeded = 1;` persisted in
`tmp/d8d62598c5dff38f1b48/config2.ini`. `$GLOBALS['config2']`
contains `clientes_core_default_seeded = '1'`.

**Verdict**: **PASS** — exactly one row inserted, flag set and
persisted. The seeder now reaches `$cliente->save()` and
`$settings->set()`/`save()` as designed; the CRITICAL-1 fix
unblocked the cold-start path.

#### Smoke #2 — Re-activation with flag set (no-op) — **PASS**

**Commands run**:

```bash
# Pre-state from smoke #1
ddev exec mysql -h db -u db -pdb db -e "SELECT COUNT(*) FROM clientes;"
# 1
grep clientes_core_default_seeded tmp/d8d62598c5dff38f1b48/config2.ini
# clientes_core_default_seeded = 1;

# Run the seeder AGAIN
ddev exec php /var/www/html/tmp/smoke_harness.php seed

# Post-state inspection
ddev exec mysql -h db -u db -pdb db -e "SELECT COUNT(*) FROM clientes;"
# still 1
```

**DB state before**: `clientes` table has 1 row, flag set to `'1'`.

**Action**: Called `\FSFramework\Plugins\clientes_core\Init::upgrade()`
a second time. The seeder's early-return on
`Init.php:64-66` (`if ($settings->get('clientes_core_default_seeded')) return;`)
short-circuited the body.

**DB state after**: `clientes` table still has exactly 1 row (no
duplicate). Flag still `'1'`. The seeder body did **not** re-execute
— proven by the count check (1 row, not 2).

**Verdict**: **PASS** — the early-return path works in real production
flow (not just in the vacuous case of Pass 1 where the flag was never
set). Combined with Smoke #1 this proves the idempotency contract:
cold start → 1 row, re-activation → still 1 row, no duplicates.

#### Smoke #3 — Non-empty install (manual row, no flag) — **PASS**

**Commands run**:

```bash
# Reset state
ddev exec mysql -h db -u db -pdb db -e "DELETE FROM clientes;"
ddev exec php /var/www/html/tmp/smoke_harness.php reset_flag

# Insert a manual row (mimic an admin who populated clientes before
# activating the plugin)
ddev exec php /var/www/html/tmp/smoke_harness.php insert_existing
# inserts: (codcliente='000099', nombre='Existing Client', ...)

# Run the seeder
ddev exec php /var/www/html/tmp/smoke_harness.php seed

# Post-state inspection
ddev exec mysql -h db -u db -pdb db -e "SELECT codcliente, nombre FROM clientes ORDER BY codcliente;"
grep clientes_core_default_seeded tmp/d8d62598c5dff38f1b48/config2.ini
```

**DB state before**: `clientes` table has 1 manual row
`codcliente='000099', nombre='Existing Client'`. Flag not set.

**Action**: Called `\FSFramework\Plugins\clientes_core\Init::upgrade()`.
The seeder's `$cliente->table_has_rows()` correctly returned `true`
(non-empty), so the `if (!$cliente->table_has_rows())` branch was
skipped — no INSERT. The code then fell through to
`$settings->set('clientes_core_default_seeded', '1'); $settings->save();`.

**DB state after**: `clientes` table still has exactly 1 row (the
manual `'Existing Client'`, no `'Cliente por defecto'` row added).
Flag `clientes_core_default_seeded = 1;` persisted.

**Verdict**: **PASS** — the manual row is preserved, no seed row is
inserted, the flag is set. This matches the spec scenario
"Non-empty install skips the insert and still sets the flag".

#### Smoke #4 — DB error during save → activation continues — **PASS**

**Commands run**:

```bash
# Reset state
ddev exec mysql -h db -u db -pdb db -e "DELETE FROM clientes;"
ddev exec php /var/www/html/tmp/smoke_harness.php reset_flag

# Inject a temporary shim into Init.php to simulate a real DB error
# during $cliente->save() (the env var gates the throw)
cp plugins/clientes_core/Init.php plugins/clientes_core/Init.php.orig
# (the shim is inserted right before $cliente->save() at line 72;
#  it throws a \RuntimeException if FS_SMOKE_TRIGGER_ERROR === '1')

# Run the seeder with the env var set
ddev exec php /var/www/html/tmp/smoke_harness.php seed trigger_error

# Post-state inspection
ddev exec mysql -h db -u db -pdb db -e "SELECT COUNT(*) FROM clientes;"
# 0
grep clientes_core_default_seeded tmp/d8d62598c5dff38f1b48/config2.ini
# (not present)

# Revert the shim
mv plugins/clientes_core/Init.php.orig plugins/clientes_core/Init.php
git status --short plugins/clientes_core/Init.php
# (clean — no diff)

# Re-run the plugin test suite to confirm the revert did not break anything
ddev exec php vendor/bin/phpunit -c plugins/clientes_core/phpunit.xml
# Tests: 41, Assertions: 95, PHPUnit Deprecations: 7. All pass.
```

**DB state before**: `clientes` table empty, flag not set.

**Action**: Called `\FSFramework\Plugins\clientes_core\Init::upgrade()`
with a `\RuntimeException('Simulated DB error for smoke #4')` thrown
by the temporary shim just before `$cliente->save()`.

**DB state after**: `clientes` table still empty (no row inserted).
Flag still not set. The seeder's
`try { ... } catch (\Throwable $e) { /* swallow */ }`
(`Init.php:68,77-80`) swallowed the exception. The harness
process exited cleanly with no fatal error — the equivalent of
"plugin activation completes successfully".

**Verdict**: **PASS** — the seeder did not crash, no row was inserted,
the flag was not set (so the next activation can retry). This is the
correct behavior for "DB error during seed does not break activation".

**Revert verification**: after removing the shim, `git status` shows
no diff in `Init.php`, and the plugin test suite still passes
41/41 — the revert was clean.

#### Smoke summary

| Smoke | Spec scenario | Verdict | One-line observation |
|---|---|---|---|
| #1 | Empty install triggers the seed | **PASS** | Exactly one row (`codcliente=000001, nombre='Cliente por defecto'`) inserted, flag set to `'1'` in GLOBALS and persisted to ini. |
| #2 | Re-activation after deactivation is a no-op | **PASS** | Second call short-circuited on the flag; row count stayed at 1, no duplicate. |
| #3 | Non-empty install skips insert, sets flag | **PASS** | Manual `'Existing Client'` row preserved, no seed row added, flag set to `'1'`. |
| #4 | DB error during seed does not break activation | **PASS** | `\RuntimeException` from a temporary shim was swallowed; no row inserted, flag not set, harness exited cleanly. |

### New findings (Pass 2)

#### WARNING

- **WARNING-1 — The seeder's `try/catch` blanket swallow continues to
  mask the next class of runtime fatal**

  `Init::upgrade()` (`plugins/clientes_core/Init.php:68,77-80`) still
  wraps the body in `try { ... } catch (\Throwable $e) { /* swallow */ }`.
  In Pass 1 this caught a real programmer error (the protected `$db`
  access) and hid it from the operator. In Pass 2 (with the autoloader
  chain correctly loaded) the seeder works, but the same `try/catch`
  would also swallow any future class-not-found, method-not-found, or
  any other `\Throwable` from the body. This is the same class of
  "silent failure" risk the original CRITICAL-1 fix surfaced.

  **What the fix did NOT change**: the `try/catch` shape. The Pass 2
  verification confirms the seeder works end-to-end, but the underlying
  shape — swallow-all — remains.

  **Mitigation that would NOT block this change but is recommended
  for the next iteration**: at minimum, write a structured log line
  inside the catch (e.g., `fs_core_log::new_error('Init::upgrade failed: ' . $e->getMessage())`
  or `error_log('fs_plugin_manager[clientes_core]: ' . $e->getMessage())`)
  so that swallowed errors are at least visible in `tmp/fs_core.log`
  or the PHP error log. This is a follow-up task, **not a blocker for
  this archive** (the seeder demonstrably works; the warning is about
  debuggability of future regressions, not the current behavior).

  This is a WARNING (not a SUGGESTION) because it is the second
  time a runtime fatal has been masked by this exact `try/catch`,
  and a future regression in the same shape will have the same
  masking behavior. The blanket swallow is the **primary risk**
  of the current design.

#### SUGGESTION

- **SUGGESTION-1 — Add a CI lint that flags `public $db` in test fakes**

  The test fake at
  `plugins/clientes_core/tests/Fixtures/InitUpgradeFakes.php:84`
  declares `public $db` on the anonymous `\FSFramework\model\cliente`
  subclass. This is what masked the protected-`$db` access in Pass 1
  (the test fake sidestepped the visibility check that the real
  `fs_model` enforces). A simple grep-based CI lint that flags
  `public \$db` inside `tests/Fixtures/` would have caught this before
  Pass 1's CRITICAL-1 surfaced. Low priority — the underlying bug
  is fixed; this is a defense-in-depth for future tests.

- **SUGGESTION-2 — Document the autoloader bootstrap requirement in
  the seeder's docblock**

  During Pass 2, the smoke harness initially failed to exercise the
  seeder because the legacy `fs_autoload` namespace map has a
  case-mismatch bug (uppercase `FSFramework\\Model\\` vs. lowercase
  `FSFramework\\model\\`) and the production autoloader
  `fs_model_autoloader` must be registered separately. The smoke
  harness had to be updated to call `\fs_model_autoloader::register(false)`
  to mimic the production request flow (which registers it via
  `fs_schema::selfHealCoreTables()` in `index.php:84`). The seeder's
  docblock at `Init.php:43-60` does not currently call this out.
  Adding a one-liner like "MUST be called after the production
  autoloader chain is registered (see `index.php` for the expected
  order)" would save future maintainers from the same trap when
  writing CLIs or unit tests that touch the seeder directly. Low
  priority — the production request flow already does the right
  thing; this is for future CLIs / direct-`Init::upgrade()` tests.

### Pre-existing out-of-scope observations

Identical to Pass 1. The single failure observed across the
`Plugins` and root suites is:

| Test | File | Status |
|---|---|---|
| `CsrfTokenTest::expiredTokenIsRejected` | `plugins/system_updater/tests/CsrfTokenTest.php:83` | Pre-existing, out-of-scope, NOT a regression introduced by this change. Same failure as in the original verify report and in `apply-progress.md` §T5 + §T7.F. `git log --name-only c5bc4b6e^..f8497cf8 -- plugins/system_updater/` shows zero files changed in this range. |

No new failures observed in Pass 2.

### File presence and isolation (Pass 2)

- **4 apply commits present** (from `git log --oneline -5`):
  - `f8497cf8` `fix(clientes_core): use public cliente::table_has_rows() to fix protected $db access` (the fix)
  - `749823a8` `chore(clientes_core): bump plugin version to 2`
  - `3133ba34` `feat(clientes_core): seed default cliente on activation via Init::upgrade()`
  - `c5bc4b6e` `test(clientes_core): scaffold InitUpgradeTest with first two scenarios`
- **5 SDD artifacts present** in `plugins/clientes_core/openspec/changes/default-client-on-activation/`:
  - `proposal.md` (14 KB)
  - `specs/clientes/spec.md` (the delta spec)
  - `design.md` (≈ 38 KB, updated for the fix)
  - `tasks.md` (16 KB, T7 added)
  - `apply-progress.md` (≈ 12 KB, Pass 1 + Pass 2 sections)
  - `verify-report.md` (this file, both Pass 1 and Pass 2)
- **`config.yaml`** present at `plugins/clientes_core/openspec/config.yaml`
  (ownership `plugin-local`).
- **Core `openspec/` NOT touched** by the apply commits.
  `git log --name-only c5bc4b6e^..f8497cf8 -- openspec/` returns zero
  files. The pre-existing changes in the core
  `openspec/changes/migrate-catalog-domain-to-catalogo-core/` (deletions)
  and the new
  `openspec/changes/archive/2026-06-18-migrate-catalog-domain-to-catalogo-core/`
  (archive) are unrelated to this change.
- **`Init.php` and `cliente.php` are unmodified vs `f8497cf8`** after
  Smoke #4's temporary shim was reverted. `git status --short plugins/clientes_core/Init.php plugins/clientes_core/model/core/cliente.php`
  returns no output.

**Isolation**: ✅ 100% inside `plugins/clientes_core/`. The core
`openspec/`, `base/`, `src/`, `controller/`, and other plugins are
untouched by the 4 apply commits.

### Security review (Pass 2)

Per the `fsframework-security-review` skill, re-audited the new code
(`cliente::table_has_rows()` at `model/core/cliente.php:282-294`,
the refactored `Init::upgrade()` at `Init.php:61-81`).

| Category | Finding | Severity |
|---|---|---|
| SQL injection | The SQL is `SELECT 1 FROM {$table_name} LIMIT 1;` where `$table_name` is `$this->table_name` (the model's own property, set in the constructor from a string literal, never user input). No user-controlled data flows into the query. | PASS |
| Hardcoded credentials / secrets | Only literal is the string `'1'` in `LIMIT 1` and the method name `table_has_rows`. No passwords, API keys, or tokens. | PASS |
| Method visibility / attack surface | `table_has_rows()` is `public` and read-only (returns a bool). Attack surface is essentially zero. | PASS |
| Try/catch blanket swallow | Still present in `Init::upgrade()`. **Same debuggability concern as Pass 1**; documented in WARNING-1 above. | WARNING (not a security issue) |
| Method name portability | The method uses `$this->table_name` (not the literal `'clientes'`), so subclasses that override the table name work without modification. | PASS |
| Test isolation | The new test fake (`InitUpgradeFakes.php:84`) exposes `$db` as `public`, same trade-off as the original `protected $db` issue. Documented in SUGGESTION-1. | SUGGESTION (not a security issue) |
| `fs_settings::save()` argument | Still called with no argument; does not assume ownership of unrelated keys. The seeder writes only `clientes_core_default_seeded`. | PASS |

**Security review**: No new security vulnerabilities introduced by
the fix. The only new method is a read-only bool-returning wrapper
around a hardcoded `SELECT 1` query against a hardcoded table name.

### Archive readiness (Pass 2)

**Status: READY for archive** (with the WARNING-1 + SUGGESTION-1+2
notes carried forward as known observations, not blockers).

The change is ready to be archived. CRITICAL-1 is resolved, the seeder
works at runtime (all 4 live smokes pass), the automated test suites
are green (only the pre-existing out-of-scope `CsrfTokenTest` failure
remains), and the SDD artifacts are consistent with the implementation.

When the orchestrator's `sdd-archive` step runs, it will need to:

1. Re-run the 4 live smoke checks one more time to confirm the
   behavior is stable across archive. They should be reproducible
   from this report.
2. Re-run the full root suite to confirm no new regressions versus
   the Pass 2 baseline. Same single pre-existing CSRF failure
   expected.
3. Create the archive dir per
   `plugins/clientes_core/openspec/config.yaml`:
   `plugins/clientes_core/openspec/changes/archive/2026-06-20-default-client-on-activation/`
4. Move the change dir into the archive dir (proposal, specs, tasks,
   design, apply-progress, verify-report all together).
5. **Merge the delta spec into the canonical stub at
   `plugins/clientes_core/openspec/specs/clientes/spec.md`**. The
   canonical stub is currently a 16-line placeholder ("This spec is
   the canonical place where delta specs under
   `openspec/changes/{name}/specs/clientes/spec.md` are merged once a
   change is archived"). The delta's ADDED Requirements (2 SHALList
   + 4 Scenarios) and the 2 new Scenarios added by the CRITICAL-1
   fix (Seeder uses the public `table_has_rows()` API;
   `cliente::table_has_rows()` is public and table-portable) must
   be incorporated. Per the plugin SDD skill §"Archive Workflow"
   step 8, this merge is the orchestrator's responsibility, not
   sdd-verify's — but it is called out here so the archive step
   does not forget it.
6. Create an `archive-report.md` mirroring this report's
   `Status` + `Findings` sections plus a closing summary. The
   closing summary should explicitly note this is a **post-verify
   fix** (the original implementation was broken at runtime per
   CRITICAL-1; the fix-and-re-verify cycle happened before archive).
7. Verify no entries in core `openspec/changes/{name}/` for this
   change name (already true; nothing to do).

### Relevant files (Pass 2)

- `plugins/clientes_core/model/core/cliente.php:282-294` — the new
  `table_has_rows(): bool` method (the fix)
- `plugins/clientes_core/Init.php:69-73` — the refactored call site
  in `Init::upgrade()` (uses the new method, not `$cliente->db`)
- `plugins/clientes_core/Init.php:68,77-80` — the `try/catch` that
  still swallows errors (WARNING-1)
- `plugins/clientes_core/tests/ClienteModelTest.php:312-357` — the 3
  new test methods for `table_has_rows()`
- `plugins/clientes_core/tests/InitUpgradeTest.php:121-323` — the 6
  refactored cases (using the new fake API)
- `plugins/clientes_core/tests/Fixtures/InitUpgradeFakes.php:107-111`
  — the fake's `table_has_rows()` method (counter + configured
  return value)
- `plugins/clientes_core/openspec/changes/default-client-on-activation/specs/clientes/spec.md:47-56`
  — the new `table_has_rows()` SHALL
- `plugins/clientes_core/openspec/changes/default-client-on-activation/specs/clientes/spec.md:218-237`
  — the 2 new Scenarios for the fix
- `plugins/clientes_core/openspec/changes/default-client-on-activation/design.md:213-247`
  — updated `cliente` API table, "Counting rows" subsection, and
  Risk #11
- `plugins/clientes_core/openspec/changes/default-client-on-activation/tasks.md:202-286`
  — T7 entry with the 6 sub-tasks
- `plugins/clientes_core/openspec/changes/default-client-on-activation/apply-progress.md:119-311`
  — Pass 2 section with T7.A through T7.F
- `base/fs_model_autoloader.php:34-38` — the autoloader that knows
  the lowercase `FSFramework\model\` namespace (SUGGESTION-2)
- `base/fs_autoload.php:179` — the legacy autoloader with the
  case-mismatch bug (SUGGESTION-2 context)
- `index.php:84` — `fs_schema::selfHealCoreTables()` registers
  `fs_model_autoloader` in the production request flow
  (SUGGESTION-2 context)
- `plugins/clientes_core/openspec/changes/default-client-on-activation/verify-report.md` —
  this file, both Pass 1 (FAIL with CRITICAL-1) and Pass 2 (PASS
  with observations)
