# Delta for client-discount-inheritance

## MODIFIED Requirements

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
