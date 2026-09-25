# Proposal: Shared client form + mandatory client group and discount group

- **Change**: `cliente-form-compartido-grupos-obligatorios`
- **SDD home**: `plugins/clientes_core/openspec/` (plugin-local, `ownership: plugin-local`)
- **Artifact store**: openspec (files)
- **Status**: proposal — ready for spec/design
- **Date**: 2026-09-22
- **Inputs**: `exploration.md` (2026-09-22), `decisions.md` (CONFIRMED by product owner)

## Intent

1. Make the **client group** (`codgrupo`) mandatory with explicit user selection.
2. Make the **discount group** (`codgrupo_descuento`) **also** mandatory with explicit user selection.
3. Unify `clientes_core`'s create ("nuevo cliente") modal and edit form into **ONE reusable
   implementation owned by `clientes_core`**, reused via Twig by `clientes_core`'s pages and by `tpvmod`.
4. Fix the **"Personalizado" conflation bug** — a discount-group code `'000000'` written into the
   client-group column `codgrupo` — across `Init.php`, `controller/ventas_cliente.php`,
   `tpvmod/lib/tpvmod_cliente.php`, plus the spec/test drift that encodes it.

The duplication is the root problem: three field-mapping paths and two discount-diff implementations
drift, and the untracked Alpine WIP proves they already diverge. The conflation is the correctness bug.

## Confirmed decisions (do not reopen)

| ID | Decision |
|----|----------|
| Q1 | Orphan clients (`codgrupo` NULL) get **`000001` "General"**; fix `ensureDefaultClientGroup()` early-return so the default is guaranteed. |
| Q2 | Clients with `codgrupo_descuento` NULL get **`000000` "Personalizado"** (d1–d4 = 0.00). |
| Q3 | `codgrupo_descuento` stays **NULL at DB level**; the mandatory rule lives in `cliente::test()` (no FK, no `NOT NULL`). **This neutralises R1.** |
| Q4 | **Block deletion of in-use groups.** |
| Q5 | **Addresses OUT** of the shared component; each consumer keeps its own address editor. |
| Q6 | `FSDK` demo data + `ventas_clientes_opciones` **OUT of scope** (follow-up only). |
| R  | `sdd-research` lane **not selected**; proceed directly. |

## Scope

### In Scope

- `plugins/clientes_core/**` (owner): `Init.php`, `model/core/cliente.php`, `model/table/clientes.xml`
  (no new FK/NOT NULL), `controller/ventas_cliente.php`, `controller/ventas_clientes.php`,
  both views, translations, plugin specs, plugin tests.
- New shared component owned by `clientes_core`: a namespaced Twig form partial plus a shared PHP
  apply/validate/diff authority.
- `plugins/tpvmod/**` as an explicitly-named **consumer slice**, implemented and verified in its own
  repo and its own SDD home.

### Out of Scope

- Addresses unification (Q5) — each consumer keeps its own address editor.
- `FSDK` demo-data generator and `ventas_clientes_opciones` (Q6) — documented follow-up only.
- New shared `clientes_core` AJAX endpoint (exploration §3.3.2) and Alpine-driven TPV saves (§3.3.4).
- Theme-level shared partial (exploration §3.1.C).
- `NOT NULL`/FK on `codgrupo_descuento` (excluded by Q3).
- **Any core change** (`base/`, `src/`, root `model/`, root `controller/`). If design proves one is
  required, STOP and report — the SDD home moves to core `openspec/`.

## Approach

Follow exploration §3 and §4, with the confirmed decisions applied:

- **Shared component lives in `clientes_core` via a namespaced Twig path** (Option 3.1.A):
  `clientes_core/Init.php` listens to `TwigLoaderEvent` and `addPath(__DIR__.'/View', 'clientes_core')`.
  Consumers `{% include '@clientes_core/Cliente/Form.html.twig' %}`. No core change; exact precedent:
  `clientes_catalogo/Init.php:31-36`.
- **View hooks stay** as extension slots *inside* the partial (`render_hook('cliente_form_after_main')`,
  `cliente_direccion_form_after_codpais`) — complementary to A, not a replacement (R5).
