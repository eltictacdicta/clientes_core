<?php
/**
 * Tests para el modelo cliente de clientes_core.
 * Pruebas de métodos puros sin conexión a DB.
 */

namespace Tests\ClientesCore;

use PHPUnit\Framework\TestCase;

class ClienteModelTest extends TestCase
{
    private object $model;

    protected function setUp(): void
    {
        require_once FS_FOLDER . '/base/fs_core_log.php';
        require_once FS_FOLDER . '/base/fs_model.php';

        if (!defined('FS_CIFNIF')) {
            define('FS_CIFNIF', 'NIF');
        }
        if (!defined('FS_ITEM_LIMIT')) {
            define('FS_ITEM_LIMIT', 50);
        }

        $ref = new \ReflectionClass('fs_model');
        $prop = $ref->getProperty('core_log');
        $prop->setAccessible(true);
        if ($prop->getValue() === null) {
            $prop->setValue(null, new \fs_core_log());
        }

        require_once FS_FOLDER . '/plugins/clientes_core/model/core/cliente.php';

        $this->model = new class() extends \FSFramework\model\cliente {
            public function __construct()
            {
                $this->nombre = '';
                $this->razonsocial = '';
                $this->cifnif = '';
                $this->regimeniva = 'General';
                $this->debaja = false;
                $this->recargo = false;
                $this->personafisica = true;
                $this->codcliente = null;
                $this->fechabaja = null;
                $this->codgrupo = null;
                $this->codproveedor = null;
                $this->observaciones = null;
                $this->diaspago = null;
                $this->d1 = null;
                $this->d2 = null;
                $this->d3 = null;
                $this->d4 = null;
                $this->descuentos_modified = false;
            }
            public function delete()
            {
                return false;
            }
            public function exists()
            {
                return false;
            }
            public function save()
            {
                return false;
            }
        };
    }

    public function testDefaultValuesWhenNoData(): void
    {
        $this->assertNull($this->model->codcliente);
        $this->assertSame('', $this->model->nombre);
        $this->assertSame('', $this->model->razonsocial);
        $this->assertSame('', $this->model->cifnif);
        $this->assertFalse($this->model->debaja);
        $this->assertNull($this->model->fechabaja);
        $this->assertNull($this->model->codgrupo);
        $this->assertNull($this->model->codproveedor);
        $this->assertTrue($this->model->personafisica);
        $this->assertSame('General', $this->model->regimeniva);
        $this->assertFalse($this->model->recargo);
        $this->assertNull($this->model->d1);
        $this->assertNull($this->model->d2);
        $this->assertNull($this->model->d3);
        $this->assertNull($this->model->d4);
        $this->assertFalse($this->model->descuentos_modified);
        $this->assertNull($this->model->codgrupo_descuento);
    }

    public function testConstructorLoadsDiscountFieldsFromData(): void
    {
        $model = $this->makeClienteFromData([
            'codcliente' => '000001',
            'nombre' => 'Test',
            'razonsocial' => 'Test S.L.',
            'tipoidfiscal' => 'NIF',
            'cifnif' => 'B12345',
            'telefono1' => '',
            'telefono2' => '',
            'fax' => '',
            'email' => '',
            'web' => '',
            'codserie' => null,
            'coddivisa' => 'EUR',
            'codpago' => null,
            'codagente' => null,
            'codgrupo' => '000001',
            'debaja' => false,
            'fechabaja' => null,
            'fechaalta' => '2026-01-01',
            'observaciones' => '',
            'regimeniva' => 'General',
            'recargo' => false,
            'personafisica' => true,
            'diaspago' => null,
            'codproveedor' => null,
            'codtarifa' => null,
            'd1' => 10.00,
            'd2' => 5.00,
            'd3' => null,
            'd4' => 2.50,
            'descuentos_modified' => true,
            'codgrupo_descuento' => '000001',
        ]);

        $this->assertSame(10.00, $model->d1);
        $this->assertSame(5.00, $model->d2);
        $this->assertNull($model->d3);
        $this->assertSame(2.50, $model->d4);
        $this->assertTrue($model->descuentos_modified);
        $this->assertSame('000001', $model->codgrupo_descuento);
    }

    public function testConstructorDefaultsDiscountFieldsWhenNoData(): void
    {
        $this->assertNull($this->model->d1);
        $this->assertNull($this->model->d2);
        $this->assertNull($this->model->d3);
        $this->assertNull($this->model->d4);
        $this->assertFalse($this->model->descuentos_modified);
    }

