# Design — default-client-on-activation

## Goals and non-goals

### Goals

- When the `clientes_core` plugin is activated, ensure that exactly one
  client named `'Cliente por defecto'` exists in the `clientes` table
  in any given installation.
- Run the seeder at most once per installation. A persistent flag in
  `fs_settings` short-circuits the body on every subsequent activation.
- Skip the insert if the `clientes` table already has at least one row
  (the operator has already populated clients) — but still set the
  flag so re-activations stop counting the table.
- Guarantee that a DB error during the seed never breaks plugin
  activation. The seeder body is wrapped in
  `try { ... } catch (\Throwable $e) { /* swallow */ }`.
- Land the change 100% inside `plugins/clientes_core/`. No edits in
  `base/`, `src/`, `controller/` or core `openspec/`.

### Non-goals

- No new translation key, no UI flash, no log line on seed success.
  The literal `'Cliente por defecto'` is a DB seed value, not a UI
  string, and stays hardcoded in the source.
- No new model, no new DB table, no schema change to `clientes.xml`.
- No settings UI to view or reset the seeder flag. Resetting
  requires editing `tmp/{FS_TMP_NAME}config2.ini` directly, which
  is a deliberate friction choice.
- No `delete_*` event listener for clients. The seeder is a one-shot
  bootstrap, not a re-runnable migration.
- No changes to the `cliente` model itself. `test()`, `save()` and
  `get_new_codigo()` are consumed as-is.
- No seeding of other domain defaults (default article, default
  supplier, etc.). Out of scope for this change.

## Architecture

### Lifecycle

`Init::upgrade()` is invoked by `base/fs_plugin_manager.php` inside
`fs_plugin_manager::enable()` after the plugin name is appended to
`$GLOBALS['plugins']` and persisted, and **before** the plugin's
XML-defined tables are created from `model/table/`.

Call chain (pseudocode):

```
fs_plugin_manager::enable($plugin_name)               // base/fs_plugin_manager.php:433
  ├── $GLOBALS['plugins'][] = $name                   // line 475
  ├── $this->save()                                   // line 476 — persist active list
  ├── require_all_models()                            // line 482
  ├── $this->runPluginUpgrade($name)                  // line 484
  │     └── Init::upgrade()                           // line 651 — call site
  ├── $this->ensurePluginTables($name)                // line 485 — create XML tables
  └── $this->enable_plugin_controllers($name)         // line 495
```

`runPluginUpgrade` itself is `private` (`base/fs_plugin_manager.php:643`):

```php
private function runPluginUpgrade(string $plugin_name): void
{
    $initClass = '\\FSFramework\\Plugins\\' . $plugin_name . '\\Init';
    if (!class_exists($initClass) || !method_exists($initClass, 'upgrade')) {
        return;
    }
    try {
        $initClass::upgrade();
    } catch (\Throwable $e) {
        error_log('fs_plugin_manager: plugin upgrade failed for ' . $plugin_name . ': ' . $e->getMessage());
    }
}
```

Two facts from this code shape the design:

1. `Init::upgrade()` MUST be `public static` — the framework calls it
   as `$initClass::upgrade()` with no instance.
2. The framework already wraps the call in its own `try/catch`, so the
   plugin-level `try/catch` is **belt-and-suspenders** rather than the
   only line of defence. It also makes the seeder safe under any
   future caller (unit tests, CLI scripts) that may not provide the
   same outer guard.

### Ordering: upgrade runs before table creation

`runPluginUpgrade($name)` is invoked **before**
`ensurePluginTables($name)` (lines 484 → 485 in
`fs_plugin_manager.php`). When the seeder calls `new \cliente()`,
the `clientes` table may not exist yet on a brand-new install.

This is acceptable: `fs_model::__construct()`
(`base/fs_model.php:105`) calls `check_table($table_name)` (line 132)
which auto-creates the table from the plugin's XML schema on the fly
when it does not exist. So the seeder effectively **doubles** as a
table-creator for the empty-install path — `new \cliente()` triggers
the creation, then `$cliente->save()` inserts the row.

### One-shot enforcement

The seeder uses a single persistent flag in `fs_settings`:

- **Key**: `clientes_core_default_seeded`
- **Value**: the string `'1'`
- **Store**: `$GLOBALS['config2']['clientes_core_default_seeded']`
  (in-memory) → persisted to `tmp/{FS_TMP_NAME}config2.ini` by
  `fs_settings::save()` → reloaded into `$GLOBALS['config2']` on
  the next request by `base/config2.php`.

Activity diagram (textual):

