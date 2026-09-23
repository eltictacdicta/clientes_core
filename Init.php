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
use FSFramework\Event\TwigLoaderEvent;
use FSFramework\model\cliente;
use FSFramework\model\grupo_clientes;
use FSFramework\model\grupo_descuentos;
use FSFramework\View\ViewHookRegistry;
use Twig\Loader\FilesystemLoader;

/**
 * Initialization class for clientes_core plugin.
 * Registers Twig globals and functions for client management.
 */
class Init
{
    public function init(): void
    {
        $dispatcher = FSEventDispatcher::getInstance();

        // Expose the plugin's View/ tree under the @clientes_core Twig
        // namespace, following the clientes_catalogo precedent. Belt and
        // braces with Html::addPluginViewPaths(); no core change required.
        $dispatcher->addListener(TwigLoaderEvent::NAME, function (TwigLoaderEvent $event) {
            $loader = $event->getLoader();
            if ($loader instanceof FilesystemLoader) {
                $loader->addPath(__DIR__ . '/View', 'clientes_core');
            }
        });

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

        // (1) Defaults + default-client seed. Both default groups are guaranteed
        // BEFORE the seed, because the seed now passes the mandatory-group test().
        try {
            self::ensureDefaultClientGroup();
            self::ensureDefaultDiscountGroup();

            $cliente = new cliente();
            if (!$cliente->table_has_rows()) {
                $cliente->nombre = 'Cliente por defecto';
                $cliente->codgrupo = '000001';
                $cliente->codgrupo_descuento = '000000';
                $cliente->save();
            }

            if (!$settings->get('clientes_core_default_seeded')) {
                $settings->set('clientes_core_default_seeded', '1');
                $settings->save();
            }
        } catch (\Throwable $e) {
            error_log('[clientes_core] Default seed failed: ' . $e->getMessage());
            // A failed seed must never break plugin activation.
            // The flag was not set, so the next activation can retry.
        }

        // (2) Legacy migration (v1 → v2) flag. Kept for compatibility; the
        // orphan backfill it used to own now lives in the mandatory-group block.
        if (!$settings->get('clientes_core_discounts_migrated')) {
            try {
                self::ensureDefaultDiscountGroup();

                $settings->set('clientes_core_discounts_migrated', '1');
                $settings->save();
            } catch (\Throwable $e) {
                error_log('[clientes_core] Discount migration failed: ' . $e->getMessage());
                // A failed migration must never break plugin activation.
            }
        }

        // (3) Mandatory-group backfill, gated by its own flag.
        self::runMandatoryGroupBackfill($settings);
    }

    /**
     * Guarantees the two default groups exist and backfills legacy clients
     * that lack them, in a single idempotent, data-only migration block.
     *
     * The block is gated by the NEW fs_settings key
     * `clientes_core_discount_group_required`. It must not reuse
     * `clientes_core_discounts_migrated`, which is already set on installs
     * that ran the previous migration and would make this a permanent no-op.
     *
     * A failure leaves the flag unset so the next activation retries, and
     * never breaks plugin activation.
     */
    private static function runMandatoryGroupBackfill(\fs_settings $settings): void
    {
        if ($settings->get('clientes_core_discount_group_required')) {
            return;
        }

        try {
            // Ensure steps first: the backfill writes codes that must exist.
            self::ensureDefaultClientGroup();
            self::ensureDefaultDiscountGroup();

            $cliente = new cliente();
            $cliente->assignOrphanClientsToGroup('000001');
            $cliente->assignOrphanClientsToDiscountGroup('000000');

            $settings->set('clientes_core_discount_group_required', '1');
            $settings->save();
        } catch (\Throwable $e) {
            error_log('[clientes_core] Mandatory group backfill failed: ' . $e->getMessage());
            // Flag left unset => the next activation retries.
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

    private static function ensureDefaultClientGroup(): void
    {
        $grupoModel = new grupo_clientes();
        if ($grupoModel->get('000001')) {
            return;
        }

        $grupo = new grupo_clientes();
        $grupo->codgrupo = '000001';
        $grupo->nombre = 'General';
        $grupo->save();
    }

    /**
     * Guarantees the default discount group '000000' "Personalizado" exists.
     * Idempotent by code lookup, never by table emptiness.
     */
    private static function ensureDefaultDiscountGroup(): void
    {
        $model = new grupo_descuentos();
        if ($model->get('000000')) {
            return;
        }

        $grupo = new grupo_descuentos();
        $grupo->codgrupo_descuento = '000000';
        $grupo->nombre = 'Personalizado';
        $grupo->d1 = 0.00;
        $grupo->d2 = 0.00;
        $grupo->d3 = 0.00;
        $grupo->d4 = 0.00;
        $grupo->save();
    }
}
