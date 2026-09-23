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
 * View contract: both clientes_core surfaces render the shared client form.
 *
 * The client group and the discount group are mandatory, so creating a client
 * without them is blocked client-side (Alpine CSP build) as well as
 * server-side by `cliente::test()`. This test binds the parameterization
 * contract (create vs edit mode, `id_prefix`, DOM id ownership) of the shared
 * partial added in Slice 2b and asserts that neither consumer keeps a private
 * copy of the field markup or the Alpine registration script.
 */
final class ClienteGrupoRequiredViewTest extends TestCase
{
    private const PLUGIN_VIEW_DIR = '/plugins/clientes_core/View';
    private const SHARED_FORM = '@clientes_core/Cliente/Form.html.twig';

    protected function setUp(): void
    {
        parent::setUp();

        // ViewHookRegistry keeps static state; reset it so a registered hook
        // cannot leak between test methods.
        $ref = new \ReflectionClass(ViewHookRegistry::class);
        $prop = $ref->getProperty('hooks');
        $prop->setAccessible(true);
        $prop->setValue(null, []);
    }

    private function view(string $relative): string
    {
        $path = dirname(__DIR__, 2) . '/view/' . $relative;
        self::assertFileExists($path);

        return (string) file_get_contents($path);
    }

    private function partial(string $relative): string
    {
        $path = FS_FOLDER . self::PLUGIN_VIEW_DIR . '/' . $relative;
        self::assertFileExists($path);

        return (string) file_get_contents($path);
    }

    private function makeEnvironment(): Environment
    {
        $loader = new FilesystemLoader();
        $loader->addPath(FS_FOLDER . self::PLUGIN_VIEW_DIR, 'clientes_core');

        return $this->addStubs(new Environment($loader));
    }

    /**
     * Environment for the real consumer views: the theme header/footer and the
     * Alpine macro are stubbed, but `ventas_cliente.html.twig` and
     * `ventas_clientes.html.twig` are loaded from disk and rendered with the
     * shared partial, so a broken include or a Twig syntax error is caught.
     */
    private function makeConsumerEnvironment(): Environment
    {
        $stubs = new ArrayLoader([
            'header.html.twig' => '',
            'footer.html.twig' => '',
            'Macro/Alpine.html.twig' => '{% macro boot() %}{% endmacro %}',
        ]);

        $consumerLoader = new FilesystemLoader(dirname(__DIR__, 2) . '/view');
        $pluginLoader = new FilesystemLoader();
        $pluginLoader->addPath(FS_FOLDER . self::PLUGIN_VIEW_DIR, 'clientes_core');

        return $this->addStubs(new Environment(new ChainLoader([$stubs, $consumerLoader, $pluginLoader])));
    }

    private function addStubs(Environment $twig): Environment
    {
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
        $cliente->razonsocial = 'Acme S.L.';
        $cliente->codgrupo = '000001';
        $cliente->codgrupo_descuento = '000000';

        return $cliente;
    }

    /**
     * The consumer views call `cliente.url()`; a lightweight stub keeps the
     * render harness free of the DB-backed model.
     */
    private function clienteWithUrl(): object
    {
        $cliente = new \Tests\ClientesCore\View\ConsumerClienteStub();
        $cliente->codcliente = '000001';
        $cliente->nombre = 'Acme';
        $cliente->razonsocial = 'Acme S.L.';
        $cliente->cifnif = 'B12345678';
        $cliente->tipoidfiscal = 'CIF';
        $cliente->personafisica = false;
        $cliente->telefono1 = '';
        $cliente->telefono2 = '';
        $cliente->fax = '';
        $cliente->email = '';
        $cliente->web = '';
        $cliente->codgrupo = '000001';
        $cliente->regimeniva = '';
        $cliente->recargo = false;
        $cliente->diaspago = '';
        $cliente->fechaalta = '2026-01-01';
        $cliente->debaja = false;
        $cliente->observaciones = '';
        $cliente->codgrupo_descuento = '000000';
        $cliente->descuentos_modified = false;
        $cliente->d1 = 0;
        $cliente->d2 = 0;
        $cliente->d3 = 0;
        $cliente->d4 = 0;

        return $cliente;
    }