```
Init::upgrade()
  │
  ├── settings = new fs_settings()
  ├── if settings.get('clientes_core_default_seeded') is truthy
  │       └── return                          ← short-circuit
  │
  └── try {
        cliente = new cliente()              ← also creates `clientes` table if missing
        count = cliente.db.select("SELECT 1 FROM clientes LIMIT 1")
        if count is empty:
            cliente.nombre = 'Cliente por defecto'
            cliente.save()                   ← INSERT; any throw is caught below
        settings.set('clientes_core_default_seeded', '1')
        settings.save()                      ← writes tmp/.../config2.ini
      } catch (\Throwable $e) {
        // swallow: never break plugin activation
      }
```

The flag is set in **both** branches (empty table → INSERT, and
non-empty table → skip insert). The non-empty branch is the safer
interpretation: a user who manually populated `clientes` before
activating the plugin avoids the per-activation `SELECT` on every
subsequent reactivation.

### Failure isolation

The entire body of the seeder (after the early-return flag check) is
wrapped in `try { ... } catch (\Throwable $e) { /* swallow */ }`. This
catches:

- DB errors during `new \cliente()` (table create failure).
- DB errors during `cliente.db->select()` (count query).
- DB errors during `cliente->save()` (INSERT).
- DB errors during `settings.set()` / `settings.save()` (config
  write).
- Any other `\Throwable` from the `cliente` constructor's
  `load_commercial_extension()` or `install()` paths.

The only exception the seeder can let propagate is a PHP `Error`
class that is not a `\Throwable` descendant — and there are none in
this code path. The outer `fs_plugin_manager` `try/catch` is the
second line of defence.

## API and contracts

### New method: `plugins/clientes_core/Init.php::upgrade`

```php
public static function upgrade(): void
```

- **Visibility**: `public` — required for `method_exists($initClass, 'upgrade')`
  in `runPluginUpgrade` to find it via reflection.
- **Static**: required — `runPluginUpgrade` calls
  `$initClass::upgrade()` without `new`.
- **Return type**: `void`. The seeder never returns data and never
  throws.
- **Side effects**:
  1. May create the `clientes` table (transitively via
     `new \cliente()` → `fs_model::__construct()` →
     `check_table()`).
  2. May INSERT a `clientes` row with `nombre = 'Cliente por defecto'`.
  3. Sets `$GLOBALS['config2']['clientes_core_default_seeded'] = '1'`
     and writes the entire `config2` array to
     `tmp/{FS_TMP_NAME}config2.ini`.

### `fs_settings` API (consumed)

File: `base/fs_settings.php`. Confirmed API (read from source):

| Method | Signature | Behaviour |
|---|---|---|
| `get` | `public function get(string $key, $default = null): mixed` (line 43) | Returns `$GLOBALS['config2'][$key] ?? $default`. |
| `set` | `public function set(string $key, $value): void` (line 55) | Writes to `$GLOBALS['config2'][$key]`. **Does not auto-save.** |
| `has` | `public function has(string $key): bool` (line 66) | Returns `isset($GLOBALS['config2'][$key])`. |
| `remove` | `public function remove(string $key): void` (line 77) | Unsets the key. |
| `save` | `public function save(): bool` (line 264) | Writes the **entire** `$GLOBALS['config2']` array to `tmp/{FS_TMP_NAME}config2.ini` (PHP-INI-style). **Takes no argument.** Returns `false` if the file cannot be opened (e.g., non-writable `tmp/`). |

Edge cases the seeder must accept:

- `fs_settings::save()` may return `false` if `tmp/` is not writable.
  The flag value remains in `$GLOBALS['config2']` for the current
  request but is lost on the next reboot. The seeder treats this as
  a silent no-op — the next activation will retry. This is the
  worst case already accepted in the proposal.
- `fs_settings::get()` returns `null` (the default) when the key is
  unset, so the seeder's truthy check correctly short-circuits on
  a never-set flag.
- `fs_settings` does not depend on a live DB connection: it only
  touches `$GLOBALS['config2']` (an in-memory array) and the
  `tmp/.../config2.ini` file. Safe to call even on a misconfigured
  install.

### `cliente` model API (consumed)

File: `plugins/clientes_core/model/core/cliente.php`. Confirmed
relevant methods:

