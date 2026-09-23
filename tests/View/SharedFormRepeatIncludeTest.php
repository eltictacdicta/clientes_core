<?php

declare(strict_types=1);

namespace Tests\ClientesCore\View;

use FSFramework\View\ViewHookRegistry;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFunction;

/**
 * The shared client form is parameterized by `id_prefix` so it is safe to
 * include more than once per page (R7), and its Alpine bootstrap stays
 * CSP-compatible and idempotent (R6).
 */
final class SharedFormRepeatIncludeTest extends TestCase
{
    private const PLUGIN_VIEW_DIR = '/plugins/clientes_core/View';

    protected function setUp(): void
    {
        parent::setUp();

        $ref = new \ReflectionClass(ViewHookRegistry::class);
        $prop = $ref->getProperty('hooks');
        $prop->setAccessible(true);
        $prop->setValue(null, []);
    }

    private function makeEnvironment(): Environment
    {
        $loader = new FilesystemLoader();
        $loader->addPath(FS_FOLDER . self::PLUGIN_VIEW_DIR, 'clientes_core');

        $twig = new Environment($loader);
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

    private function cliente(): \stdClass
    {
        $cliente = new \stdClass();
        $cliente->codcliente = '000001';
        $cliente->nombre = 'Acme';
        $cliente->codgrupo = '000001';
        $cliente->codgrupo_descuento = '000000';

        return $cliente;
    }

    private function renderFields(Environment $twig, string $prefix): string
    {
        return $twig->render('@clientes_core/Cliente/Fields.html.twig', [
            'fsc' => new \stdClass(),
            'cliente' => $this->cliente(),
            'grupos' => [(object) ['codgrupo' => '000001', 'nombre' => 'General']],
            'regimenes_iva' => [],
            'id_prefix' => $prefix,
        ]);
    }

    private function renderDiscounts(Environment $twig, string $prefix): string
    {
        return $twig->render('@clientes_core/Cliente/Discounts.html.twig', [
            'cliente' => $this->cliente(),
            'grupos_descuentos' => [(object) ['codgrupo_descuento' => '000000', 'nombre' => 'Personalizado']],
            'id_prefix' => $prefix,
        ]);
    }

    #[Test]
    public function repeatedInclusionDoesNotCollide(): void
    {
        $twig = $this->makeEnvironment();
        $html = $this->renderFields($twig, 'first')
            . $this->renderDiscounts($twig, 'first')
            . $this->renderFields($twig, 'second')
            . $this->renderDiscounts($twig, 'second');

        preg_match_all('/\sid="([^"]+)"/', $html, $matches);
        $ids = $matches[1];

        self::assertNotEmpty($ids, 'the partial must emit prefixed DOM ids');
        self::assertSame(
            count($ids),
            count(array_unique($ids)),
            'repeated inclusion with different id_prefix must not collide DOM ids'
        );
        self::assertContains('first_codgrupo', $ids);
        self::assertContains('second_codgrupo', $ids);
        self::assertContains('first_grupo_descuento', $ids);
        self::assertContains('second_grupo_descuento', $ids);
    }

    #[Test]
    public function mandatoryGroupSelectsCarryRequiredAndModel(): void
    {
        $twig = $this->makeEnvironment();
        $html = $this->renderFields($twig, 'solo') . $this->renderDiscounts($twig, 'solo');

        self::assertMatchesRegularExpression(
            '/<select name="codgrupo"[^>]*x-model="codgrupo"[^>]*required/',
            $html,
            'the client-group select must be bound to Alpine and mandatory'
        );
        self::assertMatchesRegularExpression(
            '/<select name="codgrupo_descuento"[^>]*x-model="codgrupoDescuento"[^>]*required/',
            $html,
            'the discount-group select must be bound to Alpine and mandatory'
        );

        // No selectable empty option: every empty option is disabled.
        preg_match_all('/<option value=""[^>]*>/', $html, $matches);
        self::assertNotEmpty($matches[0]);
        foreach ($matches[0] as $option) {
            self::assertStringContainsString(
                'disabled',
                $option,
                'an empty option must never be selectable'
            );
        }
    }

    #[Test]
    public function scriptsRemainCspCompatibleAndIdempotent(): void
    {
        $twig = $this->makeEnvironment();
        $first = $this->renderFields($twig, 'first');
        $second = $this->renderFields($twig, 'second');

        // Each inclusion declares the component exactly once; the shared
        // window marker guard makes any later inclusion a runtime no-op, so
        // the component is registered exactly once per page.
        self::assertSame(1, substr_count($first, "Alpine.data('clienteGrupoRequired'"));
        self::assertSame(1, substr_count($second, "Alpine.data('clienteGrupoRequired'"));

        self::assertStringContainsString('window.__clientesCoreGrupoRequiredRegistered === true', $first);
        self::assertStringContainsString('window.__clientesCoreGrupoRequiredRegistered === true', $second);

        // CSP: nonce'd classic script, no unsafe-eval, no eval helpers.
        self::assertStringContainsString('nonce="test-nonce"', $first);
        self::assertStringNotContainsString('unsafe-eval', $first . $second);
        self::assertStringNotContainsString('eval(', $first . $second);
        self::assertStringNotContainsString('new Function(', $first . $second);
    }
}
