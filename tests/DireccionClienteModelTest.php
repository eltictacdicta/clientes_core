<?php
/**
 * Tests para el modelo direccion_cliente de clientes_core.
 * Pruebas de métodos puros sin conexión a DB.
 */

namespace Tests\ClientesCore;

use PHPUnit\Framework\TestCase;

class DireccionClienteModelTest extends TestCase
{
    private object $model;

    protected function setUp(): void
    {
        require_once FS_FOLDER . '/base/fs_core_log.php';
        require_once FS_FOLDER . '/base/fs_model.php';

        if (!defined('FS_ITEM_LIMIT')) {
            define('FS_ITEM_LIMIT', 50);
        }

        $ref = new \ReflectionClass('fs_model');
        $prop = $ref->getProperty('core_log');
        $prop->setAccessible(true);
        if ($prop->getValue() === null) {
            $prop->setValue(null, new \fs_core_log());
        }

        require_once FS_FOLDER . '/plugins/clientes_core/model/core/direccion_cliente.php';

        $this->model = new class() extends \FSFramework\model\direccion_cliente {
            public function __construct()
            {
                $this->id = null;
                $this->codcliente = null;
                $this->codpais = null;
                $this->direccion = null;
                $this->apartado = null;
                $this->provincia = null;
                $this->ciudad = null;
                $this->codpostal = null;
                $this->domenvio = true;
                $this->domfacturacion = true;
                $this->descripcion = 'Principal';
                $this->fecha = date('d-m-Y');
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

    public function testDefaultValues(): void
    {
        $this->assertNull($this->model->id);
        $this->assertNull($this->model->codcliente);
        $this->assertNull($this->model->codpais);
        $this->assertNull($this->model->direccion);
        $this->assertTrue($this->model->domenvio);
        $this->assertTrue($this->model->domfacturacion);
        $this->assertSame('Principal', $this->model->descripcion);
        $this->assertSame(date('d-m-Y'), $this->model->fecha);
    }

    public function testTestRequiresCodcliente(): void
    {
        $this->model->codcliente = null;
        $this->model->direccion = 'Calle Test 1';

        $this->assertFalse($this->model->test());
    }

    public function testTestPassesWithCodcliente(): void
    {
        $this->model->codcliente = '000001';
        $this->model->direccion = 'Calle Test 1';
        $this->model->ciudad = 'Madrid';
        $this->model->provincia = 'Madrid';
        $this->model->apartado = '';
        $this->model->codpostal = '28001';
        $this->model->descripcion = 'Principal';

        $this->assertTrue($this->model->test());
    }

    public function testTestSanitizesHtml(): void
    {
        $this->model->codcliente = '000001';
        $this->model->direccion = '<b>Calle</b> Peligrosa';
        $this->model->ciudad = '<script>alert("x")</script>';
        $this->model->provincia = 'Normal';
        $this->model->apartado = '';
        $this->model->codpostal = '28001';
        $this->model->descripcion = 'Test';

        $this->model->test();

        $this->assertStringNotContainsString('<b>', $this->model->direccion);
        $this->assertStringNotContainsString('<script>', $this->model->ciudad);
    }
}