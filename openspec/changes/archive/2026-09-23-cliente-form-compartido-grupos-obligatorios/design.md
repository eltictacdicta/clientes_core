# Design: Shared client form + mandatory client group and discount group

- **Change**: `cliente-form-compartido-grupos-obligatorios`
- **SDD home**: `plugins/clientes_core/openspec/` (plugin-local, `ownership: plugin-local`)
- **Artifact store**: openspec (files)
- **Status**: design — ready for tasks
- **Date**: 2026-09-22
- **Inputs**: `proposal.md`, `exploration.md`, `decisions.md` (Q1–Q6 CONFIRMED), four delta specs
  (`shared-client-form`, `clientes`, `client-discount-inheritance`, `discount-groups`)
- **Supersedes**: none. Q1–Q6 are not reopened by this document.

---

## 1. Executive summary

The design resolves the six open questions from the proposal and fixes the architecture as follows:

1. **Shared component location**: `clientes_core` owns a namespaced Twig component under
   `plugins/clientes_core/View/Cliente/`, exposed as `@clientes_core/...` through a
   `TwigLoaderEvent` listener in `Init.php` (exact `clientes_catalogo` precedent, no core change).
2. **Shared PHP authority**: a namespaced static helper class
   `FSFramework\Plugins\clientes_core\ClienteForm` at `plugins/clientes_core/ClienteForm.php`,
   autoloaded by the **already-configured root Composer PSR-4 map**
   (`"FSFramework\\Plugins\\": "plugins/"`). **No `composer.json`, no `vendor/`, no PSR-4 setup**
   is added to the plugin (R9 resolved without introducing a plugin dependency commit).
3. **Partial granularity**: three templates — `Form.html.twig` (page/modal wrapper that owns the
   `<form>`), `Fields.html.twig` (identity fields + `cliente_form_after_main` hook + Alpine
   registration), `Discounts.html.twig` (mandatory discount group + D1–D4). The TPV composes
   `Fields` + `Discounts` inside its own tabbed form.
4. **Migration**: a single new idempotent, **data-only** block gated by
   `clientes_core_discount_group_required`, with two idempotent `ensure*` steps and two backfill
   UPDATEs. `clientes.xml` is **not modified** — no FK, no `NOT NULL` — which neutralises R1.
5. **Deletion guard**: `cliente::countByGroup()` / `cliente::countByDiscountGroup()` back the
   refusal in `ventas_clientes::delete_grupo()` and `descuentos_grupo::delete_grupo()`.
6. **TPV contract**: `tpvmod` keeps its AJAX endpoint and `{ok, codcliente, label, cliente}` shape;
   `tpvmod_cliente_apply_from_post()` becomes a one-line delegation to `ClienteForm::apply()`; the
   local diff implementation and the `'000000'` fallback are deleted, not wrapped.

No file under `base/`, `src/`, root `model/`, root `controller/` or root `openspec/` changes.

---

## 2. Architecture overview

```
                       ┌─────────────────────────────────────────────┐
                       │        plugins/clientes_core (owner)        │
                       │                                             │
  Twig namespace  ───► │  Init.php                                   │
  @clientes_core       │    · TwigLoaderEvent  → addPath(View)       │
                       │    · TwigInitEvent    → globals/hook fn      │
                       │    · upgrade()        → data-only migration  │
                       │                                             │
                       │  View/Cliente/Form.html.twig                │
                       │    └─ includes Fields + Discounts + actions  │
                       │  View/Cliente/Fields.html.twig              │
                       │    └─ identity fields + hook + Alpine script │
                       │  View/Cliente/Discounts.html.twig           │
                       │                                             │
                       │  ClienteForm.php  (PHP authority)           │
                       │    apply() · computeDescuentosModified()     │
                       │    validate() · applyAndValidate()           │
                       └───────────────┬─────────────────────────────┘
                                       │  Composer PSR-4
                                       │  FSFramework\Plugins\ → plugins/
              ┌────────────────────────┼────────────────────────────┐
              │                        │                            │
   ┌──────────▼──────────┐  ┌──────────▼──────────┐  ┌──────────────▼─────────────┐
   │ ventas_cliente.php  │  │ ventas_clientes.php │  │ tpvmod (separate repo)      │
   │ (edit page)         │  │ (create modal)      │  │  · renders Fields+Discounts │
   │  → ClienteForm      │  │  → ClienteForm      │  │  · delegates to ClienteForm │
   │  → Form.html.twig   │  │  → Form.html.twig   │  │  · keeps AJAX + JSON shape  │
   └─────────────────────┘  └─────────────────────┘  └─────────────────────────────┘
```

The component is **markup + one PHP authority**. No new HTTP endpoint, no container service, no
theme-level template (proposal Non-Goals).

---

## 3. Decision 1 — Where the shared component lives (Twig namespace)

**Chosen**: Option 3.1.A from `exploration.md` — namespaced Twig path on `clientes_core`.

- `Init::init()` gains a second listener:

```php
$dispatcher->addListener(TwigLoaderEvent::NAME, function (TwigLoaderEvent $event) {
    $loader = $event->getLoader();
    if ($loader instanceof \Twig\Loader\FilesystemLoader) {
        $loader->addPath(__DIR__ . '/View', 'clientes_core');
    }
});
```

- New directory: `plugins/clientes_core/View/Cliente/`.
- Consumers: `{% include '@clientes_core/Cliente/Form.html.twig' %}`.

**Verified facts that make this safe**

| Fact | Evidence |
|---|---|
| `TwigLoaderEvent` exists and is dispatched on the loader | `src/Event/TwigLoaderEvent.php`; `src/Core/Html.php:183-188` |
| Exact precedent | `plugins/clientes_catalogo/Init.php:26-32` |
| `Html::addPluginViewPaths()` **already** registers `<plugin>/View` under the plugin name | `src/Core/Html.php:193-217` |
| The explicit listener is belt-and-braces; it does not conflict with the automatic path | `FilesystemLoader::addPath()` appends a path; duplicate lookups are harmless |
| `plugins/clientes_core/View/` does not exist yet | verified; must be created |
| No core change needed | `TwigLoaderEvent` is an existing core extension point |

**Rejected**: theme-level partial (3.1.C) — violates plugin ownership; view-hook-as-container (3.1.B)
alone — hooks aggregate, they do not replace a whole form.

---

