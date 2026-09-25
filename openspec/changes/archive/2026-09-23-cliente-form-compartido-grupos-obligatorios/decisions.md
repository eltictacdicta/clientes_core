# Confirmed decisions — `cliente-form-compartido-grupos-obligatorios`

- **Status**: CONFIRMED by the product owner (2026-09-22). Pre-proposal handoff for `sdd-propose`.
- **Artifact store**: openspec (files), plugin-local SDD in `plugins/clientes_core/openspec/`.
- **Source**: `exploration.md` §5 (Q1–Q6). Research lane: **unselected**.

## Confirmed product decisions (proposer MUST NOT re-interview or infer consent)

| ID | Decision | Confirmed value |
|----|----------|-----------------|
| R  | `sdd-research` lane | **Not selected** — proceed directly to proposal |
| Q1 | Default **client** group for orphan clients (`codgrupo` NULL) | **`000001` "General"** (fix `ensureDefaultClientGroup()` early-return so it is guaranteed) |
| Q2 | Default **discount** group for clients with `codgrupo_descuento` NULL | **`000000` "Personalizado"** (d1–d4 = 0.00) |
| Q3 | DB-level enforcement on `codgrupo_descuento` | **Keep column NULL**; invariant enforced in `cliente::test()` (no FK, no `NOT NULL`) |
| Q4 | In-use group deletion | **Block deletion when a group is in use** |
| Q5 | Scope of the shared component | **Addresses OUT** — the shared component covers the client form only; each consumer keeps its own address editor |
| Q6 | `FSDK` demo data + `ventas_clientes_opciones` | **OUT of scope** — documented as follow-up only |

## Non-negotiable constraints carried into proposal/design

- **R1** (highest risk): `PluginSchemaSynchronizer::synchronize()` syncs XML tables/constraints
  **before** `Init::upgrade()`. Design must not rely on a constraint the on-disk data contradicts.
  Given Q3 (no FK / no NOT NULL), R1 is largely neutralised for `codgrupo_descuento` — confirm this
  explicitly in the proposal.
- **R2**: `fsframework.ini` `require` is a bare name list; no version guard. Cross-repo releases of
  `clientes_core` and `tpvmod` must be coordinated.
- **R3**: two independent SDD homes — SDD lives in `clientes_core`; the `tpvmod` consumer slice is
  implemented and verified in the `tpvmod` repo (`ddev exec php vendor/bin/phpunit -c plugins/tpvmod/phpunit.xml`).
- **R4**: dirty `clientes_core` baseline (uncommitted Alpine edits, unrelated `direccion_cliente.php` WIP).
- **R5**: `clientes_catalogo` depends on `cliente_form_after_main` / `cliente_direccion_form_after_codpais`.
- **R10**: active core changes `view-hook-registry-core`, `ventas-clientes-controller-dedup`,
  `ventas-clientes-dispatch-regression-test` overlap this surface.

## Delivery shape agreed for planning

Three separately reviewable slices, all owned by `clientes_core` (slice 3 in `tpvmod`):

1. Conflation fix + mandatory-group data layer (model, `Init`, XML decision, specs, tests).
2. Shared form component in `clientes_core` + wire its own pages to it.
3. `tpvmod` consumer migration (separate repo).

Review budget for the session: **800 changed lines**.
