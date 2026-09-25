# Exploration: Shared client form + mandatory client group and discount group

- **Change**: `cliente-form-compartido-grupos-obligatorios`
- **SDD home**: `plugins/clientes_core/openspec/` (plugin-local, `ownership: plugin-local`)
- **Artifact store**: openspec (files)
- **Status**: exploration only — no code, no specs, no tasks
- **Date**: 2026-09-22
- **Filename note**: the `sdd-explore` skill's OpenSpec convention names this artifact
  `exploration.md` (not `explore.md`). This file uses the convention name.

---

## 1. Current state

### 1.1 Two divergent client form implementations

| Aspect | `clientes_core` (owner) | `tpvmod` (consumer) |
|---|---|---|
| Edit form | `view/ventas_cliente.html.twig` (full page, 540 lines) | `view/ajax/tpv_cliente_form.html.twig` (tabbed AJAX partial, 267 lines) |
| Create form | inline modal inside `view/ventas_clientes.html.twig` (lines 255-339) | same AJAX partial, `es_nuevo` branch |
| Submit path | classic POST to `index.php?page=ventas_cliente` / `ventas_clientes` | AJAX `POST index.php?page=tpvmod` handled by `lib/tpvmod_cliente_ajax.php` |
| Field mapping | `controller/ventas_cliente.php::save_cliente()` + `controller/ventas_clientes.php::nuevo_cliente_pure()` | `lib/tpvmod_cliente.php::tpvmod_cliente_apply_from_post()` |
| Discount-group diff logic | inlined in `ventas_cliente.php:151-166` | inlined in `tpvmod_cliente.php:118-141` (`tpvmod_cliente_sync_descuentos_modified_flag`) |
| JS | none beyond Alpine `clienteGrupoRequired` | `view/js/tpvmod-cliente.js` (285 lines, jQuery + `$.ajax`) |
| Tabs | no tabs; single panel + separate addresses panel | 3 tabs: Datos / Descuentos / Direcciones |
| Addresses | separate panel with modal `#modal_nueva_dir` (structured form) | inline 5-column mini-form + edit/delete buttons, `window.tpvDireccionesData` JSON blob |
| Alpine | `x-data="clienteGrupoRequired"` (CSP build, nonce'd registration) | none |

`tpvmod` is included in 6 TPV views via `{% include 'partials/modal_clientes.html.twig' %}`
(`tpvmod.html.twig`, `tpvmod2.html.twig`, `tpvmodedita.html.twig`, `tpvmod_albaranes.html.twig`,
`tpvmod_pedidos.html.twig`, `tpvmod_facturas.html.twig`, `tpvmod_presupuestos.html.twig`).

Existing reusable surfaces in the framework (verified, working):

- **Twig namespaced paths**: `Html::addPluginViewPaths()` (`src/Core/Html.php:193-217`) already
  registers every plugin's `view/` and `View/` dirs under the plugin name, and
  `TwigLoaderEvent` (`src/Event/TwigLoaderEvent.php`) lets a plugin `$loader->addPath(__DIR__.'/View', 'ns')`.
  Working example: `clientes_catalogo/Init.php:31-36` → `@clientes_catalogo/...`.
- **View hooks**: `FSFramework\View\ViewHookRegistry` (`src/View/ViewHookRegistry.php`) +
  `render_hook(hook, ctx)` Twig function (`src/Core/Html.php:417-419`). `clientes_catalogo/Init.php:44-53`
  registers `cliente_form_after_main` and `cliente_direccion_form_after_codpais`; `ventas_cliente.html.twig`
  renders them at lines 217 and 464.
- **Alpine CSP build**: `themes/AdminLTE/view/Macro/Alpine.html.twig` (`alpine.boot()`, nonce'd, deferred,
  no `unsafe-eval`). Any shared component must use `Alpine.data()` registration from a nonce'd classic
  script and the idempotent `window.__*Registered` marker pattern (see `ventas_cliente.html.twig:510-538`).

### 1.2 Group cardinality today

| Slot | Column | FK entity | Today |
|---|---|---|---|
| Client group | `clientes.codgrupo` varchar(6) | `gruposclientes.codgrupo` | `test()` already rejects NULL/'' (`cliente.php:541-544`); create modal + edit form already require it via Alpine — **but only in `clientes_core`, and only in uncommitted working-tree edits** |
| Discount group | `clientes.codgrupo_descuento` varchar(6) | `gruposdescuentos.codgrupo_descuento` | Optional. No FK in `model/table/clientes.xml`. Forms offer an empty option and both save paths null it (`ventas_cliente.php:149`, `tpvmod_cliente.php:107-110`, `tpvmod_cliente_ajax` via `apply_from_post`) |

`clientes.xml` declares only one FK: `ca_clientes_grupos` on `codgrupo → gruposclientes(codgrupo)`
with `ON DELETE SET NULL ON UPDATE CASCADE`. There is **no** FK on `codgrupo_descuento`.
`fs_schema` does manage FK create/drop and column sync (`base/fs_schema.php:617-750`,
`compare_constraints` in `base/fs_mysql.php:209+`), and `PluginSchemaSynchronizer` runs
`Init::upgrade()` **after** table sync on every activation/update
(`src/Core/Plugin/PluginSchemaSynchronizer.php:39-52,57-93`).

### 1.3 The "Personalizado" conflation

`Init::upgrade()` (`Init.php:91-114`, flag-gated by `clientes_core_discounts_migrated`) creates:

- a **discount** group `grupo_descuentos` with `codgrupo_descuento = '000000'`, `nombre = 'Personalizado'` (`Init.php:99-104`);
- then calls `$cliente->assignOrphanClientsToGroup('000000')` (`Init.php:107`), which runs
  `UPDATE clientes SET codgrupo = '000000' WHERE codgrupo IS NULL` (`cliente.php:596-603`).

That code is wrong: `'000000'` is a **discount**-group code, and no `gruposclientes` row with code
`'000000'` exists anywhere. `ensureDefaultClientGroup()` creates `codgrupo = '000001'`, `nombre = 'General'`
(`Init.php:193-204`).

Other writers of `'000000'` into the **client**-group column:

- `controller/ventas_cliente.php:137` — `$this->cliente->codgrupo = !empty($codgrupo) ? $codgrupo : '000000';`
- `plugins/tpvmod/lib/tpvmod_cliente.php:81` — same fallback for the TPV save path.
- Read-side orphan-view: `view/ventas_clientes.html.twig:204` treats `'000000'` as undeletable.

Why this produces NULLs: `cliente::test()` would reject `'000000'`… but the client-group FK
`ca_clientes_grupos` is `ON DELETE SET NULL`, and `cliente::fix_db()` (`cliente.php:892-898`)
`UPDATE clientes SET codgrupo = NULL WHERE codgrupo NOT IN (SELECT codgrupo FROM gruposclientes)`.
`fix_db()` has **no caller anywhere** in the repo today (verified by grep), so the NULLs come from
the FK `ON DELETE SET NULL` path when a referenced group row is removed and from any client row that
predates the FK. Either way, the persisted row can hold a client group that violates the intended
invariant while `test()` passes at write time.

Spec/test drift encoding the conflation:

- `openspec/specs/clientes/spec.md` "New client gets default group" (lines 303-321) — says the
  controller assigns the "Personalizado" group to `codgrupo`.
- `openspec/specs/client-discount-inheritance/spec.md` "Group is mandatory for all clients"
  (lines 106-132) — scenarios explicitly use `codgrupo = '000000'` for the discount default.
- `openspec/specs/discount-groups/spec.md` lines 21-24 and 101-119 — "Personalizado (code `000000`)
  … assigned to all existing clients without a group"; scenario "the group code is deterministic
  (e.g., '000000' or next available)".
- `tests/InitUpgradeTest.php:333-367` asserts `grupo_clientes::$storedGroups['000000']` exists and
  `assignOrphanCodgrupo === '000000'`.
- `tests/Controller/VentasClienteDiscountsTest.php:418` mirrors the fallback `'000000'`; T21 at
  lines 835-887 asserts `assertSame('000000', $controller->cliente->codgrupo)`.
- `tests/Fixtures/InitUpgradeFakes.php` — `grupo_clientes` fake stores by `codgrupo` and `cliente`
  fake exposes `$assignOrphanCodgrupo`; both encode the current behaviour.

### 1.4 Uncommitted work in `clientes_core` (blocking for a clean baseline)

`plugins/clientes_core` is on `main`, dirty:

```
 M model/core/direccion_cliente.php     (+113/-13: transactional multi-write rewrite)
 M translations/messages.en.yaml        (+3: grupo-obligatorio, seleccione-grupo, sin-grupos-disponibles)
 M translations/messages.es.yaml        (+3)
 M view/ventas_cliente.html.twig        (+55/-? : Alpine mandatory client group)
 M view/ventas_clientes.html.twig       (+60/-? : Alpine mandatory client group in create modal)
?? tests/View/                          (untracked: ClienteGrupoRequiredViewTest.php)
```

The intent item "client group already partially done" is exactly this working tree. It is not
committed and not released (`fsframework.ini version = 3.0.2`, last commit `aa39e0b`).
`tpvmod` is on `master`, clean.

### 1.5 Cross-repo facts that constrain the design

- `clientes_core` `require = "business_data"` (root of the client domain).
- `clientes_facturacion` `require = "clientes_core"`.
- `clientes_catalogo` `require = "clientes_core,catalogo_core,clientes_facturacion"`.
- `tpvmod` `require = "clientes_facturacion,catalogo_core"` — **`clientes_core` is transitive,
  not direct**. `tpvmod`'s own `openspec/config.yaml` context claims a direct `clientes_core`
  dependency, but `fsframework.ini` does not.
- `LocalPluginRequirementsReader` parses `require` as a plain comma-separated **name list**
  (`src/Core/Plugin/LocalPluginRequirementsReader.php:40-48`). There is **no version constraint
  grammar** in `require`. `min_version` is validated **only against the FSFramework core version**
  (`base/fs_plugin_manager.php:910-918`), not against dependency plugin versions.
  → A "requires clientes_core >= X.Y.Z" contract cannot be expressed with the existing mechanism.
- `tpvmod` loads its client code with `require_model('cliente.php')` (`lib/tpvmod_cliente_ajax.php:14-22`),
  so it binds to the global `\cliente` alias (wrapper at `plugins/clientes_core/model/cliente.php`).

---

## 2. Affected areas

### `clientes_core`

- `Init.php` — conflation fix; default discount group; orphan assignment; possibly a new
  `clientes_core_discount_group_required` flag; `ensureDefaultClientGroup()` naming/code.
- `model/core/cliente.php` — `test()` (add `codgrupo_descuento` requirement), `assignOrphanClientsToGroup()`
  (needs a discount-group sibling), `getEffectiveDiscounts()` (NULL branch becomes dead),
  `resetToGroupDefaults()`, `fix_db()` (FK-aware NULLing), `save()`/`buildSql()`.
- `model/table/clientes.xml` — new FK on `codgrupo_descuento`, nullable-vs-NOT NULL decision.
- `controller/ventas_cliente.php` — `save_cliente()` fallback removal; `change_grupo_descuento()`.
- `controller/ventas_clientes.php` — `nuevo_cliente_pure()` fallback removal.
- `view/ventas_cliente.html.twig` and `view/ventas_clientes.html.twig` — replace inlined forms with
  the shared component (or keep thin wrappers around it).
- New shared component (paths TBD by design): template(s) under `View/` (namespaced) or
  `themes/AdminLTE/view/...`; a JS file for the Alpine/data wiring; a shared partial for the
  create-modal variant.
- New/updated controller surface for the AJAX consumer (research item R2 below).
- `translations/messages.{en,es}.yaml` — new keys (`sin-grupo-descuentos` exists only as a Twig key
  used at `ventas_cliente.html.twig:242`; verify it is present in both files).
- `tests/InitUpgradeTest.php`, `tests/Fixtures/InitUpgradeFakes.php`,
  `tests/Controller/VentasClienteDiscountsTest.php`, `tests/View/ClienteGrupoRequiredViewTest.php` —
  conflation assertions and view contracts must change.
- `openspec/specs/clientes/spec.md`, `openspec/specs/client-discount-inheritance/spec.md`,
  `openspec/specs/discount-groups/spec.md` — spec drift correction.

### `tpvmod` (separate repo — referenced from this SDD, implemented in its own)

- `view/partials/modal_clientes.html.twig` — replace the bespoke form modal with the shared component.
- `view/ajax/tpv_cliente_form.html.twig` — candidate for deletion or reduction to a thin adapter.
- `view/js/tpvmod-cliente.js` — search-modal JS probably stays; form/save/direcciones JS is the
  duplication to remove.
- `lib/tpvmod_cliente.php` — `tpvmod_cliente_apply_from_post()` becomes a wrapper or disappears;
  the `'000000'` fallback must go.
- `lib/tpvmod_cliente_ajax.php` — form/save/direcciones dispatch.
- `controller/tpvmod.php`, `tpvmod_albaranes.php`, `tpvmod_pedidos.php`, `tpvmod_facturas.php`,
  `tpvmod_presupuestos.php` — the `tpvmod_cliente_ajax_dispatch($this)` call sites.
- `fsframework.ini` — declare `clientes_core` as a direct requirement.
- `tests/TpvmodClienteHelpersTest.php` — asserts on `apply_from_post` and the discount-group NULL branch.
- `openspec/config.yaml` context — already claims the direct dependency; align with `fsframework.ini`.

### Core (framework) — only if the design needs it

- `src/Core/Html.php` / `src/Event/TwigLoaderEvent.php` / `src/View/ViewHookRegistry.php` —
  existing mechanisms; **no core change expected** if the component is consumed via a namespaced
  Twig path or a view hook. Any core change moves part of the SDD to the core `openspec/`.
- `openspec/changes/view-hook-registry-core/` is an **active (unarchived)** core change for the
  hook registry. Its status must be checked before this change relies on hooks.

---

## 3. Design space

### 3.1 Where the shared component lives

| Option | Description | Pros | Cons | Effort |
|---|---|---|---|---|
| A. Namespaced Twig path on `clientes_core` | `clientes_core/Init.php` listens to `TwigLoaderEvent`, `addPath(__DIR__.'/View', 'clientes_core')`; consumers `{% include '@clientes_core/Cliente/Form.html.twig' %}` | Exact precedent (`clientes_catalogo`); no core change; explicit call-site; works cross-plugin; partials can `include`/`extends` within the namespace | Consumers must know the namespace; no automatic injection into an existing form | Low |
| B. View hook | `clientes_core` registers a hook the consumer renders (`render_hook('cliente_form', ctx)`) | `clientes_catalogo` precedent; allows multiple plugins to contribute | Hooks append/aggregate — they do not *replace* a whole form; ordering is registration-order; the consumer must already have a slot to render into | Low |
| C. Theme-level shared macro/partial | Put the component under `themes/AdminLTE/view/` | Globally includable | Themes are not plugin-owned; `clientes_core` would depend on a theme layout; violates "each plugin owns its SDD/code" | Medium |

Notes: A and B are complementary, not exclusive — the natural shape is **A for the whole form
partial** plus **B for extension slots inside it** (mirroring `cliente_form_after_main`). Option C is
weak because the component must be owned by `clientes_core`.

### 3.2 Parameterization for "modal vs page"

Candidate context keys (final names are a `sdd-design` concern):

- `mode`: `create` | `edit` | `modal-create` (or a boolean `embedded`).
- `action_url` / `form_action`: where the form posts (page URL vs TPV endpoint).
- `submit_mode`: `page-redirect` | `ajax-json`.
- `show_tabs`: the TPV variant has Datos/Descuentos/Direcciones; the `clientes_core` page has a
  single panel plus a separate addresses panel.
- `cliente`: existing entity or empty.
- `grupos`, `grupos_descuentos`, `regimenes_iva`: catalog data (must be provided by the *consumer's*
  controller, since `clientes_core` has no service to fetch them for another plugin's request).
- `allow_delete`, `readonly_code`, `id_prefix` (DOM id collisions when two instances coexist).
- Extension hooks inside the partial (`render_hook(...)`).

Open design tension: the TPV variant needs **tabs including addresses**; the `clientes_core` page
needs **addresses as a separate panel**. Either the partial exposes an `include_addresses` flag, or
addresses stay outside the shared component in both consumers. The TPV addresses editor is
materially different (inline mini-form + JS, no modal).

### 3.3 How `tpvmod` consumes it without duplicating JS/save logic

| Option | Description | Pros | Cons | Effort |
|---|---|---|---|---|
| **1. Keep TPV AJAX, share markup + a shared save service** | `tpvmod` renders the shared partial via `@clientes_core`, keeps its AJAX endpoint; `tpvmod_cliente_apply_from_post()` delegates to a shared PHP "apply + validate + diff" function owned by `clientes_core`; `tpvmod` JS keeps only the modal-open/serialize glue | Minimal UX change; no new HTTP contract; removes the field-mapping and diff duplication (the real duplication); TPV keeps its small `input-sm` styling via a container class | Two save paths still exist (page POST + TPV AJAX); a shared PHP apply-service must be callable from a legacy plugin (function or static service, not a container-only service); markup must render acceptably in both contexts | Medium |
| **2. Shared AJAX endpoint in `clientes_core`** | Add a `clientes_core` AJAX endpoint (or a dedicated controller) that owns client CRUD + address CRUD over JSON; both the page and `tpvmod` call it | Single save path, single validation path, single diff logic; the page could also go AJAX later | New public HTTP contract; CSRF + authz must be re-established for a cross-plugin caller; `clientes_core` grows a controller it does not need for its own pages; `tpvmod` still needs its JSON-shaped response (`label`, `cliente` payload) for `tpvmodSeleccionarCliente()` / `recalcular()`; larger blast radius | High |
| **3. Full-page fallback from the TPV** | `tpvmod` drops its modal and links to `index.php?page=ventas_cliente&cod=...` | Zero duplication | Breaks the TPV flow (user leaves the ticket); unacceptable UX regression | Low but wrong |
| **4. Alpine-driven AJAX inside the shared component** | Shared partial owns an Alpine component that POSTs JSON; the page uses it too | One implementation, one path | Requires the Alpine CSP build in `tpvmod` (currently not loaded) — changes CSP surface across 6 TPV views; larger change than the product need | High |

The TPV JSON response contract that must be preserved either way (`tpvmod-cliente.js:168-176`):
`{ ok, codcliente, label, cliente }`, consumed by `tpvmodSeleccionarCliente()` and ultimately
`recalcular()`.

Required shared PHP capability, whichever option wins — a single authority for:

1. field mapping POST → `cliente` (currently duplicated in `ventas_cliente.php:126-171`,
   `ventas_clientes.php:249-274`, `tpvmod_cliente.php:48-113`);
2. the `descuentos_modified` diff vs the selected discount group (duplicated in
   `ventas_cliente.php:151-166` and `tpvmod_cliente.php:118-141`);
3. the mandatory-group validation trigger (delegating to `cliente::test()`).

`clientes_core` has no `src/` service layer today (only `src/ViewHookRegistry.php` and `src/Twig/`).
`composer.json`/PSR-4 status for a namespaced PHP service must be checked in `sdd-design`.

### 3.4 What "discount group required" implies

**DB / FK**

- `clientes.codgrupo_descuento` is `character varying(6)` `nulo=YES` and has **no FK**.
  Making the *business* rule mandatory does not require the column to become `NOT NULL`; but an FK
  `codgrupo_descuento → gruposdescuentos(codgrupo_descuento)` was deliberately considered before
  (there is evidence in `ventas_clientes.html.twig:204` of an intended `'000000'` undeletable group).
  Adding an FK with `ON DELETE SET NULL` would silently NULL clients when a discount group is deleted
  — that directly contradicts "must be explicitly selected". `ON DELETE RESTRICT` or no FK at all are
  the coherent choices. `fs_schema::syncConstraints` will create the FK on the next activation, and it
  will **fail or partially apply if orphan rows exist first** — so migration must backfill *before*
  the constraint sync, which is a hard ordering constraint because `PluginSchemaSynchronizer` syncs
  tables/constraints **before** calling `Init::upgrade()`.
- Nullable column + mandatory business rule means the invariant lives only in `test()`; a direct SQL
  writer bypasses it. This is the same exposure `codgrupo` already has. Decide explicitly whether
  `NOT NULL` is wanted (it forces a three-step migration: backfill → `SET NOT NULL` → FK).

**Migration for existing `codgrupo_descuento IS NULL` clients**

- The default discount group "Personalizado" (`'000000'`, d1-d4 = 0) already exists from the
  `clientes_core_discounts_migrated` block, and `InitUpgradeTest` asserts it. So the migration target
  exists; only the assignment of NULL clients is missing.
- Required new step: `UPDATE clientes SET codgrupo_descuento = <default> WHERE codgrupo_descuento IS NULL`.
- It needs its own idempotency flag (e.g. `clientes_core_discount_group_required`). Reusing
  `clientes_core_discounts_migrated` is wrong: that flag is already `'1'` on every install that ran
  the previous migration, so gating on it makes the new step a permanent no-op.

**The owner has NOT decided what orphan clients get.** The candidates surfaced by the code:

- assign the existing `'000000'` "Personalizado" discount group (d1-d4 = 0 → no change in pricing);
- create a distinct default discount group with a new name/code;
- refuse the migration and require an explicit operator selection.

This must be an explicit product decision in the proposal; it interacts with the conflation fix
(§3.5) because the same default code is currently misused on both columns.

### 3.5 The conflation fix

Correct semantics:

- `codgrupo` → `gruposclientes` (client categorization; `'000001'` "General" already seeded by
  `ensureDefaultClientGroup()`, which is broken in a different way: it returns early whenever the
  table is non-empty, so it will not create "General" on an install that has groups but no `'000001'`).
- `codgrupo_descuento` → `gruposdescuentos` (`'000000'` "Personalizado", d1-d4 = 0).

Code paths to correct:

1. `Init.php:107` — `assignOrphanClientsToGroup('000000')` must target a **valid** `gruposclientes`
   code (the new default decision), not the discount-group code.
2. `controller/ventas_cliente.php:137` — drop the `'000000'` fallback; an empty `codgrupo` must fail
   validation (and the form already blocks it client-side).
3. `plugins/tpvmod/lib/tpvmod_cliente.php:81` — same fallback; must go (this is a `tpvmod` edit).
4. `view/ventas_clientes.html.twig:204` — `g.codgrupo != '000000'` as "undeletable" is wrong; the
   undeletable default is a *client* group and its code should be `'000001'` (or whatever the design
   settles on). This read-side bug also makes the real default group deletable.
5. `Init::upgrade()` legacy block — the `assignOrphanClientsToGroup('000000')` step must be re-run
   correctly for installs where it already executed. **The flag `clientes_core_discounts_migrated`
   already suppressed it**, so a fresh flag-gated step is mandatory for remediation.

`fix_db()` follow-up: because the FK is `ON DELETE SET NULL`, `fix_db()`'s NULLing is a *symptom
repair*. Once the invariant is enforced, either (a) the orphan clients must be backfilled again after
any group deletion, or (b) the delete path must refuse to delete a group in use. `ventas_clientes.php`
`delete_grupo()` currently deletes unconditionally (only the UI hides the button).

Spec/test corrections this forces (all inside `clientes_core`):

- `openspec/specs/clientes/spec.md` — MODIFY "New client gets default group": the default is a
  **client** group; the "Personalizado" mapping is removed.
- `openspec/specs/client-discount-inheritance/spec.md` — MODIFY "Group is mandatory for all clients":
  separate the two group concepts; scenarios must use the right column per concept.
- `openspec/specs/discount-groups/spec.md` — the "Personalizado (code `000000`)" paragraph must say
  it is the **discount** default, and it must not claim it is assigned to `codgrupo`.
- `tests/InitUpgradeTest.php:333-367` — no `grupo_clientes::$storedGroups['000000']`; orphan
  assignment must assert the client-group code; a new test for the discount-group backfill.
- `tests/Fixtures/InitUpgradeFakes.php` — extend the fake `cliente` with a
  `assignOrphanDiscountGroupsTo...` counter sibling.
- `tests/Controller/VentasClienteDiscountsTest.php:418,835-887` — T21's empty-`codgrupo` case changes
  from "assigns 000000" to "fails validation".
- Any test asserting `grupo_descuentos` `'000000'` remains valid (that part is correct).

Ripple outside the plugin (must be enumerated in the proposal, may be out of this change's scope):

- `plugins/FSDK/lib/generar_datos_prueba.php:489-491` sets `codgrupo = $this->grupos[0]->codgrupo`
  **or NULL** — a null client group. It does not set `codgrupo_descuento` at all. With mandatory
  groups, seeded demo data will fail to save.
- `plugins/tarifario` creates client groups (`Services/ExcelRowUpdater.php:368-377`) but is unrelated
  to client assignment.
- `plugins/clientes_facturacion/controller/ventas_clientes_opciones.php` stores `nuevocli_codgrupo`
  default with an **empty string** default (`:73`), and its RainTPL view
  (`view/ventas_clientes_opciones.html:211`) offers a group list. This is a *new-client default*
  option page. Whether it must also force a group (and whether it is even reachable — the only
  reference found is a role definition in `factura_pdf1`) is an open question.

---

## 4. Recommendation

Order the work as three internally-coupled but separately reviewable slices, all owned by
`clientes_core`:

1. **Conflation + mandatory-group data layer** (model, `Init`, XML, specs, tests). This is the
   smallest slice that removes the `'000000'`-in-`codgrupo` bug and makes the discount group
   mandatory at the model level. It also makes the two group concepts unambiguous, which the shared
   component needs in order to render correct labels/selects.
2. **Shared form component in `clientes_core`** (namespaced Twig path — Option 3.1.A — plus a shared
   PHP apply/diff authority). Wire `ventas_cliente.html.twig` and the `ventas_clientes.html.twig`
   create modal to it first; keep the extension hooks (`cliente_form_after_main`,
   `cliente_direccion_form_after_codpais`) working — `clientes_catalogo` depends on them.
3. **`tpvmod` consumer migration** (separate repo, referenced from this SDD): replace
   `view/ajax/tpv_cliente_form.html.twig` + the save/direcciones JS with the shared component,
   keeping the TPV AJAX endpoint and JSON response shape (Option 3.3.1). Do **not** route the TPV
   through a new `clientes_core` endpoint in this change; the cross-plugin authz/CSRF cost is not
   justified by the duplication being removed.

Rejected for now: new shared AJAX endpoint (3.3.2) and Alpine-driven TPV saves (3.3.4) — both enlarge
the blast radius beyond the stated need. Theme-level shared partial (3.1.C) — violates plugin
ownership.

---

## 5. Open questions / risks for the proposal to resolve

### Must be decided by the product owner

- **Q1** What default **client** group do orphan clients get after the conflation fix? Candidates:
  the existing `'000001'` "General"; a brand-new dedicated default (new code + name); or no
  automatic assignment (operator must fix them). Affects `Init::upgrade()`, `ensureDefaultClientGroup()`,
  `view/ventas_clientes.html.twig:204`, `FixDb`, and the spec scenarios.
- **Q2** What default **discount** group do clients with `codgrupo_descuento IS NULL` get? The existing
  `'000000'` "Personalizado" (d1-d4 = 0) is the obvious candidate but the owner has not confirmed it,
  and whether "Personalizado" stays the canonical name matters for `discount-groups` spec.
- **Q3** Is `codgrupo_descuento` allowed to remain `NULL` at the DB level (invariant only in `test()`),
  or does it become `NOT NULL` + FK? `NOT NULL` forces a three-step migration and changes any direct
  SQL writer.
- **Q4** Should an in-use group be undeletable (and is that in scope)? Currently `delete_grupo()`
  deletes unconditionally; the FK's `ON DELETE SET NULL` is what silently re-orphans clients.
- **Q5** Does the shared component's scope include **addresses**? The TPV variant tabs them in with a
  bespoke editor; the `clientes_core` page shows them in a separate panel with a modal. Unifying
  addresses roughly doubles the component's surface.
- **Q6** Are the `FSDK` demo-data generator and the legacy `ventas_clientes_opciones` new-client
  defaults in scope? Both create clients without the now-mandatory groups.

### Technical risks

- **R1 — Schema-sync ordering vs backfill.** `PluginSchemaSynchronizer` runs `fs_schema::syncPluginTables`
  (create/alter columns + constraints) **before** `Init::upgrade()`. If the design adds an FK or
  `NOT NULL` on `codgrupo_descuento`, existing NULL/orphan rows exist *at the time the constraint is
  applied*. The migration must therefore either run a pre-flight backfill that the synchronizer can
  invoke, or the design must avoid a constraint that the on-disk data contradicts. This is the single
  highest-risk item and must be resolved in `sdd-design`.
- **R2 — Cross-repo coupling has no version guard.** `require` is a name list; `min_version` compares
  against the *core* version only. A `tpvmod` that expects the new shared component cannot prevent
  activation against an older `clientes_core`. Mitigation options: make `clientes_core` a **direct**
  requirement in `tpvmod/fsframework.ini` (necessary but not sufficient), gate the shared partial on a
  Twig `is defined` probe, and/or release both plugins together with an explicit operator note. Decide
  in `sdd-design`.
- **R3 — Two independent SDD homes.** The artifact lives in `clientes_core`; the `tpvmod` consumer
  edits live in the `tpvmod` repo with its own `openspec/`. The proposal must name the `tpvmod` change
  explicitly and state that verification of slice 3 happens in the `tpvmod` suite
  (`ddev exec php vendor/bin/phpunit -c plugins/tpvmod/phpunit.xml`). Neither plugin's SDD may be
  duplicated into the core `openspec/`.
- **R4 — Dirty working tree baseline.** `clientes_core` is dirty and the Alpine mandatory-client-group
  edits are uncommitted/untagged. `strict_tdd: true` needs a known baseline: either commit that work
  first or fold it into slice 2. `tests/View/ClienteGrupoRequiredViewTest.php` is untracked and its
  assertions (`trans('sin-grupo')` must not appear) constrain the shared component's markup.
- **R5 — Extension-hook compatibility.** `clientes_catalogo` registers
  `cliente_form_after_main` and `cliente_direccion_form_after_codpais`. If the shared component
  restructures `ventas_cliente.html.twig`, those hooks must still render (with the same context keys
  `fsc`, `cliente`) or `clientes_catalogo` silently loses the divisa/pais fields. `ViewHookRegistry`
  swallows render errors into `error_log`, so the failure would be silent.
- **R6 — `stealth_mode` CSP.** `src/Core/StealthMode.php:95` sets a strict CSP and
  `src/Security/SecurityHeaders.php:23` allows `'unsafe-inline'` for scripts. The existing Alpine
  registration uses nonce'd classic scripts. Any new JS must keep the nonce pattern; the idempotent
  `window.__*Registered` marker is required because views can be re-rendered/included several times
  (`modal_clientes.html.twig` is included in 6 TPV views, and `ventas_clientes` renders the modal on
  the same page as the list).
- **R7 — DOM id collisions.** The shared partial will be included both in a TPV page (alongside other
  modals) and in a page that may render it once per context. Fixed ids (`#modal_cliente_form`,
  `#f_cliente_tpv`) must become parameterized or the second include breaks the first.
- **R8 — `ensureDefaultClientGroup()` early-return bug.** It returns early when `gruposclientes` is
  non-empty, so a default group may be absent on real installs. Any new default-group logic must not
  inherit that guard.
- **R9 — `clientes_core` has no PHP service layer.** `src/` contains only Twig/View helpers. A shared
  PHP apply/validate authority means introducing the plugin's first namespaced service — the plugin's
  Composer/PSR-4 autoload setup must be verified in `sdd-design` (plugins must commit `vendor/`, and
  `clientes_core` currently has no `composer.json`/`vendor/`).
- **R10 — Active core change overlap.** `openspec/changes/view-hook-registry-core/` is unarchived.
  Confirm its state before making hook behaviour load-bearing.

---

## 6. Ready for proposal

**Yes.** The design space is mapped, the conflation bug and its spec/test drift are located precisely,
and the remaining unknowns are product decisions (Q1-Q6) plus one hard technical constraint (R1).

The proposal must:

- answer Q1 and Q2 explicitly (or state that they are deferred and what the placeholder default is);
- state the scope boundary for Q5 (addresses) and Q6 (FSDK / `ventas_clientes_opciones`);
- name the `tpvmod` consumer change and its own SDD home;
- carry R1 and R2 as first-class design constraints;
- split delivery into the three slices of §4 and forecast the 400-line review budget per slice.
