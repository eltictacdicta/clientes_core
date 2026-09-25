# Delta for clientes

## MODIFIED Requirements

### Requirement: New client gets default group

The prior behaviour — assigning a fallback group to a new client when no group
was selected — is removed. A client's group (`codgrupo`) SHALL be explicitly
selected by the user. `cliente::test()` SHALL reject a `codgrupo` that is
`NULL` or an empty string. No controller, model, activation-time save step or
consumer SHALL assign a fallback group code when `codgrupo` is empty; the save
SHALL fail validation instead.

`'000000'` is a discount-group code (`gruposdescuentos.codgrupo_descuento`).
No code path in `clientes_core` or its consumers SHALL write `'000000'` into
`codgrupo`. The paths corrected by this change are `Init::upgrade()`,
`controller/ventas_cliente.php`, `controller/ventas_clientes.php` and the
`tpvmod` consumer.

The client group `'000001'` "General" SHALL be guaranteed by
`ensureDefaultClientGroup()` even when the `gruposclientes` table is non-empty
but lacks `'000001'`. The early return on a non-empty table SHALL be fixed.

The default client group is the migration backfill target for legacy rows
without a group; it is not a runtime fallback for new saves. The read-side
marker for the undeletable default group in `view/ventas_clientes.html.twig`
SHALL be the client group `'000001'`, not the discount code `'000000'`.

#### Scenario: New client without an explicit group fails validation

- GIVEN a new client form submitted without a group selection
- WHEN the save is processed
- THEN `cliente::test()` fails with a group-required error
- AND no `clientes` row is persisted
- AND `codgrupo` is not set to any fallback code

#### Scenario: Client with NULL or empty codgrupo fails validation

- GIVEN a client with `codgrupo = NULL` or `codgrupo = ''`
- WHEN `test()` is called
- THEN validation fails
- AND an error message indicates a group is required

#### Scenario: No code path writes the discount code into codgrupo

- GIVEN a client save through `Init::upgrade()`, `controller/ventas_cliente.php`,
  `controller/ventas_clientes.php`, or the `tpvmod` consumer
- WHEN the client group is empty
- THEN `'000000'` is never assigned to `codgrupo`
- AND the mandatory client group validation is the only outcome

#### Scenario: General group is guaranteed on a populated table

- GIVEN a `gruposclientes` table that is non-empty and does not contain `'000001'`
- WHEN `ensureDefaultClientGroup()` runs
- THEN a group `'000001'` named `'General'` is created
- AND the early return on a non-empty table does not skip it

#### Scenario: The undeletable default marker is the client group code

- GIVEN the client-group listing renders its delete controls
- WHEN it decides which group is the protected default
- THEN it keys that decision on `'000001'`
- AND it does not key it on the discount code `'000000'`

## ADDED Requirements

### Requirement: Mandatory group backfill on plugin activation

`Init::upgrade()` SHALL guarantee the two default groups exist and backfill
legacy clients that lack them, in a single idempotent, data-only migration
block:

1. guarantee client group `'000001'` "General" exists;
2. guarantee discount group `'000000'` "Personalizado" exists;
3. `UPDATE clientes SET codgrupo = '000001' WHERE codgrupo IS NULL`;
4. `UPDATE clientes SET codgrupo_descuento = '000000' WHERE codgrupo_descuento IS NULL`.

The block SHALL be gated by a NEW flag `clientes_core_discount_group_required`
in `fs_settings`. It SHALL NOT reuse `clientes_core_discounts_migrated`, which
is already set on installs that ran the previous migration and would make the
new step a permanent no-op.

The migration SHALL be data-only: it SHALL NOT add a foreign key or a
`NOT NULL` constraint to `codgrupo_descuento` (Q3), and SHALL NOT alter the
existing `ca_clientes_grupos` foreign key on `codgrupo`. A failure inside the
block SHALL leave the flag unset so the next activation retries, and SHALL NOT
break plugin activation.

#### Scenario: Orphan clients are backfilled to the client group

- GIVEN existing `clientes` rows with `codgrupo IS NULL`
- AND the `clientes_core_discount_group_required` flag is unset
- WHEN `Init::upgrade()` runs
- THEN every such row has `codgrupo = '000001'`
- AND the `'000001'` "General" group exists

#### Scenario: Clients without a discount group are backfilled

- GIVEN existing `clientes` rows with `codgrupo_descuento IS NULL`
- AND the `clientes_core_discount_group_required` flag is unset
- WHEN `Init::upgrade()` runs
- THEN every such row has `codgrupo_descuento = '000000'`
- AND the `'000000'` "Personalizado" discount group exists

#### Scenario: New flag gates the migration independently of the legacy flag

- GIVEN `clientes_core_discounts_migrated` is already set to `'1'`
- AND `clientes_core_discount_group_required` is unset
- WHEN `Init::upgrade()` runs
- THEN the backfill still executes
- AND the `clientes_core_discount_group_required` flag is set to `'1'`

#### Scenario: Re-running is a no-op

- GIVEN `clientes_core_discount_group_required` is set to `'1'`
- WHEN `Init::upgrade()` runs again
- THEN no default group is re-created
- AND no backfill UPDATE is issued

#### Scenario: Migration adds no constraint

- GIVEN the migration has run
- WHEN `model/table/clientes.xml` is inspected
- THEN `codgrupo_descuento` remains nullable
- AND no foreign key on `codgrupo_descuento` was added
- AND the `ca_clientes_grupos` foreign key is unchanged
