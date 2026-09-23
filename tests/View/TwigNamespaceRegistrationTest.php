<?php

declare(strict_types=1);

namespace Tests\ClientesCore\View;

use FSFramework\Event\FSEventDispatcher;
use FSFramework\Event\TwigLoaderEvent;
use FSFramework\Plugins\clientes_core\Init;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Twig\Loader\FilesystemLoader;

/**
 * The plugin exposes its templates under the `clientes_core` Twig namespace
 * through a TwigLoaderEvent listener, following the clientes_catalogo
 * precedent (no core change).
 */
final class TwigNamespaceRegistrationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        FSEventDispatcher::reset();
    }

    #[Test]
    public function initRegistersThePluginViewNamespace(): void
    {
        (new Init())->init();

        $loader = new FilesystemLoader();
        FSEventDispatcher::getInstance()->dispatch(new TwigLoaderEvent($loader), TwigLoaderEvent::NAME);

        $expected = realpath(FS_FOLDER . '/plugins/clientes_core/View');
        $paths = array_map('realpath', $loader->getPaths('clientes_core'));

        self::assertNotFalse($expected, 'the plugin View directory must exist');
        self::assertCount(1, $paths, 'exactly one path must be added to the namespace');
        self::assertContains(
            $expected,
            $paths,
            'Init must add the plugin View directory under the clientes_core namespace'
        );
    }

    #[Test]
    public function namespaceIsEmptyWithoutInit(): void
    {
        // Negative control: the namespace comes from Init's listener, not from
        // a global default, so dispatching without init() leaves it empty.
        $loader = new FilesystemLoader();
        FSEventDispatcher::getInstance()->dispatch(new TwigLoaderEvent($loader), TwigLoaderEvent::NAME);

        self::assertSame([], $loader->getPaths('clientes_core'));
    }
}