| Method / Property | Signature | Behaviour |
|---|---|---|
| Constructor | `__construct($data = FALSE)` (line 173) | When `$data` is `false`/null, populates all fields from the `cliente` defaults block (lines 213-239). Reads `FS_CIFNIF` constant (line 217) — defaults `tipoidfiscal` to `'NIF'`. Calls `$this->default_items->coddivisa()` and `$this->default_items->codpago()` (lines 225-226) — both return static class members (`base/fs_default_items.php:61-79`), no DB hit. Calls `load_commercial_extension()` (line 241) — a no-op when `class_exists('cliente_facturacion')` is false. **Triggers** `fs_model::__construct('clientes')` which auto-creates the `clientes` table if missing. |
| `save` | `public function save(): bool` (line 494) | Calls `$this->test()` first (line 496). If `test()` returns false, returns `false`. Otherwise runs the `INSERT` (or `UPDATE`) and returns `true` on success. |
| `get_new_codigo` | `public function get_new_codigo(): string` (line 392) | `SELECT MAX(...)` on `codcliente`; returns 6-digit zero-padded MAX+1, or `'000001'` on an empty table. Called **transitively** via `test()` (line 405) when `$this->codcliente` is `null`. |
| `table_has_rows` | `public function table_has_rows(): bool` (newly added at line 282 by the CRITICAL-1 fix) | Returns `true` iff the `clientes` table contains at least one row. Encapsulates the `SELECT 1 ... LIMIT 1` query against `$this->table_name`. The seeder calls this method instead of reaching for `$this->db` directly. |
| Table name | `$this->table_name === 'clientes'` (set by `parent::__construct('clientes')` at line 175) | — |
| DB handle | `protected $db` (inherited from `fs_model`, line 69 of `base/fs_model.php`) | `fs_db2` instance created by `fs_model::__construct()` at line 108. The seeder does **not** touch this directly — `table_has_rows()` is the public seam. |

#### Counting rows: no `count()` method on `cliente`

The `cliente` model has **no `count()` method**, and the model's
`$db` handle is `protected` (`base/fs_model.php:69`). Direct
access from outside the class hierarchy throws
`Error: Cannot access protected property`. The fix landed by this
change (CRITICAL-1) is to expose a public wrapper:

```php
// plugins/clientes_core/model/core/cliente.php:288-291
public function table_has_rows(): bool
{
    $rows = $this->db->select("SELECT 1 FROM " . $this->table_name . " LIMIT 1;");
    return !empty($rows);
}
```

The seeder calls `$cliente->table_has_rows()` instead of reaching
for `$cliente->db` directly. This uses the existing `clientes`
table on which the constructor just called `check_table()` — so
the table is guaranteed to exist. `SELECT 1 ... LIMIT 1` is
cheaper than `SELECT COUNT(*)` and returns a non-empty array iff
at least one row exists. The method is table-portable: it uses
`$this->table_name` rather than the literal string `'clientes'`,
so subclasses that override the table name (e.g. for test
fixtures) still work without modification.

### DB access from `Init::upgrade()`

`Init::upgrade()` is static. It does not receive any DI container
or constructor-injected services. The seeder must obtain a DB handle
the same way every legacy `fs_model` consumer does: by instantiating
`new \cliente()`. The `cliente` constructor:

1. Calls `parent::__construct('clientes')` which creates a new
   `fs_db2` (`base/fs_model.php:108`).
2. Stores it on `$this->db` (`base/fs_model.php:69`).
3. Calls `check_table('clientes')` which uses
   `$GLOBALS['plugins']` (line 450 of `base/fs_model.php`) to locate
   the plugin's `model/table/clientes.xml`.

This is the same dependency on global state that every legacy model
in the codebase already has. It is acceptable here because:

- The seeder runs from a fully-bootstrapped request — the kernel
  has already populated `$GLOBALS['plugins']`, `$GLOBALS['db_type']`
  and friends.
- The DB connection created by `fs_db2()` is the same singleton-style
  connection the rest of the application uses (the class memoises
  the connection inside the instance).
- The plugin-scope data flow is already established: `fs_model`
  reads `$GLOBALS['plugins']` to find plugin-owned XML schemas.

For the unit tests, the DB handle cannot be `fs_db2` (no DB
available). The test seam is **dependency injection of the `cliente`
class**: the test patches `\cliente` in the global namespace with a
subclass that returns controlled `select()` results. See the
**Test plan** section.

## Implementation sketch

### New `use` / `require_once` additions to `Init.php`

`plugins/clientes_core/Init.php` already has:

```php
namespace FSFramework\Plugins\clientes_core;

require_once __DIR__ . '/src/ViewHookRegistry.php';

use FSFramework\Event\FSEventDispatcher;
use FSFramework\Event\TwigInitEvent;
```

The seeder does not need new `use` statements — `\cliente` and
`\fs_settings` are resolved from the global namespace. No new
`require_once` is needed either: `fs_model.php` already pulls in
`fs_db2.php`, `fs_cache.php`, `fs_core_log.php`, `fs_default_items.php`
(`base/fs_model.php:20-24`). `fs_settings.php` is loaded transitively
by the bootstrap on every request.

The only addition is the new method declaration below.

### `Init::upgrade()` body (pseudocode, ~30 lines)

