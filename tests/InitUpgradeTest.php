<?php
declare(strict_types=1);

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
 * Init::upgrade() is a static method that consumes global
 * collaborators — \FSFramework\model\cliente,
 * \FSFramework\model\grupo_clientes,
 * \FSFramework\model\grupo_descuentos and \fs_settings — via the
 * production-side `use` imports and bare `new` calls. None is
 * constructor-injected (the seeder has no instance, no DI container).
 *
 * To exercise the seeder's branches without a real DB and without
 * a real INI file on disk, this test class:
 *
 *   1. Depends on PHPUnit's `processIsolation` being enabled (see
 *      plugins/clientes_core/phpunit.xml, `processIsolation="true"`).
 *      Without process isolation, a sibling test eagerly requires the
 *      production cliente.php, and once a class is loaded PHP does not
 *      let another file redefine the same FQCN.
 *
 *   2. Registers a PREPENDED autoloader in setUp() that loads the
 *      in-memory fakes from tests/Fixtures/InitUpgradeFakes.php.
 *
 *   3. The fakes use the same FQCN as the production classes. The
 *      autoloader loads them only when the class is first requested;
 *      thereafter the class is fixed for the rest of the process.
 *
 * Because process isolation reports any child-process stderr output as
 * a test error, the tests that intentionally exercise a failure branch
 * (which the seeder reports via error_log) redirect the error_log
 * destination to /dev/null for the duration of the call.
 */
#[\PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses]
#[\PHPUnit\Framework\Attributes\PreserveGlobalState(false)]
class InitUpgradeTest extends TestCase
{
    /** Guard against registering the autoloader twice in the same process. */
    private static bool $autoloaderRegistered = false;