- **`tpvmod` keeps its AJAX endpoint and JSON shape** (Option 3.3.1): the TPV renders the shared
  partial and delegates save/validate/diff to a shared PHP authority owned by `clientes_core`.
  Preserved contract: `{ ok, codcliente, label, cliente }` consumed by `tpvmodSeleccionarCliente()`
  and `recalcular()`. The `'000000'`-into-`codgrupo` fallback is deleted, not wrapped.
- **Single PHP authority** for (1) POST → `cliente` field mapping, (2) the `descuentos_modified`
  diff vs the selected discount group, (3) triggering mandatory-group validation via `cliente::test()`.
  All three current inline copies collapse onto it.
- **Migration is data-only, run inside `Init::upgrade()`**, gated by a NEW idempotency flag
  (`clientes_core_discount_group_required`; reusing `clientes_core_discounts_migrated` would be a
  permanent no-op). Steps: guarantee `000001` "General" exists (fixed `ensureDefaultClientGroup()`);
  guarantee `000000` "Personalizado" exists; `UPDATE clientes SET codgrupo='000001' WHERE codgrupo IS NULL`;
  `UPDATE clientes SET codgrupo_descuento='000000' WHERE codgrupo_descuento IS NULL`.
- **Q4 deletion guard**: `delete_grupo()` refuses when any `clientes` row references the group.
  Because the `ca_clientes_grupos` FK is `ON DELETE SET NULL`, the guard is the invariant protection
  for `codgrupo`; for `codgrupo_descuento` (no FK) it prevents dangling references.

## Capabilities

### New Capabilities

- `shared-client-form`: the `@clientes_core` Twig partial, its modal-vs-page parameterization,
  its Alpine/data wiring, and the shared PHP apply/validate/diff authority consumed by both plugins.

### Modified Capabilities

- `clientes`: "New client gets default group" — default is the **client** group `000001` "General";
  the `000000` mapping to `codgrupo` is removed.
- `client-discount-inheritance`: "Group is mandatory for all clients" — the two group concepts are
  separated; scenarios must use `codgrupo` for client groups and `codgrupo_descuento` for discount
  groups; discount group becomes mandatory via `test()`.
- `discount-groups`: "Default Personalizado group" — `000000` is the **discount** default only;
  in-use groups cannot be deleted.

`tpvmod` has its own SDD home; this change records only the consumer contract for it.

## Delivery Slices

Forecast against the **800 changed-line session budget** (author additions + deletions).

| # | Slice | Owner | Forecast | Budget risk |
|---|-------|-------|----------|-------------|
| 1 | Conflation fix + mandatory-group data layer (`Init.php`, `model/core/cliente.php`, `model/table/clientes.xml` decision, controllers, `ventas_clientes.html.twig:204`, translations, 3 specs, tests) | `clientes_core` | ~450–550 | Med (over 400 alone) |
| 2 | Shared form component (`Init.php` Twig path, new `View/` partials, JS, shared PHP authority, wire `ventas_cliente.html.twig` + create modal, `tests/View/`) | `clientes_core` | ~500–650 | High |
| 3 | `tpvmod` consumer migration: render `@clientes_core`, reduce/delete its form partial + save JS, delegate PHP, drop `000000` fallback, `fsframework.ini` direct `require` | `tpvmod` (own repo, own SDD) | ~350–450 | Med |

Combined ~1300–1650 → **exceeds the 800 budget**; sequential chained slices required.

Guard lines: `Decision needed before apply: Yes` · `Chained PRs recommended: Yes` · `400-line budget risk: High`.

## Risks

