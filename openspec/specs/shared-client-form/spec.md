# shared-client-form domain

## Purpose

Source of truth spec for the `shared-client-form` domain inside the
`clientes_core` plugin. This spec covers the single reusable Twig client-form
partial exposed under the `@clientes_core` namespace, its modal/page
parameterization, the preserved extension hooks, the shared PHP
apply/validate/diff authority, the deliberate exclusion of address editors,
and the `tpvmod` consumer contract.

## Domain context

The `clientes_core` plugin owns a shared client identity form consumed by its
own create modal (`view/ventas_clientes.html.twig`) and edit page
(`view/ventas_cliente.html.twig`), and by consumer plugins such as `tpvmod`.
The partial is exposed under the plugin's Twig namespace `@clientes_core/...`,
registered by `plugins/clientes_core/Init.php` through a `TwigLoaderEvent`
listener that calls `$loader->addPath(__DIR__ . '/View', 'clientes_core')`,
following the existing `clientes_catalogo` precedent — no core change is
required. Field mapping, the `descuentos_modified` diff and the mandatory-group
validation are centralized in a single shared PHP authority so the page and TPV
save paths cannot drift. Addresses are deliberately excluded from the shared
component (Q5); each consumer keeps its own address editor. The `tpvmod`
contract is recorded here as a cross-repo reference; its implementation lives
in the `tpvmod` repository under its own SDD home.

## Requirements

### Requirement: Shared client form partial

`clientes_core` SHALL own a single reusable Twig template that renders the
client identity form and is consumed by both the create ("nuevo cliente")
modal and the edit page. The partial SHALL be exposed under the plugin's Twig
namespace as `@clientes_core/...`.

The namespace SHALL be registered by `plugins/clientes_core/Init.php` through a
`TwigLoaderEvent` listener that calls
`$loader->addPath(__DIR__ . '/View', 'clientes_core')`, following the existing
`clientes_catalogo` precedent (no core change).

`clientes_core`'s create modal (in `view/ventas_clientes.html.twig`) and edit
page (in `view/ventas_cliente.html.twig`) SHALL both render from that partial;
neither SHALL keep an independent second copy of the client form field markup.

The exact path and granularity of the partial inside `View/` are owned by
`sdd-design`.

#### Scenario: Twig namespace is registered

- GIVEN `clientes_core` is active
- WHEN the Twig loader dispatches `TwigLoaderEvent::NAME`
- THEN `Init.php` adds the plugin `View` directory under the namespace `clientes_core`
- AND `@clientes_core/...` resolves from inside a Twig template

#### Scenario: Both clientes_core surfaces render the shared partial

- GIVEN the client edit page and the client create modal
- WHEN each renders
- THEN both include the same `@clientes_core` client form partial
- AND neither keeps a private duplicate of the client form field set

#### Scenario: The shared partial does not depend on a core change

- GIVEN the shared component is registered only through `TwigLoaderEvent`
- WHEN the plugin is installed on an unmodified FSFramework core
- THEN the namespace and the partial resolve
- AND no file under `base/`, `src/`, root `model/` or root `controller/` is required to change

### Requirement: Form parameterization for modal and page modes

The shared partial SHALL be parameterized by its include context so that one
template serves the create-modal flow and the edit-page flow. The context SHALL
select at least: create versus edit/embedded mode, the submit target, and a DOM
id prefix. The exact context key names are owned by `sdd-design` and SHALL NOT
be inferred from this delta.

