# Tasks: Shared client form + mandatory client group and discount group

- **Change**: `cliente-form-compartido-grupos-obligatorios`
- **SDD home**: `plugins/clientes_core/openspec/` (`ownership: plugin-local`)
- **Artifact store**: openspec (files)
- **Strict TDD**: `true` — every behavior change is a RED → GREEN pair.
- **Runner**: `ddev exec php vendor/bin/phpunit -c plugins/clientes_core/phpunit.xml`
- **Repo note**: `plugins/clientes_core` is its own git repository, so PR 1–6 land there and PR 7 lands in the `tpvmod` repo. No PR touches the core repo.
- **Inputs**: `proposal.md`, `design.md` (§4–§16), `decisions.md` (Q1–Q6), four delta specs.
- **Supersedes**: none.

## Resume point (session handoff)

- **Done and merged**: Slice 0 (`clientes_core#1`), Slice 1a (`clientes_core#2`), and the OidcProvider consumer corrective (`OidcProvider#1`).
- **Done, PR open**: Slice 1b (`clientes_core#3`, branch `feat/clientes-core-conflation-guards`).
- **Done, COMMITTED on `feat/clientes-core-shared-form-authority` (2 commits, LOCAL ONLY — not pushed)**: Slice 2a (tasks 2.1–2.4) — `ClienteForm` authority + both controllers delegated, inline `descuentos_modified` diff removed. Full plugin suite `106 tests / 343 assertions / 1 error / 1 warning`, the only error being the pre-existing read-only `CuentaBancoClienteModelTest`. Contract validator: PASS. **PR 4's base is `feat/clientes-core-conflation-guards`** (Slice 1b, PR #3 still open); that branch was reset to its remote tip `8326492`, so no published history was rewritten.
- **Done, COMMITTED on `feat/clientes-core-shared-form-views` (1 commit `524eda0`, LOCAL ONLY — not pushed)**: Slice 2b-i (tasks 2.5–2.9) — reusable `View/Cliente/Fields.html.twig` + `View/Cliente/Discounts.html.twig`, exposed under `@clientes_core` by a `TwigLoaderEvent` listener in `Init.php`. Contract validator: PASS.
- **Done, COMMITTED on `feat/clientes-core-shared-form-consumers` (LOCAL ONLY — not pushed)**: Slice 2b-ii (tasks 2.10–2.15) — `View/Cliente/Form.html.twig` (the form **body**) plus both consumer views rewired to the shared partial. **This completes all in-repo implementation (Slices 0–2).** Contract validator: PASS, including an explicit ruling that the "`Form.html.twig` owns the `<form>`" requirement is impossible to honour — design §5.1's correction note records why (the create modal's `<form>` must wrap consumer-only Bootstrap modal chrome).
- **Next**: `sdd-verify` for the whole change, then `sdd-archive`. Remaining outside this repo: Slice 3 (`tpvmod`, PR 7, external repo).
- **Ledger caveat**: objectives `slice-2a-shared-php-authority` and `slice-2b-i-twig-partials` are `complete`, but the settles recorded `changed_lines: 0` because the actors staged nothing — the 800-line attempt budget never self-enforced. Track the session budget by hand.
- **Gap from the Slice 2a contract validator — RESOLVED (2026-09-22)**: `tests/Controller/VentasClienteDiscountsTest.php::invokeSaveCliente()` no longer carries a copy of the d1–d4 diff; it delegates to `ClienteForm::computeDescuentosModified()`. **Delegating `apply()` is infeasible, not merely hard**: `controller/ventas_cliente.php` hard-`require_once`s the real `grupo_descuentos` model, so the test stub can never win the class lookup and `class_alias` cannot override an already-declared class (observed during apply: the resolved group returned live-DB data, `d1=30`). Design §9.6 now carries the correction note. **Residual**: the harness still maps fields locally, and `save_cliente()` has no end-to-end behavioural test — production delegation rests on the source guard `saveClienteDelegatesMappingAndDiffToSharedAuthority` plus the behavioural `nuevo_cliente_pure()` test.
- **Budget reality**: the design forecasts were optimistic. Actuals — Slice 0 `174`, Slice 1a `859`, OidcProvider corrective `245`, Slice 1b `746`, Slice 2a `726`, Slice 2b-i `~680`, Slice 2b-ii `~1018` → the 800-line session budget is exceeded roughly **5x**, and **every** slice blew the 400-line review budget and needs a `size:exception`. Revised total ~4400–4800 lines.
- **Follow-up (not in scope)**: OidcProvider `migration011` hardcodes `utf8mb3` while the referenced tables are `utf8mb4`, so `oidc_cliente_grupos` is never created on MySQL 8 — the root cause of its residual schema/migration test failures.