| Risk | Likelihood | Mitigation |
|------|-----------|------------|
| **R1** schema-sync ordering vs backfill (`PluginSchemaSynchronizer` syncs constraints before `Init::upgrade()`) | High | **Neutralised by Q3.** `clientes.xml` gains no FK and no `NOT NULL`; migration is data-only UPDATEs inside `Init::upgrade()`; the existing `ca_clientes_grupos` FK is untouched. Design must explicitly verify no new constraint is introduced. |
| **R2** cross-repo coupling has no version guard (`require` is a bare name list; `min_version` compares to core only) | High | Add `clientes_core` as a **direct** `require` in `tpvmod/fsframework.ini`; release both plugins together with an operator note; guard the shared include with a Twig namespaced-path availability probe and keep a minimal fallback. |
| **R3** two independent SDD homes | Med | `tpvmod` slice is named here but implemented in its own repo/`openspec/`; verified via `ddev exec php vendor/bin/phpunit -c plugins/tpvmod/phpunit.xml`. No `tpvmod` artifact is copied into `clientes_core/openspec/`; nothing is created under core `openspec/`. |
| **R4** dirty `clientes_core` baseline | High | Commit or fold the uncommitted Alpine edits (and add untracked `tests/View/ClienteGrupoRequiredViewTest.php`) before apply. **Preserve the unrelated `model/core/direccion_cliente.php` WIP untouched.** |
| **R5** `clientes_catalogo` depends on `cliente_form_after_main` / `cliente_direccion_form_after_codpais` | High | Keep both hooks rendering inside the partial with context `{fsc, cliente}`; add a regression assertion. `ViewHookRegistry` swallows errors, so a silent drop is the failure mode. |
| **R6** `stealth_mode` strict CSP | Med | Nonce'd classic script + idempotent `window.__*Registered` marker; no `unsafe-eval`; views can be included multiple times. |
| **R7** DOM id collisions | Med | Parameterize ids via context (`id_prefix`); no hard-coded `#modal_cliente_form` / `#f_cliente_tpv`. |
| **R8** `ensureDefaultClientGroup()` early-return bug | Med | Q1 fix: guarantee `000001` "General" even when the table is non-empty; explicit test. |
| **R9** `clientes_core` has no PHP service layer / no `composer.json` | Med | Resolve in `sdd-design` (see open questions); prefer no new `vendor/`. If Composer is added, `tasks.md` must commit `vendor/` per the plugin dependency rule. |
| **R10** active core changes overlap (`view-hook-registry-core`, `ventas-clientes-controller-dedup`, `ventas-clientes-dispatch-regression-test`) | Med | Confirm `view-hook-registry-core` state before making hook behaviour load-bearing; avoid depending on new hook features. |

## Open Design Questions (for `sdd-design`)

- Exact context keys for modal-vs-page parameterization: `mode`/`embedded`, `action_url`,
  `submit_mode` (`page-redirect` | `ajax-json`), `allow_delete`, `readonly_code`, `id_prefix`.
- Where the shared PHP authority lives, given no `src/` service layer and no `composer.json`:
  plain function file require'd by `Init.php`, a namespaced static helper class, or a new PSR-4 setup.
- Granularity of the shared partial (one `Form.html.twig` vs sub-partials) and how the
  `ventas_clientes` create modal reuses it under `mode=create`.
- How `tpvmod`'s PHP wrapper delegates to the authority while preserving the JSON shape.
- Idempotency details of the new discount backfill flag and the exact "General"/"Personalizado"
  ensure steps.
- Deletion-block semantics: which group types are checked and the exact refusal message/behavior.

## Affected Areas

| Area | Impact | Description |
|------|--------|-------------|
| `plugins/clientes_core/Init.php` | Modified | Conflation fix, discount backfill + new flag, `ensureDefaultClientGroup()` fix, Twig path, hook registration |
| `plugins/clientes_core/model/core/cliente.php` | Modified | `test()` requires `codgrupo_descuento`; orphan/discount helpers; `getEffectiveDiscounts()` NULL branch |
| `plugins/clientes_core/model/table/clientes.xml` | Reviewed | No new FK / no `NOT NULL` (Q3) — decision recorded, not changed |
| `plugins/clientes_core/controller/ventas_cliente.php` | Modified | Drop `'000000'` fallback; delegate mapping/diff to shared authority |
| `plugins/clientes_core/controller/ventas_clientes.php` | Modified | Drop `'000000'` fallback in `nuevo_cliente_pure()`; `delete_grupo()` in-use guard |
| `plugins/clientes_core/view/ventas_cliente.html.twig` | Modified | Render shared component; keep hooks |
| `plugins/clientes_core/view/ventas_clientes.html.twig` | Modified | Create modal renders shared component; fix `'000000'` undeletable read |
| `plugins/clientes_core/View/**` (new) | New | Namespaced shared form partial(s) |
| `plugins/clientes_core/translations/messages.{en,es}.yaml` | Modified | Group-required keys, verify `sin-grupo-descuentos` |
| `plugins/clientes_core/openspec/specs/{clientes,client-discount-inheritance,discount-groups}/spec.md` | Modified | Conflation/spec drift correction |
| `plugins/clientes_core/tests/**` | Modified | `InitUpgradeTest`, `InitUpgradeFakes`, `VentasClienteDiscountsTest`, `tests/View/` |
| `plugins/tpvmod/**` (separate repo) | Modified | Consumer slice (slice 3); own SDD home |

