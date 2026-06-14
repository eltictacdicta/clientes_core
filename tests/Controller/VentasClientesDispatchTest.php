<?php
/**
 * Regression test for the silent-failure dispatch bug in
 * plugins/clientes_core/controller/ventas_clientes.php.
 *
 * Bug: dispatch condition checked `filter_input(INPUT_POST, 'codcliente')`,
 * which is falsy for the empty-string auto-generate path. The fix
 * (commit 331daf96) introduced an `action=nuevo_cliente` sentinel.
 *
 * Strategy: The controller exposes a public `dispatch(): array` method
 * (added in the refactor) that returns a structured result without
 * emitting HTTP side effects. The test calls `dispatch()` directly
 * via a reflection-built controller, with a Symfony\Request holding
 * the desired POST body and autoloader stubs for `cliente` and
 * `grupo_clientes` so no real DB is touched.
 *
 * Maps 1:1 to scenarios in spec.md (VCT-01.a..e). VCT-01.f requires
 * a strict-format stub that PHP cannot swap mid-process; documented
 * as a follow-up in the verify report.
 */

declare(strict_types=1);

namespace Tests\ClientesCore\Controller;

use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use FSFramework\Security\CsrfManager;

final class VentasClientesDispatchTest extends TestCase
{
    /** @var object */
    private $controller;

    /** @var int Buffer level captured in setUp; tearDown only closes back to this. */
    private int $bufferLevelAtSetup = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bufferLevelAtSetup = ob_get_level();
        ob_start();
        $this->loadStubs();
        $this->resetCoreLog();

        // The controller is not autoloaded (controllers live under plugin/controller/,
        // outside the fs_model_autoloader scope). Load the file and its parent class
        // chain so ReflectionClass can find ventas_clientes.
        if (!class_exists('ventas_clientes', false)) {
            require_once FS_FOLDER . '/base/fs_controller.php';
            require_once FS_FOLDER . '/plugins/clientes_core/extras/clientes_controller.php';
            require_once FS_FOLDER . '/plugins/clientes_core/controller/ventas_clientes.php';
        }

        $reflection = new \ReflectionClass(\ventas_clientes::class);
        $this->controller = $reflection->newInstanceWithoutConstructor();

        // user (public on fs_controller line 163): used by allow_delete_on()
        $this->controller->user = new class {
            public function allow_delete_on($page) { return false; }
        };

        // class_name (protected on fs_controller line 84) and page (public
        // line 144) are normally set by fs_controller::__construct().
        // url() (line 702) returns $this->page->url(), so we must populate
        // both for the listing branch to function.
        $classNameProp = new \ReflectionProperty(\fs_controller::class, 'class_name');
        $classNameProp->setAccessible(true);
        $classNameProp->setValue($this->controller, \ventas_clientes::class);

        $this->controller->page = new class {
            public function url() { return 'index.php?page=ventas_clientes'; }
        };

        // core_log and cache are protected on fs_app, normally set by fs_app::__construct.
        // newInstanceWithoutConstructor skips that, so we must populate them.
        $coreLogProp = new \ReflectionProperty(\fs_app::class, 'core_log');
        $coreLogProp->setAccessible(true);
        $coreLogProp->setValue($this->controller, new \fs_core_log(\ventas_clientes::class));

        $cacheProp = new \ReflectionProperty(\fs_app::class, 'cache');
        $cacheProp->setAccessible(true);
        $cacheProp->setValue($this->controller, new \fs_cache());

        // db (protected on fs_controller line 90). The listing branch in
        // loadAllClientes() calls $this->db->select_limit() and select().
        // Provide a no-op stub so no real DB is touched.
        $dbProp = new \ReflectionProperty(\fs_controller::class, 'db');
        $dbProp->setAccessible(true);
        $dbProp->setValue($this->controller, new class {
            public function select_limit($sql, $limit, $offset) { return []; }
            public function select($sql) { return []; }
            public function exec($sql) { return true; }
            public function var2str($v) { return is_string($v) ? ("'" . addslashes($v) . "'") : (string)(int)$v; }
        });

        // request (protected on fs_controller line 58)
        $requestProp = new \ReflectionProperty(\fs_controller::class, 'request');
        $requestProp->setAccessible(true);
        $requestProp->setValue($this->controller, Request::create('/', 'POST', [
            CsrfManager::FIELD_NAME => CsrfManager::generateToken(),
        ]));