## Hard constraints (verify at the end of every slice)

1. **No core change**: no edit under `base/`, `src/`, root `model/`, root `controller/` (read-only). If a task requires one, STOP and re-home the SDD.
2. **No artifact under core `openspec/`**: everything stays in `plugins/clientes_core/openspec/`.
3. `plugins/clientes_core/model/table/clientes.xml` (read-only) stays **byte-identical**: no FK, no `NOT NULL` on `codgrupo_descuento` (Q3). Guard: `git diff --exit-code` on it.
4. **No Composer**: the authority is autoloaded by the root PSR-4 map `"FSFramework\\Plugins\\": "plugins/"` in `composer.json` (read-only). Do **not** add a plugin `composer.json` or `vendor/`. If that mapping ever proves insufficient, STOP and flag it.
5. Q1–Q6 are closed. Do not re-open them.

## Validator findings — explicit resolutions

- **VF-1 — missing translation keys.** Verified: `grupo-descuentos` and `sin-grupo-descuentos` are **not** defined in either locale today; both are referenced only at `plugins/clientes_core/view/ventas_cliente.html.twig`. `design.md` §15.6 wrongly lists `grupo-descuentos` as "existing/reused". **Resolved**: add `grupo-descuentos`, `grupo-descuentos-obligatorio` and `seleccione-grupo-descuentos` as **new keys** (task 1.18). Do **not** add `sin-grupo-descuentos`: design §15.6 removes the empty option, so the key would be dead churn. Adjacent pre-existing gap recorded, not fixed: `nuevo-grupo-descuentos` is also undefined (`plugins/clientes_core/view/descuentos_grupo.html.twig`) — out of scope, follow-up only.
- **VF-2 — `razonsocial` empty→`nombre` normalization.** It appears in no delta-spec scenario, but it exists today only on the create path (`plugins/clientes_core/controller/ventas_clientes.php`). **Resolved: KEEP it**, as an explicit task (2.2) with its own covering unit test. Justification: dropping it regresses the create path, and `shared-client-form → "Page and TPV produce identical field state"` requires one deterministic mapping for every consumer. It is an intentional behavior change for the edit path, flagged here so verification reviews it deliberately rather than as an accidental side effect.
- **VF-3 — baseline suite is RED before apply.** Measured on the dirty tree: 69 tests, 7 errors. Six are `InitUpgradeTest` failures (`Class "FSFramework\model\grupo_descuentos" not found`) that task 1.5 fixes. One is pre-existing and unrelated: `CuentaBancoClienteModelTest::testBusinessDataStubDelegatesToClientesCore` (missing `plugins/business_data/model/cuenta_banco_cliente.php`, read-only). Do not chase that one; baseline success means "no new failures beyond these 7".

## Review Workload Forecast

| Slice | Estimated changed lines | 400-line budget risk |
|---|---|---|
| 0 — baseline hygiene (PR 1) | 174 (121 tracked WIP + 53 untracked test) | Low |
| 1a — model gate + migration (PR 2) | ~330–390 | Med |
| 1b — conflation removal + guards + specs + i18n (PR 3) | ~230–270 | Low–Med |
| 2a — PHP authority (PR 4) | ~250–300 | Med |
| 2b-i — namespace + partials (PR 5) | ~300–350 | Med |
| 2b-ii — Form wrapper + consumer wiring (PR 6) | ~280–330 | Med |
| **Combined (this repo)** | **~1560–1810** | **High** |
| 3 — `tpvmod` consumer (PR 7, external repo) | ~350–450 (measured there) | Med |

**Session budget is 800 changed lines.** The combined estimate is ~2× the budget: this cannot land in one session. The worst single slice (2b-i) stays under 400, so every PR is reviewable — but the chain needs at least two sessions.

**Discrepancy flagged:** `proposal.md` forecasts Slice 2 at ~500–650. Task-level accounting gives ~830–980, because it includes three new templates (~265 lines), two full consumer-view rewrites (~150 changed) and ~340 lines of view/authority tests. The higher number is the planning number; the proposal's is optimistic.

```text
Decision needed before apply: Yes
Chained PRs recommended: Yes
Chain strategy: pending
400-line budget risk: High
```

### Suggested Work Units

