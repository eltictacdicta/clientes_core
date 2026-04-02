<?php
/**
 * Tests para el modelo grupo_clientes de clientes_core.
 * Pruebas de métodos puros sin conexión a DB.
 */

namespace Tests\ClientesCore;

use PHPUnit\Framework\TestCase;

class GrupoClientesModelTest extends TestCase
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

        require_once FS_FOLDER . '/plugins/clientes_core/model/core/grupo_clientes.php';

        $this->model = new class() extends \FSFramework\model\grupo_clientes {
            public function __construct()
            {
                $this->codgrupo = null;
                $this->nombre = null;
                $this->codtarifa = null;
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
        $this->assertNull($this->model->codgrupo);
        $this->assertNull($this->model->nombre);
        $this->assertNull($this->model->codtarifa);
    }

    public function testUrlWithCode(): void
    {
        $this->model->codgrupo = '000001';
        $this->assertSame('index.php?page=ventas_grupo&cod=000001', $this->model->url());
    }

    public function testUrlWithoutCode(): void
    {
        $this->model->codgrupo = null;
        $this->assertSame('index.php?page=ventas_clientes#grupos', $this->model->url());
    }

    public function testTestValidName(): void
    {
        $this->model->nombre = 'Grupo Premium';
        $this->assertTrue($this->model->test());
    }

    public function testTestRejectsEmptyName(): void
    {
        $this->model->nombre = '';
        $this->assertFalse($this->model->test());
    }

    public function testTestRejectsTooLongName(): void
    {
        $this->model->nombre = str_repeat('A', 101);
        $this->assertFalse($this->model->test());
    }

    public function testTestSanitizesHtml(): void
    {
        $this->model->nombre = '<b>Grupo</b> Especial';
        $this->model->test();
        $this->assertStringNotContainsString('<b>', $this->model->nombre);
    }
}