## 4. Decision 2 — Where the shared PHP authority lives (R9)

**Chosen**: a namespaced static helper class autoloaded by the **existing root Composer PSR-4 map**.

| Property | Value |
|---|---|
| FQCN | `FSFramework\Plugins\clientes_core\ClienteForm` |
| File | `plugins/clientes_core/ClienteForm.php` |
| Autoload | root `composer.json` → `"FSFramework\\Plugins\\": "plugins/"` |
| New `composer.json` | **No** |
| New `vendor/` | **No** |
| PSR-4 setup in the plugin | **No** |

**Justification**

1. **Zero new infrastructure.** `FSFramework\Plugins\clientes_core\Init` is already resolved by
   exactly this mapping (`PluginSchemaSynchronizer` calls
   `class_exists('\FSFramework\Plugins\' . $pluginName . '\Init')`). The authority uses the same,
   proven mechanism. PSR-4 prefix matching is longest-prefix-first, so `FSFramework\Plugins\` wins
   over `FSFramework\` (→ `src/`). The root autoloader is not `classmap-authoritative`, so new files
   resolve without `composer dump-autoload`.
2. **Cross-plugin callable without a hardcoded path.** A plain function file (Option A) would force
   `tpvmod` to `require_once FS_FOLDER . '/plugins/clientes_core/...'` — a path coupling and a
   duplicate require guard. A class autoloads by name.
3. **Directly unit-testable.** The plugin's `phpunit.xml` bootstraps the root
   `vendor/autoload.php`, so `ClienteForm` is autoloadable in tests with no extra setup.
4. **Avoids the dormant-`src/` trap.** `plugins/clientes_core/src/Twig/TercerosExtension.php`
   declares `FSFramework\Plugins\clientes_core\Twig\TercerosExtension`, which the PSR-4 map would
   look for at `plugins/clientes_core/Twig/TercerosExtension.php` — it is **unreachable** (verified:
   no references, no plugin `composer.json`). `src/ViewHookRegistry.php` is only a deprecated
   `class_alias` shim. Placing the authority under `src/` would repeat that mistake.
5. **R9 resolved without a dependency commit.** Because no Composer dependency is added, the
   plugin `vendor/` rule is not triggered and `tasks.md` carries no vendor step.

**Rejected**: introducing a plugin `composer.json`/PSR-4 (would require committing `vendor/` and
duplicating a mapping the root already provides); a plain function file (path coupling).

### 4.1 `ClienteForm` public API

```php
namespace FSFramework\Plugins\clientes_core;

final class ClienteForm
{
    /** Fields mapped verbatim as strings. */
    public const TEXT_FIELDS = [
        'nombre', 'razonsocial', 'tipoidfiscal', 'cifnif', 'telefono1', 'telefono2',
        'fax', 'email', 'web', 'regimeniva', 'diaspago', 'observaciones',
    ];

    /** Fields mapped as `'1' === true`. */
    public const BOOL_FIELDS = ['recargo', 'personafisica', 'debaja'];

    /** Fields mapped as `'' => null`, otherwise the string. */
    public const NULLABLE_TEXT_FIELDS = ['coddivisa'];

    /** Discount columns compared against the selected discount group. */
    public const DISCOUNT_FIELDS = ['d1', 'd2', 'd3', 'd4'];

    /**
     * Map a submission onto a cliente. Selective: only keys present in $post are
     * touched, so partial updates (change_grupo, reset_descuentos) keep working.
     * NO fallback group is ever written (Q1/Q3).
     */
    public static function apply(object $cliente, array $post): object;

    /**
     * Pure diff: true when any d1-d4 differs from the loaded group (rounded to 2
     * decimals). Returns false when no group is supplied.
     */
    public static function computeDescuentosModified(object $cliente, ?object $grupoDescuentos): bool;

    /** The single mandatory-group gate: delegates to cliente::test(). */
    public static function validate(object $cliente): bool;

