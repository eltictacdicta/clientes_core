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

/**
 * Redirect stub for legacy page=ventas_grupo URLs.
 *
 * The full ventas_grupo controller now lives in facturacion_base.
 * This stub ensures that bookmarks or external links to
 * index.php?page=ventas_grupo don't 404 when facturacion_base
 * is not installed.
 */
class ventas_grupo extends clientes_controller
{
    public function __construct()
    {
        parent::__construct(__CLASS__, 'Grupos', 'ventas', FALSE, FALSE);
    }

    protected function private_core()
    {
        parent::private_core();
        header('Location: index.php?page=ventas_clientes&tab=grupos');
        exit;
    }
}