| Unit | Goal | Likely PR | Focused test command | Runtime harness | Rollback boundary |
|------|------|-----------|----------------------|-----------------|-------------------|
| 0 | Clean, known baseline (R4) | PR 1 | `ddev exec php vendor/bin/phpunit -c plugins/clientes_core/phpunit.xml` (expect the recorded 7 errors) | `git -C plugins/clientes_core status --porcelain` must show only the preserved WIP | Revert the single baseline commit |
| 1a | Model gate + idempotent data migration | PR 2 | `ddev exec php vendor/bin/phpunit -c plugins/clientes_core/phpunit.xml --filter 'ClienteModel\|InitUpgrade\|ClienteSchema'` | `Init::upgrade()` on a populated DB; assert no `codgrupo_descuento IS NULL` remains | Revert PR 2; created `000001`/`000000` rows are harmless |
| 1b | No `'000000'` in `codgrupo`; in-use deletion blocked; specs + i18n truthful | PR 3 | `ddev exec php vendor/bin/phpunit -c plugins/clientes_core/phpunit.xml --filter 'VentasClienteDiscounts\|VentasClientesDispatch\|DescuentosGrupoDelete\|Translations'` | Manual: attempt deleting an in-use client group and `'000000'` in the admin UI | Revert PR 3 (no schema, no data written by this slice) |
| 2a | One PHP authority for mapping/diff/validate | PR 4 | `ddev exec php vendor/bin/phpunit -c plugins/clientes_core/phpunit.xml --filter ClienteFormAuthority` | Save with empty groups through both controllers → same validation error | Revert PR 4; controllers keep working on the PR 3 code |
| 2b-i | `@clientes_core` namespace + reusable partials | PR 5 | `ddev exec php vendor/bin/phpunit -c plugins/clientes_core/phpunit.xml --filter View` | Render `@clientes_core/Cliente/Fields.html.twig` in a real `Twig\Environment` with a registered hook | Revert PR 5; consumers still use their own markup |
| 2b-ii | Own pages render the shared form | PR 6 | `ddev exec php vendor/bin/phpunit -c plugins/clientes_core/phpunit.xml` | Load `ventas_cliente` edit page and the create modal; save both | Revert PR 6; restores the PR 3 views |
| 3 | `tpvmod` consumes the contract | PR 7 (external) | `ddev exec php vendor/bin/phpunit -c plugins/tpvmod/phpunit.xml` | Open the TPV client form; save via AJAX; assert `{ok,codcliente,label,cliente}` | Revert in `tpvmod`; the added `require` is safe to keep |

---

## Slice 0 — Baseline hygiene (R4) — PR 1

**GATE: no Slice 1 task may start until 0.4 passes.** The baseline forecast for this slice is 174 changed lines; it is pre-existing WIP, not new work.

- [x] 0.1 Record the exact dirty state: `git -C plugins/clientes_core status --porcelain` must show 4 modified files, `plugins/clientes_core/model/core/direccion_cliente.php` (read-only) modified, and `plugins/clientes_core/tests/View/` + the change dir untracked. No test; ~0 lines.
- [x] 0.2 Path-scoped stage (workdir = the plugin repo root): stage exactly `plugins/clientes_core/view/ventas_cliente.html.twig`, `plugins/clientes_core/view/ventas_clientes.html.twig`, `plugins/clientes_core/translations/messages.en.yaml`, `plugins/clientes_core/translations/messages.es.yaml` and `plugins/clientes_core/tests/View/`; never `git add -A`, and never `plugins/clientes_core/model/core/direccion_cliente.php` (read-only). Test: the staged file list equals those five paths; ~0 lines.
- [x] 0.3 Commit the baseline (conventional commit, e.g. `feat(clientes_core): require client group selection in client views`). No test; ~0 lines.
- [x] 0.4 Verify the post-commit baseline: `git status --porcelain` shows only `plugins/clientes_core/model/core/direccion_cliente.php` (read-only) plus the untracked change dir; run `ddev exec php vendor/bin/phpunit -c plugins/clientes_core/phpunit.xml` and record exactly the 7 known errors from VF-3. ~0 lines.
- [x] 0.5 Assert the preserved WIP is untouched: in the plugin repo, `git diff --stat` still reports `plugins/clientes_core/model/core/direccion_cliente.php` (read-only) at 71/42; no test; ~0 lines.

## Slice 1a — Model gate + mandatory-group migration — PR 2