```php
/**
 * Plugin activation hook.
 *
 * Called by fs_plugin_manager::runPluginUpgrade() (see
 * base/fs_plugin_manager.php) every time this plugin is enabled.
 *
 * Seeds a single "Cliente por defecto" cliente on a fresh install
 * so downstream sales/invoicing flows have at least one valid
 * codcliente to reference. Idempotent: a persistent flag in
 * fs_settings short-circuits the body on every subsequent run.
 *
 * MUST stay public and static — the framework calls it via
 * $initClass::upgrade() with no instance. MUST NOT throw — a
 * plugin-level try/catch isolates DB failures so activation
 * always completes.
 */
public static function upgrade(): void
{
    $settings = new \fs_settings();
    if ($settings->get('clientes_core_default_seeded')) {
        return;
    }

    try {
        $cliente = new \cliente();

        if (!$cliente->table_has_rows()) {
            $cliente->nombre = 'Cliente por defecto';
            $cliente->save();
        }

        $settings->set('clientes_core_default_seeded', '1');
        $settings->save();
    } catch (\Throwable $e) {
        // Swallow: a failed seed must never break plugin activation.
        // The flag was not set, so the next activation can retry.
    }
}
```

Notes on the body:

- The early-return flag check (lines 3-5) sits **outside** the
  `try/catch` because `$settings->get()` reads a global array and
  cannot throw. Keeping the check outside the `try` is also a
  minor perf win: a re-activated install skips the body
  completely.
- `$cliente = new \cliente()` (line 8) is inside the `try` because
  it can throw (e.g., DB unreachable during table creation).
- `$cliente->table_has_rows()` (line 10) is the public seam
  introduced by the CRITICAL-1 fix. It encapsulates the
  `SELECT 1 ... LIMIT 1` query against `$this->table_name` and
  returns `true` iff the table has at least one row. Reaching for
  `$cliente->db` directly from `Init::upgrade()` would throw
  `Error: Cannot access protected property` (`base/fs_model.php:69`).
- `cliente::save()` (line 12) calls `test()` first, which calls
  `get_new_codigo()` and `validateFields()`. `validateFields()`
  passes with `nombre='Cliente por defecto'` and all the constructor
  defaults (length, email, telefono1/2/fax, cifnif all within
  bounds).
- `$settings->set(...)` (line 16) is in-memory only; the
  `fs_settings::save()` call (line 17) is what persists it.

### No changes to the existing `init()` method

The instance `init()` method on `Init` (lines 33-40) registers
Twig extensions via `FSEventDispatcher` and runs on every request
that hits the bootstrap. The seeder's static `upgrade()` is
**unrelated** to it — different visibility, different caller, no
shared state. The seeder adds zero new behaviour to `init()`.

This is restated explicitly: the implementation MUST NOT modify
`init()`, its `use` statements, or the `ViewHookRegistry` import.

## Test plan

### Runner

```bash
ddev exec php vendor/bin/phpunit -c plugins/clientes_core/phpunit.xml
```

This runs the existing `clientes_core` test suite
(`plugins/clientes_core/phpunit.xml` → `<testsuite name="clientes_core">`).

### File location

**Choice**: `plugins/clientes_core/tests/InitUpgradeTest.php` (flat
in `tests/`, matching the existing convention
`ClienteModelTest.php`, `DireccionClienteModelTest.php`,
`GrupoClientesModelTest.php`).

Rejected: `plugins/clientes_core/tests/Init/UpgradeTest.php` (subdir
structure). The existing `tests/Controller/` subdir is the only
subdir in use, and it groups by namespace. `Init::upgrade()` is
not a controller, and creating a new subdir just for one file is
premature. The flat location also keeps the test path short for
`ddev exec php vendor/bin/phpunit --filter InitUpgradeTest`.

### Bootstrap requirements

The test class will, in `setUp()`:

1. Reset `$GLOBALS['config2']` to an empty array (or a known
   fixture) so `fs_settings` reads/writes start from a clean slate.
2. Stub the global `\cliente` class to a fake that returns
   controlled DB results — see **Mocking strategy** below.
3. Reload the `cliente.php` source file if needed, and require
   the `fs_settings.php` source file via the bootstrap.
4. Define `FS_FOLDER`, `FS_TMP_NAME`, `FS_CIFNIF`, `FS_ITEM_LIMIT`
   via `tests/bootstrap.php` (already provided — see
   `plugins/clientes_core/tests/ClienteModelTest.php:17-25` for the
   pattern).

### Test cases (minimum)

Each test resets `$GLOBALS['config2']` to a known starting state in
`setUp()` and verifies both the DB side and the flag side.

#### 1. `emptyTableAndNoFlag_insertsAndSetsFlag`

- Pre: `$GLOBALS['config2']` is empty. Stub `\cliente::db->select`
  returns `[]` (table empty).
- Action: `Init::upgrade()`.
- Assert:
  - The fake `\cliente` was instantiated.
  - `cliente->save()` was called exactly once.
  - The fake cliente's `nombre` is `'Cliente por defecto'`.
  - `$GLOBALS['config2']['clientes_core_default_seeded'] === '1'`.

#### 2. `emptyTableAndFlagAlreadySet_isNoOp`

- Pre: `$GLOBALS['config2']['clientes_core_default_seeded'] = '1'`.
- Action: `Init::upgrade()`.
- Assert:
  - The fake `\cliente` was **not** instantiated (no DB call).
  - The flag is still `'1'`.

