<?php
declare(strict_types=1);

namespace Tests\ClientesCore\View;

use PHPUnit\Framework\TestCase;

/**
 * View contract: the client group is mandatory.
 *
 * Creating a client without a group must be blocked client-side (Alpine CSP)
 * so the form is not submitted and the typed data is not lost. The edit view
 * must not offer an empty "sin grupo" option for the client group.
 */
final class ClienteGrupoRequiredViewTest extends TestCase
{
    private function view(string $relative): string
    {
        $path = dirname(__DIR__, 2) . '/view/' . $relative;
        self::assertFileExists($path);

        return (string) file_get_contents($path);
    }

    public function testCreateViewRequiresGroupSelection(): void
    {
        $view = $this->view('ventas_clientes.html.twig');

        self::assertStringContainsString('x-data="clienteGrupoRequired"', $view);
        self::assertStringContainsString("Alpine.data('clienteGrupoRequired'", $view);
        self::assertStringContainsString('name="codgrupo"', $view);
        self::assertStringContainsString('required', $view);
        self::assertStringNotContainsString("trans('sin-grupo')", $view);
    }

    public function testEditViewDisallowsEmptyGroupOption(): void
    {
        $view = $this->view('ventas_cliente.html.twig');

        self::assertStringContainsString('x-data="clienteGrupoRequired"', $view);
        self::assertStringContainsString("Alpine.data('clienteGrupoRequired'", $view);
        self::assertStringNotContainsString("trans('sin-grupo')", $view);
    }

    public function testBothViewsBootAlpineCspBuild(): void
    {
        foreach (['ventas_clientes.html.twig', 'ventas_cliente.html.twig'] as $relative) {
            $view = $this->view($relative);
            self::assertStringContainsString("import 'Macro/Alpine.html.twig' as alpine", $view);
            self::assertStringContainsString('alpine.boot()', $view);
        }
    }

    /**
     * The undeletable default marker must key on the client group code
     * ('000001'), never on the discount code ('000000').
     */
    public function testClientGroupDeleteControlKeysOnClientGroupCode(): void
    {
        $view = $this->view('ventas_clientes.html.twig');

        self::assertStringContainsString("g.codgrupo != '000001'", $view);
        self::assertDoesNotMatchRegularExpression(
            "/codgrupo\\s*!=\\s*'000000'/",
            $view,
            'the protected default must not be compared against the discount code'
        );
    }
}
