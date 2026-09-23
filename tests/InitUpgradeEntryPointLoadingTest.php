<?php
/**
 * This file is part of FSFramework originally based on Facturascript 2017
 * Copyright (C) 2026 FSFramework Team
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Lesser General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Lesser General Public License for more details.
 *
 * You should have received a copy of the GNU Lesser General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 */
declare(strict_types=1);

namespace Tests\ClientesCore;

use PHPUnit\Framework\TestCase;

/**
 * Entry-point independence regression for `clientes_core\Init::upgrade()`.
 *
 * `upgrade()` reads `fs_settings` and `fs_cache` at its top. In the real
 * activation flow it does NOT fatal because
 * `PluginSchemaSynchronizer::ensureInitMigrationRuntime()` preloads a
 * hardcoded legacy class list before invoking `upgrade()`. That is implicit
 * coupling: `upgrade()` is not self-sufficient and relies on a contract only
 * that one caller honours. Any other invocation (a CLI migration script, a
 * future caller, a test) fataled with `Class "fs_settings" not found`.
 *
 * The whole unit corpus stayed green while the gap existed because
 * `InitUpgradeTest` injects a fake `fs_settings` through a prepended
 * autoloader: a same-process test can never prove entry-point independence,
 * because once any earlier code loaded the class the guard is never
 * exercised. This test therefore spawns fresh PHP processes with a clean
 * entry point and asserts `upgrade()` resolves its own dependencies.
 *
 * The subprocess intentionally does NOT preload `fs_settings` or `fs_cache`,
 * and does NOT require `base/fs_settings.php` anywhere in the setup. It also
 * tolerates the DB-bound body aborting inside the seeder's own try/catch:
 * the assertion is about LEGACY-CLASS LOADING, not the DB outcome.
 */
final class InitUpgradeEntryPointLoadingTest extends TestCase
{
    private const TMP_DIR_NAME = 'clientes_core_init_upgrade_probe/';

    private const INIT_PATH = 'plugins/clientes_core/Init.php';

    /** @var list<string> */
    private array $tempScripts = [];

    protected function tearDown(): void
    {
        foreach ($this->tempScripts as $script) {
            if (is_file($script)) {
                @unlink($script);
            }
        }
        $this->tempScripts = [];

        $tmpDir = FS_FOLDER . '/tmp/' . self::TMP_DIR_NAME;
        $configFile = $tmpDir . 'config2.ini';
        if (is_file($configFile)) {
            @unlink($configFile);
        }
        if (is_dir($tmpDir)) {
            @rmdir($tmpDir);
        }

        parent::tearDown();
    }

    /**
     * The minimal entry point: only the plugin's own `Init.php` is required.
     * Neither `fs_settings` nor `fs_cache` (nor `fs_model`, which transitively
     * loads `fs_cache`) is preloaded. `upgrade()` must load both on demand.
     */
    public function testUpgradeLoadsFsSettingsAndFsCacheFromAMinimalEntryPoint(): void
    {
        $snippet = $this->minimalBootstrapSnippet() . <<<'PHP'
if (class_exists('fs_settings', false) || class_exists('fs_cache', false)) {
    fwrite(STDERR, "PRELOADED\n");
    exit(3);
}

\FSFramework\Plugins\clientes_core\Init::upgrade();

if (!class_exists('fs_settings', false)) {
    fwrite(STDERR, "FS_SETTINGS_NOT_LOADED\n");
    exit(4);
}
if (!class_exists('fs_cache', false)) {
    fwrite(STDERR, "FS_CACHE_NOT_LOADED\n");
    exit(5);
}

echo "ok\n";
exit(0);
PHP;

        $result = $this->runInCleanProcess($snippet);

        $this->assertSame(
            0,
            $result['code'],
            "Init::upgrade() must resolve fs_settings and fs_cache on demand.\n" . $result['output']
        );
        $this->assertStringNotContainsString('Class "fs_settings" not found', $result['output']);
        $this->assertStringNotContainsString('Class "fs_cache" not found', $result['output']);
        $this->assertStringNotContainsString('Fatal error', $result['output']);
        $this->assertSame('ok', trim($result['stdout']));
    }

