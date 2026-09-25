# Delta for discount-groups

## MODIFIED Requirements

### Requirement: Default "Personalizado" group

The default group named "Personalizado" with `d1–d4 = 0.00` is a **discount**
group: it is stored in `gruposdescuentos` keyed by
`codgrupo_descuento = '000000'`, created during plugin migration with a
deterministic code, and used as the mandatory discount default for clients
without a discount group.

It SHALL NOT be assigned to, or referenced by, the client-group column
`codgrupo`. It SHALL NOT be deletable while any `clientes` row references it as
`codgrupo_descuento`.

#### Scenario: Personalizado is created as the discount default

- GIVEN the plugin is being upgraded
- WHEN the migration runs
- THEN a `grupo_descuentos` row with `codgrupo_descuento = '000000'`,
  `nombre = 'Personalizado'` and `d1–d4 = 0.00` exists
- AND the group code is deterministic: `'000000'`
- AND no `clientes.codgrupo` value is set to `'000000'`

#### Scenario: Personalizado cannot be deleted while in use

- GIVEN the "Personalizado" discount group exists
- AND at least one `clientes` row has `codgrupo_descuento = '000000'`
- WHEN a delete is requested for `'000000'`
- THEN the deletion is refused
- AND an error message is reported

## ADDED Requirements

### Requirement: In-use group deletion is blocked

A group SHALL NOT be deleted while any `clientes` row references it. The guard
SHALL cover both group types:

- client group (`grupo_clientes`): refuse when any `clientes.codgrupo` equals
  the group code;
- discount group (`grupo_descuentos`): refuse when any
  `clientes.codgrupo_descuento` equals the group code.

The client-group guard SHALL live in the `clientes_core` delete path
(`controller/ventas_clientes.php::delete_grupo()`), because the
`ca_clientes_grupos` foreign key is `ON DELETE SET NULL` and would otherwise
silently re-orphan clients. The discount-group guard SHALL live in
`controller/descuentos_grupo.php::delete_grupo()`.

#### Scenario: In-use client group cannot be deleted

- GIVEN a `grupo_clientes` referenced by at least one `clientes.codgrupo`
- WHEN `delete_grupo()` is called for that code
- THEN the group row is not deleted
- AND an error message explains the group is in use

#### Scenario: In-use discount group cannot be deleted

- GIVEN a `grupo_descuentos` referenced by at least one
  `clientes.codgrupo_descuento`
- WHEN the discount-group delete is called for that code
- THEN the group row is not deleted
- AND an error message explains the group is in use

#### Scenario: Unreferenced group can still be deleted

- GIVEN a group whose code is referenced by no `clientes` row
- WHEN the delete is requested
- THEN the group row is removed
