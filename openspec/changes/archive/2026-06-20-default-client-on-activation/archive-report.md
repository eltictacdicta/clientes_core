# Archive Report — default-client-on-activation

**Change**: `default-client-on-activation`
**Plugin**: `plugins/clientes_core/`
**Archived**: 2026-06-20
**Status**: ✅ **ARCHIVED** — SDD cycle closed (post-verify fix; see Verify outcome)

## Status

**ARCHIVED** — change closed. The plugin SDD cycle is complete: the
`default-client-on-activation` change has been implemented, verified
(Pass 1 + Pass 2), post-verify-fixed (CRITICAL-1), re-verified, and
moved into the plugin's own archive. The delta spec has been merged
into the canonical source of truth at
`plugins/clientes_core/openspec/specs/clientes/spec.md`. No entry
exists (and none was ever created) in the core `openspec/changes/` for
this change name.

## Change summary

Restored the operator expectation from a 2017-era FacturaScripts
install: the first time `clientes_core` is activated, a single
placeholder client (`nombre='Cliente por defecto'`) is planted in the
`clientes` table so downstream sales / invoicing flows always have at
least one valid `codcliente` to reference. The seeder is a
flag-gated, idempotent, never-throws one-shot bootstrap that runs
exactly once per installation via the static
`\FSFramework\Plugins\clientes_core\Init::upgrade()` hook called by
`base/fs_plugin_manager::runPluginUpgrade()`. The persistence layer
is `fs_settings` with flag key `clientes_core_default_seeded = '1'`
(written via `fs_settings::save()` to
`tmp/{FS_TMP_NAME}config2.ini`).

The table-empty check goes through a new public model method
`\FSFramework\model\cliente::table_has_rows(): bool`
(`plugins/clientes_core/model/core/cliente.php:282-294`) which
encapsulates the `SELECT 1 ... LIMIT 1` query via `$this->table_name`.
The method exists precisely because `fs_model::$db` is `protected`
and the seeder (living in another class) cannot reach the handle
directly; this is the architectural fix for CRITICAL-1 surfaced by
Pass 1 of the verify phase.

## Commits

The change is implemented across **4 atomic commits** in the branch's
git history (most recent first):

| # | SHA | Conventional commit |
|---|-----|---------------------|
| 1 | `f8497cf8` | `fix(clientes_core): use public cliente::table_has_rows() to fix protected $db access` (the post-verify fix for CRITICAL-1) |
| 2 | `749823a8` | `chore(clientes_core): bump plugin version to 2` |
| 3 | `3133ba34` | `feat(clientes_core): seed default cliente on activation via Init::upgrade()` |
| 4 | `c5bc4b6e` | `test(clientes_core): scaffold InitUpgradeTest with first two scenarios` |

The first three commits are the original 3-commit apply (TDD red →
green → housekeeping). Commit 1 (`f8497cf8`) is the post-verify fix
(CRITICAL-1 → refactor to use the new public `table_has_rows()`
method). Commits 1 and 3 split the test scaffolding from the
implementation per the project's TDD red-then-green commit
convention; commit 2 is the version bump per the established plugin
version convention.

## Files changed

Production code (committed):

- `plugins/clientes_core/Init.php` — added `use \FSFramework\model\cliente;`
  import + new `public static function upgrade(): void` method
  (≈ +37 lines); existing instance `init()` method untouched.
- `plugins/clientes_core/model/core/cliente.php` — added the public
  `table_has_rows(): bool` method (CRITICAL-1 fix, ≈ +12 lines
  including docblock).
- `plugins/clientes_core/fsframework.ini` — `version = 1` →
  `version = 2` (behavioural change; matches the
  `fix-backup-worker-recovery` precedent).

Tests (committed):

- `plugins/clientes_core/tests/InitUpgradeTest.php` — 6 cases
  (4 required + 2 bonus); refactored in commit `f8497cf8` to use
  the new fake API.
- `plugins/clientes_core/tests/ClienteModelTest.php` — 3 new cases
  for `cliente::table_has_rows()`.
- `plugins/clientes_core/tests/Fixtures/InitUpgradeFakes.php` — new
  test fakes; updated in commit `f8497cf8` to expose a public
  `table_has_rows()` method.
- `plugins/clientes_core/phpunit.xml` — added
  `processIsolation="true"` so the autoloader-based fake injection
  can intercept the first-time `cliente` load.

The SDD artifacts listed in the next section are NOT counted here —
they are part of the archive directory listing.

## SDD artifacts archived