## Rollback Plan

- Each slice is independently revertable by reverting its commit(s); slices are sequential, not entangled.
- Slice 1 is the highest-risk: the migration is data-only and additive. Rollback = revert the code;
  the `000001`/`000000` group rows are harmless if left, and the backfilled FK-valid values remain
  valid. No schema change to roll back because Q3 forbids `NOT NULL`/FK.
- Slice 2: keep the current `ventas_cliente.html.twig` content until the shared partial renders
  equivalently; revert restores the prior view. Consumers fall back to their pre-change form.
- Slice 3: `tpvmod` reverts to its prior partial/JS; the cross-repo `require` addition is safe to keep.
- Never force-push a released plugin; a defect after release gets a follow-up patch release.

## Dependencies

- `clientes_core` is the owner and the only writer of the shared component.
- `tpvmod` must declare a direct `clientes_core` requirement (name-list only — R2).
- `clientes_catalogo` hook compatibility (R5).
- Confirmation of the active core change `view-hook-registry-core` (R10).

## Success Criteria

- [ ] `codgrupo` is never written as `'000000'` anywhere in `clientes_core` or `tpvmod`.
- [ ] A save with empty `codgrupo` fails validation; a save with empty `codgrupo_descuento` fails `test()`.
- [ ] After migration, no `clientes` row has `codgrupo IS NULL` or `codgrupo_descuento IS NULL`
      (verified on a populated install), and `000001` "General" always exists.
- [ ] `clientes_core`'s create modal and edit form render from ONE shared template, and their
      field-mapping/diff logic lives in ONE shared PHP authority.
- [ ] `tpvmod` renders the same shared partial, keeps its AJAX endpoint and `{ok,codcliente,label,cliente}`
      response, and its suite passes: `ddev exec php vendor/bin/phpunit -c plugins/tpvmod/phpunit.xml`.
- [ ] In-use groups cannot be deleted via `delete_grupo()`.
- [ ] `clientes_catalogo` extension hooks still render on the edit page.
- [ ] `ddev exec php vendor/bin/phpunit -c plugins/clientes_core/phpunit.xml` passes; plugin specs match
      the new behavior (no `'000000'`-in-`codgrupo` assertions).
- [ ] No core file changed and no artifact exists under root `openspec/` for this change.

## Non-Goals

- Unifying address editors (Q5).
- `FSDK` demo data and `ventas_clientes_opciones` (Q6) — follow-up only.
- New cross-plugin AJAX endpoint, Alpine-driven TPV saves, theme-level partial.
- `NOT NULL`/FK on `codgrupo_descuento` (Q3).
- Any core (`base/`, `src/`, root `model/`, root `controller/`) change.

## Baseline Note (R4)

`plugins/clientes_core` is on `main`, **dirty**: uncommitted Alpine mandatory-client-group edits in
both views, three new translation keys per locale, untracked `tests/View/ClienteGrupoRequiredViewTest.php`,
and an **unrelated** transactional rewrite in `model/core/direccion_cliente.php`. That unrelated WIP
**must be preserved** and must not be folded into this change. Apply must start from a known baseline:
commit or explicitly fold the Alpine edits (and the untracked test) first.

## TPV Consumer Slice (R3)

Slice 3 is a **separate change** implemented in the `tpvmod` repo, with its own `openspec/` home and
its own verification (`ddev exec php vendor/bin/phpunit -c plugins/tpvmod/phpunit.xml`). This proposal
defines the contract only. No `tpvmod` artifact is duplicated into `plugins/clientes_core/openspec/`,
and no entry is created under the core `openspec/`.
