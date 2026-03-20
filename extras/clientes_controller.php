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

/**
 * Controlador base local para clientes_core.
 * Mantiene solo las utilidades que este plugin usa realmente.
 */
class clientes_controller extends fs_controller
{
    /**
     * TRUE si el usuario tiene permiso para eliminar en la página.
     *
     * @var bool
     */
    public $allow_delete;

    protected function private_core()
    {
        $this->allow_delete = $this->user->allow_delete_on($this->class_name);
    }

    /**
     * Devuelve un array con los enlaces a las páginas en función de la url,
     * total y el offset proporcionado.
     *
     * @param string  $url
     * @param integer $total
     * @param integer $offset
     *
     * @return array
     */
    protected function fbase_paginas($url, $total, $offset)
    {
        $paginas = [];
        $i = 0;
        $num = 0;
        $actual = 1;

        while ($num < $total) {
            $paginas[$i] = array(
                'url' => $url . '&offset=' . ($i * FS_ITEM_LIMIT),
                'num' => $i + 1,
                'actual' => ($num == $offset)
            );

            if ($num == $offset) {
                $actual = $i;
            }

            $i++;
            $num += FS_ITEM_LIMIT;
        }

        foreach ($paginas as $j => $value) {
            $enmedio = intval($i / 2);

            if (($j > 1 && $j < $actual - 5 && $j != $enmedio) || ($j > $actual + 5 && $j < $i - 1 && $j != $enmedio)) {
                unset($paginas[$j]);
            }
        }

        return count($paginas) > 1 ? $paginas : [];
    }
}