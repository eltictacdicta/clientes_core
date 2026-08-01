<?php
/**
 * This file is part of FSFramework originally based on Facturascript 2017
 * Copyright (C) 2026 Javier Trujillo <mistertekcom@gmail.com>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Lesser General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * DEPRECATED: This file is kept for backward compatibility only.
 * New code should use FSFramework\View\ViewHookRegistry directly.
 */

if (!defined('CLIENTES_CORE_VHR_DEPRECATED')) {
    define('CLIENTES_CORE_VHR_DEPRECATED', true);
    trigger_error(
        'plugins/clientes_core/src/ViewHookRegistry.php is deprecated. '
        . 'Use FSFramework\View\ViewHookRegistry instead.',
        E_USER_DEPRECATED
    );
}

require_once dirname(__DIR__, 3) . '/src/View/ViewHookRegistry.php';

class_alias(
    \FSFramework\View\ViewHookRegistry::class,
    \FSFramework\Plugins\clientes_core\ViewHookRegistry::class
);