The 6 SDD artifacts under
`plugins/clientes_core/openspec/changes/archive/2026-06-20-default-client-on-activation/`:

- `proposal.md` — the change proposal (intent, scope, approach,
  risks, open questions).
- `specs/clientes/spec.md` — the delta spec (the source of truth for
  the merge into the canonical spec).
- `design.md` — the technical design (mocking strategy, test plan,
  API table, Risks-and-mitigations; updated in the T7 fix to reflect
  the new `cliente::table_has_rows()` method).
- `tasks.md` — the task list (T1-T6 for the original apply; T7 added
  post-verify for the CRITICAL-1 fix with 6 sub-tasks T7.A-T7.F).
- `apply-progress.md` — the apply-phase TDD cycle evidence
  (Pass 1 + Pass 2 sections).
- `verify-report.md` — the verify phase report
  (Pass 1: FAIL with CRITICAL-1; Pass 2: PASS with observations).
- `archive-report.md` — this file (created at archive).

## Spec merge

The delta spec's `ADDED Requirements` were merged into the canonical
source of truth at
`plugins/clientes_core/openspec/specs/clientes/spec.md`. The
canonical stub (previously a 16-line placeholder) now contains:

- `## Purpose` (unchanged from the stub).
- `## Domain context` (new) — describes the `cliente` model, the
  `clientes` table, the activation-time seeder convention, and the
  trade-off of the silent-bootstrap design.