    /**
     * Convenience for controllers: apply() then validate().
     * @return array<int, string> validation errors (empty on success)
     */
    public static function applyAndValidate(object $cliente, array $post): array;
}
```

**Semantics fixed by this design**

| Input key | Behaviour |
|---|---|
| `codgrupo` present and `''` | `$cliente->codgrupo = null` — **no `'000000'` fallback**; `test()` rejects it |
| `codgrupo` present and non-empty | trimmed string |
| `codgrupo` absent | left untouched (preserves partial-update semantics) |
| `codgrupo_descuento` present and `''` | `null` — no fallback |
| `codgrupo_descuento` present and non-empty | trimmed string |
| `d1..d4` present | `(float)` cast |
| any `TEXT_FIELDS` absent | left untouched |
| `debaja`/`recargo`/`personafisica` present | `'1' === (string)$value` |

`apply()` ends by loading the selected discount group (guarded by
`class_exists('FSFramework\model\grupo_descuentos')`) and setting
`$cliente->descuentos_modified = self::computeDescuentosModified($cliente, $grupo)`. Group loading
lives **only here**; the diff itself is the pure static method. `computeDescuentosModified()` is the
single implementation consumed by both consumers, satisfying
`shared-client-form → "Single shared PHP apply, validate and diff authority"`.

`razonsocial` fallback (`'' => nombre`) is performed inside `apply()` **only when the target object
declares the property** (`property_exists($cliente, 'razonsocial')`), so lightweight test doubles
without it are unaffected and the edit/create/TPV paths produce identical field state.

---

## 5. Decision 3 — Partial granularity and the include-context contract

**Chosen**: three templates. A single `Form.html.twig` cannot serve the TPV because the TPV needs
its identity fields in the **Datos** tab, its discount block in the **Descuentos** tab, and its
addresses (out of scope, Q5) in the **Direcciones** tab — all inside one `<form id="f_cliente_tpv">`.
Nested forms are invalid HTML, and the TPV's `tpvmodGuardarCliente()` serializes the whole form.

| Template | Owns | Reused by |
|---|---|---|
| `View/Cliente/Form.html.twig` | **form body** — `csrf_field()`, hidden `action`/`codcliente`, `Fields` + `Discounts` includes, action row. The owning `<form>` stays in the consumer (see the correction note under §5.1) | `ventas_cliente.html.twig` (edit page), `ventas_clientes.html.twig` (create modal) |
| `View/Cliente/Fields.html.twig` | identity field set, `cliente_form_after_main` hook, Alpine CSP registration script | `Form.html.twig`, `tpvmod` Datos tab |
| `View/Cliente/Discounts.html.twig` | mandatory discount-group select, D1–D4 inputs, reset affordance | `Form.html.twig`, `tpvmod` Descuentos tab |

### 5.1 Include-context contract

All keys are optional; defaults in parentheses.

| Key | Type | Default | Semantics |
|---|---|---|---|
| `mode` | `'edit' \| 'create'` | `'edit'` | `create`: code input is an optional auto field, action is the create action, no delete. `edit`: code readonly, persisted values. |
| `cliente` | `cliente \| null` | `null` | Entity to render. `create` renders an empty entity when null. |
| ~~`action_url`~~ | — | — | **Removed — see the correction note below.** The consumer owns `<form action>`; this key is impossible to honour. |
| `action_name` | string | `'save_cliente'` | `Form.html.twig` only: hidden `action` value (`'nuevo_cliente'` in create). |
| `grupos` | iterable | `[]` | Client-group catalog (`grupo_clientes`). |
| `grupos_descuentos` | iterable | `[]` | Discount-group catalog (`grupo_descuentos`). |
| `regimenes_iva` | iterable | `[]` | Régimen IVA list. |
| `id_prefix` | string | `'cliente'` | **Required to be unique per include.** Namespaces every DOM id (R7). |
| `allow_delete` | bool | `false` | `Form.html.twig` only: render the delete affordance. |
| `readonly_code` | bool | `mode == 'edit'` | Code field readonly. |
| `show_discounts` | bool | `true` | Include `Discounts.html.twig`. |
| `show_actions` | bool | `true` | `Form.html.twig` only: render the action row. |
| `show_grupos_warning` | bool | `false` | Render the "no client groups available" alert. |
| `submit_mode` | `'page' \| 'external'` | `'page'` | `page`: `<button type="submit">`. `external`: `<button type="button">` with consumer JS. |
| `submit_attrs` | map | `{}` | Extra attributes on the submit button (e.g. `onclick`). |
| `input_class` | string | `'form-control'` | Density variant; TPV passes `'form-control input-sm'`. |

> **Correction (2026-09-23, recorded during the Slice 2b-ii apply).** This section originally made
> `Form.html.twig` own the `<form>` element and exposed `action_url` for it. That is **impossible**,
> not merely inconvenient, and it contradicts §5.3/§6.1 of this same document. In the create modal the
> `<form>` must wrap the consumer's Bootstrap `modal-header`, `modal-body` and `modal-footer`
> (`view/ventas_clientes.html.twig`), and that chrome is consumer-only: it also serves the non-modal
> edit page and the TPV. A partial that opened `<form>` would have to emit modal chrome or nest forms.
> §5.3 already declared the consumer the owner of the element carrying `x-data`, and §6.1 keeps the
> consumer-owned ids in the consumer. The implementation therefore renders the **form body** and the
> consumer keeps `<form>`, its `action` and its id. The delta spec never requires `action_url`; the
> submit target is honoured through the hidden `action` input plus the consumer's `<form action>`.
> Ruled a legitimate resolution of contradictory input by the Slice 2b-ii contract validator.

### 5.2 The create modal gains the discount block (deliberate)

The discount group is now mandatory in `cliente::test()`. A create form that does not render
`codgrupo_descuento` would post nothing and every create would fail. Therefore
`ventas_clientes.html.twig`'s create modal includes `Form.html.twig` with
`mode='create'`, `show_discounts=true`, and therefore renders the mandatory discount-group select
plus D1–D4. This is required by `client-discount-inheritance → "Missing discount group fails
validation"` and is not scope creep.

### 5.3 Consumer contract for `x-data`

`Fields.html.twig` does **not** add its own `x-data`; the element that includes the partial MUST
carry `x-data="clienteGrupoRequired"` (the `<form>`). Rationale: two nested instances of the same
Alpine component would each `init()` from the same `select`, but only the outer one is bound with
`x-model`, so the inner `missing()` would be stale. Consumers:

- `ventas_cliente.html.twig`: `<form id="form_edit_cliente" x-data="clienteGrupoRequired" …>`
- `ventas_clientes.html.twig`: create-modal `<form x-data="clienteGrupoRequired" …>`
- `tpvmod`: `<form id="f_cliente_tpv" x-data="clienteGrupoRequired" onsubmit="return false;">`

When Alpine is not loaded (tpvmod today), the directive is inert and the native `required`
attribute on both selects is the enforcement layer; the server-side `test()` is authoritative.

---

## 6. Decision 4 — DOM ids, Alpine component, CSP (R6, R7)

### 6.1 Parameterized DOM ids

Every id emitted by the partial is prefixed with `id_prefix`. No template hard-codes
`#modal_cliente_form`, `#f_cliente_tpv`, `#form_edit_cliente` or `#btn_reset_descuentos`.

| Element | id |
|---|---|
| code input | `{{ id_prefix }}_codcliente` |
| client-group select | `{{ id_prefix }}_codgrupo` |
| client-group help text | `{{ id_prefix }}_grupo_required` |
| discount-group select | `{{ id_prefix }}_grupo_descuento` |
| discount-group help text | `{{ id_prefix }}_grupo_descuento_required` |
| discount reset button | `{{ id_prefix }}_btn_reset_descuentos` |
| delete button (`Form.html.twig`) | `{{ id_prefix }}_btn_delete_cliente` |

Consumer-owned ids stay in the consumer (`form_edit_cliente`, `modal_nuevo_cliente`,
`f_cliente_tpv`, `form_delete_cliente`, `form_reset_descuentos`). `id_prefix` values chosen:
`edit_cliente` (edit page), `nuevo_cliente` (create modal), `tpv_cliente` (TPV).

### 6.2 Alpine component (extended to both groups)

