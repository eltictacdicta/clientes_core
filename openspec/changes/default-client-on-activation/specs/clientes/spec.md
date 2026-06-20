# Delta spec — default-client-on-activation

Change: `default-client-on-activation`
Base spec: `plugins/clientes_core/openspec/specs/clientes/spec.md`

## Purpose

Add an idempotent, one-shot seeder that runs the first time the
`clientes_core` plugin is activated in a given installation. The
seeder ensures exactly one client named `'Cliente por defecto'`
exists in the `clientes` table so that downstream sales and
invoicing flows always have at least one valid `codcliente` to
reference. The seeder persists a flag in `fs_settings` so it never
runs twice and never blocks plugin activation if the DB insert
itself fails.

## ADDED Requirements

### Requirement: Default client seed on plugin activation

The system SHALL, the first time the `clientes_core` plugin is
activated in a given installation, ensure that exactly one client
named "Cliente por defecto" exists in the `clientes` table.

The seeder SHALL use the static `Init::upgrade()` hook in
`plugins/clientes_core/Init.php`, called by
`base/fs_plugin_manager.php::runPluginUpgrade()`.

The seeder MUST reference the `cliente` model via the fully
qualified class `\FSFramework\model\cliente` (declared in
`plugins/clientes_core/model/core/cliente.php:21,31`), imported
with `use \FSFramework\model\cliente;` at the top of `Init.php`
and instantiated as `new cliente()`. The seeder MUST NOT rely on
the legacy global `\cliente` alias; the namespaced reference is
mandatory because it pins production to the correct class and
gives the test suite a clean autoloader seam for substitution.

The seeder SHALL be a no-op if the `clientes_core_default_seeded`
flag is already set in `fs_settings`.

The seeder SHALL determine whether the `clientes` table is
non-empty by calling `$cliente->table_has_rows()` and treating a
`true` return as "non-empty". If `table_has_rows()` returns
`true`, the seeder SHALL set the flag and return without
inserting.

The `cliente` model SHALL expose a public method
`table_has_rows(): bool` that returns `true` iff the underlying
`clientes` table contains at least one row. The method SHALL NOT
reach for `$this->db` from outside the class; it SHALL encapsulate
the `SELECT 1 ... LIMIT 1` query internally using
`$this->table_name` (so the method is portable across any model
of the same shape). The method exists precisely because
`fs_model::$db` is `protected` and a seeder that lives in another
class (e.g. `Init::upgrade()`) cannot reach the handle directly
without triggering `Error: Cannot access protected property`.

On a successful seed (empty table → INSERT), the seeder SHALL set
the `clientes_core_default_seeded` flag to `'1'` in `fs_settings` and
persist it to disk via `fs_settings::save()`.

The seeder SHALL set only `nombre='Cliente por defecto'` on the
new client; all other fields SHALL use the `cliente` model's
constructor defaults (`cifnif=''`, `tipoidfiscal='NIF'`,
`personafisica=true`, `regimeniva='General'`, `debaja=false`,
`codcliente` auto-assigned by `cliente::get_new_codigo()`).

The seeder body SHALL be wrapped in
`try { ... } catch (\Throwable $e) { /* swallow */ }` so a DB
error during the seed never breaks plugin activation.

#### Scenario: Empty install triggers the seed

- GIVEN a fresh install with no rows in `clientes`
- AND no `clientes_core_default_seeded` flag in `fs_settings`
- WHEN the plugin manager activates `clientes_core`
- AND `Init::upgrade()` runs
- THEN exactly one row is inserted into `clientes`
- AND that row's `nombre` is `'Cliente por defecto'`
- AND `clientes_core_default_seeded` is set to `'1'` in `fs_settings`
- AND the flag is persisted to `tmp/{FS_TMP_NAME}config2.ini`

#### Scenario: Non-empty install skips the insert and still sets the flag

- GIVEN an install where the `clientes` table already has at least one row
- AND no `clientes_core_default_seeded` flag in `fs_settings`
- WHEN the plugin manager activates `clientes_core`
- AND `Init::upgrade()` runs
- THEN no row is inserted into `clientes`
- AND `clientes_core_default_seeded` is set to `'1'` in `fs_settings`

#### Scenario: Re-activation after deactivation is a no-op

- GIVEN a previous activation set `clientes_core_default_seeded = '1'`
- WHEN the user deactivates and re-activates `clientes_core`
- AND `Init::upgrade()` runs again
- THEN no row is inserted into `clientes`
- AND the flag value is unchanged

#### Scenario: DB error during seed does not break activation

- GIVEN an empty `clientes` table
- AND no `clientes_core_default_seeded` flag in `fs_settings`
- WHEN the INSERT fails with a `\Throwable` (e.g. DB down, FK violation)
- AND `Init::upgrade()` runs
- THEN the `\Throwable` is swallowed inside `Init::upgrade()`
- AND plugin activation completes successfully
- AND `clientes_core_default_seeded` is NOT set (so the next
  activation can retry)

