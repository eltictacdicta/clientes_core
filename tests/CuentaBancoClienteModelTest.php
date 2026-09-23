<?php
/**
 * Tests para el modelo cuenta_banco_cliente de clientes_core.
 */

namespace Tests\ClientesCore;

use PHPUnit\Framework\TestCase;

class CuentaBancoClienteModelTest extends TestCase
{
    private object $model;

    protected function setUp(): void
    {
        require_once FS_FOLDER . '/base/fs_core_log.php';
        require_once FS_FOLDER . '/base/fs_model.php';

        $ref = new \ReflectionClass('fs_model');
        $prop = $ref->getProperty('core_log');
        $prop->setAccessible(true);
        if ($prop->getValue() === null) {
            $prop->setValue(null, new \fs_core_log());
        }

        require_once FS_FOLDER . '/plugins/clientes_core/model/cuenta_banco_cliente.php';

        $this->model = new class() extends \cuenta_banco_cliente {
            public function __construct()
            {
                $this->codcliente = null;
                $this->codcuenta = null;
                $this->descripcion = null;
                $this->iban = null;
                $this->swift = null;
                $this->principal = true;
                $this->fmandato = null;
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

    public function testClassExists(): void
    {
        $this->assertTrue(class_exists('cuenta_banco_cliente'));
    }

    public function testSchemaLivesInClientesCore(): void
    {
        $this->assertFileExists(FS_FOLDER . '/plugins/clientes_core/model/table/cuentasbcocli.xml');
        $this->assertFileDoesNotExist(FS_FOLDER . '/plugins/business_data/model/table/cuentasbcocli.xml');
    }

    public function testBusinessDataDoesNotDuplicateClientBankAccountModel(): void
    {
        // cuenta_banco_cliente is owned by clientes_core. business_data must not
        // ship a copy of the model or its schema (would fork the definition).
        $this->assertFileExists(FS_FOLDER . '/plugins/clientes_core/model/cuenta_banco_cliente.php');
        $this->assertFileDoesNotExist(FS_FOLDER . '/plugins/business_data/model/cuenta_banco_cliente.php');
    }

    public function testDefaultValues(): void
    {
        $this->assertNull($this->model->codcliente);
        $this->assertNull($this->model->codcuenta);
        $this->assertTrue($this->model->principal);
    }

    public function testUrlPointsToClienteBankAccountsTab(): void
    {
        $this->model->codcliente = '000001';
        $this->assertSame('index.php?page=ventas_cliente&cod=000001#cuentasb', $this->model->url());
    }

    public function testIbanCanBeFormattedWithSpaces(): void
    {
        $this->model->iban = 'ES7621000000000000000000';
        $this->assertSame('ES7621000000000000000000', $this->model->iban(false));
        $this->assertSame('ES76 2100 0000 0000 0000 0000 ', $this->model->iban(true));
    }
}
