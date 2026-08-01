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
 * Controlador del detalle de un cliente.
 * Plugin: clientes_core
 */
class ventas_cliente extends clientes_controller
{

    public $allow_delete;
    public $cliente;
    public $direcciones;
    public $grupos;
    public $grupos_descuentos;
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
        $this->grupos_descuentos = [];
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

                $grupoDescModel = new grupo_descuentos();
                $this->grupos_descuentos = $grupoDescModel->all();

                $action = filter_input(INPUT_GET, 'action') ?? filter_input(INPUT_POST, 'action') ?? '';

                switch ($action) {
                    case 'save_cliente':
                        $this->save_cliente();
                        break;

                    case 'delete':
                        $this->delete_cliente();
                        return;

                    case 'save_dir':
                        $this->save_direccion();
                        break;

                    case 'change_grupo':
                        $this->change_grupo();
                        break;

                    case 'change_grupo_descuento':
                        $this->change_grupo_descuento();
                        break;

                    case 'reset_descuentos':
                        $this->reset_descuentos();
                        break;

                    case 'delete_dir':
                        $this->delete_direccion();
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
        if (!$this->requireMutationCsrf()) {
            return;
        }

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
        $codgrupo = filter_input(INPUT_POST, 'codgrupo');
        $this->cliente->codgrupo = !empty($codgrupo) ? $codgrupo : '000000';
        $this->cliente->regimeniva = filter_input(INPUT_POST, 'regimeniva') ?? $this->cliente->regimeniva;
        $this->cliente->recargo = filter_input(INPUT_POST, 'recargo') === '1';
        $this->cliente->personafisica = filter_input(INPUT_POST, 'personafisica') === '1';
        $this->cliente->diaspago = filter_input(INPUT_POST, 'diaspago') ?? $this->cliente->diaspago;
        $this->cliente->observaciones = filter_input(INPUT_POST, 'observaciones') ?? $this->cliente->observaciones;
        $this->cliente->d1 = filter_input(INPUT_POST, 'd1') !== null ? (float) filter_input(INPUT_POST, 'd1') : $this->cliente->d1;
        $this->cliente->d2 = filter_input(INPUT_POST, 'd2') !== null ? (float) filter_input(INPUT_POST, 'd2') : $this->cliente->d2;
        $this->cliente->d3 = filter_input(INPUT_POST, 'd3') !== null ? (float) filter_input(INPUT_POST, 'd3') : $this->cliente->d3;
        $this->cliente->d4 = filter_input(INPUT_POST, 'd4') !== null ? (float) filter_input(INPUT_POST, 'd4') : $this->cliente->d4;

        $codgrupoDescuento = filter_input(INPUT_POST, 'codgrupo_descuento');
        $this->cliente->codgrupo_descuento = !empty($codgrupoDescuento) ? $codgrupoDescuento : null;

        if ($this->cliente->codgrupo_descuento) {
            $grupoDescModel = new grupo_descuentos();
            $grupoDesc = $grupoDescModel->get($this->cliente->codgrupo_descuento);
            if ($grupoDesc) {
                $modified = false;
                foreach (['d1', 'd2', 'd3', 'd4'] as $field) {
                    $clientVal = $this->cliente->{$field} !== null ? round((float) $this->cliente->{$field}, 2) : null;
                    $groupVal = $grupoDesc->{$field} !== null ? round((float) $grupoDesc->{$field}, 2) : null;
                    if ($clientVal !== $groupVal) {
                        $modified = true;
                        break;
                    }
                }
                $this->cliente->descuentos_modified = $modified;
            }
        }

        $debaja = filter_input(INPUT_POST, 'debaja');
        if ($debaja !== null) {
            $this->cliente->debaja = $debaja === '1';
        }

        if ($this->cliente->save()) {
            $this->new_message('Cliente guardado correctamente.');
            return;
        }

        foreach ($this->cliente->get_errors() as $error) {
            $this->new_error_msg($error);
        }
        if (empty($this->cliente->get_errors())) {
            $this->new_error_msg('Error al guardar el cliente. Verifique los datos e inténtelo de nuevo.');
        }
    }

    private function delete_cliente()
    {
        if (!$this->requireMutationCsrf()) {
            return;
        }

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
        if (!$this->requireMutationCsrf()) {
            return;
        }

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
        if (!$this->requireMutationCsrf()) {
            return;
        }

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

    private function change_grupo()
    {
        if (!$this->requireMutationCsrf()) {
            return;
        }

        $newCodgrupo = filter_input(INPUT_POST, 'codgrupo');
        if (empty($newCodgrupo)) {
            $this->new_error_msg('Debe seleccionar un grupo.');
            return;
        }

        $grupoModel = new grupo_clientes();
        $grupo = $grupoModel->get($newCodgrupo);
        if (!$grupo) {
            $this->new_error_msg('Grupo no encontrado.');
            return;
        }

        $this->cliente->codgrupo = $newCodgrupo;

        if ($this->cliente->save()) {
            $this->new_message('Grupo actualizado correctamente.');
        } else {
            foreach ($this->cliente->get_errors() as $error) {
                $this->new_error_msg($error);
            }
        }
    }

    private function change_grupo_descuento()
    {
        if (!$this->requireMutationCsrf()) {
            return;
        }

        $newCodgrupoDesc = filter_input(INPUT_POST, 'codgrupo_descuento');
        if (empty($newCodgrupoDesc)) {
            $this->new_error_msg('Debe seleccionar un grupo de descuentos.');
            return;
        }

        $grupoDescModel = new grupo_descuentos();
        $grupoDesc = $grupoDescModel->get($newCodgrupoDesc);
        if (!$grupoDesc) {
            $this->new_error_msg('Grupo de descuentos no encontrado.');
            return;
        }

        $this->cliente->codgrupo_descuento = $newCodgrupoDesc;
        $this->cliente->applyGroupDiscounts($grupoDesc);

        if ($this->cliente->save()) {
            $this->new_message('Grupo de descuentos y descuentos actualizados correctamente.');
        } else {
            foreach ($this->cliente->get_errors() as $error) {
                $this->new_error_msg($error);
            }
        }
    }

    private function reset_descuentos()
    {
        if (!$this->requireMutationCsrf()) {
            return;
        }

        if ($this->cliente->resetToGroupDefaults()) {
            if ($this->cliente->save()) {
                $this->new_message('Descuentos restaurados a los valores del grupo.');
            } else {
                foreach ($this->cliente->get_errors() as $error) {
                    $this->new_error_msg($error);
                }
            }
        } else {
            $this->new_error_msg('No se pudo restaurar los descuentos del grupo.');
        }
    }
}