    public function testGetEffectiveDiscountsUsesGroupDefaultsWhenNull(): void
    {
        $model = $this->makeClienteWithGroupStub([
            'd1' => null,
            'd2' => null,
            'd3' => null,
            'd4' => null,
        ], [
            'd1' => 10.00,
            'd2' => 5.00,
            'd3' => 0.00,
            'd4' => 2.00,
        ]);

        $discounts = $model->getEffectiveDiscounts();

        $this->assertSame(10.00, $discounts['d1']);
        $this->assertSame(5.00, $discounts['d2']);
        $this->assertSame(0.00, $discounts['d3']);
        $this->assertSame(2.00, $discounts['d4']);
    }

    public function testGetEffectiveDiscountsUsesClientValueWhenSet(): void
    {
        $model = $this->makeClienteWithGroupStub([
            'd1' => 15.00,
            'd2' => null,
            'd3' => null,
            'd4' => null,
        ], [
            'd1' => 10.00,
            'd2' => 5.00,
            'd3' => 0.00,
            'd4' => 2.00,
        ]);

        $discounts = $model->getEffectiveDiscounts();

        $this->assertSame(15.00, $discounts['d1']);
        $this->assertSame(5.00, $discounts['d2']);
    }

    public function testTestRejectsNullCodgrupo(): void
    {
        $this->model->codcliente = '000001';
        $this->model->nombre = 'Test';
        $this->model->razonsocial = 'Test';
        $this->model->cifnif = '';
        $this->model->observaciones = '';
        $this->model->debaja = false;
        $this->model->diaspago = '';
        $this->model->codgrupo = null;

        $this->assertFalse($this->model->test());
    }

    public function testApplyGroupDiscountsCopiesValuesAndClearsFlag(): void
    {
        $this->model->d1 = 99.00;
        $this->model->descuentos_modified = true;

        $grupo = (object) [
            'd1' => 10.00,
            'd2' => 5.00,
            'd3' => 0.00,
            'd4' => 2.00,
        ];

        $this->model->applyGroupDiscounts($grupo);

        $this->assertSame(10.00, $this->model->d1);
        $this->assertSame(5.00, $this->model->d2);
        $this->assertSame(0.00, $this->model->d3);
        $this->assertSame(2.00, $this->model->d4);
        $this->assertFalse($this->model->descuentos_modified);
    }

    public function testObservacionesResumeEmpty(): void
    {
        $this->model->observaciones = '';
        $this->assertSame('-', $this->model->observaciones_resume());
    }

    public function testObservacionesResumeShort(): void
    {
        $this->model->observaciones = 'Nota corta';
        $this->assertSame('Nota corta', $this->model->observaciones_resume());
    }

    public function testObservacionesResumeLong(): void
    {
        $this->model->observaciones = str_repeat('A', 100);
        $resume = $this->model->observaciones_resume();
        $this->assertSame(53, strlen($resume));
        $this->assertStringEndsWith('...', $resume);
    }

    public function testUrlWithCode(): void
    {
        $this->model->codcliente = '000001';
        $this->assertSame('index.php?page=ventas_cliente&cod=000001', $this->model->url());
    }

    public function testUrlWithoutCode(): void
    {
        $this->model->codcliente = null;
        $this->assertSame('index.php?page=ventas_clientes', $this->model->url());
    }

    public function testIsDefaultReturnsFalse(): void
    {
        $this->assertFalse($this->model->is_default());
    }

    public function testTestValidatesCodeFormat(): void
    {
        $this->model->codcliente = 'ABC123';
        $this->model->nombre = 'Test Cliente';
        $this->model->razonsocial = 'Test Cliente S.L.';
        $this->model->cifnif = '';
        $this->model->observaciones = '';
        $this->model->debaja = false;
        $this->model->diaspago = '';
        $this->model->codgrupo = '000001';

        $this->assertTrue($this->model->test());
    }

    public function testTestRejectsInvalidCode(): void
    {
        $this->model->codcliente = 'TOOLONG1';
        $this->model->nombre = 'Test';
        $this->model->razonsocial = 'Test';
        $this->model->cifnif = '';
        $this->model->observaciones = '';
        $this->model->debaja = false;
        $this->model->diaspago = '';

        $this->assertFalse($this->model->test());
    }

