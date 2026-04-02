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

        $this->model->test();

        $this->assertStringNotContainsString('<script>', $this->model->nombre);
        $this->assertStringNotContainsString('<b>', $this->model->cifnif);
    }
}