The registration script lives once, in `Fields.html.twig`, and keeps the exact CSP-safe shape
already used in the working tree (nonce'd classic script + idempotent window marker):

```twig
<script {{ csp_nonce_attr() }}>
(function () {
    if (window.__clientesCoreGrupoRequiredRegistered === true) { return; }

    function registerClienteGrupoRequired() {
        if (window.__clientesCoreGrupoRequiredRegistered === true) { return; }
        window.__clientesCoreGrupoRequiredRegistered = true;

        Alpine.data('clienteGrupoRequired', function () {
            return {
                codgrupo: '',
                codgrupoDescuento: '',
                init: function () {
                    var g = this.$el.querySelector('select[name="codgrupo"]');
                    if (g) { this.codgrupo = g.value || ''; }
                    var d = this.$el.querySelector('select[name="codgrupo_descuento"]');
                    if (d) { this.codgrupoDescuento = d.value || ''; }
                },
                missing: function () {
                    return this.codgrupo === '' || this.codgrupo === null
                        || this.codgrupoDescuento === '' || this.codgrupoDescuento === null;
                }
            };
        });
    }

    if (window.Alpine) { registerClienteGrupoRequired(); }
    else { document.addEventListener('alpine:init', registerClienteGrupoRequired); }
})();
</script>
```

- Marker name stays `window.__clientesCoreGrupoRequiredRegistered` (the same name the working-tree
  edits already use; the untracked `tests/View/ClienteGrupoRequiredViewTest.php` asserts the
  component name `clienteGrupoRequired`, which is preserved).
- The script is emitted by `Fields.html.twig`; `Form.html.twig` includes `Fields`, so a page that
  includes `Form` twice still registers once.
- The partial itself emits no `unsafe-eval` and no inline handler. Consumers that use
  `submit_mode='external'` (the TPV) pass their existing JS hook through `submit_attrs`
  (`onclick="tpvmodGuardarCliente()"`), which is unchanged from today's template.
- Both selects carry `required`; both help texts use `x-show="missing()" x-cloak`.

---

## 7. Decision 5 — Extension hooks stay inside the partial (R5)

- `Fields.html.twig` renders, at the exact position currently used by
  `view/ventas_cliente.html.twig:217`:

```twig
{{ render_hook('cliente_form_after_main', {'fsc': fsc, 'cliente': cliente})|raw }}
```

- Addresses are out of the shared component (Q5). `cliente_direccion_form_after_codpais` **stays in
  each consumer's own address editor**. For `clientes_core` that is the address modal in
  `ventas_cliente.html.twig` (currently line 464); it is moved nowhere.
- `fsc` propagates into the include automatically (Twig `include` inherits the current context);
  the include also passes `cliente` explicitly.

### 7.1 Explicit regression assertion (mandatory, because the registry swallows errors)

`ViewHookRegistry::render()` catches `\Throwable` and only writes to `error_log` (verified), so a
dropped hook is silent. The design therefore requires a **render-level** test, not a string grep:

`plugins/clientes_core/tests/View/SharedFormHooksRenderTest.php`

1. Build a `Twig\Environment` with a `FilesystemLoader` rooted at the theme views, and
   `addPath(plugins/clientes_core/View, 'clientes_core')`.
2. Register stub Twig functions `trans`, `csrf_field`, `csp_nonce_attr` and `render_hook`
   (the last delegating to `FSFramework\View\ViewHookRegistry::render($twig, $hook, $context)`).
3. `ViewHookRegistry::register('cliente_form_after_main', '<stub template>')` where the stub template
   outputs a sentinel string **and** echoes `fsc`/`cliente` presence.
4. Render `@clientes_core/Cliente/Fields.html.twig` with `{fsc: stub, cliente: stub}`.
5. Assert the sentinel appears **and** that the context keys `fsc` and `cliente` reached the hook.

This is the R5 regression assertion: it fails if the hook is dropped or its context changes.

---

## 8. Decision 6 — Conflation fix and mandatory-group data layer

### 8.1 Code paths corrected

| # | Location | Before | After |
|---|---|---|---|
| 1 | `Init.php` legacy block | `assignOrphanClientsToGroup('000000')` (a discount code into `codgrupo`) | **removed**; backfill is owned by the new block (§9) |
| 2 | `controller/ventas_cliente.php:137` | `codgrupo = !empty($c) ? $c : '000000'` | `ClienteForm::apply()` → `null` when empty; `test()` fails |
| 3 | `controller/ventas_clientes.php:260` (`nuevo_cliente_pure`) | `codgrupo = … : null` + hand-rolled mapping | `ClienteForm::apply()` (single mapping) |
| 4 | `view/ventas_clientes.html.twig:204` | `g.codgrupo != '000000'` (protects a discount code) | `g.codgrupo != '000001'` (protects the real client default) |
| 5 | `tpvmod/lib/tpvmod_cliente.php:81` | `codgrupo = … : '000000'` | delegate to `ClienteForm::apply()`; fallback deleted (tpvmod repo) |

### 8.2 `cliente::test()` — the mandatory discount-group gate (Q3)

`validateFields()` gains, after the existing `codgrupo` check:

```php
if ($this->codgrupo_descuento === null || $this->codgrupo_descuento === '') {
    $this->new_error_msg("El cliente debe tener un grupo de descuentos.");
    return false;
}
```

The invariant lives **only** here; `codgrupo_descuento` stays nullable at the DB level (Q3, accepted
limitation, documented in `client-discount-inheritance`).

### 8.3 `cliente` helper methods added

```php
/** Backfill helper: clients with no discount group. */
public function assignOrphanClientsToDiscountGroup(string $codgrupoDescuento): bool;  // UPDATE … WHERE codgrupo_descuento IS NULL

/** Deletion guard: how many clients reference this client group. */
public function countByGroup(string $codgrupo): int;                                  // SELECT COUNT(*) … WHERE codgrupo = ?

/** Deletion guard: how many clients reference this discount group. */
public function countByDiscountGroup(string $codgrupoDescuento): int;                 // SELECT COUNT(*) … WHERE codgrupo_descuento = ?
```

- `assignOrphanClientsToGroup()` (existing) keeps its name and behaviour (`codgrupo IS NULL`).
- `getEffectiveDiscounts()`'s `codgrupo_descuento === null` branch is **retained** (defensive for
  in-memory clients and `resetToGroupDefaults()`); it becomes unreachable for persisted rows.
- `fix_db()` is **not** touched (no caller anywhere; out of scope).

### 8.4 `clientes.xml` — explicit non-change (R1)

`plugins/clientes_core/model/table/clientes.xml` is **not modified**. `codgrupo_descuento` stays
`<nulo>YES</nulo>` and no `<restriccion>` is added; `ca_clientes_grupos` is unchanged.