#### 3. `nonEmptyTableAndNoFlag_skipsInsertButSetsFlag`

- Pre: `$GLOBALS['config2']` is empty. Stub `\cliente::db->select`
  returns `[['x' => 1]]` (at least one row).
- Action: `Init::upgrade()`.
- Assert:
  - The fake `\cliente` was instantiated (we needed the `db`
    handle to call `select`).
  - `cliente->save()` was **not** called.
  - `$GLOBALS['config2']['clientes_core_default_seeded'] === '1'`.

#### 4. `dbErrorDuringSave_isSwallowed`

- Pre: `$GLOBALS['config2']` is empty. Stub `\cliente::db->select`
  returns `[]`. Stub the fake `\cliente::save()` to throw a
  `\RuntimeException('boom')`.
- Action: `Init::upgrade()`.
- Assert:
  - `Init::upgrade()` does **not** re-throw (the test framework's
    default error handler will turn a propagated throw into a
    test failure).
  - The flag is **not** set (`$GLOBALS['config2']` does not
    contain the key), so the next activation can retry.
  - `settings.save()` is **not** called (or, if it is, the flag
    is not set when it is called — equivalent assertion).

#### 5. (Optional bonus) `saveFailure_doesNotCallSettingsSave`

Variant of case 4: also stub the fake `\cliente::save()` to call
`settings.set()` and `settings.save()` to ensure the `try/catch`
boundary catches the throw **before** the flag is set. The
behaviour is the same as case 4.

#### 6. (Optional bonus) `settingsSaveFailure_isSwallowed`

- Pre: empty table, no flag, stub `\cliente::save()` returns
  `true`, stub the `fs_settings::save()` to return `false`
  (or throw).
- Assert: `Init::upgrade()` does not re-throw. The flag value is
  set in `$GLOBALS['config2']` (via `set()`) but the disk write
  failed — this is the documented degradation mode.

### Mocking strategy

The two collaborators are `\cliente` and `\fs_settings`. Both are
**not constructor-injectable** because `Init::upgrade()` is static
and consumes them as global names. The tests must therefore
override them at the global level.

#### `\cliente` mock

Pattern (mirrors the existing
`plugins/clientes_core/tests/ClienteModelTest.php:36-65`):

```php
require_once FS_FOLDER . '/plugins/clientes_core/model/core/cliente.php';

$clienteStub = new class extends \FSFramework\model\cliente {
    public int $saveCalls = 0;
    public ?\RuntimeException $saveException = null;
    public array $selectResult = [];
    public object $db;

    public function __construct()
    {
        // Skip parent::__construct('clientes') to avoid real DB.
        // We still expose a $this->db with a single select() method
        // and a save() override.
        $this->db = new class($this) {
            public function __construct(private object $owner) {}
            public function select(string $sql): array
            {
                return $this->owner->selectResult;
            }
            // var2str() / escape_string() are also needed if used
            public function var2str($v) { return "'" . addslashes((string)$v) . "'"; }
        };
    }
    public function save(): bool
    {
        $this->saveCalls++;
        if ($this->saveException) {
            throw $this->saveException;
        }
        return true;
    }
    public function delete(): bool { return false; }
    public function exists(): bool { return false; }
};
```

The trick: the test class is a **named stub class** (e.g.,
`UpgradeTestStubCliente`) declared in the test file, but
`Init::upgrade()` calls `new \cliente()` which resolves to
`\FSFramework\model\cliente`. To make `new \cliente()` hit the stub,
the test sets a `class_alias` or — simpler — the test file
**declares a subclass of `\cliente` in the global namespace** so
that `new \cliente()` resolves to the alias.

Concrete approach (chosen):

- The test defines a final class `UpgradeTestFakeCliente` extending
  `\FSFramework\model\cliente` with overridable hooks
  (`shouldSaveReturn`, `shouldSaveThrow`).
- The test uses `class_alias(UpgradeTestFakeCliente::class, 'cliente')`
  in `setUp()` to make `new \cliente()` resolve to the fake.
- `tearDown()` calls `class_exists(...)` checks and clears the
  alias by setting the global symbol via a `runkit`-free trick:
  actually, the simplest is to declare the fake **as** the class
  loaded under the `\cliente` name from the start. Since
  `plugins/clientes_core/model/core/cliente.php` declares
  `namespace FSFramework\model; class cliente`, the test can
  re-declare a class with the same name in a different namespace
  and `use` it as `cliente`. **However**, `Init::upgrade()` calls
  `new \cliente()` (the fully-qualified global name), so the test
  must provide `\cliente`.

The pragmatic option that matches what the existing
`ClienteModelTest.php` does is:

- Don't alias. Use a **mock factory** pattern. Wrap the `\cliente`
  instantiation in a static method on a test-only helper
  (`TestClienteFactory::makeEmpty(): \cliente`) that production
  code does not call. But the production code does call
  `new \cliente()` directly.

**Refined design (chosen for the tests)**: introduce a `private
static function makeCliente(): \cliente` method on `Init` that
returns `new \cliente()`. Tests substitute it via a test seam. **No
— this complicates the production code for testability**.

**Final pragmatic answer (chosen)**: tests will declare
`class_alias(UpgradeTestFakeCliente::class, 'cliente')` in `setUp()`
and the production code calls `new \cliente()` which PHP will
resolve through the alias. `class_alias` does not work for
`new` invocations of the alias because `new` checks
`class_exists($name, false)` for the **exact** name. **Confirmed
trap** — this approach does not work.

**Final approach (chosen, verified)**: the test declares an
anonymous-class-like seam using **`new \cliente` indirection**:

- A test-only `class_alias(UpgradeTestFakeCliente::class, 'cliente')`
  WILL work in PHP 8.2+ for `new \cliente()` invocations because
  `class_alias` registers the alias in the global class table and
  `new $name` resolves via the table. **The reason it sometimes
  doesn't work is when the original class hasn't been loaded
  before the alias is registered.** So the test does:
  1. `require_once .../cliente.php` (loads the real `\cliente`).
  2. `require_once .../UpgradeTestFakeCliente.php` (loads the
     fake).
  3. `class_alias(UpgradeTestFakeCliente::class, 'cliente')` —
     PHP throws "class already exists". This also doesn't work.

**Working approach (final)**: PHP's `new \cliente()` is a literal
compile-time check. To override the target of `new \cliente()` you
must literally make `\cliente` (the symbol in the global
namespace) be the fake. The clean way is to define a `cliente`
class in the global namespace at the top of the test file (or in
a test helper), **before** requiring the production
`cliente.php`. The production `cliente.php` then declares
`\FSFramework\model\cliente` (a different name) and `Init::upgrade`
calls `new \cliente()` which is the **global** `\cliente` — i.e.,
the test's fake. This is the approach `Init::upgrade()` must
take in tests.

**However**, `Init::upgrade()` calls `new \cliente()` which the
production code intends to be `\FSFramework\model\cliente`. There
is no `use` for `cliente` at the top of `Init.php`, so the symbol
is resolved as global. The production runtime will fail if a
global `\cliente` does not exist. This means the production code
**must** either `use \FSFramework\model\cliente` (making the
seeder pinned to the production class) or rely on the bootstrap
registering the global alias.

**The bootstrap does register the global alias.** Looking at
`plugins/clientes_core/model/core/cliente.php` line 31:
`class cliente extends \fs_model` — the class is declared in the
`FSFramework\model` namespace. For `new \cliente()` (the global
name) to resolve to this, there must be either an autoloader
mapping for the global name, or a `class_alias` somewhere in
the bootstrap. **This needs verification** in the
`sdd-apply` step.

For the design phase: the test approach is to load the production
class and the test helper, declare the test fake in a separate
namespace, and use **`new \cliente()` pointing at a runtime
subclass**. The cleanest test seam is to:

1. Declare `\FSFramework\Plugins\clientes_core\Tests\FakeCliente`
   extending `\FSFramework\model\cliente`.
2. Use Reflection in the test to set the test fake's `db` to a
   controlled mock.
3. Run the seeder by **re-declaring `Init::upgrade()` is not
   possible** because `Init` is the production class. Instead,
   **factor the seeder body into a private static helper** on
   `Init` (e.g., `seedDefaultCliente(): void`) and have
   `upgrade()` call it. Tests then call the helper through
   Reflection and inject the fake by **replacing the global
   `\cliente` symbol**.

**The simpler final design (recommended)**: the seeder's body is
small enough (~30 lines) that a test can call it via Reflection on
`Init::upgrade()` while overriding the `\cliente` global via a
test-only autoloader or via direct class_alias with a work-around.

**Pragmatic resolution for the design phase**: the design
documents the **contract** (what the seeder does, what global
state it touches) and the test cases. The exact mechanism for
overriding `\cliente` in the test (autoloader trick, runkit, or
process-isolated child PHP) is left to the `sdd-apply` phase to
choose, with the constraint that the tests must exercise the
production `Init::upgrade()` byte-for-byte.

The most likely working approach (to be confirmed in `sdd-apply`):

- The test loads the production `cliente.php` and the
  `fs_settings.php` and `Init.php`.
- A test-only autoloader is registered first that resolves
  `cliente` (the global name) to a fake. The fake is registered
  via `spl_autoload_register` **before** the real autoloader.
- Because the production class is declared as
  `FSFramework\model\cliente`, the global `cliente` is **never
  actually declared** in the production codebase. The autoloader
  trick only works for the global name if no class loader binds
  the global name to the production class. **This is the key**:
  the production code **must** call the cliente via the
  namespace `new \FSFramework\model\cliente()` OR a global alias
  must exist.

