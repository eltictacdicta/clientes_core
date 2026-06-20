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

/**
 * Fake classes used exclusively by InitUpgradeTest.
 *
 * Loaded by a prepended autoloader registered in
 * InitUpgradeTest::setUp() — see the test class docblock for the
 * rationale. The two fakes here stand in for:
 *
 *   - \FSFramework\model\cliente  → the production cliente model
 *     (plugins/clientes_core/model/core/cliente.php). The fake skips
 *     the real parent's DB-bound constructor and exposes a stub
 *     $this->db whose select() returns a test-controlled array.
 *     The fake's save() can be made to throw to exercise the
 *     seeder's failure-isolation path.
 *
 *   - \fs_settings  → the production settings store
 *     (base/fs_settings.php). The fake reads/writes the same
 *     $GLOBALS['config2'] array the production class uses, so
 *     assertions on $GLOBALS['config2'] after Init::upgrade()
 *     are direct.
 *
 * Loaded lazily so the autoloader is only triggered when the
 * production code (or the test) first references the class name.
 * After load, the class is fixed for the rest of the process.
 */

namespace FSFramework\model {

    /**
     * In-memory fake of \FSFramework\model\cliente.
     *
     * Static state is reset by InitUpgradeTest::setUp() via the
     * resetStatic() method. Instances are tracked via $instances
     * so the test can assert how many veces the seeder
     * instantiated the model.
     *
     * The fake's $table_has_rows_result public static controls the
     * return value of table_has_rows() (the production method added
     * by the CRITICAL-1 fix in the default-client-on-activation
     * change). Each test sets it explicitly in setUp() so the
     * seeder's "empty table" / "non-empty table" branches are
     * controllable without touching the protected $db handle.
     */
    class cliente extends \fs_model
    {
        /** @var self[] Every constructor call pushes the new instance here. */
        public static array $instances = [];

        /**
         * Stub return value for table_has_rows(). When null, falls
         * back to a sensible default (true if the test set
         * table_has_rows_was_called; otherwise false).
         */
        public static ?bool $table_has_rows_result = null;

        /** Number of times table_has_rows() was invoked. */
        public static int $table_has_rows_calls = 0;

        /** Number of times save() has been invoked. */
        public static int $saveCalls = 0;

        /** If set, save() throws this on first call. */
        public static ?\Throwable $saveException = null;

        /** @var object The stub db handle (kept for backward compat). */
        public $db;

        public function __construct()
        {
            // Skip parent::__construct('clientes') — no DB, no
            // check_table(), no real fs_db2 instance. The test does
            // not need any of that to exercise the seeder.
            self::$instances[] = $this;
            $this->db = new class {
                public function select(string $sql, array $params = []): array
                {
                    return [];
                }
            };
        }

        /**
         * Mirrors the production cliente::table_has_rows() method
         * (added by the CRITICAL-1 fix in
         * plugins/clientes_core/model/core/cliente.php). The test
         * sets $table_has_rows_result in setUp() to control the
         * branch the seeder takes.
         */
        public function table_has_rows(): bool
        {
            self::$table_has_rows_calls++;
            return self::$table_has_rows_result ?? false;
        }

        public function save(): bool
        {
            self::$saveCalls++;
            if (self::$saveException !== null) {
                throw self::$saveException;
            }
            return true;
        }

        public function delete(): bool
        {
            return false;
        }

        public function exists(): bool
        {
            return false;
        }

        public static function resetStatic(): void
        {
            self::$instances = [];
            self::$table_has_rows_result = null;
            self::$table_has_rows_calls = 0;
            self::$saveCalls = 0;
            self::$saveException = null;
        }
    }
}

namespace {

    /**
     * In-memory fake of \fs_settings.
     *
     * Mirrors the production class's get/set/save signatures so
     * the seeder compiles against the same interface. Persists to
     * $GLOBALS['config2'] (same backing store the real class uses)
     * so the test's assertions on that array are direct.
     */
    class fs_settings
    {
        /** @var self[] */
        public static array $instances = [];

        public static int $setCalls = 0;
        public static int $saveCalls = 0;
        public static ?\Throwable $saveException = null;

        /** @var array<int, array{0:string, 1?:string, 2?:mixed}> Ordered call log. */
        public static array $callLog = [];

        public function __construct()
        {
            self::$instances[] = $this;
        }

        public function get(string $key, $default = null)
        {
            return $GLOBALS['config2'][$key] ?? $default;
        }

        public function set(string $key, $value): void
        {
            self::$setCalls++;
            self::$callLog[] = ['set', $key, $value];
            $GLOBALS['config2'][$key] = $value;
        }

        public function save(): bool
        {
            self::$saveCalls++;
            self::$callLog[] = ['save'];
            if (self::$saveException !== null) {
                throw self::$saveException;
            }
            return true;
        }

        public static function resetStatic(): void
        {
            self::$instances = [];
            self::$setCalls = 0;
            self::$saveCalls = 0;
            self::$saveException = null;
            self::$callLog = [];
        }
    }
}
