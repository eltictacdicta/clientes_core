# client-discount-inheritance domain

## Purpose

Source of truth spec for the `client-discount-inheritance` domain inside
the `clientes_core` plugin. This spec covers how client records inherit,
override, reset, and update discount values from their assigned
`grupo_clientes` group.

## Domain context

Client discount inheritance defines the relationship between a
`grupo_clientes` group's discount fields (`d1`–`d4`) and the
corresponding fields on a `cliente` record. When a client is assigned
to a group, the group's discounts are copied to the client. Individual
discounts may be overridden per-client; a `descuentos_modified` flag
tracks whether the client deviates from the group. Reset and group
change actions restore or replace client discounts with the applicable
group defaults.

## Requirements

### Requirement: Client inherits group discounts

When a client is assigned to a discount group, the system SHALL copy
the group's four discount values (`d1`–`d4`) to the client record.
The `descuentos_modified` flag SHALL be set to `false` on initial
assignment.

#### Scenario: Assigning a group copies discounts to client

- GIVEN a group with `d1 = 10.00`, `d2 = 5.00`, `d3 = 0.00`, `d4 = 2.00`
- AND a client with no group assigned
- WHEN the client is assigned to this group
- THEN the client's `d1`–`d4` match the group's values
- AND `descuentos_modified` is `false`

### Requirement: Individual discount override

A client MAY override any individual discount independently of the
group. When any client discount value differs from the corresponding
group value, `descuentos_modified` SHALL be set to `true`.

#### Scenario: Override a single discount

- GIVEN a client assigned to a group with `d1 = 10.00`
- AND `descuentos_modified = false`
- WHEN the client's `d1` is changed to 15.00
- THEN `descuentos_modified` becomes `true`

#### Scenario: Multiple independent overrides

- GIVEN a client assigned to a group with `d1 = 10.00`, `d2 = 5.00`
- WHEN the client's `d1` is changed to 12.00 and `d2` to 8.00
- THEN `descuentos_modified` is `true`

### Requirement: Modification indicator in UI

When `descuentos_modified` is `true`, the UI SHALL display the group
name with a "(Modificado)" suffix. When `false`, the group name is
displayed without suffix.

#### Scenario: Unmodified group shows plain name

- GIVEN a client with `descuentos_modified = false`
- AND group name "Mayorista"
- WHEN the client detail view renders
- THEN the group display shows "Mayorista"

#### Scenario: Modified group shows suffix

- GIVEN a client with `descuentos_modified = true`
- AND group name "Mayorista"
- WHEN the client detail view renders
- THEN the group display shows "Mayorista (Modificado)"

### Requirement: Reset to group defaults

The UI SHALL provide a reset button that restores all four client
discount values to the current group defaults and sets
`descuentos_modified` to `false`.

#### Scenario: Reset restores all four discounts

- GIVEN a client with overrides (`d1 = 15.00`, `d2 = 8.00`)
- AND group defaults (`d1 = 10.00`, `d2 = 5.00`, `d3 = 0.00`, `d4 = 0.00`)
- WHEN the reset action is triggered
- THEN client `d1`–`d4` equal the group's values
- AND `descuentos_modified` is `false`

### Requirement: Group change overwrites client discounts

When a client's group assignment changes to a different group, the
system SHALL overwrite all four client discount values with the new
group's discounts. The `descuentos_modified` flag SHALL be reset to
`false`.

#### Scenario: Changing group replaces all discounts

- GIVEN a client in group A (`d1 = 10.00`) with overrides (`d1 = 15.00`)
- AND group B with `d1 = 20.00`, `d2 = 10.00`, `d3 = 5.00`, `d4 = 0.00`
- WHEN the client is reassigned to group B
- THEN client `d1`–`d4` match group B's values
- AND `descuentos_modified` is `false`

### Requirement: Group is mandatory for all clients

This requirement is corrected to separate the two group concepts. A client
group (`codgrupo`, entity `grupo_clientes`) and a discount group
(`codgrupo_descuento`, entity `grupo_descuentos`) are distinct; scenarios and
implementation MUST use the column that matches the concept. Client-group
scenarios use `codgrupo`; discount-group scenarios use `codgrupo_descuento`.

Every client MUST have a discount group explicitly selected.
`cliente::test()` SHALL reject a `codgrupo_descuento` that is `NULL`. At save
time no controller, model or consumer SHALL silently assign a fallback discount
group; a missing selection SHALL fail validation instead.

The `clientes.codgrupo_descuento` column SHALL remain NULLable at the database
level (Q3): the invariant lives in `cliente::test()` only, and a direct SQL
writer can bypass it. This is an explicitly accepted limitation, not a defect,
and no foreign key SHALL be added on the column.

Clients with `codgrupo_descuento IS NULL` are assigned the `'000000'`
"Personalizado" discount group by the activation backfill migration defined in
the `clientes` capability; that migration is not a runtime fallback for new
saves.

#### Scenario: Missing discount group fails validation

- GIVEN a client with `codgrupo_descuento = NULL`
- WHEN `cliente::test()` is called
- THEN validation fails
- AND an error message indicates a discount group is required
- AND the client is not persisted

#### Scenario: Explicit discount group selection is accepted

- GIVEN a client with `codgrupo_descuento = '000000'`
- WHEN `cliente::test()` is called
- THEN validation passes for the discount group

#### Scenario: No silent fallback at save time

- GIVEN a save submitted with an empty discount group
- WHEN the save path processes the submission
- THEN no discount group code is assigned as a fallback
- AND the mandatory discount group validation fails the save

#### Scenario: DB column stays nullable

- GIVEN the `clientes` schema
- WHEN the `codgrupo_descuento` column definition is inspected
- THEN it remains nullable
- AND no foreign key enforces it

#### Scenario: Backfill uses the discount column, not the client group column

- GIVEN existing clients with `codgrupo_descuento IS NULL`
- AND clients with `codgrupo = NULL`
- WHEN the activation backfill migration runs
- THEN each `codgrupo_descuento IS NULL` row is assigned the discount group `'000000'` "Personalizado" (`d1–d4 = 0.00`)
- AND each `codgrupo IS NULL` row is assigned the client group `'000001'` "General"
- AND the two backfills never write a discount-group code into `codgrupo` or a client-group code into `codgrupo_descuento`

<!-- Source of truth. Last updated: 2026-09-22. Merged from changes/cliente-form-compartido-grupos-obligatorios/specs/client-discount-inheritance/spec.md. -->