Why this neutralises R1: `PluginSchemaSynchronizer::synchronize()` applies XML tables/constraints
**before** `Init::upgrade()`. Because the migration adds no constraint, there is no window in which
a constraint contradicts on-disk data. The existing `ca_clientes_grupos` FK is untouched and already
present; it permits NULL, so the pre-migration state is valid. The backfill writes `'000001'`
**after** `ensureDefaultClientGroup()` has guaranteed that row exists, so the FK is satisfied.

**Verification tasks (see §14):** `git diff --exit-code` on the XML file, plus a test asserting the
XML declares `<nulo>YES</nulo>` for `codgrupo_descuento` and contains no `codgrupo_descuento`
foreign key.

---

## 9. Decision 7 — Migration and idempotency mechanics (OQ5)

### 9.1 New flag

`fs_settings` key: **`clientes_core_discount_group_required`** (value `'1'`).
`clientes_core_discounts_migrated` is **not** reused — it is already `'1'` on every install that ran
the previous migration, which would make the new step a permanent no-op.

### 9.2 `Init::upgrade()` final shape

```php
public static function upgrade(): void
{
    $settings = new \fs_settings();

    // Re-run check_table() so XML column changes are detected.
    $cache = new \fs_cache();
    $cache->delete('fs_checked_tables');

    // (1) Defaults + default-client seed. Both default groups are guaranteed
    //     BEFORE the seed, because the seed now passes the mandatory-group test().
    try {
        self::ensureDefaultClientGroup();      // 000001 "General"
        self::ensureDefaultDiscountGroup();    // 000000 "Personalizado" (d1-d4 = 0.00)

        $cliente = new cliente();
        if (!$cliente->table_has_rows()) {
            $cliente->nombre = 'Cliente por defecto';
            $cliente->codgrupo = '000001';
            $cliente->codgrupo_descuento = '000000';
            $cliente->save();
        }

        if (!$settings->get('clientes_core_default_seeded')) {
            $settings->set('clientes_core_default_seeded', '1');
            $settings->save();
        }
    } catch (\Throwable $e) {
        error_log('[clientes_core] Default seed failed: ' . $e->getMessage());
    }

    // (2) Legacy v1 -> v2 flag. Kept for compatibility; no longer writes codgrupo.
    if (!$settings->get('clientes_core_discounts_migrated')) {
        try {
            self::ensureDefaultDiscountGroup();
            $settings->set('clientes_core_discounts_migrated', '1');
            $settings->save();
        } catch (\Throwable $e) {
            error_log('[clientes_core] Discount migration failed: ' . $e->getMessage());
        }
    }

    // (3) Mandatory-group backfill, own flag.
    self::runMandatoryGroupBackfill($settings);
}

private static function runMandatoryGroupBackfill(\fs_settings $settings): void
{
    if ($settings->get('clientes_core_discount_group_required')) {
        return;                                   // idempotent: already migrated
    }

    try {
        // Ensure steps first: the backfill writes codes that must exist.
        self::ensureDefaultClientGroup();
        self::ensureDefaultDiscountGroup();

        $cliente = new cliente();
        $cliente->assignOrphanClientsToGroup('000001');               // codgrupo IS NULL -> 000001
        $cliente->assignOrphanClientsToDiscountGroup('000000');       // codgrupo_descuento IS NULL -> 000000

        $settings->set('clientes_core_discount_group_required', '1');
        $settings->save();
    } catch (\Throwable $e) {
        // Flag left unset => the next activation retries. Never breaks activation.
        error_log('[clientes_core] Mandatory group backfill failed: ' . $e->getMessage());
    }
}
```

### 9.3 `ensureDefaultClientGroup()` — R8 fix

```php
private static function ensureDefaultClientGroup(): void
{
    $grupoModel = new grupo_clientes();
    if ($grupoModel->get('000001')) {
        return;                                   // idempotent by code, NOT by table emptiness
    }

    $grupo = new grupo_clientes();
    $grupo->codgrupo = '000001';
    $grupo->nombre = 'General';
    $grupo->save();
}
```

The `table_has_rows()` early return is removed: a non-empty `gruposclientes` table that lacks
`'000001'` now gets the default created (spec `clientes → "General group is guaranteed on a
populated table"`).

### 9.4 `ensureDefaultDiscountGroup()` — new

```php
private static function ensureDefaultDiscountGroup(): void
{
    $model = new grupo_descuentos();
    if ($model->get('000000')) {
        return;
    }

    $grupo = new grupo_descuentos();
    $grupo->codgrupo_descuento = '000000';
    $grupo->nombre = 'Personalizado';
    $grupo->d1 = 0.00;
    $grupo->d2 = 0.00;
    $grupo->d3 = 0.00;
    $grupo->d4 = 0.00;
    $grupo->save();
}
```

### 9.5 Idempotency proof points

| Scenario (spec) | Mechanism |
|---|---|
| Orphan clients backfilled to the client group | `assignOrphanClientsToGroup('000001')` inside the flag-gated block |
| Clients without a discount group backfilled | `assignOrphanClientsToDiscountGroup('000000')` inside the flag-gated block |
| New flag independent of the legacy flag | separate `fs_settings` key; the legacy block returns early without skipping block (3) |
| Re-running is a no-op | `runMandatoryGroupBackfill()` returns immediately when the flag is `'1'`; the `ensure*` steps are also individually idempotent by code lookup |
| Migration adds no constraint | `clientes.xml` unchanged; only `UPDATE`/`INSERT` statements |

### 9.6 Test-fixture impact

`tests/Fixtures/InitUpgradeFakes.php` must be extended:

- `cliente` fake: `public $codgrupo_descuento;`, `assignOrphanClientsToDiscountGroup()` counter +
  last-code capture, and `countByGroup()` / `countByDiscountGroup()` stubs.
- a new `FSFramework\model\grupo_descuentos` fake (in-memory `$storedGroups`, `get`, `save`,
  `table_has_rows`) so the real discount-group class is not loaded.
- `InitUpgradeTest` assertions change: no `grupo_clientes::$storedGroups['000000']`; the client-group
  orphan assertion targets `'000001'`; a new test asserts the discount backfill and the new flag
  independence.

`VentasClienteDiscountsTest` T21 flips from `assertSame('000000', …->codgrupo)` to
"empty `codgrupo` stays null / fails validation", and its inlined `save_cliente` harness delegates its
**diff** to `ClienteForm::computeDescuentosModified()` instead of carrying a copy.