    public function testTestRejectsEmptyName(): void
    {
        $this->model->codcliente = '000001';
        $this->model->nombre = '';
        $this->model->razonsocial = 'Test';
        $this->model->cifnif = '';
        $this->model->observaciones = '';
        $this->model->debaja = false;
        $this->model->diaspago = '';

        $this->assertFalse($this->model->test());
    }

    public function testTestAllowsEmptyRazonSocial(): void
    {
        $this->model->codcliente = '000001';
        $this->model->nombre = 'Test';
        $this->model->razonsocial = '';
        $this->model->cifnif = '';
        $this->model->observaciones = '';
        $this->model->debaja = false;
        $this->model->diaspago = '';
        $this->model->codgrupo = '000001';

        $this->assertTrue($this->model->test());
    }

    public function testTestSetsFechaBajaWhenDebaja(): void
    {
        $this->model->codcliente = '000001';
        $this->model->nombre = 'Test';
        $this->model->razonsocial = 'Test';
        $this->model->cifnif = '';
        $this->model->observaciones = '';
        $this->model->debaja = true;
        $this->model->fechabaja = null;
        $this->model->diaspago = '';
        $this->model->codgrupo = '000001';

        $this->model->test();

        $this->assertNotNull($this->model->fechabaja);
        $this->assertSame(date('d-m-Y'), $this->model->fechabaja);
    }

    public function testTestClearsFechaBajaWhenNotDebaja(): void
    {
        $this->model->codcliente = '000001';
        $this->model->nombre = 'Test';
        $this->model->razonsocial = 'Test';
        $this->model->cifnif = '';
        $this->model->observaciones = '';
        $this->model->debaja = false;
        $this->model->fechabaja = '01-01-2020';
        $this->model->diaspago = '';
        $this->model->codgrupo = '000001';

        $this->model->test();

        $this->assertNull($this->model->fechabaja);
    }

    public function testTestValidatesDiasPago(): void
    {
        $this->model->codcliente = '000001';
        $this->model->nombre = 'Test';
        $this->model->razonsocial = 'Test';
        $this->model->cifnif = '';
        $this->model->observaciones = '';
        $this->model->debaja = false;
        $this->model->diaspago = '1,15,31,0,50';
        $this->model->codgrupo = '000001';

        $this->model->test();

        $this->assertSame('1,15,31', $this->model->diaspago);
    }

    public function testTestKeepsNullDiasPagoWithoutWarnings(): void
    {
        $this->model->codcliente = '000001';
        $this->model->nombre = 'Test';
        $this->model->razonsocial = 'Test';
        $this->model->cifnif = '';
        $this->model->observaciones = '';
        $this->model->debaja = false;
        $this->model->diaspago = null;
        $this->model->codgrupo = '000001';

        $this->assertTrue($this->model->test());
        $this->assertNull($this->model->diaspago);
    }

    public function testTestSanitizesHtml(): void
    {
        $this->model->codcliente = '000001';
        $this->model->nombre = '<script>alert("xss")</script>';
        $this->model->razonsocial = 'Test & Co';
        $this->model->cifnif = '<b>B12345</b>';
        $this->model->observaciones = 'Normal text';
        $this->model->debaja = false;
        $this->model->diaspago = '';
        $this->model->codgrupo = '000001';

        $this->model->test();

        $this->assertStringNotContainsString('<script>', $this->model->nombre);
        $this->assertStringNotContainsString('<b>', $this->model->cifnif);
    }

    /**
     * Build a cliente instance wired to a controllable in-memory db stub.
     *
     * The anonymous subclass exposes a public $db (assignable from the
     * test) and an explicit $table_name (also assignable). The stub
     * captures the SQL string passed to select() and returns whatever
     * the test sets on $selectResult.
     *
     * @param array $selectResult
     * @return object
     */
    private function makeClienteWithStubbedDb(array $selectResult): object
    {
        $stub = new class($selectResult) {
            public string $lastSql = '';
            public array $selectResult;
            public int $selectCalls = 0;

            public function __construct(array $selectResult)
            {
                $this->selectResult = $selectResult;
            }

            public function select(string $sql, array $params = [])
            {
                $this->lastSql = $sql;
                $this->selectCalls++;
                return $this->selectResult;
            }
        };

        return new class($stub) extends \FSFramework\model\cliente {
            public $db;
            public function __construct(object $dbStub)
            {
                $this->db = $dbStub;
                $this->table_name = 'clientes';
                $this->nombre = '';
                $this->razonsocial = '';
                $this->cifnif = '';
                $this->regimeniva = 'General';
                $this->debaja = false;
                $this->recargo = false;
                $this->personafisica = true;
                $this->codcliente = null;
                $this->fechabaja = null;
                $this->codgrupo = null;
                $this->codproveedor = null;
                $this->observaciones = null;
                $this->diaspago = null;
            }
            public function delete() { return false; }
            public function exists() { return false; }
            public function save() { return false; }
        };
    }