    /**
     * @param array<string, mixed> $context
     */
    private function renderForm(array $context): string
    {
        return $this->makeEnvironment()->render(self::SHARED_FORM, array_merge([
            'fsc' => new \stdClass(),
            'grupos' => [(object) ['codgrupo' => '000001', 'nombre' => 'General']],
            'grupos_descuentos' => [(object) ['codgrupo_descuento' => '000000', 'nombre' => 'Personalizado']],
            'regimenes_iva' => [],
            'id_prefix' => 'test_cliente',
        ], $context));
    }

    #[Test]
    public function bothConsumerViewsRenderTheSharedForm(): void
    {
        foreach (['ventas_clientes.html.twig', 'ventas_cliente.html.twig'] as $relative) {
            $view = $this->view($relative);

            self::assertStringContainsString(
                self::SHARED_FORM,
                $view,
                $relative . ' must render the shared client form partial'
            );
            self::assertSame(
                1,
                substr_count($view, self::SHARED_FORM),
                $relative . ' must include the shared partial exactly once'
            );
            self::assertStringContainsString(
                'x-data="clienteGrupoRequired"',
                $view,
                $relative . ' must carry the Alpine component root on its form'
            );
        }
    }

    #[Test]
    public function consumersKeepNoDuplicateFieldSet(): void
    {
        // The identity field markup and the discount columns now live only in
        // the shared partial (R7): a consumer that still emits them would keep
        // a private second copy of the client form.
        foreach (['ventas_clientes.html.twig', 'ventas_cliente.html.twig'] as $relative) {
            $view = $this->view($relative);

            foreach (['name="nombre"', 'name="razonsocial"', 'name="cifnif"', 'name="d1"'] as $field) {
                self::assertStringNotContainsString(
                    $field,
                    $view,
                    $relative . ' must not keep its own copy of ' . $field
                );
            }
        }
    }

    #[Test]
    public function createModeRendersEmptyEntityAgainstTheCreateAction(): void
    {
        $html = $this->renderForm([
            'mode' => 'create',
            'action_name' => 'nuevo_cliente',
            'cliente' => null,
        ]);

        self::assertStringContainsString(
            '<input type="hidden" name="action" value="nuevo_cliente"',
            $html,
            'create mode must post the create action'
        );
        self::assertStringContainsString(
            'name="nombre" value=""',
            $html,
            'create mode must render an empty entity'
        );
        self::assertMatchesRegularExpression(
            '/<option value=""[^>]*selected[^>]*>— seleccione-grupo —/',
            $html,
            'create mode must not preselect a client group'
        );
        self::assertStringNotContainsString(
            '<input type="hidden" name="codcliente"',
            $html,
            'create mode with no entity must not post a codcliente'
        );
    }

    #[Test]
    public function editModeRendersPersistedEntityAgainstTheEditAction(): void
    {
        $html = $this->renderForm([
            'mode' => 'edit',
            'action_name' => 'save_cliente',
            'cliente' => $this->cliente(),
        ]);

        self::assertStringContainsString(
            '<input type="hidden" name="action" value="save_cliente"',
            $html,
            'edit mode must post the edit action'
        );
        self::assertStringContainsString(
            '<input type="hidden" name="codcliente" value="000001"',
            $html,
            'edit mode must post the persisted codcliente'
        );
        self::assertStringContainsString(
            'name="nombre" value="Acme"',
            $html,
            'edit mode must render the persisted values'
        );
    }

    #[Test]
    public function partialNamespacesIdsByPrefix(): void
    {
        $html = $this->renderForm([
            'mode' => 'edit',
            'cliente' => $this->cliente(),
            'id_prefix' => 'edit_cliente',
        ]);

        foreach (['edit_cliente_codcliente', 'edit_cliente_codgrupo', 'edit_cliente_grupo_descuento'] as $id) {
            self::assertStringContainsString(
                'id="' . $id . '"',
                $html,
                'the partial must namespace ids by id_prefix: ' . $id
            );
        }
    }