### Requirement: Idempotency and flag persistence

The seeder flag SHALL be read from and written to `fs_settings`
(existing legacy settings store at `base/fs_settings.php`).

The flag key SHALL be `clientes_core_default_seeded`.

The flag value SHALL be the string `'1'`.

The flag SHALL persist across plugin deactivation, reactivation, and
across application reboots, via `fs_settings::save()` writing to
`tmp/{FS_TMP_NAME}config2.ini` and `base/config2.php` reloading it
on the next request.

The seeder SHALL call `fs_settings::save()` after writing the flag;
this call takes no argument and persists the **entire**
`$GLOBALS['config2']` array (not just the seeder's flag) to
`tmp/{FS_TMP_NAME}config2.ini` in PHP-INI-style
(`base/fs_settings.php:264-278`). The seeder SHALL NOT depend on
the array containing only the seeder's flag, and SHALL NOT assume
ownership of unrelated keys.

The seeder's body SHALL be wrapped in a top-level
`try { ... } catch (\Throwable $e) { /* swallow */ }` so a DB
error during the seed never breaks plugin activation. This is in
addition to (not a replacement for) the framework-level
`try/catch` in `fs_plugin_manager::runPluginUpgrade`
(`base/fs_plugin_manager.php:650-654`); the local guard keeps the
seeder safe under any future caller (CLI scripts, direct
unit-test invocation) that does not provide the framework's
outer guard.

#### Scenario: Flag persists across requests

- GIVEN `Init::upgrade()` ran and set the flag to `'1'`
- WHEN the next HTTP request starts
- AND `base/config2.php` reloads `tmp/{FS_TMP_NAME}config2.ini`
- THEN `$GLOBALS['config2']['clientes_core_default_seeded']` is `'1'`
- AND a subsequent `Init::upgrade()` short-circuits without touching `clientes`

#### Scenario: Flag is not set when the seeder is skipped by an early return

- GIVEN the flag is already set to `'1'`
- WHEN `Init::upgrade()` runs
- THEN the method returns before reaching the table count or insert
- AND the flag value is unchanged

## MODIFIED Requirements

None.

## REMOVED Requirements

None.

## Scenarios

These scenarios compose the requirements above and provide the
minimum acceptance contract for the change.

### Scenario: Cold start (empty install)

- GIVEN a fresh install with an empty `clientes` table
- AND no `clientes_core_default_seeded` flag
- WHEN `clientes_core` is activated for the first time
- THEN one `cliente` row with `nombre='Cliente por defecto'` is inserted
- AND the flag is set and persisted

### Scenario: Cold start auto-creates the `clientes` table

- WHEN `Init::upgrade()` is invoked on a fresh install where the
  `clientes` table does not yet exist (e.g., right after activating
  the plugin for the first time, before `ensurePluginTables` has
  been called) AND the `clientes_core_default_seeded` flag is not
  set
- THEN the seeder SHALL instantiate `\FSFramework\model\cliente`
  via the `use \FSFramework\model\cliente;` import
- AND the model constructor SHALL auto-create the `clientes` table
  from the XML schema (`model/table/clientes.xml`)
- AND the seeder SHALL then insert exactly one client with
  `nombre='Cliente por defecto'`
- AND the seeder SHALL set the flag and call `fs_settings::save()`

### Scenario: Re-activation of a previously seeded install

- GIVEN the flag is already `'1'`
- WHEN the user re-activates `clientes_core`
- THEN the seed is a no-op
- AND no new client is inserted

### Scenario: Activation on a manually populated install

- GIVEN the `clientes` table is non-empty
- AND the flag is unset
- WHEN `clientes_core` is activated
- THEN no client is inserted
- AND the flag is set to `'1'` so future activations short-circuit

### Scenario: Activation survives a DB error

- GIVEN the `clientes` table is empty
- AND the INSERT raises a `\Throwable`
- WHEN `clientes_core` is activated
- THEN the error is swallowed inside `Init::upgrade()`
- AND activation completes
- AND the flag is NOT set (so retry is possible on the next activation)

### Scenario: Seeder uses the public `table_has_rows()` API

- WHEN `Init::upgrade()` is invoked
- THEN the seeder SHALL call `$cliente->table_has_rows()` exactly
  once
- AND the seeder SHALL NOT directly access `$cliente->db` from
  outside the class (the property is `protected` and reaching for
  it would throw `Error: Cannot access protected property`)

### Scenario: `cliente::table_has_rows()` is public and table-portable

- WHEN the `cliente` model is loaded
- THEN `\FSFramework\model\cliente::table_has_rows` SHALL be a
  public method
- AND its return type SHALL be `bool`
- AND when called against an empty table, it SHALL return `false`
- AND when called against a table with at least one row, it SHALL
  return `true`
- AND the SELECT it issues SHALL target `$this->table_name` (so a
  subclass that overrides the table name still works)