- [x] 1.1 [RED] `plugins/clientes_core/tests/ClienteModelTest.php`: `test()` rejects `codgrupo_descuento` `NULL` and `''`, accepts `'000000'`; `countByGroup()` / `countByDiscountGroup()` counts; `assignOrphanClientsToDiscountGroup()` issues the `codgrupo_descuento IS NULL` UPDATE. Scenarios: `client-discount-inheritance → "Missing discount group fails validation"`, `→ "Explicit discount group selection is accepted"`, `clientes → "Clients without a discount group are backfilled"`. Files: `plugins/clientes_core/tests/ClienteModelTest.php`; ~90 lines.
- [x] 1.2 [RED-ADJUST] Declare `codgrupo_descuento` in the anonymous `cliente` subclass and seed `'000000'` in every test that expects `test()` to pass; without this the new gate flips ~8 pre-existing green tests. Files: `plugins/clientes_core/tests/ClienteModelTest.php`; ~15 lines.
- [x] 1.3 [GREEN] `plugins/clientes_core/model/core/cliente.php`: add the `codgrupo_descuento` NULL/`''` check to `validateFields()` after the `codgrupo` check; add `assignOrphanClientsToDiscountGroup()`, `countByGroup()`, `countByDiscountGroup()`. Leave `getEffectiveDiscounts()`'s NULL branch, `assignOrphanClientsToGroup()` and `fix_db()` untouched. Files: `plugins/clientes_core/model/core/cliente.php`; ~35 lines.
- [x] 1.4 [RED + GUARD] New `plugins/clientes_core/tests/ClienteSchemaTest.php`: assert `plugins/clientes_core/model/table/clientes.xml` (read-only) keeps `<nulo>YES</nulo>` for `codgrupo_descuento`, declares no `<restriccion>`/FK on it, and still declares `ca_clientes_grupos`. Scenarios: `clientes → "Migration adds no constraint"`, `client-discount-inheritance → "DB column stays nullable"`. Files: `plugins/clientes_core/tests/ClienteSchemaTest.php`; ~45 lines.
- [x] 1.5 [RED] Extend `plugins/clientes_core/tests/Fixtures/InitUpgradeFakes.php`: add an `FSFramework\model\grupo_descuentos` fake (`$storedGroups`, `get`, `save`, `table_has_rows`) and add `codgrupo_descuento`, a discount-orphan counter + last-code capture, and the two counters to the `cliente` fake. This is what clears the 6 baseline `InitUpgradeTest` errors (VF-3). Files: `plugins/clientes_core/tests/Fixtures/InitUpgradeFakes.php`; ~50 lines.
- [x] 1.6 [RED] Rewrite `plugins/clientes_core/tests/InitUpgradeTest.php`: point the client-group orphan assertion at `'000001'` (drop `grupo_clientes::$storedGroups['000000']`); add discount backfill, new-flag independence from `clientes_core_discounts_migrated`, re-run no-op, `ensureDefaultDiscountGroup()` creation, and `ensureDefaultClientGroup()` on a populated table lacking `'000001'`. Scenarios: the five `clientes → "Mandatory group backfill on plugin activation"` scenarios plus `clientes → "General group is guaranteed on a populated table"`. Files: `plugins/clientes_core/tests/InitUpgradeTest.php`; ~70 lines.
- [x] 1.7 [GREEN] `plugins/clientes_core/Init.php`: fix `ensureDefaultClientGroup()` to be idempotent by code (remove the `table_has_rows()` early return, R8); add `ensureDefaultDiscountGroup()`; add the flag-gated `runMandatoryGroupBackfill()` using the new `clientes_core_discount_group_required` key; restructure `upgrade()` per design §9.2; delete `assignOrphanClientsToGroup('000000')` from the legacy block; seed `codgrupo_descuento = '000000'`. Files: `plugins/clientes_core/Init.php`; ~75 lines.
- [x] 1.8 [REFACTOR] `Init::upgrade()`: confirm the legacy block no longer writes `codgrupo`, the new flag is independent, and a failure inside the backfill leaves the flag unset without breaking activation. Files: `plugins/clientes_core/Init.php`; test: re-run task 1.6; ~10 lines.

## Slice 1b — Conflation removal, deletion guards, specs, i18n — PR 3

