<?php
/**
 * In-use guard for the discount-group delete path.
 *
 * A `grupo_descuentos` referenced by at least one `clientes.codgrupo_descuento`
 * row must not be deleted: the controller refuses, records a single error and
 * issues no DELETE. Covers the `discount-groups` delta scenarios
 * "In-use discount group cannot be deleted" and
 * "Personalizado cannot be deleted while in use".
 *
 * Strategy: the guard runs before the namespaced `grupo_descuentos` model is
 * loaded, so this suite can stub the global `cliente` counter and stay
 * DB-hermetic. The controller is built via reflection and its private
 * `delete_grupo()` is invoked directly, with the Symfony Request carrying the
 * code and the CSRF token.
 *
 * Process isolation: `#[RunTestsInSeparateProcesses]` keeps the eval-based
 * `cliente` stub from colliding with sibling suites that load the real class.
 */

declare(strict_types=1);

namespace Tests\ClientesCore\Controller;

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use FSFramework\Security\CsrfManager;

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class DescuentosGrupoDeleteTest extends TestCase
{
    /** @var callable|null The autoloader callback for cleanup. */
    private static $autoloaderCallback = null;

    /** @var int Buffer level captured in setUp; tearDown only closes back to this. */
    private int $bufferLevelAtSetup = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bufferLevelAtSetup = ob_get_level();
        ob_start();
        $this->loadStubs();
        $this->resetCoreLog();

        if (!class_exists(\descuentos_grupo::class, false)) {
            require_once FS_FOLDER . '/base/fs_controller.php';
            require_once FS_FOLDER . '/plugins/clientes_core/extras/clientes_controller.php';
            require_once FS_FOLDER . '/plugins/clientes_core/controller/descuentos_grupo.php';
        }
    }

    protected function tearDown(): void
    {
        $_POST = [];
        $_GET = [];
        $_REQUEST = [];
        while (ob_get_level() > $this->bufferLevelAtSetup) {
            ob_end_clean();
        }
        if (self::$autoloaderCallback !== null) {
            spl_autoload_unregister(self::$autoloaderCallback);
            self::$autoloaderCallback = null;
        }
        $this->resetCoreLog();
        parent::tearDown();
    }

    private function resetCoreLog(): void
    {
        $ref = new \ReflectionClass(\fs_core_log::class);
        $prop = $ref->getProperty('data_log');
        $prop->setAccessible(true);
        $prop->setValue(null, []);

        $nameProp = $ref->getProperty('controller_name');
        $nameProp->setAccessible(true);
        $nameProp->setValue(null, null);

        $modelRef = new \ReflectionClass('fs_model');
        $modelProp = $modelRef->getProperty('core_log');
        $modelProp->setAccessible(true);
        $modelProp->setValue(null, new \fs_core_log());
    }

    private function loadStubs(): void
    {
        if (self::$autoloaderCallback !== null) {
            spl_autoload_unregister(self::$autoloaderCallback);
        }

        $callback = function (string $class): void {
            if ($class === 'cliente' && !class_exists('cliente', false)) {
                $this->declareClienteStub();
            }
        };

        spl_autoload_register($callback, true, true);
        self::$autoloaderCallback = $callback;
    }

    private function declareClienteStub(): void
    {
        eval('class cliente extends \fs_model {
            public static $countByDiscountGroupResult = 0;
            public function __construct($data = false) { $this->table_name = "clientes"; }
            public function delete(): bool { return false; }
            public function exists(): bool { return false; }
            public function save(): bool { return false; }
            public function test(): bool { return true; }
            public function countByDiscountGroup(string $cod): int { return self::$countByDiscountGroupResult; }
        }');
    }

    /**
     * Build a descuentos_grupo controller whose delete path is ready to run.
     */
    private function buildController(string $cod, int $inUse): object
    {
        \cliente::$countByDiscountGroupResult = $inUse;

        $reflection = new \ReflectionClass(\descuentos_grupo::class);
        $controller = $reflection->newInstanceWithoutConstructor();

        $classNameProp = new \ReflectionProperty(\fs_controller::class, 'class_name');
        $classNameProp->setAccessible(true);
        $classNameProp->setValue($controller, \descuentos_grupo::class);

        $coreLogProp = new \ReflectionProperty(\fs_app::class, 'core_log');
        $coreLogProp->setAccessible(true);
        $coreLogProp->setValue($controller, new \fs_core_log(\descuentos_grupo::class));

        $cacheProp = new \ReflectionProperty(\fs_app::class, 'cache');
        $cacheProp->setAccessible(true);
        $cacheProp->setValue($controller, new \fs_cache());

        $dbProp = new \ReflectionProperty(\fs_controller::class, 'db');
        $dbProp->setAccessible(true);
        $dbProp->setValue($controller, new class {
            public function select($sql) { return []; }
            public function select_limit($sql, $limit, $offset) { return []; }
            public function exec($sql) { return true; }
            public function var2str($v) { return is_string($v) ? ("'" . addslashes($v) . "'") : (string)(int)$v; }
        });

        $requestProp = new \ReflectionProperty(\fs_controller::class, 'request');
        $requestProp->setAccessible(true);
        $requestProp->setValue($controller, Request::create('/', 'POST', [
            CsrfManager::FIELD_NAME => CsrfManager::generateToken(),
            'codgrupo_descuento' => $cod,
        ]));

        $csrfProp = new \ReflectionProperty(\fs_controller::class, 'csrf_valid');
        $csrfProp->setAccessible(true);
        $csrfProp->setValue($controller, true);

        $controller->allow_delete = true;
        $controller->grupos_descuentos = [];
        $controller->offset = 0;

        return $controller;
    }

    private function invokeDelete(object $controller): void
    {
        $method = new \ReflectionMethod(\descuentos_grupo::class, 'delete_grupo');
        $method->setAccessible(true);
        $method->invoke($controller);
    }

    /**
     * An in-use discount group is refused: one refusal error, no success
     * message (therefore no DELETE reached the model).
     */
    #[Test]
    public function deleteRefusesInUseDiscountGroup(): void
    {
        $controller = $this->buildController('000005', 2);

        $this->invokeDelete($controller);

        $errors = $controller->get_errors();
        $this->assertCount(1, $errors, 'exactly one refusal error must be recorded');
        $this->assertStringContainsString(
            'no se puede eliminar el grupo de descuentos',
            mb_strtolower($errors[0])
        );
        $this->assertSame([], $controller->get_messages(), 'no success message: the group was not deleted');
    }

    /**
     * "Personalizado" (000000) is the mandatory discount default and cannot be
     * deleted while clients reference it.
     */
    #[Test]
    public function personalizadoCannotBeDeletedWhileInUse(): void
    {
        $controller = $this->buildController('000000', 1);

        $this->invokeDelete($controller);

        $errors = $controller->get_errors();
        $this->assertCount(1, $errors);
        $this->assertStringContainsString(
            'no se puede eliminar el grupo de descuentos',
            mb_strtolower($errors[0])
        );
        $this->assertSame([], $controller->get_messages(), 'no success message: the group was not deleted');
    }
}
