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

require_once dirname(__DIR__) . '/extras/clientes_controller.php';
require_once dirname(__DIR__) . '/model/core/grupo_descuentos.php';

use FSFramework\model\grupo_descuentos;

/**
 * Controlador para la gestión de grupos de descuentos (D1-D4).
 * Entidad separada de grupos de clientes.
 * Plugin: clientes_core
 */
class descuentos_grupo extends clientes_controller
{

    public $allow_delete;
    public $grupo_descuentos;
    public $grupos_descuentos;
    public $offset;

    public function __construct()
    {
        parent::__construct(__CLASS__, 'Grupos de Descuentos', 'ventas', FALSE, TRUE);
    }

    protected function private_core()
    {
        parent::private_core();

        $this->allow_delete = $this->user->allow_delete_on($this->class_name);
        $this->offset = 0;
        $this->grupo_descuentos = FALSE;
        $this->grupos_descuentos = [];

        $grupoDescModel = new grupo_descuentos();
        $this->grupos_descuentos = $grupoDescModel->all();

        $result = $this->dispatch();

        if ($result['redirect_url'] !== null) {
            header('Location: ' . $result['redirect_url']);
            exit();
        }
    }

    public function dispatch(): array
    {
        $result = [
            'action' => null,
            'redirect_url' => null,
            'errors' => [],
        ];

        $action = filter_input(INPUT_GET, 'action') ?? filter_input(INPUT_POST, 'action') ?? '';

        switch ($action) {
            case 'save':
                $this->save_grupo();
                break;

            case 'nuevo_grupo':
                $this->nuevo_grupo();
                break;

            case 'delete':
                $this->delete_grupo();
                break;
        }

        $result['errors'] = $this->get_errors();

        return $result;
    }

    private function save_grupo()
    {
        if (!$this->requireMutationCsrf()) {
            return;
        }

        $cod = filter_input(INPUT_GET, 'cod') ?? filter_input(INPUT_POST, 'codgrupo_descuento');
        if (!$cod) {
            $this->new_error_msg('Código de grupo no proporcionado.');
            return;
        }

        $grupoDescModel = new grupo_descuentos();
        $this->grupo_descuentos = $grupoDescModel->get($cod);

        if (!$this->grupo_descuentos) {
            $this->new_error_msg('Grupo de descuentos no encontrado.');
            return;
        }

        $this->grupo_descuentos->nombre = filter_input(INPUT_POST, 'nombre') ?? $this->grupo_descuentos->nombre;
        $this->grupo_descuentos->d1 = filter_input(INPUT_POST, 'd1') !== null ? (float) filter_input(INPUT_POST, 'd1') : $this->grupo_descuentos->d1;
        $this->grupo_descuentos->d2 = filter_input(INPUT_POST, 'd2') !== null ? (float) filter_input(INPUT_POST, 'd2') : $this->grupo_descuentos->d2;
        $this->grupo_descuentos->d3 = filter_input(INPUT_POST, 'd3') !== null ? (float) filter_input(INPUT_POST, 'd3') : $this->grupo_descuentos->d3;
        $this->grupo_descuentos->d4 = filter_input(INPUT_POST, 'd4') !== null ? (float) filter_input(INPUT_POST, 'd4') : $this->grupo_descuentos->d4;

        if ($this->grupo_descuentos->save()) {
            $this->new_message('Grupo de descuentos guardado correctamente.');
        } else {
            $this->new_error_msg('Error al guardar el grupo de descuentos.');
        }

        $this->grupos_descuentos = $grupoDescModel->all();
    }

    private function nuevo_grupo()
    {
        if (!$this->requireMutationCsrf()) {
            return;
        }

        $grupoDescModel = new grupo_descuentos();
        $grupo = new grupo_descuentos();
        $grupo->codgrupo_descuento = $grupoDescModel->get_new_codigo();
        $grupo->nombre = filter_input(INPUT_POST, 'nombre_grupo') ?? '';
        $grupo->d1 = (float) (filter_input(INPUT_POST, 'd1') ?? 0);
        $grupo->d2 = (float) (filter_input(INPUT_POST, 'd2') ?? 0);
        $grupo->d3 = (float) (filter_input(INPUT_POST, 'd3') ?? 0);
        $grupo->d4 = (float) (filter_input(INPUT_POST, 'd4') ?? 0);

        if ($grupo->save()) {
            $this->new_message('Grupo de descuentos creado correctamente.');
        } else {
            $this->new_error_msg('Error al crear el grupo de descuentos.');
        }

        $this->grupos_descuentos = $grupoDescModel->all();
    }

    private function delete_grupo()
    {
        if (!$this->requireMutationCsrf()) {
            return;
        }

        if (!$this->allow_delete) {
            $this->new_error_msg('No tienes permisos para eliminar.');
            return;
        }

        $cod = filter_input(INPUT_POST, 'codgrupo_descuento') ?? '';
        if ($cod === '') {
            $this->new_error_msg('Código de grupo no proporcionado.');
            return;
        }

        $grupoDescModel = new grupo_descuentos();
        $grupo = $grupoDescModel->get($cod);
        if ($grupo) {
            if ($grupo->delete()) {
                $this->new_message('Grupo de descuentos eliminado correctamente.');
            } else {
                $this->new_error_msg('Error al eliminar el grupo de descuentos.');
            }
        } else {
            $this->new_error_msg('Grupo de descuentos no encontrado.');
        }

        $this->grupos_descuentos = $grupoDescModel->all();
    }
}