- [x] 1.9 [RED] `plugins/clientes_core/tests/Controller/VentasClienteDiscountsTest.php`: flip T21 to "empty `codgrupo` stays `null`, save fails the group-required validation, no `'000000'` is written". Scenarios: `clientes → "New client without an explicit group fails validation"` and `→ "No code path writes the discount code into codgrupo"`. Files: `plugins/clientes_core/tests/Controller/VentasClienteDiscountsTest.php`; ~30 lines.
- [x] 1.10 [GREEN] `plugins/clientes_core/controller/ventas_cliente.php`: drop the `'000000'` fallback in `save_cliente()` so an empty `codgrupo` assigns `null`. Files: `plugins/clientes_core/controller/ventas_cliente.php`; ~3 lines.
- [x] 1.11 [RED] `plugins/clientes_core/tests/Controller/VentasClientesDispatchTest.php`: `delete_grupo()` refuses when `countByGroup() > 0` (no DELETE issued, one error recorded, HTTP 200) and deletes normally when 0; `nuevo_cliente_pure()` never writes `'000000'`. Scenarios: `discount-groups → "In-use client group cannot be deleted"` and `→ "Unreferenced group can still be deleted"`. Files: `plugins/clientes_core/tests/Controller/VentasClientesDispatchTest.php`; ~45 lines.
- [x] 1.12 [GREEN] `plugins/clientes_core/controller/ventas_clientes.php::delete_grupo()`: add the in-use guard via `countByGroup()` before `$g->delete()`, with the Spanish refusal message from design §10.2. Files: `plugins/clientes_core/controller/ventas_clientes.php`; ~10 lines.
- [x] 1.13 [RED] New `plugins/clientes_core/tests/Controller/DescuentosGrupoDeleteTest.php`: `descuentos_grupo::delete_grupo()` refuses when `countByDiscountGroup() > 0`. Scenarios: `discount-groups → "In-use discount group cannot be deleted"` and `→ "Personalizado cannot be deleted while in use"`. Files: `plugins/clientes_core/tests/Controller/DescuentosGrupoDeleteTest.php`; ~45 lines.
- [x] 1.14 [GREEN] `plugins/clientes_core/controller/descuentos_grupo.php::delete_grupo()`: add the in-use guard via `countByDiscountGroup()` after the `allow_delete` check and before `$grupo->delete()`. Files: `plugins/clientes_core/controller/descuentos_grupo.php`; ~8 lines.
- [x] 1.15 [RED] `plugins/clientes_core/tests/View/ClienteGrupoRequiredViewTest.php`: assert `plugins/clientes_core/view/ventas_clientes.html.twig` keys the protected default on the client group code and contains no `'000000'` in a `codgrupo` comparison. Scenario: `clientes → "The undeletable default marker is the client group code"`. Files: `plugins/clientes_core/tests/View/ClienteGrupoRequiredViewTest.php`; ~12 lines.
- [x] 1.16 [GREEN] `plugins/clientes_core/view/ventas_clientes.html.twig`: change the delete-control marker from `'000000'` to `'000001'`. Files: `plugins/clientes_core/view/ventas_clientes.html.twig`; ~2 lines.
- [x] 1.17 [RED] New `plugins/clientes_core/tests/Translations/GroupKeysParityTest.php`: both locales define `grupo-descuentos`, `grupo-descuentos-obligatorio` and `seleccione-grupo-descuentos` (VF-1). Files: `plugins/clientes_core/tests/Translations/GroupKeysParityTest.php`; ~35 lines.
- [x] 1.18 [GREEN] Add those three new keys to `plugins/clientes_core/translations/messages.en.yaml` and `plugins/clientes_core/translations/messages.es.yaml` with the copy from design §15.6. Do not add `sin-grupo-descuentos` (VF-1). Files: both YAML files; ~8 lines.
- [x] 1.19 Sync the three canonical spec drifts (docs only, no code): `plugins/clientes_core/openspec/specs/clientes/spec.md` ("New client gets default group" + its two scenarios), `plugins/clientes_core/openspec/specs/client-discount-inheritance/spec.md` ("Group is mandatory for all clients" + its three scenarios), `plugins/clientes_core/openspec/specs/discount-groups/spec.md` (header prose + "Personalizado group is created on migration", which still says "or next available"). Files: those three spec files; ~60 lines.

## Slice 2a — Shared PHP authority — PR 4