    #[Test]
    public function bothGroupSelectsCarryRequiredAndAlpineBindings(): void
    {
        $html = $this->renderForm([
            'mode' => 'edit',
            'cliente' => $this->cliente(),
        ]);

        self::assertMatchesRegularExpression(
            '/<select\s+name="codgrupo"[^>]*x-model="codgrupo"[^>]*required/',
            $html,
            'the client-group select must be bound to Alpine and mandatory'
        );
        self::assertMatchesRegularExpression(
            '/<select\s+name="codgrupo_descuento"[^>]*x-model="codgrupoDescuento"[^>]*required/',
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
                'an empty group option must never be selectable'
            );
        }
    }

    #[Test]
    public function alpineRegistrationIsIdempotentAndNonced(): void
    {
        $html = $this->renderForm([
            'mode' => 'edit',
            'cliente' => $this->cliente(),
        ]);

        self::assertSame(
            1,
            substr_count($html, "Alpine.data('clienteGrupoRequired'"),
            'the shared partial must register the Alpine component exactly once'
        );
        self::assertStringContainsString('window.__clientesCoreGrupoRequiredRegistered === true', $html);
        self::assertStringContainsString('nonce="test-nonce"', $html);
        self::assertStringNotContainsString('unsafe-eval', $html);
        self::assertStringNotContainsString('new Function(', $html);
    }

    #[Test]
    public function externalSubmitModeRendersAButtonWithConsumerAttrs(): void
    {
        $html = $this->renderForm([
            'mode' => 'edit',
            'cliente' => $this->cliente(),
            'submit_mode' => 'external',
            'submit_attrs' => ['onclick' => 'tpvmodGuardarCliente()'],
        ]);

        // The TPV composes the partial inside its own form and submits through
        // its own JS, so the shared button must not submit natively (design §6.2).
        self::assertMatchesRegularExpression(
            '/<button[^>]*type="button"[^>]*onclick="tpvmodGuardarCliente\(\)"/',
            $html,
            'external submit mode must render a button, not a native submit'
        );
        self::assertStringNotContainsString(
            'type="submit"',
            $html,
            'external submit mode must not render a native submit button'
        );
    }

    #[Test]
    public function sharedPartialHasNoAddressEditor(): void
    {
        $html = $this->renderForm([
            'mode' => 'edit',
            'cliente' => $this->cliente(),
        ]);

        foreach (['name="codpais"', 'name="direccion"', 'name="codpostal"'] as $addressField) {
            self::assertStringNotContainsString(
                $addressField,
                $html,
                'the shared partial must not render an address editor (Q5): ' . $addressField
            );
        }
        self::assertStringNotContainsString("cliente_direccion_form_after_codpais", $html);
    }

    #[Test]
    public function clientesCoreKeepsItsOwnAddressPanelAndHook(): void
    {
        $view = $this->view('ventas_cliente.html.twig');

        self::assertStringContainsString('id="modal_nueva_dir"', $view);
        self::assertStringContainsString(
            "render_hook('cliente_direccion_form_after_codpais'",
            $view,
            'the address hook must stay in the consumer address editor'
        );
        self::assertStringContainsString('<input type="hidden" name="action" value="save_dir" />', $view);
    }

    #[Test]
    public function sharedPartialsDoNotHardCodeConsumerIds(): void
    {
        foreach (['Cliente/Form.html.twig', 'Cliente/Fields.html.twig', 'Cliente/Discounts.html.twig'] as $relative) {
            $partial = $this->partial($relative);

            foreach (['modal_cliente_form', 'f_cliente_tpv', 'form_edit_cliente', 'form_reset_descuentos'] as $forbidden) {
                self::assertStringNotContainsString(
                    $forbidden,
                    $partial,
                    $relative . ' must not hard-code the consumer id ' . $forbidden
                );
            }
            self::assertStringNotContainsString(
                'id="btn_reset_descuentos"',
                $partial,
                $relative . ' must namespace the reset button id with id_prefix'
            );
        }
    }

    #[Test]
    public function noLegacySinGrupoCopyRemains(): void
    {
        foreach (['ventas_clientes.html.twig', 'ventas_cliente.html.twig'] as $relative) {
            self::assertStringNotContainsString("trans('sin-grupo')", $this->view($relative));
        }
    }

    #[Test]
    public function bothConsumerPagesStillBootAlpineCspBuild(): void
    {
        foreach (['ventas_clientes.html.twig', 'ventas_cliente.html.twig'] as $relative) {
            $view = $this->view($relative);
            self::assertStringContainsString("import 'Macro/Alpine.html.twig' as alpine", $view);
            self::assertStringContainsString('alpine.boot()', $view);
        }
    }

    #[Test]
    public function editConsumerRendersTheSharedPartialEndToEnd(): void
    {
        $fsc = new ConsumerFscStub();
        $fsc->cliente = $this->clienteWithUrl();
        $fsc->direcciones = [];
        $fsc->allow_delete = false;
        $fsc->grupos = [(object) ['codgrupo' => '000001', 'nombre' => 'General']];
        $fsc->grupos_descuentos = [(object) ['codgrupo_descuento' => '000000', 'nombre' => 'Personalizado']];
        $fsc->regimenes_iva = [];

        $html = $this->makeConsumerEnvironment()->render('ventas_cliente.html.twig', ['fsc' => $fsc]);

        self::assertStringContainsString(
            'id="edit_cliente_codgrupo"',
            $html,
            'the edit page must render the shared client-group select'
        );
        self::assertStringContainsString('id="edit_cliente_grupo_descuento"', $html);
        self::assertStringContainsString(
            '<input type="hidden" name="action" value="save_cliente"',
            $html,
            'the edit page must render the shared edit action'
        );
        self::assertStringContainsString(
            'id="modal_nueva_dir"',
            $html,
            'the consumer address modal must survive the rewire (Q5)'
        );
    }

    #[Test]
    public function createConsumerRendersTheSharedPartialEndToEnd(): void
    {
        $fsc = new ConsumerFscStub();
        $fsc->grupo = false;
        $fsc->query = '';
        $fsc->orden = 'lower(nombre) ASC';
        $fsc->total = 0;
        $fsc->clientes = [];
        $fsc->paginas = [];
        $fsc->allow_delete = false;
        $fsc->grupos = [(object) ['codgrupo' => '000001', 'nombre' => 'General']];
        $fsc->grupos_descuentos = [(object) ['codgrupo_descuento' => '000000', 'nombre' => 'Personalizado']];
        $fsc->regimenes_iva = [];

        $html = $this->makeConsumerEnvironment()->render('ventas_clientes.html.twig', ['fsc' => $fsc]);

        self::assertStringContainsString('id="modal_nuevo_cliente"', $html);
        self::assertStringContainsString(
            'id="nuevo_cliente_codgrupo"',
            $html,
            'the create modal must render the shared client-group select'
        );
        self::assertStringContainsString(
            'id="nuevo_cliente_grupo_descuento"',
            $html,
            'the create modal must render the mandatory discount group (design §5.2)'
        );
        self::assertStringContainsString(
            '<input type="hidden" name="action" value="nuevo_cliente"',
            $html,
            'the create modal must render the shared create action'
        );
    }

    /**
     * The undeletable default marker must key on the client group code
     * ('000001'), never on the discount code ('000000').
     */
    #[Test]
    public function clientGroupDeleteControlKeysOnClientGroupCode(): void
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

/**
 * `cliente` stub for the consumer render harness: the views call
 * `cliente.url()`, so a method is required; dynamic properties keep the stub
 * free of the DB-backed model.
 */
#[\AllowDynamicProperties]
final class ConsumerClienteStub
{
    public function url(): string
    {
        return 'index.php?page=ventas_cliente&cod=000001';
    }
}

/**
 * `fsc` stub for the consumer render harness: both consumer views call
 * `fsc.url()`.
 */
#[\AllowDynamicProperties]
final class ConsumerFscStub
{
    public function url(): string
    {
        return 'index.php?page=ventas_clientes';
    }
}