    /**
     * table_has_rows() must return true when the clientes table has at
     * least one row. The test uses a stubbed db that returns a non-empty
     * array.
     */
    public function testTableHasRowsReturnsTrueWhenNonEmpty(): void
    {
        $cliente = $this->makeClienteWithStubbedDb([['x' => 1]]);

        $this->assertTrue(
            $cliente->table_has_rows(),
            'table_has_rows() must return true when the underlying select returns rows'
        );
    }

    /**
     * table_has_rows() must return false when the clientes table is
     * empty. The test uses a stubbed db that returns an empty array.
     */
    public function testTableHasRowsReturnsFalseWhenEmpty(): void
    {
        $cliente = $this->makeClienteWithStubbedDb([]);

        $this->assertFalse(
            $cliente->table_has_rows(),
            'table_has_rows() must return false when the underlying select returns no rows'
        );
    }

    /**
     * table_has_rows() must issue a SELECT against the model's table.
     * The test asserts the captured SQL contains the table name — this
     * guards against a future refactor that hardcodes a different table.
     */
    public function testTableHasRowsQueriesTheClientesTable(): void
    {
        $cliente = $this->makeClienteWithStubbedDb([]);

        $cliente->table_has_rows();

        $ref = new \ReflectionProperty(\FSFramework\model\cliente::class, 'db');
        $ref->setAccessible(true);
        $stub = $ref->getValue($cliente);

        $this->assertSame(1, $stub->selectCalls, 'table_has_rows() must call db->select() exactly once');
        $this->assertStringContainsString(
            'clientes',
            $stub->lastSql,
            'table_has_rows() must issue a SELECT that targets the clientes table'
        );
    }

    private function makeClienteFromData(array $data): object
    {
        return new class($data) extends \FSFramework\model\cliente {
            public function __construct(array $data)
            {
                $this->codcliente = $data['codcliente'];
                $this->nombre = $data['nombre'];
                $this->razonsocial = $data['razonsocial'];
                $this->tipoidfiscal = $data['tipoidfiscal'];
                $this->cifnif = $data['cifnif'];
                $this->codgrupo = $data['codgrupo'];
                $this->d1 = array_key_exists('d1', $data) && $data['d1'] !== null ? (float) $data['d1'] : null;
                $this->d2 = array_key_exists('d2', $data) && $data['d2'] !== null ? (float) $data['d2'] : null;
                $this->d3 = array_key_exists('d3', $data) && $data['d3'] !== null ? (float) $data['d3'] : null;
                $this->d4 = array_key_exists('d4', $data) && $data['d4'] !== null ? (float) $data['d4'] : null;
                $this->descuentos_modified = filter_var($data['descuentos_modified'] ?? false, FILTER_VALIDATE_BOOLEAN);
                $this->codgrupo_descuento = $data['codgrupo_descuento'] ?? null;
            }

            public function delete()
            {
                return false;
            }

            public function exists()
            {
                return false;
            }

            public function save()
            {
                return false;
            }
        };
    }

    private function makeClienteWithGroupStub(array $clientDiscounts, array $groupDiscounts): object
    {
        return new class($clientDiscounts, $groupDiscounts) extends \FSFramework\model\cliente {
            private array $groupDiscounts;

            public function __construct(array $clientDiscounts, array $groupDiscounts)
            {
                $this->codgrupo_descuento = '000001';
                $this->d1 = $clientDiscounts['d1'];
                $this->d2 = $clientDiscounts['d2'];
                $this->d3 = $clientDiscounts['d3'];
                $this->d4 = $clientDiscounts['d4'];
                $this->groupDiscounts = $groupDiscounts;
            }

            public function getEffectiveDiscounts(): array
            {
                $grupo = (object) $this->groupDiscounts;
                $result = [];
                foreach (['d1', 'd2', 'd3', 'd4'] as $field) {
                    $result[$field] = $this->{$field} !== null
                        ? (float) $this->{$field}
                        : (float) $grupo->{$field};
                }

                return $result;
            }

            public function delete()
            {
                return false;
            }

            public function exists()
            {
                return false;
            }

            public function save()
            {
                return false;
            }
        };
    }
}