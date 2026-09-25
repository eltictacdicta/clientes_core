```yaml
schema: gentle-ai.archive-result/v1
change: cliente-form-compartido-grupos-obligatorios
sdd_home: plugins/clientes_core/openspec/
ownership: plugin-local
archived_at: 2026-09-23
archive_path: plugins/clientes_core/openspec/changes/archive/2026-09-23-cliente-form-compartido-grupos-obligatorios/
verify_verdict: pass-with-warnings
critical_findings: 0
archive_status: complete
specs_synced: 4/4
specs_created: 1   # shared-client-form
specs_merged: 1    # discount-groups
specs_already_canonical: 2   # client-discount-inheritance, clientes
in_repo_tasks: 39/39
external_contract_tasks: 6/6 (documented, not implemented here)
definition_of_done: 6/7 (item 7 NOT MET, documented)
```

## Archive Report

**Change**: `cliente-form-compartido-grupos-obligatorios`
**SDD home**: `plugins/clientes_core/openspec/` (`ownership: plugin-local`)
**Archived to**: `plugins/clientes_core/openspec/changes/archive/2026-09-23-cliente-form-compartido-grupos-obligatorios/`
**Archive status**: **COMPLETE** — 0 CRITICAL findings; the two open items are documented limitations by design, not blockers.
**Scope of the change**: a shared client form for `clientes_core` (Twig partial + single PHP apply/validate/diff authority) plus the mandatory client-group and discount-group rules.

This is the terminal record of the cycle. It describes the state of the change AT CLOSE. Where
`verify-report.md` and `apply-progress` are intermediate snapshots, this report supersedes their
"pending"/"open" claims with the post-verification remediation that landed before archive.

---

### Executive summary

All in-repo slices (0, 1a, 1b, 2a, 2b-i, 2b-ii) are committed and verifiable. Slice 3 (`tpvmod`) is an
external repository and is deliberately **not implemented here**; only its contract is recorded, which
is what this archive carries. Every one of the 11 delta requirements and 38 delta scenarios maps to a
covering test that passed at runtime; the four template partials, the shared PHP authority and both
rewired consumer views render end to end in real Twig environments.

All hard constraints held at close: `model/table/clientes.xml` is byte-identical, no core file changed,
no core OpenSpec entry exists, no plugin Composer manifest or vendor tree, `model/core/direccion_cliente.php`
untouched at 71/42, and no `'000000'` is written into `codgrupo` anywhere in the plugin.

The one item that is **NOT MET** is Definition-of-done item 7 (`ddev exec composer phpstan`), which
cannot be satisfied by this change: `phpstan.neon` analyses only `src/` and the root `tests/`, so plugin
code is never analysed, and the run is RED on a pre-existing core failure
(`tests/Core/PluginEnableAjaxSafetyTest.php:308`, core commit `14a4c7b7`). Both causes lie outside this
change's scope. It is recorded as a documented limitation, not a product defect.

At close, the plugin suite is **126 tests / 444 assertions / 1 error / 1 warning** — the single error is
the pre-existing, out-of-scope `Tests\ClientesCore\CuentaBancoClienteModelTest::testBusinessDataStubDelegatesToClientesCore`
(it requires the absent read-only `plugins/business_data/model/cuenta_banco_cliente.php`). No new failures.

---

### Completeness

| Metric | Value |
|--------|-------|
| In-repo implementation tasks | **39/39 complete** (`0.1`–`0.5`, `1.1`–`1.19`, `2.1`–`2.15`) |
| External contract tasks | **6/6 documented, not implemented here** (`3.1`–`3.6`, owned by the `tpvmod` repo) |
| Definition-of-done items | **6/7 PASS**, item 7 (`phpstan`) NOT MET and documented |
| CRITICAL findings | **0** |

**Count note.** `verify-report.md`'s Completeness table states "40 in-repo". The persisted `tasks.md`
ledger enumerates **39** in-repo implementation checkboxes (`0.1`–`0.5` = 5, `1.1`–`1.19` = 19,
`2.1`–`2.15` = 15). The tasks artifact is authoritative for completion visibility, so this report uses
**39**. The discrepancy is a one-unit arithmetic slip in the snapshot, not a contradiction of state:
every in-repo task is checked in both accounts.

