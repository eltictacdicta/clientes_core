<?php

declare(strict_types=1);

namespace Tests\ClientesCore\View;

use FSFramework\View\ViewHookRegistry;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Loader\ArrayLoader;
use Twig\Loader\ChainLoader;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFunction;

/**
 * Render-level regression assertion for the shared client form hook (R5).
 *
 * ViewHookRegistry::render() catches \Throwable and only writes to error_log,
 * so a dropped hook is silent. This test renders the real partial through a
 * real Twig Environment and proves the `cliente_form_after_main` hook output
 * appears inside it with the `{fsc, cliente}` context.
 */
final class SharedFormHooksRenderTest extends TestCase
{
    private const PLUGIN_VIEW_DIR = '/plugins/clientes_core/View';
    private const SENTINEL_TEMPLATE = 'sentinel_hook.html.twig';

    protected function setUp(): void
    {
        parent::setUp();

        // ViewHookRegistry keeps static state; reset it so a hook registered
        // by one test method cannot leak into another.
        $ref = new \ReflectionClass(ViewHookRegistry::class);
        $prop = $ref->getProperty('hooks');
        $prop->setAccessible(true);
        $prop->setValue(null, []);
    }

    private function makeEnvironment(): Environment
    {
        $loader = new FilesystemLoader();
        $loader->addPath(FS_FOLDER . self::PLUGIN_VIEW_DIR, 'clientes_core');

        // Inline sentinel: it echoes a fixed marker plus the identity of the
        // context objects it received, so the assertion proves the hook ran
        // with the exact {fsc, cliente} pair and not with empty defaults.
        $sentinel = new ArrayLoader([
            self::SENTINEL_TEMPLATE => 'SENTINEL_HOOK|fsc={{ fsc.sentinel }}|cliente={{ cliente.sentinel }}',
        ]);

        $twig = new Environment(new ChainLoader([$loader, $sentinel]));

        // Stub the Twig functions the partial depends on, mirroring the
        // production registration in src/Core/Html.php.
        $twig->addFunction(new TwigFunction('trans', fn(string $key, array $params = []): string => $key));
        $twig->addFunction(new TwigFunction('csrf_field', fn(): string => '', ['is_safe' => ['html']]));
        $twig->addFunction(new TwigFunction(
            'csp_nonce_attr',
            fn(): string => 'nonce="test-nonce"',
            ['is_safe' => ['html']]
        ));
        $twig->addFunction(new TwigFunction(
            'render_hook',
            function (string $name, array $context = []) use ($twig): string {
                return ViewHookRegistry::render($twig, $name, $context);
            },
            ['is_safe' => ['html']]
        ));

        return $twig;
    }

    private function renderFields(Environment $twig): string
    {
        $fsc = new \stdClass();
        $fsc->sentinel = 'FSC_SENTINEL';

        $cliente = new \stdClass();
        $cliente->sentinel = 'CLIENTE_SENTINEL';

        return $twig->render('@clientes_core/Cliente/Fields.html.twig', [
            'fsc' => $fsc,
            'cliente' => $cliente,
        ]);
    }

    #[Test]
    public function mainHookRendersInsideTheSharedPartial(): void
    {
        ViewHookRegistry::register('cliente_form_after_main', self::SENTINEL_TEMPLATE);

        $html = $this->renderFields($this->makeEnvironment());

        self::assertStringContainsString(
            'SENTINEL_HOOK',
            $html,
            'the registered hook template output must appear inside the shared partial'
        );
        self::assertStringContainsString(
            'fsc=FSC_SENTINEL',
            $html,
            'the hook must receive the fsc context key'
        );
        self::assertStringContainsString(
            'cliente=CLIENTE_SENTINEL',
            $html,
            'the hook must receive the cliente context key'
        );
    }

    #[Test]
    public function unregisteredHookRendersNothingAndDoesNotBreakThePartial(): void
    {
        $html = $this->renderFields($this->makeEnvironment());

        self::assertStringNotContainsString('SENTINEL_HOOK', $html);
        // The partial still renders its identity field set.
        self::assertStringContainsString('name="nombre"', $html);
    }
}
