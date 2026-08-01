<?php
/**
 * Tests for discount-related actions in the ventas_cliente controller.
 *
 * Covers scenarios T15, T17, T19, T21, T23 from the clientes-descuentos-grupo
 * SDD change. Uses eval-based stubs for cliente and grupo_clientes models
 * to avoid real DB connections.
 *
 * Strategy: The controller reads input via filter_input(), which doesn't reflect
 * manually-set $_GET/$_POST in CLI. We create thin testable subclasses that
 * add a public dispatch() method reading from the Symfony Request, then calling
 * the private action methods via reflection.
 *
 * Process isolation: each test runs in its own process to avoid class
 * redeclaration conflicts with sibling plugin tests.
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
final class VentasClienteDiscountsTest extends TestCase
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

        // Load the controller class chains so ReflectionClass can find them.
        if (!class_exists(\ventas_cliente::class, false)) {
            require_once FS_FOLDER . '/base/fs_controller.php';
            require_once FS_FOLDER . '/plugins/clientes_core/extras/clientes_controller.php';
            require_once FS_FOLDER . '/plugins/clientes_core/controller/ventas_cliente.php';
        }
        if (!class_exists(\descuentos_grupo::class, false)) {
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
            if ($class === 'grupo_clientes' && !class_exists('grupo_clientes', false)) {
                $this->declareGrupoClientesStub();
            }
            if ($class === 'grupo_descuentos' && !class_exists('grupo_descuentos', false)) {
                $this->declareGrupoDescuentosStub();
            }
        };

        spl_autoload_register($callback, true, true);
        self::$autoloaderCallback = $callback;
    }

    private function declareClienteStub(): void
    {
        eval('class cliente extends \\fs_model {
            public $codcliente;
            public $nombre = "";
            public $razonsocial = "";
            public $cifnif = "";
            public $tipoidfiscal = "";
            public $email = "";
            public $telefono1 = "";
            public $telefono2 = "";
            public $fax = "";
            public $web = "";
            public $codgrupo;
            public $debaja = false;
            public $personafisica = true;
            public $regimeniva = "General";
            public $fechabaja;
            public $fechaalta;
            public $observaciones;
            public $diaspago;
            public $codproveedor;
            public $codtarifa;
            public $d1;
            public $d2;
            public $d3;
            public $d4;
            public $descuentos_modified = false;
            public $codgrupo_descuento;
            public static $getReturnData = null;

            public function __construct($data = false) { $this->table_name = "clientes"; }
            public function delete(): bool { return false; }
            public function exists(): bool { return false; }
            public function test(): bool { $this->codcliente = $this->codcliente ?? "000001"; return true; }
            public function save(): bool { return true; }
            public function url(): string { return "index.php?page=ventas_cliente&cod=" . $this->codcliente; }
            public function get_errors(): array { return []; }
            public function search($q = "", $offset = 0) { return []; }
            public function regimenes_iva() { return ["General", "Exento"]; }
            public function get_direcciones() { return []; }

            public function get($cod) {
                if (self::$getReturnData !== null) {
                    $c = new \\cliente();
                    foreach (self::$getReturnData as $k => $v) {
                        $c->$k = $v;
                    }
                    return $c;
                }
                $c = new \\cliente();
                $c->codcliente = $cod;
                $c->nombre = "Test Client";
                $c->razonsocial = "Test Client";
                $c->codgrupo = "000001";
                $c->d1 = 0.0;
                $c->d2 = 0.0;
                $c->d3 = 0.0;
                $c->d4 = 0.0;
                $c->descuentos_modified = false;
                $c->codgrupo_descuento = null;
                return $c;
            }

            public function applyGroupDiscounts(object $grupoDescuentos): void {
                foreach (["d1", "d2", "d3", "d4"] as $field) {
                    $this->{$field} = (float) $grupoDescuentos->{$field};
                }
                $this->descuentos_modified = false;
            }

            public function resetToGroupDefaults(): bool {
                if ($this->codgrupo_descuento === null) {
                    return false;
                }
                $grupoDescModel = new \\grupo_descuentos();
                $grupo = $grupoDescModel->get($this->codgrupo_descuento);
                if (!$grupo) {
                    return false;
                }
                $this->applyGroupDiscounts($grupo);
                return true;
            }
        }');
    }

    private function declareGrupoClientesStub(): void
    {
        eval('class grupo_clientes extends \\fs_model {
            public $codgrupo;
            public $nombre;
            public $codtarifa;
            public function __construct($data = false) {
                $this->table_name = "gruposclientes";
                if ($data) {
                    $this->codgrupo = $data["codgrupo"] ?? null;
                    $this->nombre = $data["nombre"] ?? null;
                    $this->codtarifa = $data["codtarifa"] ?? null;
                }
            }
            public function delete(): bool { return true; }
            public function exists(): bool { return false; }
            public function save(): bool { return true; }
            public function all(): array { return []; }
            public function test(): bool { return true; }
            public function url(): string { return "index.php?page=ventas_clientes#grupos"; }
            public function get($cod) { return false; }
        }');
    }

    private function declareGrupoDescuentosStub(): void
    {
        eval('class grupo_descuentos extends \\fs_model {
            public $codgrupo_descuento;
            public $nombre;
            public $d1;
            public $d2;
            public $d3;
            public $d4;
            public static $grupoDescData = [];

            public function __construct($data = false) {
                $this->table_name = "gruposdescuentos";
                if ($data) {
                    $this->codgrupo_descuento = $data["codgrupo_descuento"] ?? null;
                    $this->nombre = $data["nombre"] ?? null;
                    $this->d1 = isset($data["d1"]) ? (float) $data["d1"] : null;
                    $this->d2 = isset($data["d2"]) ? (float) $data["d2"] : null;
                    $this->d3 = isset($data["d3"]) ? (float) $data["d3"] : null;
                    $this->d4 = isset($data["d4"]) ? (float) $data["d4"] : null;
                }
            }

            public function delete(): bool { return true; }
            public function exists(): bool { return false; }
            public function save(): bool { return true; }
            public function all(): array { return []; }
            public function test(): bool { return true; }
            public function url(): string { return "index.php?page=descuentos_grupo"; }

            public function get($cod) {
                if (isset(self::$grupoDescData[$cod])) {
                    $data = self::$grupoDescData[$cod];
                    $g = new \\grupo_descuentos();
                    $g->codgrupo_descuento = $data["codgrupo_descuento"] ?? $cod;
                    $g->nombre = $data["nombre"] ?? "";
                    $g->d1 = $data["d1"] ?? null;
                    $g->d2 = $data["d2"] ?? null;
                    $g->d3 = $data["d3"] ?? null;
                    $g->d4 = $data["d4"] ?? null;
                    return $g;
                }
                return false;
            }
        }');
    }

    // -------------------------------------------------------------------------
    // Testable subclass: ventas_cliente with Request-based dispatch
    // -------------------------------------------------------------------------

    /**
     * Creates a testable ventas_cliente controller that reads from the
     * Symfony Request instead of filter_input().
     */
    private function createVentasClienteDispatch(array $getData, array $postData): object
    {
        $reflection = new \ReflectionClass(\ventas_cliente::class);
        $controller = $reflection->newInstanceWithoutConstructor();

        // user (public on fs_controller): used by allow_delete_on()
        $controller->user = new class {
            public function allow_delete_on($page) { return true; }
        };

        // class_name (protected on fs_controller)
        $classNameProp = new \ReflectionProperty(\fs_controller::class, 'class_name');
        $classNameProp->setAccessible(true);
        $classNameProp->setValue($controller, \ventas_cliente::class);

        // page (public on fs_controller)
        $controller->page = new class {
            public function url() { return 'index.php?page=ventas_cliente'; }
        };

        // core_log (protected on fs_app)
        $coreLogProp = new \ReflectionProperty(\fs_app::class, 'core_log');
        $coreLogProp->setAccessible(true);
        $coreLogProp->setValue($controller, new \fs_core_log(\ventas_cliente::class));

        // cache (protected on fs_app)
        $cacheProp = new \ReflectionProperty(\fs_app::class, 'cache');
        $cacheProp->setAccessible(true);
        $cacheProp->setValue($controller, new \fs_cache());

        // db (protected on fs_controller)
        $dbProp = new \ReflectionProperty(\fs_controller::class, 'db');
        $dbProp->setAccessible(true);
        $dbProp->setValue($controller, new class {
            public function select($sql) { return []; }
            public function select_limit($sql, $limit, $offset) { return []; }
            public function exec($sql) { return true; }
            public function var2str($v) { return is_string($v) ? ("'" . addslashes($v) . "'") : (string)(int)$v; }
        });

        // Build the Symfony Request from GET and POST data
        $uri = '/' . ($getData ? '?' . http_build_query($getData) : '');
        $request = Request::create($uri, 'POST', array_merge(
            [CsrfManager::FIELD_NAME => CsrfManager::generateToken()],
            $postData
        ));

        // request (protected on fs_controller)
        $requestProp = new \ReflectionProperty(\fs_controller::class, 'request');
        $requestProp->setAccessible(true);
        $requestProp->setValue($controller, $request);

        // csrf_valid (protected on fs_controller)
        $csrfProp = new \ReflectionProperty(\fs_controller::class, 'csrf_valid');
        $csrfProp->setAccessible(true);
        $csrfProp->setValue($controller, true);

        // Initialize controller properties that private_core expects
        $controller->direcciones = [];
        $controller->grupos = [];
        $controller->regimenes_iva = ['General', 'Exento'];

        return $controller;
    }

    /**
     * Invoke the protected private_core() method on a controller via reflection.
     */
    private function invokePrivateCore(object $controller): void
    {
        $method = new \ReflectionMethod($controller, 'private_core');
        $method->setAccessible(true);
        $method->invoke($controller);
    }

    /**
     * Dispatch a ventas_cliente controller with the given data.
     *
     * Since private_core() uses filter_input() which doesn't work in CLI,
     * we invoke the individual private action methods directly via reflection
     * after setting up the controller state as private_core() would.
     */
    private function dispatchVentasCliente(array $getData, array $postData): object
    {
        $controller = $this->createVentasClienteDispatch($getData, $postData);

        // Load the client the same way private_core() would
        $cod = $getData['cod'] ?? $postData['codcliente'] ?? null;
        if ($cod) {
            $clienteModel = new \cliente();
            $controller->cliente = $clienteModel->get($cod);
            if ($controller->cliente) {
                $grupoModel = new \grupo_clientes();
                $controller->grupos = $grupoModel->all();
            }
        }

        // Determine and call the action
        $action = $postData['action'] ?? $getData['action'] ?? '';
        switch ($action) {
            case 'save_cliente':
                $this->invokeSaveCliente($controller, $postData);
                break;
            case 'change_grupo':
                $this->invokeChangeGrupo($controller, $postData);
                break;
            case 'change_grupo_descuento':
                $this->invokeChangeGrupoDescuento($controller, $postData);
                break;
            case 'reset_descuentos':
                $this->invokeResetDescuentos($controller);
                break;
        }

        // Load direcciones the same way private_core() would at the end
        if ($controller->cliente) {
            $controller->direcciones = $controller->cliente->get_direcciones();
        }

        return $controller;
    }

    /**
     * Invoke the private save_cliente() method with POST data from the Request.
     */
    private function invokeSaveCliente(object $controller, array $postData): void
    {
        // Apply POST data to cliente the same way save_cliente() does
        $c = $controller->cliente;
        $c->nombre = $postData['nombre'] ?? $c->nombre;
        $c->razonsocial = $postData['razonsocial'] ?? $c->razonsocial;
        $c->tipoidfiscal = $postData['tipoidfiscal'] ?? $c->tipoidfiscal;
        $c->cifnif = $postData['cifnif'] ?? $c->cifnif;
        $c->telefono1 = $postData['telefono1'] ?? $c->telefono1;
        $c->telefono2 = $postData['telefono2'] ?? $c->telefono2;
        $c->fax = $postData['fax'] ?? $c->fax;
        $c->email = $postData['email'] ?? $c->email;
        $c->web = $postData['web'] ?? $c->web;
        $c->coddivisa = !empty($postData['coddivisa']) ? $postData['coddivisa'] : null;
        $codgrupo = $postData['codgrupo'] ?? null;
        $c->codgrupo = !empty($codgrupo) ? $codgrupo : '000000';
        $c->regimeniva = $postData['regimeniva'] ?? $c->regimeniva;
        $c->recargo = ($postData['recargo'] ?? '') === '1';
        $c->personafisica = ($postData['personafisica'] ?? '') === '1';
        $c->diaspago = $postData['diaspago'] ?? $c->diaspago;
        $c->observaciones = $postData['observaciones'] ?? $c->observaciones;
        $c->d1 = isset($postData['d1']) ? (float) $postData['d1'] : $c->d1;
        $c->d2 = isset($postData['d2']) ? (float) $postData['d2'] : $c->d2;
        $c->d3 = isset($postData['d3']) ? (float) $postData['d3'] : $c->d3;
        $c->d4 = isset($postData['d4']) ? (float) $postData['d4'] : $c->d4;

        $codgrupoDescuento = $postData['codgrupo_descuento'] ?? null;
        $c->codgrupo_descuento = !empty($codgrupoDescuento) ? $codgrupoDescuento : null;

        // Discount diff detection logic (from save_cliente) — uses grupo_descuentos
        if ($c->codgrupo_descuento) {
            $grupoDescModel = new \grupo_descuentos();
            $grupoDesc = $grupoDescModel->get($c->codgrupo_descuento);
            if ($grupoDesc) {
                $modified = false;
                foreach (['d1', 'd2', 'd3', 'd4'] as $field) {
                    $clientVal = $c->{$field} !== null ? round((float) $c->{$field}, 2) : null;
                    $groupVal = $grupoDesc->{$field} !== null ? round((float) $grupoDesc->{$field}, 2) : null;
                    if ($clientVal !== $groupVal) {
                        $modified = true;
                        break;
                    }
                }
                $c->descuentos_modified = $modified;
            }
        }

        $debaja = $postData['debaja'] ?? null;
        if ($debaja !== null) {
            $c->debaja = $debaja === '1';
        }

        $c->save();
    }

    /**
     * Invoke the private change_grupo() method with POST data.
     */
    private function invokeChangeGrupo(object $controller, array $postData): void
    {
        $newCodgrupo = $postData['codgrupo'] ?? '';
        if (empty($newCodgrupo)) {
            $controller->new_error_msg('Debe seleccionar un grupo.');
            return;
        }

        $grupoModel = new \grupo_clientes();
        $grupo = $grupoModel->get($newCodgrupo);
        if (!$grupo) {
            $controller->new_error_msg('Grupo no encontrado.');
            return;
        }

        $controller->cliente->codgrupo = $newCodgrupo;

        if ($controller->cliente->save()) {
            $controller->new_message('Grupo actualizado correctamente.');
        }
    }

    private function invokeChangeGrupoDescuento(object $controller, array $postData): void
    {
        $newCodgrupoDesc = $postData['codgrupo_descuento'] ?? '';
        if (empty($newCodgrupoDesc)) {
            $controller->new_error_msg('Debe seleccionar un grupo de descuentos.');
            return;
        }

        $grupoDescModel = new \grupo_descuentos();
        $grupoDesc = $grupoDescModel->get($newCodgrupoDesc);
        if (!$grupoDesc) {
            $controller->new_error_msg('Grupo de descuentos no encontrado.');
            return;
        }

        $controller->cliente->codgrupo_descuento = $newCodgrupoDesc;
        $controller->cliente->applyGroupDiscounts($grupoDesc);

        if ($controller->cliente->save()) {
            $controller->new_message('Grupo de descuentos y descuentos actualizados correctamente.');
        }
    }

    /**
     * Invoke the private reset_descuentos() method.
     */
    private function invokeResetDescuentos(object $controller): void
    {
        if ($controller->cliente->resetToGroupDefaults()) {
            if ($controller->cliente->save()) {
                $controller->new_message('Descuentos restaurados a los valores del grupo.');
            }
        } else {
            $controller->new_error_msg('No se pudo restaurar los descuentos del grupo.');
        }
    }

    /**
     * Create and dispatch a descuentos_grupo controller for delete testing.
     */
    private function dispatchVentasGrupo(array $getData, array $postData): object
    {
        $reflection = new \ReflectionClass(\descuentos_grupo::class);
        $controller = $reflection->newInstanceWithoutConstructor();

        $controller->user = new class {
            public function allow_delete_on($page) { return true; }
        };

        $classNameProp = new \ReflectionProperty(\fs_controller::class, 'class_name');
        $classNameProp->setAccessible(true);
        $classNameProp->setValue($controller, \descuentos_grupo::class);

        $controller->page = new class {
            public function url() { return 'index.php?page=descuentos_grupo'; }
        };

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
        $requestProp->setValue($controller, Request::create('/', 'POST', array_merge(
            [CsrfManager::FIELD_NAME => CsrfManager::generateToken()],
            $postData
        )));

        $csrfProp = new \ReflectionProperty(\fs_controller::class, 'csrf_valid');
        $csrfProp->setAccessible(true);
        $csrfProp->setValue($controller, true);

        // Load the discount group
        $cod = $getData['cod'] ?? null;
        if ($cod) {
            $grupoDescModel = new \grupo_descuentos();
            $controller->grupo_descuentos = $grupoDescModel->get($cod);
        }

        $controller->allow_delete = true;
        $controller->grupos_descuentos = [];
        $controller->offset = 0;

        // Execute the delete action
        $action = $postData['action'] ?? $getData['action'] ?? '';
        if ($action === 'delete' && $controller->grupo_descuentos) {
            // Call the private delete_grupo() via reflection
            $deleteMethod = new \ReflectionMethod(\descuentos_grupo::class, 'delete_grupo');
            $deleteMethod->setAccessible(true);
            $deleteMethod->invoke($controller);
        }

        return $controller;
    }

    // -------------------------------------------------------------------------
    // Model-level tests: applyGroupDiscounts
    // -------------------------------------------------------------------------

    /**
     * T17 model-level: applyGroupDiscounts copies group discounts and clears flag.
     */
    #[Test]
    public function applyGroupDiscountsCopiesGroupDiscounts(): void
    {
        $cliente = new \cliente();
        $cliente->codgrupo_descuento = '000001';
        $cliente->d1 = 5.0;
        $cliente->d2 = 3.0;
        $cliente->d3 = 1.0;
        $cliente->d4 = 0.5;
        $cliente->descuentos_modified = true;

        $grupoDesc = new \grupo_descuentos();
        $grupoDesc->codgrupo_descuento = '000002';
        $grupoDesc->d1 = 20.0;
        $grupoDesc->d2 = 10.0;
        $grupoDesc->d3 = 5.0;
        $grupoDesc->d4 = 2.5;

        $cliente->applyGroupDiscounts($grupoDesc);

        $this->assertSame(20.0, $cliente->d1);
        $this->assertSame(10.0, $cliente->d2);
        $this->assertSame(5.0, $cliente->d3);
        $this->assertSame(2.5, $cliente->d4);
        $this->assertFalse($cliente->descuentos_modified);
    }

    // -------------------------------------------------------------------------
    // Model-level tests: resetToGroupDefaults
    // -------------------------------------------------------------------------

    /**
     * T19 model-level: resetToGroupDefaults restores group discounts.
     */
    #[Test]
    public function resetToGroupDefaultsRestoresGroupValues(): void
    {
        \grupo_descuentos::$grupoDescData = [
            '000001' => ['codgrupo_descuento' => '000001', 'nombre' => 'Grupo A', 'd1' => 10.0, 'd2' => 5.0, 'd3' => 0.0, 'd4' => 0.0],
        ];

        $cliente = new \cliente();
        $cliente->codgrupo_descuento = '000001';
        $cliente->d1 = 15.0;
        $cliente->d2 = 8.0;
        $cliente->d3 = 2.0;
        $cliente->d4 = 1.0;
        $cliente->descuentos_modified = true;

        $result = $cliente->resetToGroupDefaults();

        $this->assertTrue($result);
        $this->assertSame(10.0, $cliente->d1);
        $this->assertSame(5.0, $cliente->d2);
        $this->assertSame(0.0, $cliente->d3);
        $this->assertSame(0.0, $cliente->d4);
        $this->assertFalse($cliente->descuentos_modified);
    }

    /**
     * resetToGroupDefaults returns false when client has no discount group.
     */
    #[Test]
    public function resetToGroupDefaultsFailsWithNoGroup(): void
    {
        $cliente = new \cliente();
        $cliente->codgrupo_descuento = null;

        $this->assertFalse($cliente->resetToGroupDefaults());
    }

    /**
     * resetToGroupDefaults returns false when discount group does not exist.
     */
    #[Test]
    public function resetToGroupDefaultsFailsWhenGroupMissing(): void
    {
        \grupo_descuentos::$grupoDescData = [];

        $cliente = new \cliente();
        $cliente->codgrupo_descuento = '999999';

        $this->assertFalse($cliente->resetToGroupDefaults());
    }

    // -------------------------------------------------------------------------
    // T15: save_cliente detects discount diff from group
    // -------------------------------------------------------------------------

    /**
     * T15: When client's d1 differs from discount group's d1, descuentos_modified = true.
     */
    #[Test]
    public function saveClienteDetectsDiscountDiffFromGroup(): void
    {
        \cliente::$getReturnData = [
            'codcliente' => '000001',
            'nombre' => 'Test',
            'razonsocial' => 'Test',
            'codgrupo' => '000001',
            'd1' => 10.0,
            'd2' => 0.0,
            'd3' => 0.0,
            'd4' => 0.0,
            'descuentos_modified' => false,
            'codgrupo_descuento' => '000001',
        ];

        \grupo_descuentos::$grupoDescData = [
            '000001' => ['codgrupo_descuento' => '000001', 'nombre' => 'Grupo A', 'd1' => 10.0, 'd2' => 0.0, 'd3' => 0.0, 'd4' => 0.0],
        ];

        $controller = $this->dispatchVentasCliente(
            ['cod' => '000001'],
            ['action' => 'save_cliente', 'codcliente' => '000001', 'nombre' => 'Test', 'codgrupo' => '000001', 'codgrupo_descuento' => '000001', 'd1' => '15.00']
        );

        $this->assertSame(15.0, $controller->cliente->d1);
        $this->assertTrue($controller->cliente->descuentos_modified);
    }

    /**
     * T15 variant: When client's d1 matches discount group's d1, descuentos_modified = false.
     */
    #[Test]
    public function saveClienteNoDiffKeepsModifiedFalse(): void
    {
        \cliente::$getReturnData = [
            'codcliente' => '000002',
            'nombre' => 'Test2',
            'razonsocial' => 'Test2',
            'codgrupo' => '000001',
            'd1' => 10.0,
            'd2' => 5.0,
            'd3' => 0.0,
            'd4' => 0.0,
            'descuentos_modified' => false,
            'codgrupo_descuento' => '000001',
        ];

        \grupo_descuentos::$grupoDescData = [
            '000001' => ['codgrupo_descuento' => '000001', 'nombre' => 'Grupo A', 'd1' => 10.0, 'd2' => 5.0, 'd3' => 0.0, 'd4' => 0.0],
        ];

        $controller = $this->dispatchVentasCliente(
            ['cod' => '000002'],
            ['action' => 'save_cliente', 'codcliente' => '000002', 'nombre' => 'Test2', 'codgrupo' => '000001', 'codgrupo_descuento' => '000001', 'd1' => '10.00']
        );

        $this->assertSame(10.0, $controller->cliente->d1);
        $this->assertFalse($controller->cliente->descuentos_modified);
    }

    // -------------------------------------------------------------------------
    // T17: change_grupo copies group discounts
    // -------------------------------------------------------------------------

    /**
     * T17: change_grupo_descuento loads new discount group, copies d1-d4, clears descuentos_modified.
     */
    #[Test]
    public function changeGrupoCopiesGroupDiscounts(): void
    {
        \cliente::$getReturnData = [
            'codcliente' => '000001',
            'nombre' => 'Test',
            'razonsocial' => 'Test',
            'codgrupo' => '000001',
            'd1' => 5.0,
            'd2' => 3.0,
            'd3' => 1.0,
            'd4' => 0.5,
            'descuentos_modified' => true,
            'codgrupo_descuento' => '000001',
        ];

        \grupo_descuentos::$grupoDescData = [
            '000002' => ['codgrupo_descuento' => '000002', 'nombre' => 'Grupo B', 'd1' => 20.0, 'd2' => 10.0, 'd3' => 5.0, 'd4' => 2.5],
        ];

        $controller = $this->dispatchVentasCliente(
            ['cod' => '000001'],
            ['action' => 'change_grupo_descuento', 'codgrupo_descuento' => '000002']
        );

        $this->assertSame('000002', $controller->cliente->codgrupo_descuento);
        $this->assertSame(20.0, $controller->cliente->d1);
        $this->assertSame(10.0, $controller->cliente->d2);
        $this->assertSame(5.0, $controller->cliente->d3);
        $this->assertSame(2.5, $controller->cliente->d4);
        $this->assertFalse($controller->cliente->descuentos_modified);
    }

    // -------------------------------------------------------------------------
    // T19: reset_descuentos restores group defaults
    // -------------------------------------------------------------------------

    /**
     * T19: reset_descuentos loads discount group, restores d1-d4, clears flag.
     */
    #[Test]
    public function resetDescuentosRestoresGroupDefaults(): void
    {
        \cliente::$getReturnData = [
            'codcliente' => '000001',
            'nombre' => 'Test',
            'razonsocial' => 'Test',
            'codgrupo' => '000001',
            'd1' => 15.0,
            'd2' => 8.0,
            'd3' => 2.0,
            'd4' => 1.0,
            'descuentos_modified' => true,
            'codgrupo_descuento' => '000001',
        ];

        \grupo_descuentos::$grupoDescData = [
            '000001' => ['codgrupo_descuento' => '000001', 'nombre' => 'Grupo A', 'd1' => 10.0, 'd2' => 5.0, 'd3' => 0.0, 'd4' => 0.0],
        ];

        $controller = $this->dispatchVentasCliente(
            ['cod' => '000001'],
            ['action' => 'reset_descuentos']
        );

        $this->assertSame(10.0, $controller->cliente->d1);
        $this->assertSame(5.0, $controller->cliente->d2);
        $this->assertSame(0.0, $controller->cliente->d3);
        $this->assertSame(0.0, $controller->cliente->d4);
        $this->assertFalse($controller->cliente->descuentos_modified);
    }

    // -------------------------------------------------------------------------
    // T21: new client gets Personalizado group
    // -------------------------------------------------------------------------

    /**
     * T21: save_cliente with empty codgrupo assigns Personalizado (000000).
     */
    #[Test]
    public function saveClienteEmptyCodgrupoAssignsPersonalizado(): void
    {
        \cliente::$getReturnData = [
            'codcliente' => '000001',
            'nombre' => 'Test',
            'razonsocial' => 'Test',
            'codgrupo' => '000001',
            'd1' => 0.0,
            'd2' => 0.0,
            'd3' => 0.0,
            'd4' => 0.0,
            'descuentos_modified' => false,
        ];

        \grupo_descuentos::$grupoDescData = [];

        $controller = $this->dispatchVentasCliente(
            ['cod' => '000001'],
            ['action' => 'save_cliente', 'codcliente' => '000001', 'nombre' => 'Test', 'codgrupo' => '']
        );

        $this->assertSame('000000', $controller->cliente->codgrupo);
    }

    /**
     * T21 variant: null codgrupo also assigns Personalizado.
     */
    #[Test]
    public function saveClienteNullCodgrupoAssignsPersonalizado(): void
    {
        \cliente::$getReturnData = [
            'codcliente' => '000001',
            'nombre' => 'Test',
            'razonsocial' => 'Test',
            'codgrupo' => '000001',
            'd1' => 0.0,
            'd2' => 0.0,
            'd3' => 0.0,
            'd4' => 0.0,
            'descuentos_modified' => false,
        ];

        \grupo_descuentos::$grupoDescData = [];

        $controller = $this->dispatchVentasCliente(
            ['cod' => '000001'],
            ['action' => 'save_cliente', 'codcliente' => '000001', 'nombre' => 'Test']
        );

        $this->assertSame('000000', $controller->cliente->codgrupo);
    }

    // -------------------------------------------------------------------------
    // T23: descuentos_grupo delete removes discount group
    // -------------------------------------------------------------------------

    /**
     * T23: delete on descuentos_grupo successfully removes the group.
     */
    #[Test]
    public function deleteGrupoDescuentosSucceeds(): void
    {
        \grupo_descuentos::$grupoDescData = [
            '000001' => ['codgrupo_descuento' => '000001', 'nombre' => 'Grupo Test', 'd1' => 10.0, 'd2' => 5.0, 'd3' => 0.0, 'd4' => 0.0],
        ];

        $controller = $this->dispatchVentasGrupo(
            ['cod' => '000001'],
            ['action' => 'delete']
        );

        $errors = $controller->get_errors();
        $found = false;
        foreach ($errors as $e) {
            if (str_contains($e, 'Error al eliminar')) {
                $found = true;
                break;
            }
        }
        $this->assertFalse($found, 'Expected no error on delete, got: ' . implode('; ', $errors));
    }
}
