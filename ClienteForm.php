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

declare(strict_types=1);

namespace FSFramework\Plugins\clientes_core;

/**
 * Single PHP authority for client-form processing.
 *
 * `clientes_core` and its consumers (for example `tpvmod`) use this class as
 * the only implementation of:
 *
 *   1. mapping submitted client-form fields onto a `cliente` instance;
 *   2. computing the `descuentos_modified` flag against the selected discount
 *      group;
 *   3. triggering the mandatory-group validation through `cliente::test()`.
 *
 * The class is autoloaded by the root Composer PSR-4 map
 * (`FSFramework\Plugins\` => `plugins/`); the plugin ships no composer.json and
 * no vendor tree.
 */
final class ClienteForm
{
    /** Fields mapped verbatim as strings. */
    public const TEXT_FIELDS = [
        'nombre', 'razonsocial', 'tipoidfiscal', 'cifnif', 'telefono1', 'telefono2',
        'fax', 'email', 'web', 'regimeniva', 'diaspago', 'observaciones',
    ];

    /** Fields mapped as `'1' === true`. */
    public const BOOL_FIELDS = ['recargo', 'personafisica', 'debaja'];

    /** Fields mapped as `'' => null`, otherwise the string. */
    public const NULLABLE_TEXT_FIELDS = ['coddivisa'];

    /** Discount columns compared against the selected discount group. */
    public const DISCOUNT_FIELDS = ['d1', 'd2', 'd3', 'd4'];

    /** Discount-group model used to resolve the selected group. */
    private const DISCOUNT_GROUP_CLASS = 'FSFramework\\model\\grupo_descuentos';

    /**
     * Map a submission onto a cliente.
     *
     * Selective: only keys present in `$post` are touched, so partial updates
     * (`change_grupo`, `reset_descuentos`) keep working. No fallback group code
     * is ever written: an empty selection becomes `null` and `test()` rejects
     * it.
     *
     * @param array<string, mixed> $post
     */
    public static function apply(object $cliente, array $post): object
    {
        $hasRazonSocial = property_exists($cliente, 'razonsocial');

        foreach (self::TEXT_FIELDS as $field) {
            if (!array_key_exists($field, $post)) {
                continue;
            }

            // Lightweight targets without the property stay untouched; the
            // razonsocial fallback below owns that field when it exists.
            if ($field === 'razonsocial' && !$hasRazonSocial) {
                continue;
            }

            $cliente->{$field} = (string) $post[$field];
        }

        foreach (self::NULLABLE_TEXT_FIELDS as $field) {
            if (!array_key_exists($field, $post)) {
                continue;
            }

            $value = $post[$field];
            $cliente->{$field} = ($value === '' || $value === null) ? null : (string) $value;
        }

        foreach (self::BOOL_FIELDS as $field) {
            if (!array_key_exists($field, $post)) {
                continue;
            }

            $cliente->{$field} = '1' === (string) $post[$field];
        }

        if (array_key_exists('codgrupo', $post)) {
            $codgrupo = trim((string) $post['codgrupo']);
            $cliente->codgrupo = $codgrupo === '' ? null : $codgrupo;
        }

        if (array_key_exists('codgrupo_descuento', $post)) {
            $codgrupoDescuento = trim((string) $post['codgrupo_descuento']);
            $cliente->codgrupo_descuento = $codgrupoDescuento === '' ? null : $codgrupoDescuento;
        }

        foreach (self::DISCOUNT_FIELDS as $field) {
            if (array_key_exists($field, $post)) {
                $cliente->{$field} = (float) $post[$field];
            }
        }

        // VF-2: keep the create-path normalization for every consumer, but only
        // when the submission carries razonsocial and the target declares it.
        if ($hasRazonSocial && array_key_exists('razonsocial', $post) && $cliente->razonsocial === '') {
            $cliente->razonsocial = (string) $cliente->nombre;
        }

        $cliente->descuentos_modified = self::computeDescuentosModified(
            $cliente,
            self::loadDiscountGroup($cliente)
        );

        return $cliente;
    }

    /**
     * Pure diff: true when any d1-d4 differs from the loaded group, compared
     * rounded to 2 decimals. Returns false when no group is supplied.
     */
    public static function computeDescuentosModified(object $cliente, ?object $grupoDescuentos): bool
    {
        if ($grupoDescuentos === null) {
            return false;
        }

        foreach (self::DISCOUNT_FIELDS as $field) {
            $clientVal = $cliente->{$field} !== null ? round((float) $cliente->{$field}, 2) : null;
            $groupVal = $grupoDescuentos->{$field} !== null ? round((float) $grupoDescuentos->{$field}, 2) : null;

            if ($clientVal !== $groupVal) {
                return true;
            }
        }

        return false;
    }

    /**
     * The single mandatory-group gate: delegates to cliente::test().
     */
    public static function validate(object $cliente): bool
    {
        return $cliente->test();
    }

    /**
     * Convenience for controllers: apply() then validate().
     *
     * @param array<string, mixed> $post
     * @return array<int, string> validation errors (empty on success)
     */
    public static function applyAndValidate(object $cliente, array $post): array
    {
        self::apply($cliente, $post);

        if (self::validate($cliente)) {
            return [];
        }

        return $cliente->get_errors();
    }

    /**
     * Resolve the selected discount group, if one is selected.
     */
    private static function loadDiscountGroup(object $cliente): ?object
    {
        $code = $cliente->codgrupo_descuento ?? null;
        if (empty($code) || !class_exists(self::DISCOUNT_GROUP_CLASS)) {
            return null;
        }

        $groupClass = self::DISCOUNT_GROUP_CLASS;
        $model = new $groupClass();
        $group = $model->get($code);

        return is_object($group) ? $group : null;
    }
}