> **Correction (2026-09-22, recorded during the Slice 2a apply).** The original text above required the
> harness to delegate to `ClienteForm::apply()`. That is **infeasible**, not merely hard:
> `controller/ventas_cliente.php` hard-`require_once`s `model/core/grupo_descuentos.php`, so the test
> process always has the **real** `FSFramework\model\grupo_descuentos` declared before any stub can be
> aliased, and `class_alias` cannot override an already-declared class. Delegating `apply()` therefore
> resolves the group against the live DB (observed: the resolved group returned real data, `d1=30`) and
> makes the harness non-deterministic. The harness keeps its local field mapping and delegates only the
> diff — the part `shared-client-form → "Descuentos diff is computed in one place"` actually constrains,
> since that scenario binds **consumers**, not test fixtures. Production delegation rests on
> `saveClienteDelegatesMappingAndDiffToSharedAuthority` (source guard) plus the behavioural
> `nuevo_cliente_pure()` test; there is no end-to-end behavioural test of `save_cliente()`.

---

## 10. Decision 8 — Deletion-block semantics (Q4, OQ6)

### 10.1 Which group types are checked

| Guard | Group type | Column checked | Location |
|---|---|---|---|
| 1 | client group (`grupo_clientes`) | `clientes.codgrupo` | `controller/ventas_clientes.php::delete_grupo()` |
| 2 | discount group (`grupo_descuentos`) | `clientes.codgrupo_descuento` | `controller/descuentos_grupo.php::delete_grupo()` |

The guard is in the controller (per the `discount-groups` delta), because the `ca_clientes_grupos`
FK is `ON DELETE SET NULL` and would otherwise silently re-orphan clients.

### 10.2 Exact refusal behaviour

Client group (`ventas_clientes::delete_grupo()`), after the existing code validation and before
`$g->delete()`:

```php
$inUse = (new cliente())->countByGroup($cod);
if ($inUse > 0) {
    $this->new_error_msg('No se puede eliminar el grupo: hay clientes asignados a él.');
    $this->grupos = (new grupo_clientes())->all();
    $this->load_clientes();
    return;                                   // no DELETE is issued
}
```

Discount group (`descuentos_grupo::delete_grupo()`), after the existing `allow_delete` and code
validation and before `$grupo->delete()`:

```php
$inUse = (new cliente())->countByDiscountGroup($cod);
if ($inUse > 0) {
    $this->new_error_msg('No se puede eliminar el grupo de descuentos: hay clientes asignados a él.');
    $this->grupos_descuentos = $grupoDescModel->all();
    return;                                   // no DELETE is issued
}
```

- Outcome: **no `DELETE` is issued**, one error message is recorded via `new_error_msg()`, the
  listing reloads, HTTP stays 200 (no exception). No `header()` side effect.
- Message language follows the surrounding controller (Spanish), consistent with every other
  message in these files. The Twig-side UI strings use `trans()` with both `en`/`es` keys.
- Because the backfill assigns every client a discount group, `'000000'` "Personalizado" becomes
  effectively undeletable, satisfying `discount-groups → "Personalizado cannot be deleted while in
  use"`. The client default `'000001'` is likewise protected.
- Unreferenced groups still delete normally.

---

## 11. Decision 9 — tpvmod consumer contract (OQ4, R2, R3)

This section defines the contract only. The implementation lives in the `tpvmod` repository under
its own SDD home; **no `tpvmod` artifact is created under `clientes_core/openspec/`** and nothing is
created under core `openspec/`.

### 11.1 PHP delegation

```php
// tpvmod/lib/tpvmod_cliente.php
function tpvmod_cliente_apply_from_post(object $cliente, array $post): void
{
    \FSFramework\Plugins\clientes_core\ClienteForm::apply($cliente, $post);
}
```

- `tpvmod_cliente_sync_descuentos_modified_flag()` is **deleted**; the diff now lives only in
  `ClienteForm::computeDescuentosModified()`.
- The `'000000'` fallback at `tpvmod_cliente.php:81` is **deleted, not wrapped**.
- `tpvmod_cliente_json_response()` is unchanged: `{ ok, codcliente, label, cliente }` is preserved
  for `tpvmodSeleccionarCliente()` / `recalcular()`.
- `tpvmod_cliente_ajax_save_cliente()` is unchanged except that it now calls the delegating wrapper.
- `ClienteForm` autoloads via the root PSR-4 map even if `clientes_core` is not active, so the class
  is always resolvable when the plugin file tree is present.

### 11.2 Twig consumption

`tpvmod/view/ajax/tpv_cliente_form.html.twig` is reduced to a shell:

```twig
<form id="f_cliente_tpv" name="f_cliente_tpv" class="form"
      onsubmit="return false;" x-data="clienteGrupoRequired">
    {{ csrf_field() }}
    <input type="hidden" name="save_cliente_tpv" value="1"/>
    {% if not es_nuevo %}<input type="hidden" name="codcliente" value="{{ cliente.codcliente }}"/>{% endif %}

    <ul class="nav nav-tabs" role="tablist"> … Datos / Descuentos / Direcciones … </ul>

    <div class="tab-content" style="padding-top: 15px;">
        <div class="tab-pane active" id="tpv_cli_datos">
            {% set shared = include('@clientes_core/Cliente/Fields.html.twig', {
                mode: es_nuevo ? 'create' : 'edit',
                cliente: cliente,
                grupos: fsc.tpv_grupos,
                grupos_descuentos: fsc.tpv_grupos_descuentos,
                regimenes_iva: fsc.tpv_regimenes_iva,
                id_prefix: 'tpv_cliente',
                input_class: 'form-control input-sm'
            }, ignore_missing=true) %}
            {% if shared is empty %}
                <div class="alert alert-danger">
                    clientes_core is out of date: the shared client form is unavailable.
                    Update the clientes_core plugin.
                </div>
            {% else %}
                {{ shared }}
            {% endif %}
        </div>

        <div class="tab-pane" id="tpv_cli_descuentos">
            {% include '@clientes_core/Cliente/Discounts.html.twig' ignore missing with {
                cliente: cliente, grupos_descuentos: fsc.tpv_grupos_descuentos,
                id_prefix: 'tpv_cliente', input_class: 'form-control input-sm'
            } %}
        </div>

        <div class="tab-pane" id="tpv_cli_direcciones">
            {# unchanged: tpvmod keeps its own address editor (Q5) #}
        </div>
    </div>
    … footer buttons (external submit_mode) …
</form>
```

