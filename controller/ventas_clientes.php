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
 * Controlador del listado de clientes.
 * Plugin: clientes_core
 */
class ventas_clientes extends clientes_controller
{

    public $allow_delete;
    public $clientes;
    public $grupo;
    public $grupos;
    public $offset;
    public $orden;
    public $query;
    public $total;
    public $paginas;

    public function __construct()
    {
        parent::__construct(__CLASS__, 'Clientes', 'ventas', FALSE, TRUE);
    }

    protected function private_core()
    {
        parent::private_core();

        $this->allow_delete = $this->user->allow_delete_on($this->class_name);
        $this->offset = 0;
        if (isset($_GET['offset'])) {
            $this->offset = intval($_GET['offset']);
        }

        $this->orden = 'lower(nombre) ASC';
        if (isset($_GET['orden'])) {
            $ordenes = [
                'nombre' => 'lower(nombre) ASC',
                'codcliente' => 'codcliente ASC',
                'fechaalta' => 'fechaalta DESC',
                'cifnif' => 'cifnif ASC',
            ];
            if (isset($ordenes[$_GET['orden']])) {
                $this->orden = $ordenes[$_GET['orden']];
            }
        }

        $this->query = '';
        $this->grupo = FALSE;
        $this->grupos = [];

        $grupo_model = new grupo_clientes();
        $this->grupos = $grupo_model->all();

        if (isset($_POST['buscar_cliente'])) {
            $this->buscar_cliente_json();
        } else if (isset($_GET['delete_grupo'])) {
            $this->delete_grupo();
        } else if (isset($_POST['nuevo_grupo'])) {
            $this->nuevo_grupo();
        } else if (isset($_POST['codcliente'])) {
            $this->nuevo_cliente();
        } else if (isset($_GET['delete'])) {
            $this->delete_cliente();
        } else {
            $this->load_clientes();
        }
    }

    private function load_clientes()
    {
        $cliente = new cliente();

        $query = fs_filter_input_req('query', '');
        $grupo = fs_filter_input_req('grupo', '');

        if ($query !== '') {
            $this->searchClientes($cliente);
        } elseif ($grupo !== '') {
            $this->filterByGroup($cliente);
        } else {
            $this->loadAllClientes();
        }

        $this->paginas = $this->fbase_paginas($this->url(), $this->total, $this->offset);
    }

    private function searchClientes(cliente $cliente): void
    {
        $this->query = fs_filter_input_req('query', '');
        $this->clientes = $cliente->search($this->query, $this->offset);
        $this->total = count($this->clientes);
    }

    private function filterByGroup(cliente $cliente): void
    {
        $this->grupo = fs_filter_input_req('grupo', '');
        $sql = "SELECT * FROM clientes WHERE codgrupo = " . $cliente->var2str($this->grupo)
            . " ORDER BY " . $this->orden;
        $data = $this->db->select_limit($sql, FS_ITEM_LIMIT, $this->offset);
        $this->clientes = $this->hydrateClientes($data);

        $data2 = $this->db->select("SELECT COUNT(*) as total FROM clientes WHERE codgrupo = " . $cliente->var2str($this->grupo) . ";");
        $this->total = $data2 ? intval($data2[0]['total']) : 0;
    }

    private function loadAllClientes(): void
    {
        $sql = "SELECT * FROM clientes ORDER BY " . $this->orden;
        $data = $this->db->select_limit($sql, FS_ITEM_LIMIT, $this->offset);
        $this->clientes = $this->hydrateClientes($data);

        $data2 = $this->db->select("SELECT COUNT(*) as total FROM clientes;");
        $this->total = $data2 ? intval($data2[0]['total']) : 0;
    }

    private function hydrateClientes(?array $data): array
    {
        if (!$data) {
            return [];
        }

        $clientes = [];
        foreach ($data as $d) {
            $clientes[] = new cliente($d);
        }
        return $clientes;
    }

    private function buscar_cliente_json()
    {
        $this->template = FALSE;

        $cli = new cliente();
        $json = [];
        foreach ($cli->search($_POST['buscar_cliente']) as $c) {
            $nombre = $c->nombre;
            if ($c->nombre != $c->razonsocial) {
                $nombre .= ' (' . $c->razonsocial . ')';
            }
            $json[] = ['value' => $c->nombre, 'data' => $c->codcliente, 'full' => $c];
        }

        header('Content-Type: application/json');
        echo json_encode(['query' => $_POST['buscar_cliente'], 'suggestions' => $json]);
    }

    private function nuevo_cliente()
    {
        $cliente = new cliente();
        $cliente->codcliente = $_POST['codcliente'] ?? null;
        $cliente->nombre = $_POST['nombre'] ?? '';
        $cliente->razonsocial = $_POST['razonsocial'] ?? $_POST['nombre'] ?? '';
        $cliente->cifnif = $_POST['cifnif'] ?? '';
        $cliente->telefono1 = $_POST['telefono1'] ?? '';
        $cliente->email = $_POST['email'] ?? '';
        $cliente->codgrupo = !empty($_POST['codgrupo']) ? $_POST['codgrupo'] : null;

        if ($cliente->save()) {
            header('Location: ' . $cliente->url());
        } else {
            $this->new_error_msg('Error al guardar el cliente.');
            $this->load_clientes();
        }
    }

    private function delete_cliente()
    {
        if (!$this->allow_delete) {
            $this->new_error_msg('No tienes permisos para eliminar.');
            $this->load_clientes();
            return;
        }

        $cliente = new cliente();
        $cli = $cliente->get($_GET['delete']);
        if ($cli) {
            if ($cli->delete()) {
                $this->new_message('Cliente eliminado correctamente.');
            } else {
                $this->new_error_msg('Error al eliminar el cliente.');
            }
        } else {
            $this->new_error_msg('Cliente no encontrado.');
        }

        $this->load_clientes();
    }

    private function nuevo_grupo()
    {
        $grupo = new grupo_clientes();
        $grupo->codgrupo = $grupo->get_new_codigo();
        $grupo->nombre = $_POST['nuevo_grupo'] ?? '';

        if ($grupo->save()) {
            $this->new_message('Grupo guardado correctamente.');
        } else {
            $this->new_error_msg('Error al guardar el grupo.');
        }

        $this->grupos = $grupo->all();
        $this->load_clientes();
    }

    private function delete_grupo()
    {
        if (!$this->allow_delete) {
            $this->new_error_msg('No tienes permisos para eliminar.');
            $this->load_clientes();
            return;
        }

        $grupo = new grupo_clientes();
        $g = $grupo->get($_GET['delete_grupo']);
        if ($g) {
            if ($g->delete()) {
                $this->new_message('Grupo eliminado correctamente.');
            } else {
                $this->new_error_msg('Error al eliminar el grupo.');
            }
        }

        $this->grupos = $grupo->all();
        $this->load_clientes();
    }
}