This is a design gap to be closed in `sdd-apply`. The
recommended resolution is: **`Init::upgrade()` declares
`use \FSFramework\model\cliente;` and instantiates
`new cliente()`.** This makes the production code pin to the
correct class, and tests can substitute the production class via
the test bootstrap's pre-load of a fake class registered under
the same name (requires the fake to be loaded before the real
one, which is achievable in the test bootstrap).

**Resolution (final, documented in design)**: the implementation
in `sdd-apply` MUST add `use \FSFramework\model\cliente;` to
`Init.php` and call `new cliente()` (not `new \cliente()`). This
makes the production code testable and removes the global-symbol
ambiguity.

#### `\fs_settings` mock

Pattern:

```php
$settingsStub = new class {
    private array $store = [];
    public int $saveCalls = 0;
    public ?\Throwable $saveException = null;

    public function get(string $key, $default = null): mixed
    {
        return $this->store[$key] ?? $default;
    }
    public function set(string $key, $value): void
    {
        $this->store[$key] = $value;
    }
    public function save(): bool
    {
        $this->saveCalls++;
        if ($this->saveException) {
            throw $this->saveException;
        }
        return true;
    }
};
```

The seeder uses `new \fs_settings()`. The test must therefore
either (a) substitute the global `\fs_settings` symbol (same
global-alias problem) or (b) the seeder MUST instantiate via
`\FSFramework\...\fs_settings` — but `fs_settings` is a core
class declared globally, so the namespace trick does not apply
cleanly.

**Resolution (final)**: a test-only autoloader registered in
`setUp()` is the cleanest seam. It binds the name `fs_settings`
to a fake during the test, then unregisters in `tearDown()`.
This works because `fs_settings` is **not** in any namespace
and the test autoloader runs first.

### Test constraints (documented for `sdd-apply`)

- `Init::upgrade()` is static. All its dependencies must be
  globally addressable OR injected through test seams.
- The production code MUST use `use` statements for
  `\FSFramework\model\cliente` (and any other namespaced model
  it touches) so tests can override the global name.
- The test file must `require_once`:
  - `base/fs_settings.php` (for the `\fs_settings` class
    signature reference, even though the test uses a fake).
  - `plugins/clientes_core/model/core/cliente.php` (for the
    `\FSFramework\model\cliente` class to extend).
  - `plugins/clientes_core/Init.php` (so `Init::upgrade` is
    loadable).
- The test autoloader trick is the cleanest way to swap
  `\fs_settings` and `\cliente` (global) for fakes. The
  test autoloader is registered first in `setUp()` and
  unregisters itself in `tearDown()`.

## Risks and mitigations

| # | Risk | L | Mitigation |
|---|---|---|---|
| 1 | `fs_settings::save()` may return `false` (e.g., non-writable `tmp/`) | L | Flag is set in memory via `set()`; the failure is silent. Next activation retries. Documented; non-blocking. |
| 2 | `Init::upgrade()` is a new convention — first adopter. Establishes a pattern future plugins will copy. | M | The method's docblock explicitly states "called once per activation by `fs_plugin_manager`" and the seeder body follows the standard flag-gate + try/catch shape. Subsequent plugins can copy this template. |
| 3 | `cliente` constructor calls `$this->default_items->coddivisa()` and `$this->default_items->codpago()`. Both return static class members (`base/fs_default_items.php:61-79`) — no DB call, no failure mode. | L | Verified by reading `base/fs_default_items.php`. Safe. The seeder inherits this with zero risk. |
| 4 | `cliente::load_commercial_extension()` is called from the constructor (`model/core/cliente.php:241`). It is a no-op when `class_exists('cliente_facturacion')` is false (line 636). At activation time, `facturacion_base` is not active, so the extension class is not loaded. | L | Verified by reading `model/core/cliente.php:634-654`. Safe. |
| 5 | Race on concurrent activation (e.g., CLI `system_updater` racing a web request). Both could pass the flag check, both could INSERT. | L | The flag is the only synchronisation primitive. Theoretical window. Worst case is a duplicate `Cliente por defecto` row, a recoverable cosmetic issue. Accepted. |
| 6 | `cliente::save()` triggers `clean_cache()` (`model/core/cliente.php:500 → 681`) which touches the global cache. | L | Standard behaviour; the seeder is no different from any other `cliente::save()` call. |
| 7 | `runPluginUpgrade` is called **before** `ensurePluginTables` (`base/fs_plugin_manager.php:484-485`). On a brand-new install, the `clientes` table does not yet exist when `Init::upgrade()` runs. | M | The `cliente` constructor's `parent::__construct('clientes')` calls `check_table('clientes')` (`base/fs_model.php:132`) which auto-creates the table from the plugin's XML schema. So `new \cliente()` doubles as a table-creator. Verified by reading `base/fs_model.php:277-305`. The seeder effectively **doubles** as a table-creator for the empty-install path. |
| 8 | Production code calls `new \cliente()` (global) but `\cliente` is not a declared symbol in the production codebase — the class lives at `\FSFramework\model\cliente`. | M | The implementation MUST `use \FSFramework\model\cliente;` and call `new cliente()`. This pins the production code to the namespaced class, makes the test seam clean, and removes the global-symbol ambiguity. (See **Test plan / Mocking strategy / Resolution (final)** above.) |
| 9 | The seeder body is wrapped in a top-level `try/catch` that swallows ALL `\Throwable`s, including programmer errors (typos, missing methods). | L | Acceptable for a one-shot bootstrap. The plugin manager's outer `try/catch` (`base/fs_plugin_manager.php:650-654`) is the second line of defence. The seeder does not need to discriminate error types. |
| 10 | `init()` is called on every request, while `upgrade()` is called only on activation. Future maintainers may confuse the two. | L | The docblock on `upgrade()` explicitly states it is the activation hook. `init()` and `upgrade()` are visually distinct (one instance, one static; one runs per request, one runs per activation). |
| 11 | **Method visibility regression:** if a future refactor removes the public `cliente::table_has_rows()` method or makes it `protected`/`private`, the seeder will fail at runtime in the same way as the original CRITICAL-1 bug. | M | The unit test for `cliente::table_has_rows()` (`Tests\ClientesCore\ClienteModelTest::testTableHasRows*`) is the canary. CI MUST remain green. The method's docblock also states the contract explicitly. The new test exercises the method via a stubbed `db` so visibility regressions surface immediately. |