### 11.3 R2 — cross-repo version guard

`require` is a bare name list with no version grammar, so a version constraint cannot be expressed.
Mitigations, all required:

1. `tpvmod/fsframework.ini` gains a **direct** `clientes_core` requirement:
   `require = "clientes_core,clientes_facturacion,catalogo_core"`.
2. The include is guarded with an `ignore_missing=true` **namespaced-path probe**. On miss, the TPV
   renders a **degraded-state alert** (no divergent field markup) instead of silently rendering a
   partial form. This is the "minimal fallback": a graceful, honest degradation that does not
   reintroduce the duplication this change removes. A second copy of the field markup is explicitly
   rejected because the `shared-client-form` delta forbids independent copies of the form logic.
3. Release `clientes_core` and `tpvmod` together, with an operator note.

### 11.4 R3 — separate SDD homes

The `tpvmod` slice is implemented in its own repo and verified with
`ddev exec php vendor/bin/phpunit -c plugins/tpvmod/phpunit.xml`. No `tpvmod` artifact is copied
into `clientes_core/openspec/`; no entry is created under core `openspec/`.

---

## 12. Data-flow sequences

### 12.1 Edit page save (`ventas_cliente`)

```
POST index.php?page=ventas_cliente&action=save_cliente
  → requireMutationCsrf()
  → ClienteForm::apply($this->cliente, $this->request->request->all())
        · selective field mapping (no fallback groups)
        · load grupo_descuentos(codgrupo_descuento) → computeDescuentosModified()
  → $this->cliente->save() → cliente::test()
        · codgrupo NULL/''  → error, no persist
        · codgrupo_descuento NULL/'' → error, no persist
  → success: new_message ; failure: surface get_errors()
```

### 12.2 Create modal save (`ventas_clientes`)

```
POST action=nuevo_cliente
  → requireMutationCsrf()
  → nuevo_cliente_pure():
        $cliente = new cliente(); codcliente = post codcliente|codigo ?: null
        ClienteForm::apply($cliente, request->all())
        $cliente->save() → test() (both groups mandatory)
  → success: redirect to cliente->url()
```

### 12.3 TPV save (`tpvmod`)

```
POST index.php?page=tpvmod&save_cliente_tpv=1
  → isCsrfValid()
  → load/instantiate cliente
  → tpvmod_cliente_apply_from_post() → ClienteForm::apply()
  → $cliente->save() → test()
  → tpvmod_cliente_json_response() → { ok, codcliente, label, cliente }
```

Both page and TPV apply the same submission through the same authority, satisfying
`shared-client-form → "Page and TPV produce identical field state"`.

### 12.4 Activation

```
fs_plugin_manager::runPluginUpgrade
  → PluginSchemaSynchronizer::synchronize   (tables/constraints — no new constraint exists)
  → Init::upgrade()
        ensureDefaultClientGroup()   (000001, idempotent)
        ensureDefaultDiscountGroup() (000000, idempotent)
        default-client seed (only when the table is empty)
        legacy flag block (no codgrupo write)
        runMandatoryGroupBackfill()  (flag-gated, data-only UPDATEs)
```

---

## 13. Test strategy

| Layer | Test | Assertion |
|---|---|---|
| Authority (unit) | `tests/ClienteFormAuthorityTest.php` (new) | selective mapping; no fallback groups; `computeDescuentosModified` equality/inequality; `validate` delegates to `test()` |
| Migration | `tests/InitUpgradeTest.php` (rewritten) | `000001`/`000000` ensured on populated tables; both backfills; new flag independent of the legacy flag; re-run no-op |
| Migration fixtures | `tests/Fixtures/InitUpgradeFakes.php` | new `grupo_descuentos` fake; `cliente` fake gains `codgrupo_descuento`, discount-orphan counter, counters |
| Model | `tests/ClienteModelTest.php` | `test()` rejects NULL/'' `codgrupo_descuento`; `countByGroup` / `countByDiscountGroup`; `assignOrphanClientsToDiscountGroup` SQL |
| Schema | `tests/ClienteSchemaTest.php` (new) | `clientes.xml` keeps `codgrupo_descuento` nullable and declares no FK on it; `ca_clientes_grupos` unchanged |
| Controllers | `tests/Controller/VentasClienteDiscountsTest.php` | T21 flips to "empty `codgrupo` stays null / fails"; harness delegates to `ClienteForm` |
| Controllers | `tests/Controller/VentasClientesDispatchTest.php` | `delete_grupo` refusal when `countByGroup > 0`; normal delete when 0 |
| Controllers | new discount-group controller test | `descuentos_grupo::delete_grupo` refusal when `countByDiscountGroup > 0` |
| View (R5) | `tests/View/SharedFormHooksRenderTest.php` (new) | renders `@clientes_core/Cliente/Fields.html.twig`, hook output present with `fsc`/`cliente` |
| View (R7/R6) | `tests/View/ClienteGrupoRequiredViewTest.php` (rewritten) | partial contains the idempotent Alpine marker, `required` selects, `id_prefix`; consumer views include `@clientes_core/Cliente/Form.html.twig`; no `trans('sin-grupo')` |
| Conflation | grep audit | no `'000000'` written into `codgrupo` anywhere in `clientes_core` or `tpvmod` |

Commands:

```bash
ddev exec php vendor/bin/phpunit -c plugins/clientes_core/phpunit.xml
ddev exec php vendor/bin/phpunit --testsuite Plugins
ddev exec php vendor/bin/phpunit          # root regression
ddev exec composer phpstan                # linter from openspec/config.yaml
```

---

## 14. R1 explicit verification (no new constraint)

Design-level guarantee plus verifiable checks:

1. `plugins/clientes_core/model/table/clientes.xml` is **not in the change set**;
   `git diff --exit-code plugins/clientes_core/model/table/clientes.xml` must succeed.
2. The `clientes` capability spec scenario "Migration adds no constraint" is covered by
   `tests/ClienteSchemaTest.php`: assert `<nulo>YES</nulo>` for `codgrupo_descuento`, assert no
   `<restriccion>` mentions `codgrupo_descuento`, assert `ca_clientes_grupos` is still declared.