        // csrf_valid (protected on fs_controller line 64) — default true
        $csrfProp = new \ReflectionProperty(\fs_controller::class, 'csrf_valid');
        $csrfProp->setAccessible(true);
        $csrfProp->setValue($this->controller, true);
    }

    protected function tearDown(): void
    {
        $_POST = [];
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

        // Reset the static controller_name too. fs_core_log::__construct()
        // only sets it when empty, so a previous test that set a different
        // name would shadow ours and make fs_controller::new_error_msg()
        // skip the 'errors' channel (it only writes to 'errors' when
        // class_name == controller_name()).
        $nameProp = $ref->getProperty('controller_name');
        $nameProp->setAccessible(true);
        $nameProp->setValue(null, null);

        // Also reset the static core_log on fs_model so the next test gets
        // a fresh instance via fs_model's lazy init.
        $modelRef = new \ReflectionClass('fs_model');
        $modelProp = $modelRef->getProperty('core_log');
        $modelProp->setAccessible(true);
        $modelProp->setValue(null, new \fs_core_log());
    }

    /** @var callable|null The autoloader callback, stored so we can unregister in tearDown. */
    private static $autoloaderCallback = null;

    /**
     * Register a prepend autoloader that, when `cliente` or `grupo_clientes` is
     * requested, declares minimal stubs via eval so no real DB is touched.
     */
    private function loadStubs(): void
    {
        if (self::$autoloaderCallback !== null) {
            spl_autoload_unregister(self::$autoloaderCallback);
        }

        $callback = function (string $class): void {
            if ($class === 'cliente' && !class_exists('cliente', false)) {
                $this->declareClienteStub();
            }
            if ($class === 'grupo_clientes' && !class_exists('grupo_clientes', false)) {
                $this->declareGrupoClientesStub();
            }
        };

        // prepend=true so we win over fs_model_autoloader
        spl_autoload_register($callback, true, true);
        self::$autoloaderCallback = $callback;
    }

    private function declareClienteStub(): void
    {
        eval('class cliente extends \fs_model {
            public $codcliente;
            public $nombre = "";
            public $razonsocial = "";
            public $cifnif = "";
            public $email = "";
            public $telefono1 = "";
            public $codgrupo;
            public $debaja = false;
            public $personafisica = true;
            public $regimeniva = "General";
            public $fechabaja;
            public $codproveedor;
            public $observaciones;
            public $diaspago;
            public function __construct($data = false) { $this->table_name = "clientes"; }
            public function delete(): bool { return false; }
            public function exists(): bool { return false; }
            public function test(): bool { $this->codcliente = $this->codcliente ?? "000001"; return true; }
            public function save(): bool { return $this->test(); }
            public function url(): string { return "index.php?page=ventas_cliente&cod=" . $this->codcliente; }
            public function get_errors(): array { return []; }
            public function search($q = "", $offset = 0) { return []; }
        }');
    }

    private function declareGrupoClientesStub(): void
    {
        eval('class grupo_clientes extends \fs_model {
            public $codgrupo;
            public $nombre;
            public function __construct($data = false) { $this->table_name = "gruposclientes"; }
            public function delete(): bool { return false; }
            public function exists(): bool { return false; }
            public function save(): bool { return true; }
            public function all(): array { return []; }
            public function test(): bool { return true; }
        }');
    }

    private function buildController(array $postData, bool $csrfValid = true): void
    {
        $requestProp = new \ReflectionProperty(\fs_controller::class, 'request');
        $requestProp->setAccessible(true);
        $requestProp->setValue($this->controller, Request::create('/', 'POST', array_merge(
            [CsrfManager::FIELD_NAME => CsrfManager::generateToken()],
            $postData
        )));
        $csrfProp = new \ReflectionProperty(\fs_controller::class, 'csrf_valid');
        $csrfProp->setAccessible(true);
        $csrfProp->setValue($this->controller, $csrfValid);
        $_POST = $postData;
        $_REQUEST = $postData;
    }

    /**
     * VCT-01.a: empty codcliente with action sentinel auto-generates a cliente.
     */
    public function testEmptyCodclienteWithActionSentinelCreatesCliente(): void
    {
        $this->buildController(['action' => 'nuevo_cliente', 'nombre' => 'Test', 'cifnif' => 'B12345']);
        $result = $this->controller->dispatch();
        $this->assertSame('nuevo_cliente', $result['action']);
        $this->assertSame('000001', $result['cliente_codcliente']);
        $this->assertNotNull($result['redirect_url']);
    }

    /**
     * VCT-01.b: user-typed codcliente is honored.
     */
    public function testTypedCodclienteIsHonored(): void
    {
        $this->buildController(['action' => 'nuevo_cliente', 'codcliente' => 'CUSTOM1', 'nombre' => 'Test']);
        $result = $this->controller->dispatch();
        $this->assertSame('nuevo_cliente', $result['action']);
        $this->assertSame('CUSTOM1', $result['cliente_codcliente']);
        $this->assertNotNull($result['redirect_url']);
    }

    /**
     * VCT-01.c: legacy `codigo` field is accepted when codcliente is missing.
     */
    public function testLegacyCodigoFieldIsAccepted(): void
    {
        $this->buildController(['action' => 'nuevo_cliente', 'codigo' => 'LEGACY1', 'nombre' => 'Test']);
        $result = $this->controller->dispatch();
        $this->assertSame('nuevo_cliente', $result['action']);
        $this->assertSame('LEGACY1', $result['cliente_codcliente']);
        $this->assertNotNull($result['redirect_url']);
    }

    /**
     * VCT-01.d: missing action sentinel falls through to listing.
     */
    public function testMissingActionFallsThroughToListing(): void
    {
        $this->buildController(['nombre' => 'Test', 'codcliente' => '']);
        $result = $this->controller->dispatch();
        $this->assertNull($result['action']);
        $this->assertNull($result['cliente_codcliente']);
        $this->assertNull($result['redirect_url']);
        $this->assertSame([], $result['errors']);
    }

    /**
     * VCT-01.e: CSRF rejection prevents dispatch.
     */
    public function testCsrfRejectionPreventsDispatch(): void
    {
        $this->buildController(['action' => 'nuevo_cliente', 'nombre' => 'Test'], csrfValid: false);
        $result = $this->controller->dispatch();
        $this->assertSame('nuevo_cliente', $result['action']);
        $this->assertNull($result['cliente_codcliente']);
        $this->assertNull($result['redirect_url']);
        $this->assertNotEmpty($result['errors']);
    }
}
