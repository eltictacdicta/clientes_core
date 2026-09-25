<?php
/**
 * Tests for the shared client-form PHP authority.
 *
 * Covers the delta scenarios:
 *   shared-client-form -> "Single shared PHP apply, validate and diff authority"
 *   shared-client-form -> "Mandatory-group validation is triggered through test()"
 *
 * The authority is a pure static helper: it maps a submission onto a cliente,
 * computes the descuentos_modified diff and delegates the mandatory-group gate
 * to cliente::test(). These tests use lightweight doubles so no DB is touched.
 *
 * Process isolation: each test runs in its own process to avoid class
 * redeclaration conflicts between the grouped double and the real model.
 */

declare(strict_types=1);

namespace Tests\ClientesCore;

use FSFramework\Plugins\clientes_core\ClienteForm;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Discount-group double registered as FSFramework\model\grupo_descuentos so
 * ClienteForm::apply() loads it without instantiating the DB-backed model.
 */
final class ClienteFormGrupoDouble
{
    public $codgrupo_descuento;
    public $nombre;
    public $d1;
    public $d2;
    public $d3;
    public $d4;

    /** @var array<string, ClienteFormGrupoDouble> */
    public static array $groups = [];

    public function __construct($data = false)
    {
    }

    public function get($cod)
    {
        return self::$groups[$cod] ?? false;
    }
}

/**
 * Cliente double declaring every field the authority maps.
 */
final class ClienteFormClienteDouble
{
    public $codcliente = '000001';
    public $nombre = '';
    public $razonsocial = '';
    public $tipoidfiscal = '';
    public $cifnif = '';
    public $telefono1 = '';
    public $telefono2 = '';
    public $fax = '';
    public $email = '';
    public $web = '';
    public $regimeniva = '';
    public $diaspago = '';
    public $observaciones = '';
    public $coddivisa;
    public $recargo = false;
    public $personafisica = false;
    public $debaja = false;
    public $codgrupo;
    public $codgrupo_descuento;
    public $d1;
    public $d2;
    public $d3;
    public $d4;
    public $descuentos_modified = false;

    public bool $testResult = true;
    /** @var array<int, string> */
    public array $errors = [];
    public int $testCalls = 0;
    /** @var array<int, object> Groups passed to applyGroupDiscounts(). */
    public array $appliedGroups = [];

    public function test(): bool
    {
        $this->testCalls++;
        return $this->testResult;
    }

    public function applyGroupDiscounts(object $grupoDescuentos): void
    {
        $this->appliedGroups[] = $grupoDescuentos;
        foreach (['d1', 'd2', 'd3', 'd4'] as $field) {
            $this->{$field} = (float) $grupoDescuentos->{$field};
        }
        $this->descuentos_modified = false;
    }