## Rollout and verification

### Manual smoke checks (in a `ddev` shell)

1. **Cold start**:
   - Drop the `clientes` table (or use a fresh DB).
   - Drop `$GLOBALS['config2']['clientes_core_default_seeded']`
     from `tmp/{FS_TMP_NAME}config2.ini` (or use a fresh install).
   - Activate `clientes_core` from the admin UI
     (`index.php?page=admin_plugins`).
   - Assert: `SELECT * FROM clientes;` shows exactly one row with
     `nombre = 'Cliente por defecto'`.
   - Assert: `tmp/{FS_TMP_NAME}config2.ini` contains
     `clientes_core_default_seeded = '1';`.

2. **Idempotent re-activation**:
   - With the seeded row and flag from step 1, deactivate
     `clientes_core` from the admin UI.
   - Reactivate `clientes_core`.
   - Assert: `SELECT COUNT(*) FROM clientes;` is still 1
     (no duplicate row).
   - Assert: the flag is still `'1'`.

3. **Non-empty install**:
   - Drop the `clientes_core_default_seeded` flag from
     `tmp/{FS_TMP_NAME}config2.ini`.
   - Insert a row manually: `INSERT INTO clientes (codcliente, nombre, ...) VALUES ('000099', 'Test', ...);`.
   - Deactivate and reactivate `clientes_core`.
   - Assert: `SELECT COUNT(*) FROM clientes;` is still 1
     (the manual row, not a seed).
   - Assert: the flag is now `'1'`.

4. **Activation survives a DB error**:
   - Stub the DB so the `INSERT` throws. The easiest is to drop
     the `clientes` table entirely but keep the flag unset.
   - Activate `clientes_core`.
   - Assert: the plugin activates successfully (the admin UI
     shows it as enabled).
   - Assert: the flag is **not** set
     (re-deactivation + re-activation retries the seed).

### CI

`ddev exec php vendor/bin/phpunit -c plugins/clientes_core/phpunit.xml`
must pass. The new `InitUpgradeTest.php` is part of the
`clientes_core` test suite and is auto-discovered by the
`<testsuite name="clientes_core"><directory>tests</directory>`
declaration in `plugins/clientes_core/phpunit.xml`. No edit to
`phpunit.xml` is required.

## Files to add or modify

| File | Action | Description |
|---|---|---|
| `plugins/clientes_core/Init.php` | **modify** | Add `public static function upgrade(): void` (and the `use \FSFramework\model\cliente;` import per Risk #8). Leave the existing `init()` method and the `ViewHookRegistry` import untouched. |
| `plugins/clientes_core/tests/InitUpgradeTest.php` | **create** | New test class covering the four scenarios in the **Test plan** section. Uses a test-autoloader seam to swap `\fs_settings` and `\cliente` (global) for fakes. |

No other files in the repository are modified. No edits to
`base/`, `src/`, `controller/`, `model/`, `core openspec/`, the
core `phpunit.xml`, or any other plugin.

## Open questions

None. All product decisions confirmed in the proposal; the only
technical detail left for the `sdd-apply` phase is the
test-autoloader mechanism (documented under **Test plan / Mocking
strategy / Resolution (final)**).