---

### Specs Synced (Step 8)

Delta specs live at `changes/cliente-form-compartido-grupos-obligatorios/specs/`; the canonical source of
truth is `plugins/clientes_core/openspec/specs/`. All four deltas are now reflected in canonical specs.

| Domain | Canonical before | Action | Details |
|--------|------------------|--------|---------|
| `client-discount-inheritance` | Existed | **Already canonical — no merge** | Its single `MODIFIED` requirement (`Group is mandatory for all clients`) matched the delta body and all 5 scenarios exactly. Synced earlier in commit `8326492` (Slice 1b, PR 3). |
| `clientes` | Existed | **Already canonical — no merge** | `MODIFIED` `New client gets default group` (5 scenarios) and `ADDED` `Mandatory group backfill on plugin activation` (5 scenarios, a superset of the delta's 4) both matched the delta exactly. Synced in `8326492`. |
| `discount-groups` | Existed | **MERGED** (real work) | The canonical spec was synced at Slice 1 (`8326492`) but the delta later gained the `ADDED` requirement `In-use group deletion is blocked` (Slice 2 delete guards), which never reached canonical. Merged via `gentle-ai sdd-archive-compose`; also refreshed `MODIFIED` `Default "Personalizado" group` to the delta wording. |
| `shared-client-form` | **Did NOT exist** | **CREATED** | New canonical spec at `specs/shared-client-form/spec.md`, built from the delta's 6 `ADDED` requirements in canonical form (no delta markers). |

**Why `discount-groups` needed a real merge.** `verify-report.md` was written against the state at
verification time; the Slice 2 deletion-guard delta requirement was applied to code and tests but the
canonical spec still lacked it. This is exactly the drift the archive phase exists to close. The merge
ran through the native composer, not a model-driven Read/Edit:

```bash
gentle-ai sdd-archive-compose \
  --canonical "plugins/clientes_core/openspec/specs/discount-groups/spec.md" \
  --delta "plugins/clientes_core/openspec/changes/cliente-form-compartido-grupos-obligatorios/specs/discount-groups/spec.md" \
  --output "<tmp>"   # exit 0; then appended the project source-of-truth footer and installed atomically
```

`git diff` confirms the three unrelated requirements (`Discount group discount fields`, `Discount cascade
semantics`, `Group CRUD follows existing patterns`) were preserved byte-for-byte; only the target
requirement changed and the new one was appended.

**Delta-sync verification (post-merge).** Every requirement named in every delta now exists in its
canonical spec:

| Domain | Delta requirements | Present in canonical |
|--------|--------------------|----------------------|
| `client-discount-inheritance` | 1 | 1/1 |
| `clientes` | 2 | 2/2 |
| `discount-groups` | 2 | 2/2 |
| `shared-client-form` | 6 | 6/6 |

No canonical spec carries a leftover `ADDED` / `MODIFIED` / `REMOVED` / `RENAMED` marker.

**New-spec byte-preservation.** `shared-client-form` is a NEW canonical spec, so the six requirement
bodies were extracted from the delta and assembled through the shell (authored header + byte-preserved
requirements + footer), never regenerated by the model. A `diff` of the delta's requirement block against
the assembled spec's requirement block is **empty** — the requirement text is byte-identical to the delta.

---

### Hard constraints (re-verified at close)

| # | Constraint | Result |
|---|---|---|
| 1 | `model/table/clientes.xml` byte-identical | ✅ PASS |
| 2 | No file under core `base/`, `src/`, root `model/`, root `controller/` modified | ✅ PASS |
| 3 | No entry for this change under core `openspec/changes/` | ✅ PASS |
| 4 | No `plugins/clientes_core/composer.json` and no `plugins/clientes_core/vendor/` | ✅ PASS |
| 5 | `model/core/direccion_cliente.php` preserved read-only WIP at 71 insertions / 42 deletions | ✅ PASS |
| 6 | Nothing writes `'000000'` into `codgrupo` under `plugins/clientes_core/` | ✅ PASS |
| 7 | `plugins/tpvmod` and `plugins/OidcProvider` have no uncommitted change caused by this change | ✅ PASS |

---

### Build & Tests at close

**Plugin suite** — `ddev exec php vendor/bin/phpunit -c plugins/clientes_core/phpunit.xml`

```text
Tests: 126, Assertions: 444, Errors: 1, Warnings: 1.
1) Tests\ClientesCore\CuentaBancoClienteModelTest::testBusinessDataStubDelegatesToClientesCore
   Error: Failed opening required '.../plugins/business_data/model/cuenta_banco_cliente.php'
```

The single error is the pre-existing, out-of-scope missing read-only sibling plugin `business_data`.
The count is +1 test / +5 assertions over the verification-time snapshot (`125 / 439`) because the
WARNING-3 remediation test (`saveClienteEditPathMapsSubmissionThroughSharedAuthority`) was added in
commit `16cf5eb`. **No new failures.**

**Root Plugins suite** — `ddev exec php vendor/bin/phpunit --testsuite Plugins`

All residual failures are pre-existing `Tests\OidcProvider\*` (the `migration011` `utf8mb3` vs `utf8mb4`
bug). **Zero `Tests\ClientesCore` failures.**

**Build/static analysis**: `ddev exec composer phpstan` exits 1 and does not analyse plugin code — see
Definition-of-done item 7 below.

---

### Definition of done (final)

| # | Item | Result |
|---|---|---|
| 1 | Slices 0–2 complete; slice 3's contract recorded and its release coordinated, not implemented here | ✅ PASS |
| 2 | Plugin suite has no new failures beyond the VF-3 baseline | ✅ PASS |
| 3 | No `'000000'` written into `codgrupo` anywhere under `plugins/clientes_core/` | ✅ PASS |
| 4 | `model/table/clientes.xml` byte-identical | ✅ PASS |
| 5 | No core file modified; no artifact under the core OpenSpec store | ✅ PASS |
| 6 | No plugin-level Composer manifest and no Composer vendor tree | ✅ PASS |
| 7 | `ddev exec composer phpstan` passes for the touched surface | ❌ **NOT MET (documented limitation)** |

---

### Deliberately-unchecked items (documented, not silently ticked)

Two checkbox groups in the archived `tasks.md` remain `[ ]` **by design**. They are recorded here so the
audit trail does not read them as missing work.

**1. Tasks `3.1`–`3.6` — the `tpvmod` consumer contract (external repository).** `tasks.md` states
verbatim: *"Owned by the `tpvmod` repository and its own SDD home. Nothing here is implemented inside
this change, and no `tpvmod` artifact is copied into `plugins/clientes_core/openspec/`."* These six lines
are contract references, not implementation tasks of this change. Their implementation and acceptance
gate (`ddev exec php vendor/bin/phpunit -c plugins/tpvmod/phpunit.xml`) live in the `tpvmod` repo's own
change (PR 7). They are intentionally left unchecked here; ticking them would falsely claim work this
change did not do.

**2. Definition-of-done item 7 — `phpstan` NOT MET.** It carries an inline NOT MET note in `tasks.md`
and cannot be met by this change: `phpstan.neon` analyses only `src/` and the root `tests/`, so plugin
code is never analysed, and the run is RED on a pre-existing core failure
(`tests/Core/PluginEnableAjaxSafetyTest.php:308`, core commit `14a4c7b7`). Recorded as a documented
limitation, not a blocker.

No other checkbox is unchecked. All in-repo implementation tasks and Definition-of-done items 1–6 are `[x]`.

---

### Final-state remediation (work done AFTER `verify-report.md` was written)

`verify-report.md` was written first and then updated in place; this archive report is the record at
close. The facts at close, which supersede the snapshot's open items:

- **WARNING-3 (edit save path had no behavioural test) — CLOSED** after verification, in commit
  `16cf5eb`: `tests/Controller/VentasClienteDiscountsTest.php` gained
  `saveClienteEditPathMapsSubmissionThroughSharedAuthority`, which invokes the real
  `ventas_cliente::save_cliente()` via reflection. It was proven **non-vacuous by mutation** (removing
  the `ClienteForm::apply()` call made it fail with `'Original Name'` instead of `'Mapped By Authority'`),
  and the production file was restored byte-for-byte.
- **SUGGESTION-1 (stale `tasks.md` checkboxes) — CLOSED**: `0.1`–`0.5` and `1.1`–`1.8` were `[ ]`
  despite those slices being merged; now `[x]`. For the record: the verify report's original
  SUGGESTION-1 text also claimed `1.9`–`1.19` were unchecked — that was **inaccurate**; they were
  already `[x]`. The report now says so.
- **SUGGESTION-2 — open by design**: three brittle `file_get_contents` source guards
  (`saveClienteProductionCodeNeverFallsBackToDiscountCode`, `saveClienteDelegatesMappingAndDiffToSharedAuthority`,
  `clientGroupDeleteControlKeysOnClientGroupCode`), each now backed by a behavioural companion test.
- **SUGGESTION-3 — open by design**: the cross-repo `tpvmod` release-ordering obligation
  (`tpvmod/fsframework.ini` must declare `clientes_core` before this change ships). It cannot be verified
  from this repo.

**Design corrections recorded during apply** (carried here so they are not lost):

- **Design §5.1** — the `Form.html.twig` "owns the `<form>`" claim is impossible: the create modal's
  `<form>` must wrap consumer-only Bootstrap modal chrome, so the partial renders the form **body**.
  The consumer keeps the owning `<form>`, its `action` and its id.
- **Design §9.6** — the `VentasClienteDiscountsTest` harness cannot delegate to `ClienteForm::apply()`
  because `controller/ventas_cliente.php` hard-`require_once`s the real `grupo_descuentos` model, so the
  stub can never win the class lookup. The harness delegates only the diff, with the reason documented
  in-source.

---

### Commits (plugin repo — all local only, nothing pushed)

- `0b6a28d` — Slice 0, PR 1, merged
- `2f1230f` — Slice 1a, PR 2, merged
- `cc5c0dd`, `91f0b49`, `2b2fc55`, `77be2e1`, `6159c16`, `8326492` — Slice 1b, PR 3, open
- `39960d4`, `8d8962a` — Slice 2a, PR 4, unpushed
- `524eda0` — Slice 2b-i, PR 5, unpushed
- `c86ad41`, `320d04a`, `16cf5eb` — Slice 2b-ii + remediation, PR 6, unpushed

---

### Budget reality (recorded)

Every slice blew the 400-line review budget — actuals `174`, `859`, `245`, `746`, `726`, `~680`, `~1018`
— roughly 5× the 800-line session budget. Every slice needed a `size:exception`. The sharper residual is
that no artifact in this change proves a single reviewer saw the whole delta in one reviewable unit;
verification re-derived behaviour from the committed tree rather than from the ledger. Recommend a
hand-tracked budget for the next change.

---

### Archive mechanics (Mechanical Copy Contract)

- The change directory was moved with a plain shell `mv` (the change dir is **untracked in git by
  project convention**; `git mv` failed with status 128 and the fallback path was taken only after the
  snapshot check confirmed the source was unchanged).
- A recursive pre-move snapshot was taken and compared to the archived destination:
  **`diff -r` output was EMPTY** — the archived tree is byte-identical to the pre-move snapshot.
- The active `changes/` directory no longer contains this change; the archived tree contains all 10
  change artifacts.
- `archive-report.md` (this file) is additive and was written after the move.

---

### Source of truth updated

The following canonical specs now reflect the shipped behavior:

- `plugins/clientes_core/openspec/specs/client-discount-inheritance/spec.md` (already canonical)
- `plugins/clientes_core/openspec/specs/clientes/spec.md` (already canonical)
- `plugins/clientes_core/openspec/specs/discount-groups/spec.md` (merged: `In-use group deletion is blocked` added)
- `plugins/clientes_core/openspec/specs/shared-client-form/spec.md` (**created**)

---

### SDD Cycle Complete

The change has been fully planned, implemented, verified, and archived. The core `openspec/` store was
NOT touched: no entry for this change exists there, and no file under it was created, moved or edited.
Ready for the next change.
