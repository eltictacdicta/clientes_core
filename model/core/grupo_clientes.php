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
 * Un grupo de clientes, que puede estar asociado a una tarifa.
 */
class grupo_clientes extends \fs_model
{

    /**
     * Clave primaria
     * @var string
     */
    public $codgrupo;

    /**
     * Nombre del grupo
     * @var string 
     */
    public $nombre;

    /**
     * Código de la tarifa asociada, si la hay
     * @var string 
     */
    public $codtarifa;

    public function __construct($data = FALSE)
    {
        parent::__construct('gruposclientes');
        if ($data) {
            $this->codgrupo = $data['codgrupo'];
            $this->nombre = $data['nombre'];
            $this->codtarifa = $data['codtarifa'];
        } else {
            $this->codgrupo = NULL;
            $this->nombre = NULL;
            $this->codtarifa = NULL;
        }
    }

    protected function install()
    {
        if (class_exists('tarifa')) {
            $tarifa = new \tarifa();
            // La instancia se crea para asegurar la carga de la clase y su tabla
        }

        return '';
    }

    /**
     * @return string
     */
    public function url()
    {
        if ($this->codgrupo == NULL) {
            return 'index.php?page=ventas_clientes#grupos';
        }

        return 'index.php?page=ventas_grupo&cod=' . urlencode($this->codgrupo);
    }

    /**
     * @return string
     */
    public function get_new_codigo()
    {
        if (strtolower(FS_DB_TYPE) == 'postgresql') {
            $sql = "SELECT codgrupo from " . $this->table_name . " where codgrupo ~ '^\d+$'"
                . " ORDER BY codgrupo::integer DESC";
        } else {
            $sql = "SELECT codgrupo from " . $this->table_name . " where codgrupo REGEXP '^[0-9]+$'"
                . " ORDER BY CAST(`codgrupo` AS decimal) DESC";
        }

        $data = $this->db->select_limit($sql, 1, 0);
        if ($data) {
            return sprintf('%06s', (1 + intval($data[0]['codgrupo'])));
        }

        return '000001';
    }

    /**
     * @param string $cod
     * @return \grupo_clientes|boolean
     */
    public function get($cod)
    {
        $data = $this->db->select("SELECT * FROM " . $this->table_name . " WHERE codgrupo = " . $this->var2str($cod) . ";");
        if ($data) {
            return new \grupo_clientes($data[0]);
        }

        return FALSE;
    }

    public function exists()
    {
        if (is_null($this->codgrupo)) {
            return FALSE;
        }

        return $this->db->select("SELECT * FROM " . $this->table_name . " WHERE codgrupo = " . $this->var2str($this->codgrupo) . ";");
    }

    public function test(): bool
    {
        $this->nombre = $this->no_html($this->nombre);

        if (strlen($this->nombre) < 1 || strlen($this->nombre) > 100) {
            $this->new_error_msg("Nombre de grupo no válido.");
            return false;
        }

        return true;
    }

    public function save()
    {
        if (!$this->test()) {
            return FALSE;
        }

        if ($this->exists()) {
            $sql = "UPDATE " . $this->table_name . " SET nombre = " . $this->var2str($this->nombre)
                . ", codtarifa = " . $this->var2str($this->codtarifa)
                . "  WHERE codgrupo = " . $this->var2str($this->codgrupo) . ";";
        } else {
            $sql = "INSERT INTO " . $this->table_name . " (codgrupo,nombre,codtarifa) VALUES "
                . "(" . $this->var2str($this->codgrupo)
                . "," . $this->var2str($this->nombre)
                . "," . $this->var2str($this->codtarifa) . ");";
        }

        return $this->db->exec($sql);
    }

    public function delete()
    {
        return $this->db->exec("DELETE FROM " . $this->table_name . " WHERE codgrupo = " . $this->var2str($this->codgrupo) . ";");
    }

    public function all()
    {
        $data = $this->db->select("SELECT * FROM " . $this->table_name . " ORDER BY nombre ASC;");
        return $this->all_from_data($data);
    }

    /**
     * @param string $cod
     * @return \grupo_clientes[]
     */
    public function all_with_tarifa($cod)
    {
        $data = $this->db->select("SELECT * FROM " . $this->table_name . " WHERE codtarifa = " . $this->var2str($cod) . " ORDER BY codgrupo ASC;");
        return $this->all_from_data($data);
    }

    private function all_from_data(&$data)
    {
        $glist = array();
        if ($data) {
            foreach ($data as $d) {
                $glist[] = new \grupo_clientes($d);
            }
        }

        return $glist;
    }
}
