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

Every client MUST have a discount group assigned. A default group
"Personalizado" (with 0% discounts) is created during migration and
assigned to all existing clients without a group. New clients are
assigned "Personalizado" by default if no group is selected.

#### Scenario: Migration assigns default group to existing clients

- GIVEN existing clients with `codgrupo = NULL`
- WHEN the plugin migration runs
- THEN a group "Personalizado" with `d1–d4 = 0.00` exists
- AND all clients with `codgrupo = NULL` are assigned to "Personalizado"

#### Scenario: New client gets default group

- GIVEN a new client being created
- AND no group is selected in the form
- WHEN the client is saved
- THEN `codgrupo` is set to the "Personalizado" group code

#### Scenario: All clients have a group after migration

- GIVEN the migration has completed
- WHEN any client is loaded
- THEN `codgrupo` is NOT NULL
- AND discount fields show the group's values (or overrides if modified)

<!-- Source of truth. Last updated: 2026-07-26. Created from changes/clientes-descuentos-grupo/specs/client-discount-inheritance/spec.md. -->
