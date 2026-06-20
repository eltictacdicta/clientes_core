# Change: default-client-on-activation

## Why

A fresh FSFramework installation activates the `clientes_core` plugin
with an empty `clientes` table. Sales and invoicing flows (presupuestos,
pedidos, albaranes, facturas) reference `clientes.codcliente` as a
foreign key, and `cliente::get_new_codigo()` only generates the next
code from existing rows — so on a brand-new install there is no
`sentinel` client row to assign to cash sales, anonymous walk-in
invoices, or test transactions. The result is FK errors and broken
flows in the first minutes of a deployment.

The operator expectation in a 2017-era FacturaScripts install was that
the plugin would plant a single placeholder client at activation time,
so downstream flows always had at least one valid client to point at.
This change restores that expectation as a one-shot, idempotent
seeder: it runs at most once per installation, persists the fact that
it ran, and never blocks activation if the seed itself fails.

## What changes

- A new static `Init::upgrade()` method on `plugins/clientes_core/Init.php`
  runs the first time the plugin manager activates `clientes_core`
  (`base/fs_plugin_manager.php::runPluginUpgrade()`).
- If the `clientes_core_default_seeded` flag in `fs_settings` is not
  set, the seeder counts rows in the `clientes` table.
  - If the table is empty, it inserts a single `cliente` with
    `nombre = 'Cliente por defecto'` and all other fields left at the
    model's constructor defaults (`cifnif=''`, `tipoidfiscal='NIF'`,
    `personafisica=true`, `regimeniva='General'`, `debaja=false`,
    `codcliente` auto-assigned by `cliente::get_new_codigo()`).
  - In both branches (empty and non-empty table), the seeder sets
    `clientes_core_default_seeded = '1'` in `fs_settings` and
    persists it to disk via `fs_settings::save()`.
- The seeder body is wrapped in `try { ... } catch (\Throwable $e) { /* swallow */ }`
  so a DB error during the insert never breaks plugin activation.
- No translation key is added. The literal string `'Cliente por defecto'`
  is a DB seed value, not a UI string, and stays hardcoded in the
  source.
- A new isolated test class covers the seeder logic, exercising the
  flag-empty/flag-set and table-empty/table-non-empty branches with
  mocked DB and `fs_settings`.

## Scope

### In scope

- `plugins/clientes_core/Init.php` — new static `upgrade()` method
  (coexists with the existing instance `init()` method which is still
  called per request for Twig registration).
- `plugins/clientes_core/tests/InitUpgradeSeederTest.php` — new test
  file using the project's anonymous-subclass + mock `fs_db2` pattern
  (matches `plugins/clientes_core/tests/ClienteModelTest.php` style).
- `fs_settings` (`base/fs_settings.php`) — used as the persistence
  layer for the `clientes_core_default_seeded` flag. No new public
  API; only the existing `get()` / `set()` / `save()` methods.
- `plugins/clientes_core/openspec/` — new change scaffolding
  (config.yaml, specs/clientes/spec.md, changes/default-client-on-activation/{proposal.md, specs/}).

### Out of scope

- No translation key, no UI flash, no log line on seed success.
- No new model, no new DB table, no schema change to `clientes.xml`.
- No settings UI to view or reset the flag.
- No `delete_*` event listener for clients — the seeder is a
  one-shot bootstrap, not a re-runnable migration.
- No changes to the `cliente` model itself (`test()`, `save()`,
  `get_new_codigo()` are consumed as-is).
- No changes to the core `openspec/` tree. The change is 100% internal
  to the plugin.

## Approach

The seeder runs as a `public static` method on `Init` so the framework
can call it via reflection (see `fs_plugin_manager::runPluginUpgrade`,
`base/fs_plugin_manager.php:643-655`). The body is small and depends
on three precise contracts from the design phase:

- **Cliente class location**: the production class is declared as
  `class cliente extends \fs_model` inside the `FSFramework\model`
  namespace (`plugins/clientes_core/model/core/cliente.php:21,31`).
  The seeder MUST reference it via `use \FSFramework\model\cliente;`
  at the top of `Init.php` and instantiate `new cliente()` — NOT the
  legacy global `\cliente` alias. This pins the production code to
  the namespaced class, removes the global-symbol ambiguity, and
  gives the test suite a clean autoloader seam for substitution
  (verified during the design-phase mock-strategy analysis).

