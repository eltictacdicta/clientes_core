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

namespace FSFramework\Plugins\clientes_core;

use FSFramework\Event\FSEventDispatcher;
use FSFramework\Event\TwigInitEvent;
use FSFramework\model\cliente;
use FSFramework\model\grupo_descuentos;
use FSFramework\View\ViewHookRegistry;

/**
 * Initialization class for clientes_core plugin.
 * Registers Twig globals and functions for client management.
 */
class Init
{
    public function init(): void
    {
        $dispatcher = FSEventDispatcher::getInstance();

        $dispatcher->addListener(TwigInitEvent::NAME, function (TwigInitEvent $event) {
            $this->registerTwigExtensions($event->getTwig());
        });
    }

    /**
     * Activation hook. Called once per plugin activation by
     * fs_plugin_manager::runPluginUpgrade (base/fs_plugin_manager.php
     * around line 643-655). Convention established by the
     * default-client-on-activation change. Static, idempotent via
     * fs_settings flag, fail-safe via try/catch.
     *
     * Seeds a single "Cliente por defecto" cliente on a fresh
     * install so downstream sales/invoicing flows always have at
     * least one valid codcliente to reference. A persistent
     * clientes_core_default_seeded flag in fs_settings
     * short-circuits the body on every subsequent activation.
     *
     * The body is wrapped in try { ... } catch (\Throwable $e)
     * so a DB error during the seed never breaks plugin
     * activation. This is in addition to (not a replacement
     * for) the framework-level try/catch in runPluginUpgrade.
     */
    public static function upgrade(): void
    {
        $settings = new \fs_settings();

        // Invalidate schema check cache so check_table() re-runs and detects
        // new/changed columns from XML (e.g. codgrupo_descuento on clientes).
        $cache = new \fs_cache();
        $cache->delete('fs_checked_tables');

        if (!$settings->get('clientes_core_default_seeded')) {
            try {
                $cliente = new cliente();
                if (!$cliente->table_has_rows()) {
                    $cliente->nombre = 'Cliente por defecto';
                    $cliente->save();
                }

                $settings->set('clientes_core_default_seeded', '1');
                $settings->save();
            } catch (\Throwable $e) {
                error_log('[clientes_core] Default seed failed: ' . $e->getMessage());
                // A failed seed must never break plugin activation.
                // The flag was not set, so the next activation can retry.
            }
        }

        // Legacy migration (v1 → v2): moved from grupo_clientes to grupo_descuentos.
        // Only runs once via the flag; safe to leave as a no-op for new installs.
        if ($settings->get('clientes_core_discounts_migrated')) {
            return;
        }

        try {
            $grupoDescModel = new grupo_descuentos();
            if (!$grupoDescModel->get('000000')) {
                $grupoDesc = new grupo_descuentos();
                $grupoDesc->codgrupo_descuento = '000000';
                $grupoDesc->nombre = 'Personalizado';
                $grupoDesc->save();
            }

            $cliente = new cliente();
            $cliente->assignOrphanClientsToGroup('000000');

            $settings->set('clientes_core_discounts_migrated', '1');
            $settings->save();
        } catch (\Throwable $e) {
            error_log('[clientes_core] Discount migration failed: ' . $e->getMessage());
            // A failed migration must never break plugin activation.
        }
    }

    private function registerTwigExtensions(\Twig\Environment $twig): void
    {
        $this->registerClienteResumen($twig);
        $this->registerClienteEstado($twig);
        $this->registerDireccionCompleta($twig);
        $this->registerClientesRenderHook($twig);
    }

    private function registerClienteResumen(\Twig\Environment $twig): void
    {
        try {
            $twig->addFunction(new \Twig\TwigFunction('cliente_resumen', function ($cliente) {
                if (!$cliente) {
                    return '';
                }

                $nombre = $cliente->nombre;
                if ($cliente->nombre !== $cliente->razonsocial && !empty($cliente->razonsocial)) {
                    $nombre .= ' (' . $cliente->razonsocial . ')';
                }

                return $nombre;
            }));
        } catch (\LogicException) {
        }
    }

    private function registerClienteEstado(\Twig\Environment $twig): void
    {
        try {
            $twig->addFunction(new \Twig\TwigFunction('cliente_estado', function ($cliente) {
                if (!$cliente) {
                    return '';
                }

                return $cliente->debaja ? 'inactive' : 'active';
            }));
        } catch (\LogicException) {
        }
    }

    private function registerDireccionCompleta(\Twig\Environment $twig): void
    {
        try {
            $twig->addFunction(new \Twig\TwigFunction('direccion_completa', function ($direccion) {
                if (!$direccion) {
                    return '';
                }

                $parts = array_filter([
                    $direccion->direccion,
                    $direccion->codpostal,
                    $direccion->ciudad,
                    $direccion->provincia,
                ]);

                return implode(', ', $parts);
            }));
        } catch (\LogicException) {
        }
    }

    private function registerClientesRenderHook(\Twig\Environment $twig): void
    {
        try {
            $twig->addFunction(new \Twig\TwigFunction('clientes_render_hook', function ($hook, $context = []) use ($twig) {
                if (!is_string($hook)) {
                    return '';
                }

                return ViewHookRegistry::render($twig, $hook, is_array($context) ? $context : []);
            }));
        } catch (\LogicException) {
        }
    }
}
