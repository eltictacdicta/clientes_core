# Delta for clientes

## ADDED Requirements

### Requirement: Client discount fields

The `cliente` model SHALL have four decimal discount fields (`d1`,
`d2`, `d3`, `d4`) stored as `decimal(5,2)` in the `clientes` DB
table, and a boolean `descuentos_modified` flag (default `false`).
The XML schema in `model/table/clientes.xml` SHALL define all five
new columns with NULL defaults for the decimals and `false` for
the boolean.

#### Scenario: New client has no discounts

- GIVEN a new `cliente` record
- WHEN the record is saved
- THEN `d1`–`d4` are NULL or 0.00
- AND `descuentos_modified` is `false`

#### Scenario: Existing clients survive schema migration

- GIVEN an existing `clientes` table with rows but no discount columns
- WHEN `fix_db()` runs or the schema is synced
- THEN the five new columns are added with NULL defaults
- AND existing rows are preserved with NULL discount values

### Requirement: Client save includes discount fields

The `cliente` model's `save()` and `buildSql()` methods SHALL
include `d1`, `d2`, `d3`, `d4`, and `descuentos_modified` in
INSERT and UPDATE statements. The constructor SHALL initialize
these fields from the `$data` array when provided.

#### Scenario: Discount values persist on save

- GIVEN a client with `d1 = 15.00`, `descuentos_modified = true`
- WHEN `save()` is called
- THEN the values are written to the `clientes` table
- AND a subsequent load returns the same values

#### Scenario: Constructor loads discount fields

- GIVEN a database row with `d1 = 10.00`, `d2 = 5.00`
- WHEN a `cliente` is instantiated with that row data
- THEN `$cliente->d1` is `10.00`
- AND `$cliente->d2` is `5.00`

### Requirement: New client gets default group

When a new client is created without a group selection, the controller
SHALL assign the "Personalizado" default group. The model's `test()`
method SHALL validate that `codgrupo` is not NULL.

#### Scenario: New client without group selection gets Personalizado

- GIVEN a new client form submitted without group selection
- WHEN the controller processes the save
- THEN `codgrupo` is set to the "Personalizado" group code
- AND client discounts are initialized to the group's values (0.00)

#### Scenario: Client with NULL codgrupo fails validation

- GIVEN a client with `codgrupo = NULL`
- WHEN `test()` is called
- THEN validation fails
- AND an error message indicates a group is required
