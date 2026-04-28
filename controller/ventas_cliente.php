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
require_once dirname(__DIR__, 3) . '/src/Security/CsrfManager.php';

use FSFramework\Security\CsrfManager;

/**
 * Controlador del detalle de un cliente.
 * Plugin: clientes_core
 */
class ventas_cliente extends clientes_controller
{

    public $allow_delete;
    public $cliente;
    public $direcciones;
    public $grupos;
    public $regimenes_iva;

    public function __construct()
    {
        parent::__construct(__CLASS__, 'Cliente', 'ventas', FALSE, FALSE);
    }

    protected function private_core()
    {
        parent::private_core();

        $this->allow_delete = $this->user->allow_delete_on($this->class_name);
        $this->cliente = FALSE;
        $this->direcciones = [];
        $this->grupos = [];
        $this->regimenes_iva = [];

        $cod = filter_input(INPUT_GET, 'cod');
        if (!$cod) {
            $cod = filter_input(INPUT_POST, 'codcliente');
        }

        if ($cod) {
            $cliente_model = new cliente();
            $this->cliente = $cliente_model->get($cod);

            if ($this->cliente) {
                $this->regimenes_iva = $this->cliente->regimenes_iva();

                $grupo_model = new grupo_clientes();
                $this->grupos = $grupo_model->all();

                $action = filter_input(INPUT_GET, 'action') ?? filter_input(INPUT_POST, 'action') ?? '';

                switch ($action) {
                    case 'save_cliente':
                        $this->save_cliente();
                        break;

                    case 'delete':
                        if (CsrfManager::isValid(filter_input(INPUT_POST, '_csrf_token') ?? '')) {
                            $this->delete_cliente();
                        } else {
                            $this->new_error_msg('Token de seguridad no válido.');
                        }
                        return;

                    case 'save_dir':
                        $this->save_direccion();
                        break;

                    case 'delete_dir':
                        if (CsrfManager::isValid(filter_input(INPUT_POST, '_csrf_token') ?? '')) {
                            $this->delete_direccion();
                        } else {
                            $this->new_error_msg('Token de seguridad no válido.');
                        }
                        break;

                    case 'new_dir':
                        break;

                    default:
                        break;
                }

                $this->direcciones = $this->cliente->get_direcciones();
            } else {
                $this->new_error_msg('Cliente no encontrado.');
            }
        } else {
            $this->new_error_msg('No se ha proporcionado el código del cliente.');
        }
    }

    private function save_cliente()
    {
        $this->cliente->nombre = filter_input(INPUT_POST, 'nombre') ?? $this->cliente->nombre;
        $this->cliente->razonsocial = filter_input(INPUT_POST, 'razonsocial') ?? $this->cliente->razonsocial;
        $this->cliente->tipoidfiscal = filter_input(INPUT_POST, 'tipoidfiscal') ?? $this->cliente->tipoidfiscal;
        $this->cliente->cifnif = filter_input(INPUT_POST, 'cifnif') ?? $this->cliente->cifnif;
        $this->cliente->telefono1 = filter_input(INPUT_POST, 'telefono1') ?? $this->cliente->telefono1;
        $this->cliente->telefono2 = filter_input(INPUT_POST, 'telefono2') ?? $this->cliente->telefono2;
        $this->cliente->fax = filter_input(INPUT_POST, 'fax') ?? $this->cliente->fax;
        $this->cliente->email = filter_input(INPUT_POST, 'email') ?? $this->cliente->email;
        $this->cliente->web = filter_input(INPUT_POST, 'web') ?? $this->cliente->web;
        $this->cliente->coddivisa = !empty(filter_input(INPUT_POST, 'coddivisa')) ? filter_input(INPUT_POST, 'coddivisa') : null;
        $this->cliente->codgrupo = !empty(filter_input(INPUT_POST, 'codgrupo')) ? filter_input(INPUT_POST, 'codgrupo') : null;
        $this->cliente->regimeniva = filter_input(INPUT_POST, 'regimeniva') ?? $this->cliente->regimeniva;
        $this->cliente->recargo = filter_input(INPUT_POST, 'recargo') === '1';
        $this->cliente->personafisica = filter_input(INPUT_POST, 'personafisica') === '1';
        $this->cliente->diaspago = filter_input(INPUT_POST, 'diaspago') ?? $this->cliente->diaspago;
        $this->cliente->observaciones = filter_input(INPUT_POST, 'observaciones') ?? $this->cliente->observaciones;

        $debaja = filter_input(INPUT_POST, 'debaja');
        if ($debaja !== null) {
            $this->cliente->debaja = $debaja === '1';
        }

        if ($this->cliente->save()) {
            $this->new_message('Cliente guardado correctamente.');
        } else {
            $this->new_error_msg('Error al guardar el cliente.');
        }
    }

    private function delete_cliente()
    {
        if (!$this->allow_delete) {
            $this->new_error_msg('No tienes permisos para eliminar.');
            return;
        }

        if ($this->cliente->delete()) {
            $this->new_message('Cliente eliminado correctamente.');
            header('Location: index.php?page=ventas_clientes');
        } else {
            $this->new_error_msg('Error al eliminar el cliente.');
        }
    }

    private function save_direccion()
    {
        $dir_id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);

        if ($dir_id) {
            $dir_model = new direccion_cliente();
            $dir = $dir_model->get($dir_id);
            if (!$dir) {
                $this->new_error_msg('Dirección no encontrada.');
                return;
            }
            if ($dir->codcliente !== $this->cliente->codcliente) {
                $this->new_error_msg('La dirección no pertenece a este cliente.');
                return;
            }
        } else {
            $dir = new direccion_cliente();
            $dir->codcliente = $this->cliente->codcliente;
        }

        $dir->descripcion = filter_input(INPUT_POST, 'descripcion') ?? $dir->descripcion;
        $dir->direccion = filter_input(INPUT_POST, 'direccion') ?? $dir->direccion;
        $dir->ciudad = filter_input(INPUT_POST, 'ciudad') ?? $dir->ciudad;
        $dir->provincia = filter_input(INPUT_POST, 'provincia') ?? $dir->provincia;
        $dir->codpostal = filter_input(INPUT_POST, 'codpostal') ?? $dir->codpostal;
        $dir->codpais = filter_input(INPUT_POST, 'codpais') ?? $dir->codpais;
        $dir->apartado = filter_input(INPUT_POST, 'apartado') ?? $dir->apartado;
        $dir->domenvio = filter_input(INPUT_POST, 'domenvio') === '1';
        $dir->domfacturacion = filter_input(INPUT_POST, 'domfacturacion') === '1';

        if ($dir->save()) {
            $this->new_message('Dirección guardada correctamente.');
        } else {
            $this->new_error_msg('Error al guardar la dirección.');
        }
    }

    private function delete_direccion()
    {
        if (!$this->allow_delete) {
            $this->new_error_msg('No tienes permisos para eliminar.');
            return;
        }

        $dir_id = filter_input(INPUT_POST, 'dir_id', FILTER_VALIDATE_INT);
        if ($dir_id) {
            $dir_model = new direccion_cliente();
            $dir = $dir_model->get($dir_id);
            if ($dir && $dir->codcliente === $this->cliente->codcliente && $dir->delete()) {
                $this->new_message('Dirección eliminada correctamente.');
            } else {
                $this->new_error_msg('Error al eliminar la dirección.');
            }
        }
    }
}
