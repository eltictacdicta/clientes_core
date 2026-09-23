# discount-groups domain

## Purpose

Source of truth spec for the `discount-groups` domain inside the
`clientes_core` plugin. This spec covers the `grupo_descuentos` entity
(stored in `gruposdescuentos`, keyed by `codgrupo_descuento`), its
discount fields (`d1`–`d4`), validation constraints, cascade semantics,
and the default "Personalizado" discount group that serves as the
mandatory discount default for all clients. It is distinct from the
`grupo_clientes` client group (stored in `gruposclientes`, keyed by
`codgrupo`), whose default is `'000001'` "General".

## Domain context

The `grupo_descuentos` model (declared in
`plugins/clientes_core/model/core/grupo_descuentos.php`) persists to
the `gruposdescuentos` DB table, keyed by `codgrupo_descuento`. Each
group defines four discount percentages (`d1`–`d4`) stored as
`decimal(5,2)` with values between 0.00 and 100.00 inclusive. These
discounts are inherited by client records via `clientes.codgrupo_descuento`
and form a cascading chain for price calculation (D1 applies to base,
D2 to D1's result, etc.).

A default discount group "Personalizado" (code `000000`, d1–d4 = 0.00)
is created during plugin migration and assigned to all existing clients
without a discount group. This group is the mandatory discount default
and cannot be deleted while any `clientes` row references it as
`codgrupo_descuento`. It is never assigned to `clientes.codgrupo`; the
client-group default is `'000001'` "General".

## Requirements

### Requirement: Discount group discount fields

Each `grupo_clientes` record SHALL have four decimal discount fields:
`d1`, `d2`, `d3`, `d4`, stored as `decimal(5,2)` in the
`gruposclientes` DB table. Values MUST be between 0.00 and 100.00
inclusive. Default values SHALL be 0.00 for all four fields.

#### Scenario: New group defaults to zero discounts

- GIVEN a new `grupo_clientes` record with no discount values set
- WHEN the record is saved
- THEN `d1`, `d2`, `d3`, `d4` are all 0.00

#### Scenario: Discount values must be within range

- GIVEN a `grupo_clientes` record with `d1 = 105.00`
- WHEN `test()` is called
- THEN validation fails
- AND an error message is set indicating the value is out of range

#### Scenario: Discount values accept two decimal places

- GIVEN a `grupo_clientes` record with `d2 = 12.55`
- WHEN the record is saved
- THEN `d2` is persisted as `12.55`

### Requirement: Discount cascade semantics

The four discount fields form a cascading chain: D1 applies to the
base price, D2 applies to the result of D1, D3 to the result of D2,
and D4 to the result of D3. The final price is calculated as:
`base × (1 - D1/100) × (1 - D2/100) × (1 - D3/100) × (1 - D4/100)`.

#### Scenario: Single discount applied

- GIVEN a group with `d1 = 10.00` and `d2 = d3 = d4 = 0.00`
- WHEN the cascade is calculated on a base price of 100.00
- THEN the final price is 90.00

#### Scenario: Multiple cascading discounts

- GIVEN a group with `d1 = 10.00`, `d2 = 20.00`, `d3 = 5.00`, `d4 = 0.00`
- WHEN the cascade is calculated on a base price of 100.00
- THEN D1 reduces to 90.00, D2 reduces to 72.00, D3 reduces to 68.40
- AND the final price is 68.40

#### Scenario: All-zero discounts preserve base price

- GIVEN a group with `d1 = d2 = d3 = d4 = 0.00`
- WHEN the cascade is calculated on a base price of 250.00
- THEN the final price is 250.00

### Requirement: Group CRUD follows existing patterns

The `grupo_clientes` model SHALL extend `fs_model` following the
existing patterns: `test()` for validation, `save()` for
insert/update, `delete()` for removal, and `exists()` for lookup.
The XML schema in `model/table/gruposclientes.xml` SHALL define
the four new columns alongside existing group fields.

#### Scenario: Group with discounts can be saved and retrieved

- GIVEN a `grupo_clientes` with `d1 = 15.00`, `d2 = 10.00`
- WHEN saved and then retrieved by code
- THEN the four discount fields match the saved values

#### Scenario: Group delete cascades to no discount data

- GIVEN a `grupo_clientes` record with discounts
- WHEN the group is deleted
- THEN the group row is removed
- AND no orphan discount data remains (discounts live on the group row)

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

<!-- Source of truth. Last updated: 2026-09-23. Merged from changes/cliente-form-compartido-grupos-obligatorios/specs/discount-groups/spec.md. -->
