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

require_once 'plugins/clientes_core/extras/clientes_controller.php';

/**
 * Controlador del detalle de un grupo de clientes.
 * Plugin: clientes_core
 */
class ventas_grupo extends clientes_controller
{

    public $allow_delete;
    public $grupo;
    public $clientes;
    public $offset;
    public $total;
    public $paginas;

    public function __construct()
    {
        parent::__construct(__CLASS__, 'Grupo de clientes', 'ventas', FALSE, FALSE);
    }

    protected function private_core()
    {
        parent::private_core();

        $this->allow_delete = $this->user->allow_delete_on($this->class_name);
        $this->grupo = FALSE;
        $this->clientes = [];
        $this->offset = 0;
        $this->total = 0;
        $this->paginas = [];

        if (isset($_GET['offset'])) {
            $this->offset = intval($_GET['offset']);
        }

        $cod = filter_input(INPUT_GET, 'cod');
        if ($cod) {
            $grupo_model = new grupo_clientes();
            $this->grupo = $grupo_model->get($cod);

            if ($this->grupo) {
                $action = filter_input(INPUT_GET, 'action') ?? filter_input(INPUT_POST, 'action') ?? '';

                switch ($action) {
                    case 'save':
                        $this->save_grupo();
                        break;

                    case 'delete':
                        $this->delete_grupo();
                        return;
                }

                $this->load_clientes();
            } else {
                $this->new_error_msg('Grupo no encontrado.');
            }
        } else {
            $this->new_error_msg('No se ha proporcionado el código del grupo.');
        }
    }

    private function load_clientes()
    {
        $cliente_model = new cliente();
        $sql = "SELECT * FROM clientes WHERE codgrupo = " . $cliente_model->var2str($this->grupo->codgrupo)
            . " ORDER BY lower(nombre) ASC";
        $data = $this->db->select_limit($sql, FS_ITEM_LIMIT, $this->offset);
        $this->clientes = [];
        if ($data) {
            foreach ($data as $d) {
                $this->clientes[] = new cliente($d);
            }
        }

        $data2 = $this->db->select("SELECT COUNT(*) as total FROM clientes WHERE codgrupo = "
            . $cliente_model->var2str($this->grupo->codgrupo) . ";");
        $this->total = $data2 ? intval($data2[0]['total']) : 0;

        $this->paginas = $this->fbase_paginas($this->url(), $this->total, $this->offset);
    }

    public function url()
    {
        if ($this->grupo) {
            return $this->grupo->url();
        }

        return parent::url();
    }

    private function save_grupo()
    {
        $this->grupo->nombre = $_POST['nombre'] ?? $this->grupo->nombre;
        $this->grupo->codtarifa = !empty($_POST['codtarifa']) ? $_POST['codtarifa'] : null;

        if ($this->grupo->save()) {
            $this->new_message('Grupo guardado correctamente.');
        } else {
            $this->new_error_msg('Error al guardar el grupo.');
        }
    }

    private function delete_grupo()
    {
        if (!$this->allow_delete) {
            $this->new_error_msg('No tienes permisos para eliminar.');
            return;
        }

        if ($this->grupo->delete()) {
            $this->new_message('Grupo eliminado correctamente.');
            header('Location: index.php?page=ventas_clientes');
        } else {
            $this->new_error_msg('Error al eliminar el grupo.');
        }
    }
}