3. `PluginSchemaSynchronizer` ordering is therefore irrelevant: with no constraint added, there is
   no constraint-vs-data window. The only DB writes are `INSERT` of the two default groups and two
   `UPDATE`s, executed after the ensure steps.

---

## 15. Cross-cutting constraints

### 15.1 No core change (hard)

Files touched are only under `plugins/clientes_core/**` (this change) and `plugins/tpvmod/**` (its
own change/repo). No file under `base/`, `src/`, root `model/`, root `controller/`, or root
`openspec/` is modified or created. If implementation discovers a required core change, **stop** and
re-home the SDD to core `openspec/`.

### 15.2 R4 — clean baseline before apply

`plugins/clientes_core` is on `main`, dirty: `model/core/direccion_cliente.php` (unrelated
transactional rewrite), the Alpine mandatory-group edits in both views, three new translation keys
per locale, and untracked `tests/View/`. `apply` MUST start from a known baseline:

1. Create a baseline commit containing **only** the Alpine edits + translation keys + the untracked
   view test, staging path-scoped (`git add view/ventas_cliente.html.twig view/ventas_clientes.html.twig
   translations/messages.en.yaml translations/messages.es.yaml tests/View/`).
2. `model/core/direccion_cliente.php` is **never staged** and stays untouched.
3. Slice 2 then rewrites the views to include the shared partial, absorbing (and superseding) the
   Alpine baseline commit; the translation keys survive.

### 15.3 R6 — CSP

Only the nonce'd classic script pattern (`csp_nonce_attr()`), the idempotent
`window.__clientesCoreGrupoRequiredRegistered` marker, and no `unsafe-eval`. The partial emits no
inline handler; consumers keep their pre-existing JS wiring via `submit_attrs`. The
partial is safe to include multiple times.

### 15.4 R7 — DOM ids

All partial ids derive from `id_prefix` (§6.1). Consumer-owned ids remain in the consumers.

### 15.5 R10 — active core changes

`view-hook-registry-core` is active (unarchived) and `ventas-clientes-controller-dedup` /
`ventas-clientes-dispatch-regression-test` overlap this surface. This design depends **only** on the
existing `ViewHookRegistry::register()/render()` API and the existing `render_hook` Twig function
(the same surface `clientes_catalogo` already uses); it introduces no new hook feature. The R5
render test guards the dependency. Confirm the core change's state before apply; because this change
touches no core file, there is no merge conflict in core.

### 15.6 Translations

New keys required in `translations/messages.{en,es}.yaml`:

- `grupo-descuentos-obligatorio` — "You must select a discount group to save." / "Debe seleccionar
  un grupo de descuentos para guardar."
- `seleccione-grupo-descuentos` — "Select a discount group" / "Seleccione un grupo de descuentos"

Existing keys reused: `grupo-obligatorio`, `seleccione-grupo`, `sin-grupos-disponibles`,
`grupo-cliente`, `grupo-descuentos`. The `sin-grupo-descuentos` empty option is **removed** (the
discount group is mandatory); the key becomes unused and may be left in place (no dead-key churn
required) or removed in the same slice.

Controller refusal messages stay in Spanish, matching the surrounding files; the Twig UI copy uses
`trans()` keys in both locales.

---

## 16. Implementation slices (aligned with the proposal)

| # | Slice | Owner | Design sections |
|---|---|---|---|
| 1 | Conflation fix + mandatory-group data layer | `clientes_core` | §8, §9, §10, §12.4, §15.2/§15.6 |
| 2 | Shared form component + wire `clientes_core` pages | `clientes_core` | §3, §4, §5, §6, §7, §12.1, §12.2 |
| 3 | `tpvmod` consumer migration | `tpvmod` (own repo/SDD) | §11, §12.3 |

Slices are sequential and independently revertable. Slice 1 must land before slice 2 (the partial
renders the mandatory discount group). Slice 3 depends on slice 2 being released.

---

## 17. Traceability — spec requirement → design

| Spec requirement | Design |
|---|---|
| `shared-client-form` → Shared client form partial | §3, §5 |
| `shared-client-form` → Form parameterization for modal and page modes | §5.1, §6 |
| `shared-client-form` → Client form extension hooks remain rendered | §7, §7.1 |
| `shared-client-form` → Single shared PHP apply, validate and diff authority | §4, §4.1, §12 |
| `shared-client-form` → Addresses are excluded from the shared component | §7 (Q5) |
| `shared-client-form` → TPV consumer contract | §11 |
| `clientes` → New client gets default group | §8.1, §8.2, §9.3 |
| `clientes` → Mandatory group backfill on plugin activation | §9 |
| `client-discount-inheritance` → Group is mandatory for all clients | §8.2, §8.4, §9 |
| `discount-groups` → Default "Personalizado" group | §9.4, §10 |
| `discount-groups` → In-use group deletion is blocked | §10 |

---

## 18. Risks and open items

| Risk | Status after design |
|---|---|
| R1 schema-sync ordering | **Neutralised**: `clientes.xml` unchanged; explicit checks in §14 |
| R2 cross-repo coupling | Mitigated in §11.3 (direct `require` + `ignore_missing` probe + degraded alert + coordinated release) |
| R3 two SDD homes | Handled in §11.4; no artifact duplication |
| R4 dirty baseline | Procedure in §15.2; `direccion_cliente.php` preserved untouched |
| R5 hook regression | Render-level assertion in §7.1 |
| R6 CSP | §6.2, §15.3 |
| R7 DOM id collisions | §6.1, §15.4 |
| R8 `ensureDefaultClientGroup()` | §9.3 |
| R9 no PHP service layer | §4 (root PSR-4, no `vendor/`) |
| R10 active core changes | §15.5 (existing hook API only) |

**No blocking open questions remain.** Q1–Q6 are consumed as confirmed and not reopened. The
`tpvmod` Alpine question is resolved by degradation (native `required` + server-side `test()`), not
by loading Alpine into six TPV views.

---

## 19. No-core-change confirmation

Confirmed: this design requires **no** modification to `base/`, `src/`, root `model/`, root
`controller/`, or root `openspec/`. The component is exposed through the existing `TwigLoaderEvent`
extension point and the existing root Composer PSR-4 map. If implementation proves a core change is
required, work stops and the SDD home moves to core `openspec/`.