- **Row count via the model DB handle**: the `cliente` model has no
  `count()` method. The seeder uses the model's internal `fs_db2`
  handle to detect a non-empty table with the cheapest possible
  query:
  ```php
  $rows = $cliente->db->select("SELECT 1 FROM clientes LIMIT 1");
  $tableEmpty = empty($rows);
  ```
  `SELECT 1 ... LIMIT 1` returns a non-empty array iff at least one
  row exists, and `empty($rows)` is `true` for both `null` and `[]`.

- **`fs_settings::save()` writes the whole `config2` array**: the
  `save()` method (line 264 of `base/fs_settings.php`) takes **no
  argument** and persists the entire `$GLOBALS['config2']` array to
  `tmp/{FS_TMP_NAME}config2.ini` (PHP-INI-style). The seeder does
  not assume ownership of that array: the flag is one key among many,
  and the seeder never reads, mutates, or asserts anything about the
  other keys. If `save()` returns `false` (e.g., `tmp/` not writable),
  the in-memory `$GLOBALS['config2']` value is still updated by the
  preceding `set()` call, and the next activation will retry.

- **Framework already wraps `upgrade()` in its own `try/catch`**:
  `fs_plugin_manager::runPluginUpgrade` (`base/fs_plugin_manager.php:643-655`)
  invokes `$initClass::upgrade()` inside its own
  `try { ... } catch (\Throwable $e) { error_log(...) }`. The
  seeder's own `try/catch` is therefore **belt-and-suspenders**, not
  the only line of defence: it keeps the seeder safe under any
  future caller (CLI scripts, direct unit-test invocation) that
  does not provide the framework's outer guard.

Putting the contracts together, the seeder's body follows this
flow:

1. `Init::upgrade()` is declared `public static function upgrade(): void`.
2. Build a `new \fs_settings()` and read
   `$settings->get('clientes_core_default_seeded')`. If truthy
   (already `'1'` from a previous run), return immediately — outside
   the `try` because reading from a global array cannot throw.
3. Otherwise, enter `try { ... } catch (\Throwable $e) { /* swallow */ }`.
4. Inside the `try`:
   - Instantiate `new cliente()` (via the `use` import). The
     constructor calls `fs_model::__construct('clientes')` which
     auto-creates the `clientes` table from `model/table/clientes.xml`
     on a brand-new install.
   - `$rows = $cliente->db->select("SELECT 1 FROM clientes LIMIT 1");`
     - If `empty($rows)`: set `$cliente->nombre = 'Cliente por defecto'`
       and call `$cliente->save()`. Any thrown `\Throwable` is
       caught by the outer `catch`.
     - If non-empty: skip the insert.
5. After the insert branch (success or skipped), call
   `$settings->set('clientes_core_default_seeded', '1')` and
   `$settings->save()` to persist the entire `config2` array.
6. The method always returns without re-throwing; plugin activation
   cannot fail because of this seeder.

The "set the flag in both branches" choice is the safer interpretation
of product decision 7: a user who manually populated `clientes` before
activating the plugin still avoids the per-activation count query on
subsequent reactivations, and a re-activation after deactivation never
re-runs the insert.

## Affected files

| File | Action | Notes |
|------|--------|-------|
| `plugins/clientes_core/Init.php` | modify | Add a new `use \FSFramework\model\cliente;` import at the top of the file (joins the existing import block — `FSEventDispatcher`, `TwigInitEvent`, and the `ViewHookRegistry` `require_once` are left untouched). Add `public static function upgrade(): void` and the seeder body. The existing instance `init()` method is NOT modified — Twig extension registration continues to run on every request, unchanged. |
| `plugins/clientes_core/tests/InitUpgradeSeederTest.php` | create | TDD coverage for: flag empty + empty table → insert + flag set; flag set → no-op; flag empty + non-empty table → no insert + flag set; DB error during insert → swallowed, flag not set; double-upgrade idempotency. |
| `plugins/clientes_core/phpunit.xml` | unchanged | Already lists `model` and `src` source dirs and the `clientes_core` test suite. |
| `base/fs_settings.php` | unchanged | Consumed via `new fs_settings()`; `get()`, `set()`, `save()` already exist. |
| `base/fs_plugin_manager.php` | unchanged | Already invokes `Init::upgrade()` via `runPluginUpgrade()` (lines 643-655). |

## Risks and mitigations

