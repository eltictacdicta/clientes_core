<?php
/**
 * This file is part of FSFramework originally based on Facturascript 2017
 * Copyright (C) 2026 Javier Trujillo <mistertekcom@gmail.com>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Lesser General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 */

namespace FSFramework\Plugins\clientes_core;

final class ViewHookRegistry
{
    /**
     * @var array<string, array<int, string>>
     */
    private static $hooks = [];

    public static function register(string $hook, string $template): void
    {
        if (!isset(self::$hooks[$hook])) {
            self::$hooks[$hook] = [];
        }

        if (!in_array($template, self::$hooks[$hook], true)) {
            self::$hooks[$hook][] = $template;
        }
    }

    public static function has(string $hook): bool
    {
        return !empty(self::$hooks[$hook]);
    }

    public static function render(\Twig\Environment $twig, string $hook, array $context = []): string
    {
        if (!self::has($hook)) {
            return '';
        }

        $html = '';
        foreach (self::$hooks[$hook] as $template) {
            try {
                $html .= $twig->render($template, $context);
            } catch (\Throwable $e) {
            }
        }

        return $html;
    }
}