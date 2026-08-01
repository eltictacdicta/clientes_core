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
 * Un grupo de descuentos, con los porcentajes D1-D4.
 * Entidad separada de grupo_clientes (que es solo para categorización).
 */
class grupo_descuentos extends \fs_model
{
    private const SQL_SELECT_ALL = 'SELECT * FROM ';
    private const PK_WHERE = ' WHERE codgrupo_descuento = ';

    /**
     * Clave primaria
     * @var string
     */
    public $codgrupo_descuento;

    /**
     * Nombre del grupo de descuentos
     * @var string 
     */
    public $nombre;

    /** @var float|null */
    public $d1;

    /** @var float|null */
    public $d2;

    /** @var float|null */
    public $d3;

    /** @var float|null */
    public $d4;

    public function __construct($data = FALSE)
    {
        parent::__construct('gruposdescuentos');
        if ($data) {
            $this->codgrupo_descuento = $data['codgrupo_descuento'];
            $this->nombre = $data['nombre'];
            $this->d1 = array_key_exists('d1', $data) && $data['d1'] !== null ? (float) $data['d1'] : null;
            $this->d2 = array_key_exists('d2', $data) && $data['d2'] !== null ? (float) $data['d2'] : null;
            $this->d3 = array_key_exists('d3', $data) && $data['d3'] !== null ? (float) $data['d3'] : null;
            $this->d4 = array_key_exists('d4', $data) && $data['d4'] !== null ? (float) $data['d4'] : null;
        } else {
            $this->codgrupo_descuento = NULL;
            $this->nombre = NULL;
            $this->d1 = NULL;
            $this->d2 = NULL;
            $this->d3 = NULL;
            $this->d4 = NULL;
        }
    }

    protected function install()
    {
        return '';
    }

    /**
     * @return string
     */
    public function url()
    {
        if ($this->codgrupo_descuento == NULL) {
            return 'index.php?page=descuentos_grupo';
        }

        return 'index.php?page=descuentos_grupo&cod=' . urlencode($this->codgrupo_descuento);
    }

    /**
     * @return string
     */
    public function get_new_codigo()
    {
        if (strtolower(FS_DB_TYPE) == 'postgresql') {
            $sql = "SELECT codgrupo_descuento from " . $this->table_name . " where codgrupo_descuento ~ '^\d+$'"
                . " ORDER BY codgrupo_descuento::integer DESC";
        } else {
            $sql = "SELECT codgrupo_descuento from " . $this->table_name . " where codgrupo_descuento REGEXP '^[0-9]+$'"
                . " ORDER BY CAST(`codgrupo_descuento` AS decimal) DESC";
        }

        $data = $this->db->select_limit($sql, 1, 0);
        if ($data) {
            return sprintf('%06s', (1 + intval($data[0]['codgrupo_descuento'])));
        }

        return '000001';
    }

    /**
     * @param string $cod
     * @return self|boolean
     */
    public function get($cod)
    {
        $data = $this->db->select(self::SQL_SELECT_ALL . $this->table_name . self::PK_WHERE . $this->var2str($cod) . ";");
        if ($data) {
            return new self($data[0]);
        }

        return FALSE;
    }

    public function exists()
    {
        if (is_null($this->codgrupo_descuento)) {
            return FALSE;
        }

        return $this->db->select(self::SQL_SELECT_ALL . $this->table_name . self::PK_WHERE . $this->var2str($this->codgrupo_descuento) . ";");
    }

    public function test(): bool
    {
        $this->nombre = $this->no_html($this->nombre);

        if (strlen($this->nombre) < 1 || strlen($this->nombre) > 100) {
            $this->new_error_msg("Nombre de grupo de descuentos no válido.");
            return false;
        }

        foreach (['d1', 'd2', 'd3', 'd4'] as $field) {
            if ($this->{$field} !== null) {
                $val = (float) $this->{$field};
                if ($val < 0.00 || $val > 100.00) {
                    $this->new_error_msg("Descuento $field fuera de rango (0-100): $val");
                    return false;
                }
            }
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
                . ", d1 = " . $this->var2str($this->d1)
                . ", d2 = " . $this->var2str($this->d2)
                . ", d3 = " . $this->var2str($this->d3)
                . ", d4 = " . $this->var2str($this->d4)
                . self::PK_WHERE . $this->var2str($this->codgrupo_descuento) . ";";
        } else {
            $sql = "INSERT INTO " . $this->table_name . " (codgrupo_descuento,nombre,d1,d2,d3,d4) VALUES "
                . "(" . $this->var2str($this->codgrupo_descuento)
                . "," . $this->var2str($this->nombre)
                . "," . $this->var2str($this->d1)
                . "," . $this->var2str($this->d2)
                . "," . $this->var2str($this->d3)
                . "," . $this->var2str($this->d4) . ");";
        }

        return $this->db->exec($sql);
    }

    public function delete()
    {
        return $this->db->exec("DELETE FROM " . $this->table_name . self::PK_WHERE . $this->var2str($this->codgrupo_descuento) . ";");
    }

    public function all()
    {
        $data = $this->db->select(self::SQL_SELECT_ALL . $this->table_name . " ORDER BY nombre ASC;");
        return $this->all_from_data($data);
    }

    private function all_from_data(&$data)
    {
        $glist = array();
        if ($data) {
            foreach ($data as $d) {
                $glist[] = new self($d);
            }
        }

        return $glist;
    }
}