- `## Requirements` (new) with two requirements:
  - `### Requirement: Default client seed on plugin activation` —
    all 9 SHALLs and 4 Scenarios from the delta, including the
    new SHALLs introduced by the T7 fix (the `table_has_rows()`
    public method SHALL and the "Seeder uses the public
    `table_has_rows()` API" + "`cliente::table_has_rows()` is public
    and table-portable" Scenarios).
  - `### Requirement: Idempotency and flag persistence` — all 6
    SHALLs and 2 Scenarios from the delta.
- `## Scenarios` (new) — the 7 cross-cutting scenarios (4
  composed of the requirements' scenarios + 3 new ones from the
  fix: cold-start table-create, seeder uses public API, and
  `table_has_rows()` is public and table-portable).

**MODIFIED Requirements** and **REMOVED Requirements** in the delta
are empty (per the delta's own declaration) and were not merged.

The merge preserves the delta's exact SHALL wording (no
paraphrasing). The 33 SHALL/MUST statements and 13 Scenarios in the
canonical spec match the delta exactly (verified via
`grep -c "SHALL\|MUST"` and `grep -c "Scenario:"`).

A footer line was added to the canonical spec:
`<!-- Source of truth. Last updated: 2026-06-20. Merged from changes/default-client-on-activation/specs/clientes/spec.md. -->`

## Verify outcome

The change went through **two verify passes**:

- **Pass 1** (commit `749823a8`): **FAIL** with **CRITICAL-1** —
  `Init::upgrade()` line 70 read `$cliente->db->select(...)` from
  outside the class hierarchy; `$db` is `protected` in
  `base/fs_model.php:69`; PHP raised
  `Error: Cannot access protected property` and the seeder's
  `try/catch` swallowed the error. All four live smokes (#1 cold
  start, #2 re-activation, #3 non-empty install, #4 DB error) were
  either FAIL or PASS-in-vacuum-only. The unit tests passed only
  because the test fake's `$db` was `public`, masking the bug.
- **Pass 2** (commit `f8497cf8`, the post-verify fix): **PASS with
  observations** — CRITICAL-1 fully resolved by adding the public
  `cliente::table_has_rows(): bool` method and refactoring
  `Init::upgrade()` to use it. All four live smokes (#1-#4) now
  pass on the live ddev MariaDB 10.11 instance. The plugin test
  suite passes 41/41 (32 pre-existing + 6 refactored
  `InitUpgradeTest` + 3 new `ClienteModelTest::testTableHasRows*`
  cases). The `Plugins` suite (292 tests) and the full root suite
  (717 tests) report the **same single pre-existing failure**:
  `CsrfTokenTest::expiredTokenIsRejected` in
  `plugins/system_updater/tests/CsrfTokenTest.php:83` — pre-existing,
  out-of-scope, NOT a regression introduced by this change
  (verified via `git log --name-only c5bc4b6e^..f8497cf8 -- plugins/system_updater/`
  which shows zero files changed in this range).

**No new regressions** in any test layer. The CRITICAL-1 fix's
specific 4-line scope (Init.php call site + new public method on
cliente + 3 new unit tests + test fake update) was strictly additive
and exercised end-to-end by the live smoke harness.

## Open follow-ups (not blocking archive)

These items are explicitly **not** blockers; they are carried forward
from Pass 2 §"New findings" as known observations for the next
iteration:

1. **WARNING-1 — `Init::upgrade()` `try/catch` blanket swallow
   continues to mask the next class of runtime fatal.** The
   `try { ... } catch (\Throwable $e) { /* swallow */ }` shape
   (`Init.php:68,77-80`) is intentional (the seeder must never
   block activation) but it swallowed a real programmer error in
   Pass 1 (the protected `$db` access) and will swallow any future
   class-not-found, method-not-found, or similar `\Throwable`.
   **Recommended follow-up**: add a structured log line inside the
   catch (e.g., `error_log('fs_plugin_manager[clientes_core]: ' . $e->getMessage())`)
   so swallowed errors are visible in the PHP error log without
   changing the "never block activation" contract.

2. **SUGGESTION-1 — Add a CI lint that flags `public $db` in test
   fakes.** The test fake at
   `plugins/clientes_core/tests/Fixtures/InitUpgradeFakes.php:84`
   declares `public $db` on the anonymous `\FSFramework\model\cliente`
   subclass, which is what masked the protected-`$db` access in
   Pass 1. A simple grep-based CI lint that flags
   `public \$db` inside `tests/Fixtures/` would have caught this
   before CRITICAL-1 surfaced. Low priority; defense-in-depth for
   future tests.

3. **SUGGESTION-2 — Document the autoloader bootstrap requirement in
   the seeder's docblock.** During Pass 2, the smoke harness
   initially failed to exercise the seeder because the legacy
   `fs_autoload` namespace map has a case-mismatch bug (uppercase
   `FSFramework\\Model\\` vs. lowercase `FSFramework\\model\\`)
   and the production autoloader `fs_model_autoloader` must be
   registered separately (via `fs_schema::selfHealCoreTables()` in
   `index.php:84`). The seeder's docblock at `Init.php:43-60` does
   not currently call this out. Adding a one-liner ("MUST be
   called after the production autoloader chain is registered")
   would save future maintainers writing CLIs or unit tests that
   touch the seeder directly from the same trap. Low priority;
   the production request flow already does the right thing.

## Core openspec isolation confirmed

The change is **100% internal to `plugins/clientes_core/`** and
respects the "OpenSpec per plugin" convention documented in
`AGENTS.md` and `plugins/clientes_core/openspec/config.yaml`. No
entry was created in the core `openspec/changes/` for this change
name. Verification:

```bash
$ ls openspec/changes/default-client-on-activation/ 2>/dev/null && echo "LEAK (wrong)" || echo "ABSENT (correct)"
ABSENT (correct)

$ ls openspec/changes/archive/2026-06-20-default-client-on-activation/ 2>/dev/null && echo "LEAK (wrong)" || echo "ABSENT (correct)"
ABSENT (correct)
```

Both lines say **ABSENT (correct)**. The plugin SDD is fully
isolated from the core openspec tree. This was also independently
confirmed during the verify phase via
`git log --name-only c5bc4b6e^..f8497cf8 -- openspec/` which
returned zero files.

## Pre-existing out-of-scope observations

The verify phase observed one pre-existing test failure in the
`Plugins` and root suites that is **NOT** a regression introduced by
this change:

| Test | File | Status |
|---|---|---|
| `CsrfTokenTest::expiredTokenIsRejected` | `plugins/system_updater/tests/CsrfTokenTest.php:83` | Pre-existing, out-of-scope. `git log --name-only c5bc4b6e^..f8497cf8 -- plugins/system_updater/` shows zero files changed in this range. The same failure was observed and classified as pre-existing in `apply-progress.md` §T5 and §T7.F. |

The plugin test suite (`plugins/clientes_core/phpunit.xml`) is green
41/41 and shows no failures of any kind.

## SDD cycle complete

The `default-client-on-activation` change has been fully planned
(proposal + design + delta spec), implemented (3-commit apply + 1
post-verify fix commit), verified (Pass 1 FAIL → post-verify fix →
Pass 2 PASS), and archived under the plugin's own openspec. The
delta spec was merged into the canonical source of truth
(`plugins/clientes_core/openspec/specs/clientes/spec.md`); all
SHALL/MUST statements and all 13 Scenarios are preserved exactly.

Ready for the next change. **Plugin SDDs remain properly isolated
from core** — see `AGENTS.md` for the "OpenSpec per plugin"
convention.

---

**SDD Cycle Complete**
