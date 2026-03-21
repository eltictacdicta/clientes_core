<?php
/**
 * This file is part of FSFramework originally based on Facturascript 2017
 * Copyright (C) 2025 Javier Trujillo <mistertekcom@gmail.com>
 * Copyright (C) 2014-2017 Carlos Garcia Gomez <neorazorx@gmail.com>
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

namespace FSFramework\model;

/**
 * Una dirección de un cliente. Puede tener varias.
 */
class direccion_cliente extends \fs_model
{
    private const SQL_SELECT_ALL = 'SELECT * FROM ';
    private const SQL_UPDATE = 'UPDATE ';
    private const PK_WHERE_ID = ' WHERE id = ';
    private const FK_WHERE_CLIENTE = ' WHERE codcliente = ';

    /**
     * Clave primaria.
     * @var integer
     */
    public $id;

    /**
     * Código del cliente asociado.
     * @var string 
     */
    public $codcliente;
    public $codpais;
    public $apartado;
    public $provincia;
    public $ciudad;
    public $codpostal;
    public $direccion;

    /**
     * TRUE -> esta dirección es la principal para envíos.
     * @var boolean 
     */
    public $domenvio;

    /**
     * TRUE -> esta dirección es la principal para facturación.
     * @var boolean
     */
    public $domfacturacion;
    public $descripcion;

    /**
     * Fecha de última modificación.
     * @var string 
     */
    public $fecha;

    public function __construct($data = FALSE)
    {
        parent::__construct('dirclientes');
        if ($data) {
            $this->id = $this->intval($data['id']);
            $this->codcliente = $data['codcliente'];
            $this->codpais = $data['codpais'];
            $this->apartado = $data['apartado'];
            $this->provincia = $data['provincia'];
            $this->ciudad = $data['ciudad'];
            $this->codpostal = $data['codpostal'];
            $this->direccion = $data['direccion'];
            $this->domenvio = $this->str2bool($data['domenvio']);
            $this->domfacturacion = $this->str2bool($data['domfacturacion']);
            $this->descripcion = $data['descripcion'];
            $this->fecha = date('d-m-Y', strtotime($data['fecha']));
        } else {
            $this->id = NULL;
            $this->codcliente = NULL;
            $this->codpais = NULL;
            $this->apartado = NULL;
            $this->provincia = NULL;
            $this->ciudad = NULL;
            $this->codpostal = NULL;
            $this->direccion = NULL;
            $this->domenvio = TRUE;
            $this->domfacturacion = TRUE;
            $this->descripcion = 'Principal';
            $this->fecha = date('d-m-Y');
        }
    }

    protected function install()
    {
        return '';
    }

    /**
     * @param int $id
     * @return \direccion_cliente|boolean
     */
    public function get($id)
    {
        $data = $this->db->select(self::SQL_SELECT_ALL . $this->table_name . self::PK_WHERE_ID . $this->var2str($id) . ";");
        if ($data) {
            return new \direccion_cliente($data[0]);
        }

        return FALSE;
    }

    public function exists()
    {
        if (is_null($this->id)) {
            return FALSE;
        }

        return $this->db->select(self::SQL_SELECT_ALL . $this->table_name . self::PK_WHERE_ID . $this->var2str($this->id) . ";");
    }

    public function test(): bool
    {
        $this->direccion = $this->no_html($this->direccion);
        $this->ciudad = $this->no_html($this->ciudad);
        $this->provincia = $this->no_html($this->provincia);
        $this->apartado = $this->no_html($this->apartado);
        $this->codpostal = $this->no_html($this->codpostal);
        $this->descripcion = $this->no_html($this->descripcion);

        if (empty($this->codcliente)) {
            $this->new_error_msg("Código de cliente obligatorio para la dirección.");
            return false;
        }

        return true;
    }

    public function save()
    {
        if (!$this->test()) {
            return FALSE;
        }

        $this->fecha = date('d-m-Y');

        $sql = "";
        if ($this->domenvio) {
            $sql .= self::SQL_UPDATE . $this->table_name . " SET domenvio = false"
                . self::FK_WHERE_CLIENTE . $this->var2str($this->codcliente) . ";";
        }
        if ($this->domfacturacion) {
            $sql .= self::SQL_UPDATE . $this->table_name . " SET domfacturacion = false"
                . self::FK_WHERE_CLIENTE . $this->var2str($this->codcliente) . ";";
        }

        if ($this->exists()) {
            $sql .= self::SQL_UPDATE . $this->table_name . " SET codcliente = " . $this->var2str($this->codcliente)
                . ", codpais = " . $this->var2str($this->codpais)
                . ", apartado = " . $this->var2str($this->apartado)
                . ", provincia = " . $this->var2str($this->provincia)
                . ", ciudad = " . $this->var2str($this->ciudad)
                . ", codpostal = " . $this->var2str($this->codpostal)
                . ", direccion = " . $this->var2str($this->direccion)
                . ", domenvio = " . $this->var2str($this->domenvio)
                . ", domfacturacion = " . $this->var2str($this->domfacturacion)
                . ", descripcion = " . $this->var2str($this->descripcion)
                . ", fecha = " . $this->var2str($this->fecha)
                . self::PK_WHERE_ID . $this->var2str($this->id) . ";";

            return $this->db->exec($sql);
        }

        $sql .= "INSERT INTO " . $this->table_name . " (codcliente,codpais,apartado,provincia,ciudad,codpostal,
            direccion,domenvio,domfacturacion,descripcion,fecha) VALUES (" . $this->var2str($this->codcliente)
            . "," . $this->var2str($this->codpais)
            . "," . $this->var2str($this->apartado)
            . "," . $this->var2str($this->provincia)
            . "," . $this->var2str($this->ciudad)
            . "," . $this->var2str($this->codpostal)
            . "," . $this->var2str($this->direccion)
            . "," . $this->var2str($this->domenvio)
            . "," . $this->var2str($this->domfacturacion)
            . "," . $this->var2str($this->descripcion)
            . "," . $this->var2str($this->fecha) . ");";

        if ($this->db->exec($sql)) {
            $this->id = $this->db->lastval();
            return TRUE;
        }

        return FALSE;
    }

    public function delete()
    {
        return $this->db->exec("DELETE FROM " . $this->table_name . self::PK_WHERE_ID . $this->var2str($this->id) . ";");
    }

    public function all($offset = 0)
    {
        $dirlist = array();

        $data = $this->db->select_limit(self::SQL_SELECT_ALL . $this->table_name . " ORDER BY id ASC", FS_ITEM_LIMIT, $offset);
        if ($data) {
            foreach ($data as $d) {
                $dirlist[] = new \direccion_cliente($d);
            }
        }

        return $dirlist;
    }

    /**
     * @param string $cod
     * @return \direccion_cliente[]
     */
    public function all_from_cliente($cod)
    {
        $dirlist = array();
        $sql = self::SQL_SELECT_ALL . $this->table_name . self::FK_WHERE_CLIENTE . $this->var2str($cod)
            . " ORDER BY id DESC;";

        $data = $this->db->select($sql);
        if ($data) {
            foreach ($data as $d) {
                $dirlist[] = new \direccion_cliente($d);
            }
        }

        return $dirlist;
    }

    /**
     * Aplica correcciones a la tabla.
     */
    public function fix_db()
    {
        $this->db->exec("DELETE FROM " . $this->table_name . " WHERE codcliente NOT IN (SELECT codcliente FROM clientes);");
    }
}