    /** error_log destination captured before suppression. */
    private static string|false $previousErrorLog = false;

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
        \FSFramework\model\grupo_clientes::resetStatic();
        \FSFramework\model\grupo_descuentos::resetStatic();
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
            if ($class === 'FSFramework\\model\\cliente'
                || $class === 'FSFramework\\model\\grupo_clientes'
                || $class === 'FSFramework\\model\\grupo_descuentos'
                || $class === 'fs_settings') {
                require_once __DIR__ . '/Fixtures/InitUpgradeFakes.php';
                return class_exists($class, false);
            }
            return false;
        }, true, true);

        self::$autoloaderRegistered = true;
    }

    /**
     * Silence error_log for the duration of a call that intentionally
     * triggers the seeder's failure branch. Process isolation treats
     * any stderr output as a test error, and the failure path is
     * deliberately exercised here.
     */
    private static function suppressErrorLog(): void
    {
        self::$previousErrorLog = ini_get('error_log');
        ini_set('error_log', '/dev/null');
    }

    private static function restoreErrorLog(): void
    {
        ini_set('error_log', self::$previousErrorLog === false ? '' : self::$previousErrorLog);
    }

    /**
     * Seed the fakes so the two default groups already exist.
     */
    private static function seedDefaultGroups(): void
    {
        \FSFramework\model\grupo_clientes::$storedGroups = [
            '000001' => ['codgrupo' => '000001', 'nombre' => 'General', 'codtarifa' => null],
        ];
        \FSFramework\model\grupo_descuentos::$storedGroups = [
            '000000' => [
                'codgrupo_descuento' => '000000',
                'nombre' => 'Personalizado',
                'd1' => 0.00,
                'd2' => 0.00,
                'd3' => 0.00,
                'd4' => 0.00,
            ],
        ];
    }

    /**
     * Case 1 — empty table + no flag → insert + set flag.
     */
    public function test_seeds_default_client_when_table_empty_and_flag_unset(): void
    {
        \FSFramework\model\cliente::$table_has_rows_result = false;

        \FSFramework\Plugins\clientes_core\Init::upgrade();

        $this->assertSame(
            1,
            \FSFramework\model\cliente::$saveCalls,
            'save() must be called exactly once when the table is empty'
        );
        $this->assertCount(
            2,
            \FSFramework\model\cliente::$instances,
            'cliente must be instantiated for the seed and the backfill'
        );
        $this->assertSame(
            'Cliente por defecto',
            \FSFramework\model\cliente::$instances[0]->nombre,
            'Seeded cliente must have the canonical default name'
        );
        $this->assertSame(
            '000001',
            \FSFramework\model\cliente::$instances[0]->codgrupo,
            'Seeded cliente must reference the General client group'
        );
        $this->assertSame(
            '000000',
            \FSFramework\model\cliente::$instances[0]->codgrupo_descuento,
            'Seeded cliente must reference the Personalizado discount group'
        );
        $this->assertSame(
            '1',
            $GLOBALS['config2']['clientes_core_default_seeded'] ?? null,
            'Flag must be set to the string "1"'
        );
        $this->assertSame(
            3,
            \fs_settings::$saveCalls,
            'fs_settings::save() must run for the default seed, the legacy flag and the backfill flag'
        );
        $this->assertSame(
            1,
            \FSFramework\model\cliente::$table_has_rows_calls,
            'table_has_rows() must be called exactly once to detect the empty table'
        );
        $this->assertSame(1, \FSFramework\model\grupo_clientes::$saveCalls);
        $this->assertSame(1, \FSFramework\model\grupo_descuentos::$saveCalls);
    }

    /**
     * Case 2 — every flag already set and every group present → no writes.
     */
    public function test_is_noop_when_all_flags_already_set(): void
    {
        $GLOBALS['config2']['clientes_core_default_seeded'] = '1';
        $GLOBALS['config2']['clientes_core_discounts_migrated'] = '1';
        $GLOBALS['config2']['clientes_core_discount_group_required'] = '1';
        \FSFramework\model\cliente::$table_has_rows_result = true;
        self::seedDefaultGroups();

        \FSFramework\Plugins\clientes_core\Init::upgrade();

        $this->assertSame(
            0,
            \FSFramework\model\cliente::$saveCalls,
            'save() must not run when the table already has rows'
        );
        $this->assertSame(0, \FSFramework\model\grupo_clientes::$saveCalls);
        $this->assertSame(0, \FSFramework\model\grupo_descuentos::$saveCalls);
        $this->assertSame(0, \FSFramework\model\cliente::$assignOrphanCalls);
        $this->assertSame(0, \FSFramework\model\cliente::$assignDiscountOrphanCalls);
        $this->assertSame(
            '1',
            $GLOBALS['config2']['clientes_core_default_seeded'] ?? null,
            'Flag value must remain "1"'
        );
    }

    /**
     * Case 3 — non-empty table + no flag → no insert, but flags ARE set.
     */
    public function test_is_noop_when_table_nonempty_and_sets_flags(): void
    {
        \FSFramework\model\cliente::$table_has_rows_result = true;

        \FSFramework\Plugins\clientes_core\Init::upgrade();

        $this->assertCount(
            2,
            \FSFramework\model\cliente::$instances,
            'cliente must be instantiated for table_has_rows() and the backfill'
        );
        $this->assertSame(
            0,
            \FSFramework\model\cliente::$saveCalls,
            'save() must NOT be called when the table already has rows'
        );
        $this->assertSame(
            '1',
            $GLOBALS['config2']['clientes_core_default_seeded'] ?? null,
            'Flag must be set to "1" so future activations short-circuit'
        );
        $this->assertSame(
            '1',
            $GLOBALS['config2']['clientes_core_discount_group_required'] ?? null,
            'The mandatory-group backfill flag must be set'
        );
    }

    /**
     * Case 4 — DB error during save is swallowed.
     */
    public function test_swallows_db_error_during_save(): void
    {
        \FSFramework\model\cliente::$table_has_rows_result = false;
        \FSFramework\model\cliente::$saveException = new \RuntimeException('boom');

        self::suppressErrorLog();
        try {
            \FSFramework\Plugins\clientes_core\Init::upgrade();
        } finally {
            self::restoreErrorLog();
        }

        $this->assertSame(
            1,
            \FSFramework\model\cliente::$saveCalls,
            'save() must be invoked once before the throw'
        );
        $this->assertArrayNotHasKey(
            'clientes_core_default_seeded',
            $GLOBALS['config2'],
            'Default seed flag must NOT be set when the save throws (so the next activation retries)'
        );
        $this->assertSame(
            2,
            \fs_settings::$saveCalls,
            'fs_settings::save() must still run for the legacy flag and the backfill flag'
        );
        $this->assertSame(
            '1',
            $GLOBALS['config2']['clientes_core_discounts_migrated'] ?? null,
            'Discount migration flag must be set even when the default seed save throws'
        );
        $this->assertSame(
            '1',
            $GLOBALS['config2']['clientes_core_discount_group_required'] ?? null,
            'Backfill flag must be set even when the default seed save throws'
        );
    }

    /**
     * Case 5 (bonus) — cold start, table does not yet exist.
     */
    public function test_cold_start_auto_creates_table(): void
    {
        \FSFramework\model\cliente::$table_has_rows_result = false;

        \FSFramework\Plugins\clientes_core\Init::upgrade();

        $this->assertCount(
            2,
            \FSFramework\model\cliente::$instances,
            'cliente must be instantiated for the seed and the backfill during cold start'
        );
        $this->assertSame(
            1,
            \FSFramework\model\cliente::$saveCalls,
            'save() must run after table_has_rows() reports an empty table'
        );
        $this->assertSame(
            'Cliente por defecto',
            \FSFramework\model\cliente::$instances[0]->nombre,
            'Seeded cliente must have the canonical default name'
        );
    }

    /**
     * Case 6 (bonus) — set and save are called in the right order.
     */
    public function test_sets_flag_via_set_and_save(): void
    {
        \FSFramework\model\cliente::$table_has_rows_result = false;

        \FSFramework\Plugins\clientes_core\Init::upgrade();

        $setIndex = -1;
        $saveIndex = -1;
        foreach (\fs_settings::$callLog as $i => $entry) {
            if ($entry[0] === 'set' && ($entry[1] ?? null) === 'clientes_core_default_seeded') {
                $setIndex = $i;
            }
            if ($entry[0] === 'save') {
                $saveIndex = $i;
            }
        }

        $this->assertGreaterThanOrEqual(
            0,
            $setIndex,
            'fs_settings::set(clientes_core_default_seeded, ...) must be called'
        );
        $this->assertGreaterThanOrEqual(
            0,
            $saveIndex,
            'fs_settings::save() must be called'
        );
        $this->assertLessThan(
            $saveIndex,
            $setIndex,
            'fs_settings::set(...) must run BEFORE fs_settings::save()'
        );
    }

    /**
     * Creates both default groups and backfills the two orphan columns,
     * each from its own column.
     */
    public function test_creates_default_groups_and_backfills_orphans(): void
    {
        \FSFramework\model\cliente::$table_has_rows_result = true;
        $GLOBALS['config2']['clientes_core_default_seeded'] = '1';

        \FSFramework\Plugins\clientes_core\Init::upgrade();

        // Client group: '000001' General, never the discount code.
        $this->assertSame(1, \FSFramework\model\grupo_clientes::$saveCalls);
        $this->assertArrayHasKey(
            '000001',
            \FSFramework\model\grupo_clientes::$storedGroups ?? [],
            'General client group must be stored with code 000001'
        );
        $this->assertSame(
            'General',
            \FSFramework\model\grupo_clientes::$storedGroups['000001']['nombre']
        );
        $this->assertArrayNotHasKey(
            '000000',
            \FSFramework\model\grupo_clientes::$storedGroups ?? [],
            'The discount code 000000 must never be stored as a client group'
        );

        // Discount group: '000000' Personalizado, d1-d4 = 0.00.
        $this->assertSame(1, \FSFramework\model\grupo_descuentos::$saveCalls);
        $this->assertArrayHasKey(
            '000000',
            \FSFramework\model\grupo_descuentos::$storedGroups ?? [],
            'Personalizado discount group must be stored with code 000000'
        );
        $personalizado = \FSFramework\model\grupo_descuentos::$storedGroups['000000'];
        $this->assertSame('Personalizado', $personalizado['nombre']);
        $this->assertSame(0.00, $personalizado['d1']);
        $this->assertSame(0.00, $personalizado['d4']);

        // Client-group orphan backfill targets 000001.
        $this->assertSame(1, \FSFramework\model\cliente::$assignOrphanCalls);
        $this->assertSame(
            '000001',
            \FSFramework\model\cliente::$assignOrphanCodgrupo,
            'Orphan client-group backfill must target 000001'
        );

        // Discount orphan backfill targets 000000.
        $this->assertSame(1, \FSFramework\model\cliente::$assignDiscountOrphanCalls);
        $this->assertSame(
            '000000',
            \FSFramework\model\cliente::$assignDiscountOrphanCodgrupo,
            'Orphan discount-group backfill must target 000000'
        );

        $this->assertSame(
            '1',
            $GLOBALS['config2']['clientes_core_discount_group_required'] ?? null,
            'Mandatory-group backfill flag must be set'
        );
    }

    /**
     * The new flag gates the backfill independently of the legacy flag.
     */
    public function test_new_flag_gates_backfill_independently_of_legacy_flag(): void
    {
        \FSFramework\model\cliente::$table_has_rows_result = true;
        $GLOBALS['config2']['clientes_core_default_seeded'] = '1';
        $GLOBALS['config2']['clientes_core_discounts_migrated'] = '1';

        \FSFramework\Plugins\clientes_core\Init::upgrade();

        $this->assertSame(
            1,
            \FSFramework\model\cliente::$assignOrphanCalls,
            'The backfill must still run when only the legacy flag is set'
        );
        $this->assertSame('000001', \FSFramework\model\cliente::$assignOrphanCodgrupo);
        $this->assertSame(1, \FSFramework\model\cliente::$assignDiscountOrphanCalls);
        $this->assertSame('000000', \FSFramework\model\cliente::$assignDiscountOrphanCodgrupo);
        $this->assertSame(
            '1',
            $GLOBALS['config2']['clientes_core_discount_group_required'] ?? null,
            'The new flag must be set to "1"'
        );
    }

    /**
     * Re-running the migration is a no-op.
     */
    public function test_rerunning_backfill_is_a_noop(): void
    {
        \FSFramework\model\cliente::$table_has_rows_result = true;
        $GLOBALS['config2']['clientes_core_default_seeded'] = '1';
        $GLOBALS['config2']['clientes_core_discounts_migrated'] = '1';
        $GLOBALS['config2']['clientes_core_discount_group_required'] = '1';
        self::seedDefaultGroups();

        \FSFramework\Plugins\clientes_core\Init::upgrade();

        $this->assertSame(
            0,
            \FSFramework\model\grupo_clientes::$saveCalls,
            'No client group must be re-created when the backfill flag is set'
        );
        $this->assertSame(
            0,
            \FSFramework\model\grupo_descuentos::$saveCalls,
            'No discount group must be re-created when the backfill flag is set'
        );
        $this->assertSame(
            0,
            \FSFramework\model\cliente::$assignOrphanCalls,
            'No client-group UPDATE must be issued on a re-run'
        );
        $this->assertSame(
            0,
            \FSFramework\model\cliente::$assignDiscountOrphanCalls,
            'No discount-group UPDATE must be issued on a re-run'
        );
    }

    /**
     * R8 — a populated gruposclientes table lacking '000001' still gets
     * the General default created (the old table_has_rows() early return
     * is removed).
     */
    public function test_ensure_default_client_group_on_populated_table_lacking_default(): void
    {
        \FSFramework\model\cliente::$table_has_rows_result = true;
        $GLOBALS['config2']['clientes_core_default_seeded'] = '1';
        $GLOBALS['config2']['clientes_core_discounts_migrated'] = '1';
        $GLOBALS['config2']['clientes_core_discount_group_required'] = '1';
        \FSFramework\model\grupo_clientes::$storedGroups = [
            '000002' => ['codgrupo' => '000002', 'nombre' => 'Otro', 'codtarifa' => null],
        ];
        \FSFramework\model\grupo_descuentos::$storedGroups = [
            '000000' => [
                'codgrupo_descuento' => '000000',
                'nombre' => 'Personalizado',
                'd1' => 0.00,
                'd2' => 0.00,
                'd3' => 0.00,
                'd4' => 0.00,
            ],
        ];

        \FSFramework\Plugins\clientes_core\Init::upgrade();

        $this->assertArrayHasKey(
            '000001',
            \FSFramework\model\grupo_clientes::$storedGroups,
            'A non-empty table lacking 000001 must still get the General default'
        );
        $this->assertSame(
            'General',
            \FSFramework\model\grupo_clientes::$storedGroups['000001']['nombre']
        );
        $this->assertSame(
            1,
            \FSFramework\model\grupo_clientes::$saveCalls,
            'Exactly the General default must be created'
        );
    }

    /**
     * A failure inside the backfill leaves its flag unset so the next
     * activation retries, and never breaks activation.
     */
    public function test_backfill_failure_leaves_flag_unset_without_breaking_activation(): void
    {
        \FSFramework\model\cliente::$table_has_rows_result = true;
        $GLOBALS['config2']['clientes_core_default_seeded'] = '1';
        $GLOBALS['config2']['clientes_core_discounts_migrated'] = '1';
        self::seedDefaultGroups();
        \FSFramework\model\cliente::$assignDiscountOrphanException = new \RuntimeException('backfill boom');

        self::suppressErrorLog();
        try {
            \FSFramework\Plugins\clientes_core\Init::upgrade();
        } finally {
            self::restoreErrorLog();
        }

        $this->assertArrayNotHasKey(
            'clientes_core_discount_group_required',
            $GLOBALS['config2'],
            'The backfill flag must stay unset so the next activation retries'
        );
        $this->assertSame(
            '1',
            $GLOBALS['config2']['clientes_core_default_seeded'] ?? null,
            'Activation must not break: earlier blocks keep their flags'
        );
        $this->assertSame(
            '1',
            $GLOBALS['config2']['clientes_core_discounts_migrated'] ?? null,
            'Activation must not break: the legacy flag keeps its value'
        );
    }
}