| Risk | L | Mitigation |
|------|---|------------|
| `upgrade()` runs on every activation; nothing stops repeat inserts. | M | Persistent flag `clientes_core_default_seeded` in `fs_settings` short-circuits the seeder; second line of defence is the `clientes` count check. |
| DB error during the seed insert could break plugin activation. | M | Outer `try { ... } catch (\Throwable $e) { /* swallow */ }`. The plugin manager itself also has its own try/catch around the upgrade call. |
| `default_items->coddivisa()` / `default_items->codpago()` may not be initialised at activation time. | M | The seeder only assigns `nombre`; all other fields use the `cliente` constructor defaults, which the existing test confirms pass `test()` with empty `cifnif`. No call to `default_items` is made. |
| First plugin to use `Init::upgrade()` — establishes a convention for future plugins. | M | Documented explicitly here and in the proposal; subsequent plugins can copy the seeder shape (idempotent flag + try/catch). |
| Race condition on two concurrent activations (e.g. CLI `system_updater` racing a web request). | L | Flag is the only synchronisation primitive; the window is theoretical and the worst case is a duplicate `Cliente por defecto` row (a recoverable cosmetic issue, not a correctness one). Accepted. |
| `fs_settings::save()` writes to `tmp/{FS_TMP_NAME}config2.ini`; if the directory is not writable the save fails silently. | L | Flag write is the only side effect of a failed save; next activation re-attempts. The seeder insert itself already ran (or was skipped) before the flag write. |
| `cliente::save()` triggers `clean_cache()` which touches the global cache. | L | Standard behaviour; the seeder is no different from any other `cliente::save()` call. |
| Table does not exist on first activation: `runPluginUpgrade` runs before `ensurePluginTables` (`base/fs_plugin_manager.php:484-485`), so on a brand-new install the `clientes` table does not yet exist when `Init::upgrade()` is invoked. | M | `new cliente()` (via the `use \FSFramework\model\cliente;` import) triggers `fs_model::__construct → check_table` which auto-creates the `clientes` table from `model/table/clientes.xml` on the fly when it is missing. The seeder therefore **doubles as a table creator** on the empty-install path: instantiation creates the table, then `$cliente->save()` inserts the row. This is an explicit, expected side effect — documented so future maintainers do not assume the table must already exist before `Init::upgrade()` runs. |
| `fs_settings::save()` writes the **entire** `$GLOBALS['config2']` array (not just the seeder's flag) to `tmp/{FS_TMP_NAME}config2.ini`. | L | Acceptable. The seeder does not rely on the array containing only its own flag, and it does not assume ownership of unrelated keys — it only reads/writes the `clientes_core_default_seeded` key via `get()` / `set()`. The whole-array write is treated as opaque persistence, and the seeder is robust against other keys being present (or absent). |
| First adopter of `Init::upgrade()` — the activation hook has no precedent in the codebase. Future maintainers may not recognise the static / per-activation contract. | M | Add a docblock comment at the top of the new `upgrade()` method in the source explaining: "Activation hook, called once per activation by `fs_plugin_manager::runPluginUpgrade`. Convention established by the default-client-on-activation change." Subsequent plugins can copy this template verbatim. |
| `plugins/clientes_core/Init.php` already has `use` statements (`FSEventDispatcher`, `TwigInitEvent`) and a `require_once` for `ViewHookRegistry`. The new `use \FSFramework\model\cliente;` joins an existing import block; no structural change to the file. | L | Mitigation: the new import is appended to the existing `use` block in source order. The `namespace FSFramework\Plugins\clientes_core;` declaration, the `require_once __DIR__ . '/src/ViewHookRegistry.php';` line, and the existing `init()` method are all left untouched. Verified by reading `plugins/clientes_core/Init.php` lines 20-40. |

## Out of scope / non-goals

- No i18n: the literal `'Cliente por defecto'` is a DB seed value.
  A future i18n pass over seed data is a separate change.
- No UI feedback: no flash message, no log line, no notification.
  The seeder is a silent bootstrap.
- No "user explicitly chose to skip" flag — the seeder's "skip if
  table non-empty" branch already gives users that outcome implicitly.
- No settings UI to reset the flag. Resetting requires editing
  `tmp/{FS_TMP_NAME}config2.ini` directly, which is a deliberate
  friction choice (avoid accidental re-seeds).
- No bulk-import of multiple default clients. Exactly one seed row.
- No support for seeding other domain defaults (e.g. default article,
  default supplier). Out of scope for this change.
- No modifications to the existing instance `Init::init()` method —
  Twig extension registration continues to run on every request,
  unchanged. The seeder adds a new **static** `upgrade()` method;
  it does not touch the instance `init()` lifecycle, the existing
  `use` block (`FSEventDispatcher`, `TwigInitEvent`), or the
  `ViewHookRegistry` `require_once`.

## Open questions

None — all product decisions confirmed by user.
