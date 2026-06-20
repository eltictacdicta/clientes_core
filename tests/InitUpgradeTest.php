<?php
/**
 * This file is part of FSFramework originally based on Facturascript 2017
 * Copyright (C) 2025 Javier Trujillo <mistertekcom@gmail.com>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Lesser General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Lesser General Public License for more details.
 *
 * You should have received a copy of the GNU Lesser General Public License
 * along with this program. If not, see <http://www.gnu.org/licenses/>.
 */

namespace Tests\ClientesCore;

use PHPUnit\Framework\TestCase;

/**
 * Tests for the plugin activation seeder: Init::upgrade().
 *
 * === Test seam: autoloader-based fake injection ===
 *
 * Init::upgrade() is a static method that consumes two global
 * collaborators — \FSFramework\model\cliente and \fs_settings —
 * via the production-side `use \FSFramework\model\cliente;` import
 * and a bare `new \fs_settings()`. Neither is constructor-injected
 * (the seeder has no instance, no DI container).
 *
 * To exercise the seeder's branches without a real DB and without
 * a real INI file on disk, this test class:
 *
 *   1. Depends on PHPUnit's `processIsolation` being enabled (see
 *      plugins/clientes_core/phpunit.xml, `processIsolation="true"`).
 *      Without process isolation, the sibling test
 *      `ClienteModelTest` eagerly requires the production
 *      `plugins/clientes_core/model/core/cliente.php` in its
 *      setUp(), and once a class is loaded PHP does not let
 *      another file redefine the same FQCN. The prepended
 *      autoloader below can only intercept a *first-time* load
 *      of \FSFramework\model\cliente, which requires a fresh
 *      process. The InitUpgradeTest is small enough (6 tests)
 *      that the per-test process overhead is acceptable.
 *
 *   2. Registers a PREPENDED autoloader in setUp() that, when
 *      asked for either `\FSFramework\model\cliente` or
 *      `fs_settings`, loads the in-memory fakes from
 *      `tests/Fixtures/InitUpgradeFakes.php`. The fakes mirror
 *      the production API surface that the seeder consumes
 *      (cliente::save, fs_settings::get/set/save) and expose
 *      static counters/logs the assertions read.
 *
 *   3. The fakes use the same FQCN as the production classes.
 *      The autoloader loads them only when the class is first
 *      requested; thereafter the class is fixed for the rest
 *      of the process.
 *
 * The autoloader is idempotent (registered via a static guard)
 * and lives for the duration of each separate process. No global
 * state leaks between tests.
 *
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class InitUpgradeTest extends TestCase
{
    /** Guard against registering the autoloader twice in the same process. */
    private static bool $autoloaderRegistered = false;

    protected function setUp(): void
    {
        parent::setUp();

        // Clean global state for every test.
        $GLOBALS['config2'] = [];
        if (!isset($GLOBALS['plugins']) || !is_array($GLOBALS['plugins'])) {
            $GLOBALS['plugins'] = [];
        }

        // Register the fake autoloader FIRST so the resetStatic()
        // calls below trigger the fake load (not the production
        // class load via the fs_model_autoloader fallback).
        self::registerFakeAutoloader();

        // Reset the fakes' static observation counters / logs.
        \FSFramework\model\cliente::resetStatic();
        \fs_settings::resetStatic();
    }

    private static function registerFakeAutoloader(): void
    {
        if (self::$autoloaderRegistered) {
            return;
        }

        spl_autoload_register(static function (string $class): bool {
            if (class_exists($class, false)) {
                return true;
            }
            if ($class === 'FSFramework\\model\\cliente' || $class === 'fs_settings') {
                require_once __DIR__ . '/Fixtures/InitUpgradeFakes.php';
                return class_exists($class, false);
            }
            return false;
        }, true, true);

        self::$autoloaderRegistered = true;
    }

    /**
     * Case 1 — empty table + no flag → insert + set flag.
     *
     * Spec: default-client-on-activation#Scenario:Empty install triggers the seed.
     */
    public function test_seeds_default_client_when_table_empty_and_flag_unset(): void
    {
        \FSFramework\model\cliente::$selectResult = [];

        \FSFramework\Plugins\clientes_core\Init::upgrade();

        $this->assertSame(
            1,
            \FSFramework\model\cliente::$saveCalls,
            'save() must be called exactly once when the table is empty'
        );
        $this->assertCount(
            1,
            \FSFramework\model\cliente::$instances,
            'cliente must be instantiated exactly once'
        );
        $this->assertSame(
            'Cliente por defecto',
            \FSFramework\model\cliente::$instances[0]->nombre,
            'Seeded cliente must have the canonical default name'
        );
        $this->assertSame(
            '1',
            $GLOBALS['config2']['clientes_core_default_seeded'] ?? null,
            'Flag must be set to the string "1"'
        );
        $this->assertSame(
            1,
            \fs_settings::$saveCalls,
            'fs_settings::save() must be called once after writing the flag'
        );
    }

    /**
     * Case 2 — flag already set → no cliente, no DB hit, no save.
     *
     * Spec: default-client-on-activation#Scenario:Re-activation after deactivation is a no-op.
     */
    public function test_is_noop_when_flag_already_set(): void
    {
        $GLOBALS['config2']['clientes_core_default_seeded'] = '1';

        \FSFramework\Plugins\clientes_core\Init::upgrade();

        $this->assertCount(
            0,
            \FSFramework\model\cliente::$instances,
            'No cliente instance must be created when the flag is set'
        );
        $this->assertSame(
            0,
            \FSFramework\model\cliente::$selectCalls,
            'No DB query must be issued when the flag short-circuits'
        );
        $this->assertSame(
            '1',
            $GLOBALS['config2']['clientes_core_default_seeded'] ?? null,
            'Flag value must remain "1"'
        );
    }
}
