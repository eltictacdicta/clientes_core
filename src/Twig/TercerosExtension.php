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

namespace FSFramework\Plugins\clientes_core\Twig;

use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;
use Twig\TwigFilter;

/**
 * Twig extension providing helper functions for client/third-party templates.
 * Can be registered as a standalone Twig extension or used via Init.php event listener.
 */
class TercerosExtension extends AbstractExtension
{
    public function getName(): string
    {
        return 'clientes_core_terceros';
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('cliente_badge', [$this, 'clienteBadge'], ['is_safe' => ['html']]),
            new TwigFunction('direccion_tipo_badges', [$this, 'direccionTipoBadges'], ['is_safe' => ['html']]),
        ];
    }

    public function getFilters(): array
    {
        return [
            new TwigFilter('formato_telefono', [$this, 'formatoTelefono']),
        ];
    }

    /**
     * Returns an HTML badge indicating client status (active/inactive).
     */
    public function clienteBadge($cliente): string
    {
        if (!$cliente) {
            return '';
        }

        if ($cliente->debaja) {
            return '<span class="label label-danger"><i class="fa fa-ban"></i> De baja</span>';
        }

        return '<span class="label label-success"><i class="fa fa-check"></i> Activo</span>';
    }

    /**
     * Returns HTML badges for address type (shipping/billing).
     */
    public function direccionTipoBadges($direccion): string
    {
        if (!$direccion) {
            return '';
        }

        $badges = '';
        if ($direccion->domenvio) {
            $badges .= '<span class="label label-info"><i class="fa fa-truck"></i> Envío</span> ';
        }
        if ($direccion->domfacturacion) {
            $badges .= '<span class="label label-primary"><i class="fa fa-file-text-o"></i> Facturación</span>';
        }

        return $badges;
    }

    /**
     * Formats a phone number for display.
     */
    public function formatoTelefono(?string $telefono): string
    {
        if (empty($telefono)) {
            return '-';
        }

        return $telefono;
    }
}