- [x] 2.1 [RED] New `plugins/clientes_core/tests/ClienteFormAuthorityTest.php`: selective mapping (absent key leaves the field untouched), `''`→`null` for both group keys, no fallback group ever written, `TEXT_FIELDS`/`BOOL_FIELDS`/`NULLABLE_TEXT_FIELDS` semantics, `computeDescuentosModified()` equality/inequality rounded to 2 decimals and `false` with no group, `validate()` delegates to `test()`, `applyAndValidate()` returns the error list. Scenarios: `shared-client-form → "Single shared PHP apply, validate and diff authority"` and `→ "Mandatory-group validation is triggered through test()"`. Files: `plugins/clientes_core/tests/ClienteFormAuthorityTest.php`; ~105 lines.
- [x] 2.2 [RED] Add the VF-2 case to that test: `razonsocial === ''` becomes `nombre` **only when** `property_exists($cliente, 'razonsocial')`; a double without the property is untouched. Explicit behavior change — see VF-2. Files: `plugins/clientes_core/tests/ClienteFormAuthorityTest.php`; ~25 lines.
- [x] 2.3 [GREEN] New `plugins/clientes_core/ClienteForm.php` (`FSFramework\Plugins\clientes_core\ClienteForm`) with `apply()`, `computeDescuentosModified()`, `validate()`, `applyAndValidate()` and the four field constants; `apply()` loads the discount group guarded by `class_exists()` and sets `descuentos_modified`. No plugin-level Composer manifest and no Composer vendor tree. Files: `plugins/clientes_core/ClienteForm.php`; ~100 lines.
- [x] 2.4 [GREEN-INTEGRATION] Delegate both controllers to the authority: `plugins/clientes_core/controller/ventas_cliente.php::save_cliente()` and `plugins/clientes_core/controller/ventas_clientes.php::nuevo_cliente_pure()` call `ClienteForm::apply()`; remove the inline `descuentos_modified` diff. Scenario: `shared-client-form → "Descuentos diff is computed in one place"`. Files: `plugins/clientes_core/controller/ventas_cliente.php`, `plugins/clientes_core/controller/ventas_clientes.php`; test: the existing controller suites stay green; ~55 lines.

## Slice 2b-i — Twig namespace + reusable partials — PR 5

- [x] 2.5 [RED] New `plugins/clientes_core/tests/View/SharedFormHooksRenderTest.php` per design §7.1: real `Twig\Environment` + `FilesystemLoader`, `addPath(plugins/clientes_core/View, 'clientes_core')`, stub `trans`/`csrf_field`/`csp_nonce_attr`/`render_hook` (delegating to `ViewHookRegistry::render()`), register a sentinel hook, render `plugins/clientes_core/View/Cliente/Fields.html.twig` with `{fsc, cliente}`, assert the sentinel and both context keys. Scenario: `shared-client-form → "Main hook renders inside the shared partial"` (R5). Files: `plugins/clientes_core/tests/View/SharedFormHooksRenderTest.php`; ~80 lines.
- [x] 2.6 [RED] New `plugins/clientes_core/tests/View/SharedFormRepeatIncludeTest.php`: render the partial twice with different `id_prefix` values; every DOM id is unique and the Alpine registration marker appears exactly once. Scenarios: `shared-client-form → "Repeated inclusion does not collide"` and `→ "Scripts remain CSP-compatible"`. Files: `plugins/clientes_core/tests/View/SharedFormRepeatIncludeTest.php`; ~45 lines.
- [x] 2.7 [GREEN] `plugins/clientes_core/Init.php`: add a `TwigLoaderEvent` listener calling `addPath(__DIR__ . '/View', 'clientes_core')`, with the `FilesystemLoader` import, following `plugins/clientes_catalogo/Init.php` (read-only) as precedent. Scenario: `shared-client-form → "Twig namespace is registered"`. Files: `plugins/clientes_core/Init.php`; ~15 lines.
- [x] 2.8 [GREEN] New `plugins/clientes_core/View/Cliente/Discounts.html.twig`: mandatory discount-group select (`required`, no empty option), D1–D4 inputs, reset affordance, `id_prefix`-derived ids, `input_class`. Files: that template; ~55 lines.
- [x] 2.9 [GREEN] New `plugins/clientes_core/View/Cliente/Fields.html.twig`: identity field set, the `cliente_form_after_main` hook with context `{fsc, cliente}`, `x-model` on both selects, `required` on both, and the nonce'd idempotent Alpine script from design §6.2 extended to both groups. **No address editor** (Q5). Files: that template; ~130 lines.

## Slice 2b-ii — Form wrapper + consumer wiring — PR 6