    /** @return array<int, string> */
    public function get_errors(): array
    {
        return $this->errors;
    }
}

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class ClienteFormAuthorityTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (!class_exists('FSFramework\\model\\grupo_descuentos', false)) {
            class_alias(ClienteFormGrupoDouble::class, 'FSFramework\\model\\grupo_descuentos');
        }

        ClienteFormGrupoDouble::$groups = [];
    }

    // -------------------------------------------------------------------------
    // apply() — selective mapping
    // -------------------------------------------------------------------------

    /**
     * An absent key must leave the target field untouched, so partial updates
     * (change_grupo, reset_descuentos) keep working.
     */
    #[Test]
    public function applyLeavesAbsentFieldsUntouched(): void
    {
        $cliente = new ClienteFormClienteDouble();
        $cliente->nombre = 'Persisted';
        $cliente->cifnif = 'B00000000';
        $cliente->telefono1 = '600000000';
        $cliente->email = 'persisted@example.com';
        $cliente->regimeniva = 'General';
        $cliente->diaspago = '30';
        $cliente->observaciones = 'keep me';
        $cliente->recargo = true;
        $cliente->coddivisa = 'EUR';
        $cliente->codgrupo = '000001';
        $cliente->codgrupo_descuento = '000001';
        $cliente->d1 = 10.0;

        ClienteForm::apply($cliente, ['nombre' => 'Updated']);

        $this->assertSame('Updated', $cliente->nombre);
        $this->assertSame('B00000000', $cliente->cifnif);
        $this->assertSame('600000000', $cliente->telefono1);
        $this->assertSame('persisted@example.com', $cliente->email);
        $this->assertSame('General', $cliente->regimeniva);
        $this->assertSame('30', $cliente->diaspago);
        $this->assertSame('keep me', $cliente->observaciones);
        $this->assertTrue($cliente->recargo);
        $this->assertSame('EUR', $cliente->coddivisa);
        $this->assertSame('000001', $cliente->codgrupo);
        $this->assertSame('000001', $cliente->codgrupo_descuento);
        $this->assertSame(10.0, $cliente->d1);
    }

    /**
     * Both group keys map '' to null. That is the whole conflation fix: an
     * empty selection never becomes a real group code.
     */
    #[Test]
    public function applyMapsEmptyGroupCodesToNull(): void
    {
        $cliente = new ClienteFormClienteDouble();
        $cliente->codgrupo = '000001';
        $cliente->codgrupo_descuento = '000000';

        ClienteForm::apply($cliente, ['codgrupo' => '', 'codgrupo_descuento' => '']);

        $this->assertNull($cliente->codgrupo);
        $this->assertNull($cliente->codgrupo_descuento);
    }

    /**
     * A non-empty group key is stored trimmed and no fallback code is invented.
     */
    #[Test]
    public function applyNeverWritesFallbackGroupCodes(): void
    {
        $cliente = new ClienteFormClienteDouble();

        ClienteForm::apply($cliente, ['codgrupo' => '  000042  ', 'codgrupo_descuento' => '000007']);

        $this->assertSame('000042', $cliente->codgrupo);
        $this->assertSame('000007', $cliente->codgrupo_descuento);
        $this->assertNotSame('000000', $cliente->codgrupo);
    }

    /**
     * TEXT_FIELDS are copied verbatim, BOOL_FIELDS read '1', and
     * NULLABLE_TEXT_FIELDS turn '' into null.
     */
    #[Test]
    public function applyMapsTextBoolAndNullableTextFields(): void
    {
        $cliente = new ClienteFormClienteDouble();

        ClienteForm::apply($cliente, [
            'tipoidfiscal' => 'NIF',
            'web' => 'https://example.com',
            'observaciones' => 'note',
            'recargo' => '1',
            'personafisica' => '0',
            'debaja' => '1',
            'coddivisa' => '',
        ]);

        $this->assertSame('NIF', $cliente->tipoidfiscal);
        $this->assertSame('https://example.com', $cliente->web);
        $this->assertSame('note', $cliente->observaciones);
        $this->assertTrue($cliente->recargo);
        $this->assertFalse($cliente->personafisica);
        $this->assertTrue($cliente->debaja);
        $this->assertNull($cliente->coddivisa);
    }

    /**
     * Triangulation for NULLABLE_TEXT_FIELDS: a non-empty value is kept.
     */
    #[Test]
    public function applyKeepsNonEmptyNullableTextField(): void
    {
        $cliente = new ClienteFormClienteDouble();

        ClienteForm::apply($cliente, ['coddivisa' => 'USD']);

        $this->assertSame('USD', $cliente->coddivisa);
    }

    /**
     * Discount columns are cast to float so the diff compares numbers.
     */
    #[Test]
    public function applyCastsDiscountFieldsToFloat(): void
    {
        $cliente = new ClienteFormClienteDouble();

        ClienteForm::apply($cliente, ['d1' => '10.50', 'd2' => '0', 'd3' => '2', 'd4' => '1.25']);

        $this->assertSame(10.5, $cliente->d1);
        $this->assertSame(0.0, $cliente->d2);
        $this->assertSame(2.0, $cliente->d3);
        $this->assertSame(1.25, $cliente->d4);
    }

    // -------------------------------------------------------------------------
    // computeDescuentosModified() — the single diff implementation
    // -------------------------------------------------------------------------

    /**
     * Inequality is detected and equality is not, compared rounded to 2 decimals.
     */
    #[Test]
    public function computeDescuentosModifiedComparesRoundedValues(): void
    {
        $cliente = new ClienteFormClienteDouble();
        $cliente->d1 = 10.004;
        $cliente->d2 = 5.0;
        $cliente->d3 = 0.0;
        $cliente->d4 = 0.0;

        $grupo = new ClienteFormGrupoDouble();
        $grupo->d1 = 10.0;
        $grupo->d2 = 5.0;
        $grupo->d3 = 0.0;
        $grupo->d4 = 0.0;

        $this->assertFalse(ClienteForm::computeDescuentosModified($cliente, $grupo));

        $cliente->d4 = 0.01;
        $this->assertTrue(ClienteForm::computeDescuentosModified($cliente, $grupo));
    }

    /**
     * With no group there is nothing to differ from, so the flag is false even
     * when the client discount columns are populated.
     */
    #[Test]
    public function computeDescuentosModifiedReturnsFalseWithoutGroup(): void
    {
        $cliente = new ClienteFormClienteDouble();
        $cliente->d1 = 99.0;
        $cliente->d2 = 88.0;
        $cliente->d3 = 77.0;
        $cliente->d4 = 66.0;

        $this->assertFalse(ClienteForm::computeDescuentosModified($cliente, null));
    }

    /**
     * An unset (NULL) group discount and a 0.00 client discount mean the same
     * thing — no discount — so the diff must treat them as equal. Otherwise the
     * "Personalizado" group (NULL d1-d4) would flag every client as modified
     * right after its defaults are applied.
     */
    #[Test]
    public function computeDescuentosModifiedTreatsNullAndZeroAsEqual(): void
    {
        $cliente = new ClienteFormClienteDouble();
        $cliente->d1 = 0.0;
        $cliente->d2 = 0.0;
        $cliente->d3 = 0.0;
        $cliente->d4 = 0.0;

        $grupo = new ClienteFormGrupoDouble();
        $grupo->d1 = null;
        $grupo->d2 = null;
        $grupo->d3 = null;
        $grupo->d4 = null;

        $this->assertFalse(ClienteForm::computeDescuentosModified($cliente, $grupo));

        $grupo->d1 = 5.0;
        $this->assertTrue(ClienteForm::computeDescuentosModified($cliente, $grupo));
    }

    /**
     * apply() loads the selected group and sets descuentos_modified from the
     * single diff implementation (scenario: "Descuentos diff is computed in one
     * place"). The client is already in the group, so the submitted d1-d4 are
     * per-client overrides and survive the mapping.
     */
    #[Test]
    public function applySetsDescuentosModifiedFromLoadedGroup(): void
    {
        $grupo = new ClienteFormGrupoDouble();
        $grupo->codgrupo_descuento = '000001';
        $grupo->d1 = 10.0;
        $grupo->d2 = 5.0;
        $grupo->d3 = 0.0;
        $grupo->d4 = 0.0;
        ClienteFormGrupoDouble::$groups['000001'] = $grupo;

        $cliente = new ClienteFormClienteDouble();
        $cliente->codgrupo_descuento = '000001';
        $cliente->d2 = 5.0;
        $cliente->d3 = 0.0;
        $cliente->d4 = 0.0;
        ClienteForm::apply($cliente, ['codgrupo_descuento' => '000001', 'd1' => '15.00']);

        $this->assertSame('000001', $cliente->codgrupo_descuento);
        $this->assertSame(15.0, $cliente->d1);
        $this->assertTrue($cliente->descuentos_modified);

        $matching = new ClienteFormClienteDouble();
        $matching->codgrupo_descuento = '000001';
        $matching->d2 = 5.0;
        $matching->d3 = 0.0;
        $matching->d4 = 0.0;
        ClienteForm::apply($matching, ['codgrupo_descuento' => '000001', 'd1' => '10.00']);

        $this->assertFalse($matching->descuentos_modified);
    }

    /**
     * Spec client-discount-inheritance: "Group change overwrites client
     * discounts" — switching from one non-null group to another must copy the
     * new group's d1-d4 and clear descuentos_modified, discarding stale form
     * values from the previous group.
     */
    #[Test]
    public function applyOverwritesDiscountsWhenGroupChanges(): void
    {
        $old = new ClienteFormGrupoDouble();
        $old->codgrupo_descuento = '000001';
        $old->d1 = 10.0;
        $old->d2 = 5.0;
        $old->d3 = 0.0;
        $old->d4 = 0.0;
        ClienteFormGrupoDouble::$groups['000001'] = $old;

        $new = new ClienteFormGrupoDouble();
        $new->codgrupo_descuento = '000002';
        $new->d1 = 20.0;
        $new->d2 = 10.0;
        $new->d3 = 5.0;
        $new->d4 = 0.0;
        ClienteFormGrupoDouble::$groups['000002'] = $new;

        $cliente = new ClienteFormClienteDouble();
        $cliente->codgrupo_descuento = '000001';
        $cliente->d1 = 15.0;
        $cliente->d2 = 8.0;
        $cliente->d3 = 0.0;
        $cliente->d4 = 0.0;
        $cliente->descuentos_modified = true;

        ClienteForm::apply($cliente, [
            'codgrupo_descuento' => '000002',
            // Stale values from the old group / manual edits: must be discarded.
            'd1' => '15.00',
            'd2' => '8.00',
            'd3' => '0',
            'd4' => '0',
        ]);

        $this->assertSame('000002', $cliente->codgrupo_descuento);
        $this->assertSame(20.0, $cliente->d1);
        $this->assertSame(10.0, $cliente->d2);
        $this->assertSame(5.0, $cliente->d3);
        $this->assertSame(0.0, $cliente->d4);
        $this->assertFalse($cliente->descuentos_modified);
        $this->assertCount(1, $cliente->appliedGroups);
    }

    /**
     * First assignment (null -> code) is a discount-group change, so it must
     * copy the group's values and clear the modified flag. Spec
     * client-discount-inheritance: "Assigning a group copies discounts to
     * client".
     */
    #[Test]
    public function applyOverwritesDiscountsOnFirstAssignment(): void
    {
        $grupo = new ClienteFormGrupoDouble();
        $grupo->codgrupo_descuento = '000001';
        $grupo->d1 = 10.0;
        $grupo->d2 = 5.0;
        $grupo->d3 = 0.0;
        $grupo->d4 = 0.0;
        ClienteFormGrupoDouble::$groups['000001'] = $grupo;

        $cliente = new ClienteFormClienteDouble();
        $this->assertNull($cliente->codgrupo_descuento);

        ClienteForm::apply($cliente, [
            'codgrupo_descuento' => '000001',
            // Stale/default form values must not survive the assignment.
            'd1' => '15.00',
            'd2' => '5.00',
            'd3' => '0',
            'd4' => '0',
        ]);

        $this->assertSame('000001', $cliente->codgrupo_descuento);
        $this->assertSame(10.0, $cliente->d1);
        $this->assertSame(5.0, $cliente->d2);
        $this->assertSame(0.0, $cliente->d3);
        $this->assertSame(0.0, $cliente->d4);
        $this->assertFalse($cliente->descuentos_modified);
        $this->assertCount(1, $cliente->appliedGroups);
    }

    // -------------------------------------------------------------------------
    // validate() / applyAndValidate() — the mandatory-group gate
    // -------------------------------------------------------------------------

    /**
     * validate() is the mandatory-group gate and delegates to cliente::test():
     * the double returns false even though its group codes are populated, which
     * proves the authority does not reimplement the check.
     */
    #[Test]
    public function validateDelegatesToClienteTest(): void
    {
        $cliente = new ClienteFormClienteDouble();
        $cliente->codgrupo = '000001';
        $cliente->codgrupo_descuento = '000001';
        $cliente->testResult = false;

        $this->assertFalse(ClienteForm::validate($cliente));
        $this->assertSame(1, $cliente->testCalls);

        $cliente->testResult = true;
        $this->assertTrue(ClienteForm::validate($cliente));
        $this->assertSame(2, $cliente->testCalls);
    }

    /**
     * applyAndValidate() returns the validation error list on failure and an
     * empty list on success (scenario: "Mandatory-group validation is triggered
     * through test()").
     */
    #[Test]
    public function applyAndValidateReturnsValidationErrors(): void
    {
        ClienteFormGrupoDouble::$groups = [];

        $failing = new ClienteFormClienteDouble();
        $failing->testResult = false;
        $failing->errors = ['El cliente debe tener un grupo de descuentos.'];

        $errors = ClienteForm::applyAndValidate($failing, ['codgrupo' => '', 'codgrupo_descuento' => '']);

        $this->assertSame(['El cliente debe tener un grupo de descuentos.'], $errors);
        $this->assertNull($failing->codgrupo);
        $this->assertNull($failing->codgrupo_descuento);

        $passing = new ClienteFormClienteDouble();
        $passing->testResult = true;

        $this->assertSame([], ClienteForm::applyAndValidate($passing, ['codgrupo' => '000001']));
        $this->assertSame('000001', $passing->codgrupo);
    }

    // -------------------------------------------------------------------------
    // VF-2: razonsocial '' falls back to nombre
    // -------------------------------------------------------------------------

    /**
     * VF-2 (intentional, deliberately-flagged behavior change for the edit
     * path): an empty razonsocial becomes nombre when the target object
     * declares the property.
     */
    #[Test]
    public function applyRazonSocialEmptyFallsBackToNombre(): void
    {
        $cliente = new ClienteFormClienteDouble();

        ClienteForm::apply($cliente, ['nombre' => 'Acme S.L.', 'razonsocial' => '']);

        $this->assertSame('Acme S.L.', $cliente->razonsocial);
    }

    /**
     * VF-2 counterpart: a double without the razonsocial property is left
     * untouched. This is the deliberately-flagged behavior change, reviewed on
     * purpose so no dynamic property is invented on lightweight targets.
     */
    #[Test]
    public function applyLeavesTargetWithoutRazonSocialUntouched(): void
    {
        $target = new \stdClass();

        ClienteForm::apply($target, ['nombre' => 'Acme S.L.', 'razonsocial' => '']);

        $this->assertSame('Acme S.L.', $target->nombre);
        $this->assertFalse(property_exists($target, 'razonsocial'));
    }
}
