# Delta for discount-groups

## ADDED Requirements

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

A default group named "Personalizado" with `d1–d4 = 0.00` SHALL be
created during plugin migration. This group serves as the mandatory
default for all clients. It SHALL NOT be deletable through the UI.

#### Scenario: Personalizado group is created on migration

- GIVEN the plugin is being upgraded
- WHEN the migration runs
- THEN a group with `nombre = 'Personalizado'` and `d1–d4 = 0.00` exists
- AND the group code is deterministic (e.g., '000000' or next available)

#### Scenario: Personalizado group cannot be deleted

- GIVEN the "Personalizado" group exists
- WHEN a user attempts to delete it
- THEN the deletion is prevented
- AND an error message indicates this is the default group