- [x] 2.10 [GREEN] New `plugins/clientes_core/View/Cliente/Form.html.twig`: owns the `<form>` (with `x-data="clienteGrupoRequired"` on the consumer side), `csrf_field()`, hidden `action`/`codcliente`, includes `Fields` + `Discounts`, the action row, `allow_delete`, `submit_mode`, `show_grupos_warning`. Files: that template; ~80 lines. **Correction (2026-09-23)**: implemented as the form *body* — the consumer keeps the owning `<form>`, its `action` and its id. Design §5.1's correction note records why "owns the `<form>`" is impossible here.
- [x] 2.11 [GREEN] Wire `plugins/clientes_core/view/ventas_cliente.html.twig`: include `plugins/clientes_core/View/Cliente/Form.html.twig` with `mode='edit'`, `action_name='save_cliente'`, `id_prefix='edit_cliente'`; keep the consumer's address modal and the `cliente_direccion_form_after_codpais` hook (read-only position, Q5). Files: `plugins/clientes_core/view/ventas_cliente.html.twig`; ~70 lines.
- [x] 2.12 [GREEN] Wire the `ventas_clientes` create modal to the shared `Form` with `mode='create'`, `action_name='nuevo_cliente'`, `id_prefix='nuevo_cliente'`, `show_discounts=true` (design §5.2), and `x-data="clienteGrupoRequired"` on the modal form. Files: `plugins/clientes_core/view/ventas_clientes.html.twig`; ~70 lines.
- [x] 2.13 [RED + GREEN] Rewrite `plugins/clientes_core/tests/View/ClienteGrupoRequiredViewTest.php` against the new structure: both consumer views include `@clientes_core/Cliente/Form.html.twig`; the partial namespaces ids by `id_prefix`; both selects carry `required`; the Alpine marker is idempotent; no `trans('sin-grupo')` remains; the partial renders no address editor. Scenarios: `shared-client-form → "Both clientes_core surfaces render the shared partial"`, `→ "Create mode renders an empty entity against the create action"`, `→ "Edit mode renders the persisted entity against the edit action"`, `→ "Shared partial has no address editor"`, `→ "clientes_core keeps its own address panel"`. Files: `plugins/clientes_core/tests/View/ClienteGrupoRequiredViewTest.php`; ~65 lines.
- [x] 2.14 [REFACTOR] Confirm no hard-coded `#modal_cliente_form`, `#f_cliente_tpv`, `#form_edit_cliente` or `#btn_reset_descuentos` remains inside the partial, and that no consumer view keeps a duplicate field set (R7). Files: the three partials under `plugins/clientes_core/View/Cliente/` plus `plugins/clientes_core/view/ventas_cliente.html.twig` and `plugins/clientes_core/view/ventas_clientes.html.twig`; test: task 2.13; ~15 lines.
- [x] 2.15 Full-suite gate for this repo: `ddev exec php vendor/bin/phpunit -c plugins/clientes_core/phpunit.xml` plus `ddev exec php vendor/bin/phpunit --testsuite Plugins`; confirm no new failures beyond the VF-3 baseline. No file changes; ~0 lines.

## Slice 3 — `tpvmod` consumer (EXTERNAL, referenced change) — PR 7

**Owned by the `tpvmod` repository and its own SDD home. Nothing here is implemented inside this change, and no `tpvmod` artifact is copied into `plugins/clientes_core/openspec/`.** The paths below are contract references only.

- [ ] 3.1 Record the contract, not the work: `plugins/tpvmod/lib/tpvmod_cliente.php` (read-only) must delegate to `FSFramework\Plugins\clientes_core\ClienteForm::apply()`, delete the `'000000'` fallback, and delete `tpvmod_cliente_sync_descuentos_modified_flag()`.
- [ ] 3.2 Preserved response contract: `plugins/tpvmod/lib/tpvmod_cliente_ajax.php` (read-only) keeps `{ ok, codcliente, label, cliente }` for `tpvmodSeleccionarCliente()` / `recalcular()`. Scenario: `shared-client-form → "TPV keeps its AJAX response contract"`.
- [ ] 3.3 Twig consumption: `plugins/tpvmod/view/ajax/tpv_cliente_form.html.twig` (read-only) renders `Fields` + `Discounts` inside its own `<form id="f_cliente_tpv">` with `ignore_missing=true`, `id_prefix='tpv_cliente'` and a degraded-state alert on miss (design §11.2/§11.3). Scenario: `shared-client-form → "TPV renders the shared partial"`.
- [ ] 3.4 Cross-repo guard: `plugins/tpvmod/fsframework.ini` (read-only) gains `clientes_core` in its direct `require` list; release `clientes_core` and `tpvmod` together (R2).
- [ ] 3.5 Verification command owned by that repo (present in this checkout): `ddev exec php vendor/bin/phpunit -c plugins/tpvmod/phpunit.xml` (read-only). It is the acceptance gate for PR 7 and is run from the `tpvmod` change, not from this one.
- [ ] 3.6 Cross-check before releasing `clientes_core`: `plugins/tpvmod/fsframework.ini` (read-only) must declare `clientes_core` **before** PR 6 ships, otherwise a `tpvmod` install can render a partial that does not exist yet.