    /**
     * The realistic production-shaped entry point: `base/fs_model.php` is
     * loaded (which transitively loads `fs_cache`) but `fs_settings` is NOT.
     * This is the exact coupling that only the plugin synchronizer honours.
     */
    public function testUpgradeLoadsFsSettingsFromAnEntryPointThatLoadedOnlyTheModelBase(): void
    {
        $snippet = $this->modelBaseBootstrapSnippet() . <<<'PHP'
if (class_exists('fs_settings', false)) {
    fwrite(STDERR, "PRELOADED\n");
    exit(3);
}

\FSFramework\Plugins\clientes_core\Init::upgrade();

if (!class_exists('fs_settings', false)) {
    fwrite(STDERR, "FS_SETTINGS_NOT_LOADED\n");
    exit(4);
}

echo "ok\n";
exit(0);
PHP;

        $result = $this->runInCleanProcess($snippet);

        $this->assertSame(
            0,
            $result['code'],
            "Init::upgrade() must resolve fs_settings on demand.\n" . $result['output']
        );
        $this->assertStringNotContainsString('Class "fs_settings" not found', $result['output']);
        $this->assertStringNotContainsString('Fatal error', $result['output']);
        $this->assertSame('ok', trim($result['stdout']));
    }

    /**
     * Defines the constants the plugin needs, ensures the tmp directory exists,
     * and requires ONLY the plugin's `Init.php`. No framework base class is
     * preloaded: that is the gap under test.
     */
    private function minimalBootstrapSnippet(): string
    {
        $folder = var_export(FS_FOLDER, true);
        $init = var_export(FS_FOLDER . '/' . self::INIT_PATH, true);

        return <<<PHP
<?php
define('FS_FOLDER', {$folder});
define('FS_TMP_NAME', '{$this->tmpName()}');
\$tmpDir = FS_FOLDER . '/tmp/' . FS_TMP_NAME;
if (!is_dir(\$tmpDir) && !mkdir(\$tmpDir, 0777, true) && !is_dir(\$tmpDir)) {
    fwrite(STDERR, "NO_TMP_DIR\n");
    exit(9);
}
require {$init};

PHP;
    }

    /**
     * Requires `base/fs_model.php` (the model base the seeder's models extend)
     * plus the plugin's `Init.php`, and deliberately NOT `fs_settings`.
     */
    private function modelBaseBootstrapSnippet(): string
    {
        $folder = var_export(FS_FOLDER, true);
        $init = var_export(FS_FOLDER . '/' . self::INIT_PATH, true);

        return <<<PHP
<?php
define('FS_FOLDER', {$folder});
define('FS_TMP_NAME', '{$this->tmpName()}');
\$tmpDir = FS_FOLDER . '/tmp/' . FS_TMP_NAME;
if (!is_dir(\$tmpDir) && !mkdir(\$tmpDir, 0777, true) && !is_dir(\$tmpDir)) {
    fwrite(STDERR, "NO_TMP_DIR\n");
    exit(9);
}
require FS_FOLDER . '/base/fs_model.php';
require {$init};

PHP;
    }

    private function tmpName(): string
    {
        return self::TMP_DIR_NAME;
    }

    /**
     * Runs a snippet in a brand-new PHP process. Skips (never fails) when the
     * PHP binary cannot be resolved, so an environment without a usable CLI
     * binary does not report a spurious regression.
     *
     * @return array{code: int, stdout: string, stderr: string, output: string}
     */
    private function runInCleanProcess(string $snippet): array
    {
        $php = $this->resolvePhpBinary();
        if ($php === null) {
            $this->markTestSkipped('No usable PHP CLI binary could be resolved for the subprocess probe.');
        }

        $script = tempnam(sys_get_temp_dir(), 'init_upgrade_entry_');
        if ($script === false) {
            $this->markTestSkipped('Could not create a temporary script for the subprocess probe.');
        }

        file_put_contents($script, $snippet);
        $this->tempScripts[] = $script;

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open([$php, $script], $descriptors, $pipes);
        if (!is_resource($process)) {
            $this->markTestSkipped('proc_open() could not start the PHP subprocess probe.');
        }

        fclose($pipes[0]);
        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($process);

        return [
            'code' => $code,
            'stdout' => $stdout,
            'stderr' => $stderr,
            'output' => $stdout . $stderr,
        ];
    }

    private function resolvePhpBinary(): ?string
    {
        $candidates = [PHP_BINARY, PHP_BINDIR . '/php', 'php'];

        foreach ($candidates as $candidate) {
            if ($candidate === '') {
                continue;
            }
            if ($candidate === 'php') {
                $resolved = trim((string) @shell_exec('command -v php 2>/dev/null'));

                return $resolved !== '' ? $resolved : null;
            }
            if (is_file($candidate) && is_executable($candidate)) {
                return $candidate;
            }
        }

        return null;
    }
}