The partial SHALL be safe to include more than once in a single page: repeated
inclusions MUST NOT collide DOM ids and MUST NOT register its Alpine component
more than once. Any script it emits SHALL remain compatible with the strict CSP
(nonce'd classic script, idempotent registration marker, no `unsafe-eval`).

#### Scenario: Create mode renders an empty entity against the create action

- GIVEN the create-modal context
- WHEN the partial renders
- THEN the entity fields are empty
- AND the form targets the create action
- AND the client group select renders without a preselected value

#### Scenario: Edit mode renders the persisted entity against the edit action

- GIVEN an existing `cliente` and the edit context
- WHEN the partial renders
- THEN the fields show the persisted values
- AND the form targets the edit action

#### Scenario: Repeated inclusion does not collide

- GIVEN the partial is included twice in one rendered page with different id prefixes
- WHEN both instances render
- THEN every DOM id in the page is unique
- AND the Alpine component is registered exactly once

#### Scenario: Scripts remain CSP-compatible

- GIVEN `stealth_mode` applies its strict Content Security Policy
- WHEN the partial renders and boots its Alpine component
- THEN the script uses the request nonce and no `unsafe-eval`
- AND a second inclusion does not double-register the component

### Requirement: Client form extension hooks remain rendered

The shared partial SHALL render the `cliente_form_after_main` extension hook
inside the main client panel, with the context array `{fsc, cliente}`.

Because addresses remain outside the shared component (Q5), each consumer's own
address editor SHALL continue to render the
`cliente_direccion_form_after_codpais` hook immediately after the `codpais`
field, with the context array `{fsc, cliente}`.

The hook rendering contract SHALL be preserved so `clientes_catalogo` keeps
contributing its fields; a registered hook MUST NOT be silently dropped by the
partial restructure.

#### Scenario: Main hook renders inside the shared partial

- GIVEN `clientes_catalogo` registered `cliente_form_after_main`
- WHEN the shared partial renders
- THEN the hook template output appears inside the main client panel
- AND the hook receives context keys `fsc` and `cliente`

#### Scenario: Address hook still renders in the consumer address editor

- GIVEN `clientes_catalogo` registered `cliente_direccion_form_after_codpais`
- WHEN a client address editor renders its `codpais` field
- THEN the hook output appears immediately after `codpais`
- AND the hook receives context keys `fsc` and `cliente`

### Requirement: Single shared PHP apply, validate and diff authority

`clientes_core` SHALL own one PHP authority for client-form processing, used by
its own pages and by consumer plugins. The authority SHALL be the only
implementation of:

1. mapping submitted client-form fields onto a `cliente` instance;
2. computing the `descuentos_modified` flag by comparing the client discounts
   against the selected discount group;
3. triggering the mandatory-group validation through `cliente::test()`.

The current inline copies in `controller/ventas_cliente.php`,
`controller/ventas_clientes.php` and the `tpvmod` consumer SHALL delegate to
this authority instead of reimplementing it. The location and shape of the
authority are owned by `sdd-design`.

#### Scenario: Page and TPV produce identical field state

- GIVEN the same submitted client-form data
- WHEN it is applied through the page save path and through the TPV save path
- THEN both produce the same `cliente` field values

#### Scenario: Descuentos diff is computed in one place

- GIVEN a submitted discount group and client discounts that differ from it
- WHEN the authority applies the submission to a `cliente`
- THEN `descuentos_modified` is set from the single diff implementation
- AND no consumer recomputes the diff independently

#### Scenario: Mandatory-group validation is triggered through test()

- GIVEN a submission with a missing or empty group
- WHEN the authority validates it
- THEN validation fails through `cliente::test()`
- AND the failure surfaces as a validation error, not as a silent fallback

### Requirement: Addresses are excluded from the shared component

The shared client form partial SHALL cover the client identity fields only. It
SHALL NOT render an address editor; each consumer SHALL keep its own address
editor (Q5).

#### Scenario: Shared partial has no address editor

- GIVEN the shared `@clientes_core` client form partial
- WHEN it renders
- THEN no address list, address form or `codpais` field is rendered by it

#### Scenario: clientes_core keeps its own address panel

- GIVEN the client edit page
- WHEN it renders
- THEN addresses are rendered by the page's own address panel, not by the shared partial

### Requirement: TPV consumer contract for the shared client form

The `tpvmod` plugin SHALL consume the shared client form by rendering the
`@clientes_core` client form partial, and SHALL keep its existing AJAX endpoint
and its JSON response shape `{ ok, codcliente, label, cliente }` consumed by
`tpvmodSeleccionarCliente()` and `recalcular()`.

`tpvmod` SHALL delegate field mapping and the `descuentos_modified` diff to the
shared PHP authority and SHALL NOT keep its own copy of that logic. The
`'000000'` fallback written into `codgrupo` SHALL be removed, not wrapped.

The implementation of this contract lives in the `tpvmod` repository under its
own SDD home; this delta records the contract only and SHALL NOT create any
artifact under the core `openspec/`.

#### Scenario: TPV renders the shared partial

- GIVEN the TPV client form is opened
- WHEN it renders
- THEN it includes the `@clientes_core` client form partial

#### Scenario: TPV keeps its AJAX response contract

- GIVEN a valid TPV client save
- WHEN the AJAX endpoint responds
- THEN the JSON payload contains `ok`, `codcliente`, `label` and `cliente`
- AND `label` and `cliente` remain populated for `tpvmodSeleccionarCliente()`

#### Scenario: TPV no longer writes the discount code into the client group

- GIVEN a TPV client save submitted with an empty `codgrupo`
- WHEN the TPV save path applies the submission
- THEN `codgrupo` is NOT set to `'000000'`
- AND the save fails the mandatory client group validation

#### Scenario: TPV mapping and diff come from the shared authority

- GIVEN the TPV save path applies a submitted client form
- WHEN the client is persisted
- THEN its discount diff was computed by the shared authority
- AND `tpvmod` no longer carries an independent copy of the mapping or diff logic

<!-- Source of truth. Last updated: 2026-09-23. Created from changes/cliente-form-compartido-grupos-obligatorios/specs/shared-client-form/spec.md. -->