## OidcProvider consumer (discovered during the Slice 1a apply)

The model gate in task 1.3 (`cliente::test()` rejects `codgrupo_descuento` NULL/`''`) broke an **unaccounted consumer** the design did not enumerate: `plugins/OidcProvider` went from **7 → 20 failures** because it persists `cliente` rows without a discount group.

- **Corrective (done — `OidcProvider` repo, its own PR)**: `Service/ClienteDiscountGroupDefaults::applyDefault()` assigns `'000000'` "Personalizado" only when the property is null/blank, wired at every `cliente::save()` call site. The suite returns to its **7-failure baseline**; the 13 gate-caused failures are gone. The `clientes_core` gate was **not** relaxed.
- **Cross-repo ordering (R2)**: the `clientes_core` slice-1a PR and the `OidcProvider` consumer PR **must land together** — the gate breaks the consumer until it lands.
- **Follow-up (NOT in scope)**: a pre-existing `migration011` bug hardcodes `utf8mb3` while the referenced tables are `utf8mb4`, so MySQL 8 rejects the FK and `oidc_cliente_grupos` is never created. This is the root cause of the residual `migration011_*` / `OidcSchemaContractTest` / `OidcLegacySchemaParityTest` failures and deserves its own change.

## Traceability — every delta requirement has a task pair

| Delta requirement | RED task | GREEN task |
|---|---|---|
| `shared-client-form` → Shared client form partial | 2.5, 2.13 | 2.7, 2.10, 2.11, 2.12 |
| `shared-client-form` → Form parameterization for modal and page modes | 2.6, 2.13 | 2.8, 2.9, 2.10 |
| `shared-client-form` → Client form extension hooks remain rendered | 2.5, 2.13 | 2.9 |
| `shared-client-form` → Single shared PHP apply, validate and diff authority | 2.1, 2.2 | 2.3, 2.4 |
| `shared-client-form` → Addresses are excluded from the shared component | 2.13 | 2.9, 2.11 |
| `shared-client-form` → TPV consumer contract | 3.1, 3.3 | 3.1 (external repo) |
| `clientes` → New client gets default group | 1.9, 1.15 | 1.10, 1.16 |
| `clientes` → Mandatory group backfill on plugin activation | 1.5, 1.6 | 1.7, 1.8 |
| `client-discount-inheritance` → Group is mandatory for all clients | 1.1, 1.4 | 1.3 |
| `discount-groups` → Default "Personalizado" group | 1.6, 1.13 | 1.7, 1.14 |
| `discount-groups` → In-use group deletion is blocked | 1.11, 1.13 | 1.12, 1.14 |

## Definition of done

- [x] Slices 0–2 complete; slice 3's contract recorded and its release coordinated, not implemented here.
- [x] `ddev exec php vendor/bin/phpunit -c plugins/clientes_core/phpunit.xml` has no new failures beyond the VF-3 baseline.
- [x] No `'000000'` is written into `codgrupo` anywhere under `plugins/clientes_core/` (grep audit, design §13).
- [x] `plugins/clientes_core/model/table/clientes.xml` (read-only) is byte-identical (`git diff --exit-code`).
- [x] No file under the core directories — base, src, root model, root controller (all read-only) — was modified; no artifact exists under the core OpenSpec store.
- [x] No plugin-level Composer manifest was added and no Composer vendor tree exists under the plugin: the authority autoloads from the root PSR-4 map.
- [ ] `ddev exec composer phpstan` passes for the touched surface. — **NOT MET (verified 2026-09-23)**: `phpstan.neon` analyses only `src/` and the root `tests/`, so plugin code is never analysed, and the run is RED on a pre-existing core failure (`tests/Core/PluginEnableAjaxSafetyTest.php:308`, core commit `14a4c7b7`). Both causes lie outside this change; recorded as a documented limitation, not a blocker.
