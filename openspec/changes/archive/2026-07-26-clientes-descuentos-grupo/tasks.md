# Tasks: clientes-descuentos-grupo

## Review Workload Forecast

| Field | Value |
|-------|-------|
| Estimated changed lines | ~350 (production ~200, tests ~150) |
| 400-line budget risk | Medium |
| Chained PRs recommended | No |
| Suggested split | Single PR |
| Delivery strategy | single-pr |
| Chain strategy | pending |

Decision needed before apply: Yes
Chained PRs recommended: No
Chain strategy: size-exception
400-line budget risk: Medium

### Suggested Work Units

| Unit | Goal | Likely PR | Focused test command | Runtime harness | Rollback boundary |
|------|------|-----------|----------------------|-----------------|-------------------|
| 1 | Schema + migration + models + controller + view | Single PR | `ddev exec php vendor/bin/phpunit -c plugins/clientes_core/phpunit.xml` | Manual: create client, assign group, modify discount, reset, change group | Revert single commit/PR |

## Phase 1: Schema + Migration (~40 LoC)

- [x] T01. **Test**:grupo_clientes schema discount fields — add test in `GrupoClientesModelTest.php` that instantiates from data array with d1–d4 and verifies defaults are 0.00 (~8 LoC)
- [x] T02. **Schema**: Add `d1`, `d2`, `d3`, `d4` columns to `plugins/clientes_core/model/table/gruposclientes.xml` — each `decimal(5,2)`, DEFAULT 0.00, nullable YES (~8 LoC)
- [x] T03. **Test**: cliente schema discount fields — add test in `ClienteModelTest.php` that instantiates from data with d1–d4 and descuentos_modified, verifies NULL defaults for decimals and false for flag (~8 LoC)
- [x] T04. **Schema**: Add `d1`–`d4` (`decimal(5,2)`, DEFAULT NULL) + `descuentos_modified` (`boolean`, DEFAULT false) to `plugins/clientes_core/model/table/clientes.xml` (~12 LoC)
- [x] T05. **Test**: Init migration creates Personalizado — extend `InitUpgradeTest.php` with fakes for `grupo_clientes`, verify `upgrade()` creates group `000000` with d1–d4=0.00 and updates NULL clients (~12 LoC)
- [x] T06. **Migration**: `Init::upgrade()` — add new flag `clientes_core_discounts_migrated`, create "Personalizado" group via `grupo_clientes`, UPDATE clients with NULL codgrupo to `'000000'` (~20 LoC)

## Phase 2: Models (~120 LoC)

- [x] T07. **Test**: grupo_clientes discount save/load/range validation — test in `GrupoClientesModelTest.php`: defaults to 0.00, `test()` rejects d1=105, accepts d2=12.55 (~10 LoC)
- [x] T08. **Model**: Extend `grupo_clientes` — add `d1`–`d4` properties, update constructor to load from data/default 0.00, add range validation in `test()`, include d1–d4 in INSERT/UPDATE SQL in `save()` (~30 LoC)
- [x] T09. **Test**: cliente discount constructor/save/load — test in `ClienteModelTest.php`: constructor loads d1–d4 from data, NULL defaults when no data, descuentos_modified defaults to false (~8 LoC)
- [x] T10. **Model**: Extend `cliente` — add `d1`–`d4` + `descuentos_modified` properties, update constructor, update `buildInsertSql`/`buildUpdateSql` to include new columns (~40 LoC)
- [x] T11. **Test**: getEffectiveDiscounts fallback — test: client with NULL d1 returns group default; client with non-NULL d1 returns client value (~8 LoC)
- [x] T12. **Model**: Add `getEffectiveDiscounts()`, `applyGroupDiscounts(grupo_clientes)`, `resetToGroupDefaults()` to `cliente` (~30 LoC)
- [x] T13. **Test**: cliente `test()` validates codgrupo NOT NULL — test that client with codgrupo=null fails validation (~5 LoC)
- [x] T14. **Model**: Add codgrupo NOT NULL validation to `cliente::validateFields()` (~10 LoC)

## Phase 3: Controller (~80 LoC)

- [x] T15. **Test**: save_cliente detects discount diff from group — test in `VentasClientesDispatchTest.php`: when d1 differs from group, descuentos_modified is set to true (~10 LoC)
- [x] T16. **Controller**: Update `ventas_cliente::save_cliente()` — read d1–d4 from POST, compare with group defaults, set `descuentos_modified` (~15 LoC)
- [x] T17. **Test**: change_grupo action copies group discounts — test: changing group sets client d1–d4 to new group values, descuentos_modified=false (~8 LoC)
- [x] T18. **Controller**: Add `change_grupo` case to `ventas_cliente::private_core()` switch — load new group, call `applyGroupDiscounts()`, save (~20 LoC)
- [x] T19. **Test**: reset_descuentos action restores group defaults — test: reset loads current group, restores d1–d4, sets flag=false (~8 LoC)
- [x] T20. **Controller**: Add `reset_descuentos` case to `ventas_cliente::private_core()` switch — load group, call `resetToGroupDefaults()`, save (~15 LoC)
- [x] T21. **Test**: new client gets Personalizado — test that controller assigns default group when codgrupo is empty on save (~5 LoC)
- [x] T22. **Controller**: In `save_cliente()`, assign "Personalizado" group code when codgrupo is empty (~10 LoC)
- [x] T23. **Test**: ventas_grupo prevents Personalizado deletion — test that deleting group "000000" fails with error message (~8 LoC)
- [x] T24. **Controller**: Add deletion guard in `ventas_grupo::delete_grupo()` — check codgrupo === '000000' before delete (~10 LoC)

## Phase 4: View (~50 LoC)

- [x] T25. **View**: Add discount section to `plugins/clientes_core/view/ventas_cliente.html.twig` — panel with 4 number inputs (d1–d4), group name display with "(Modificado)" suffix when flag=true, reset button (`action=reset_descuentos`), group change handler (`action=change_grupo`) (~50 LoC)

## Phase 5: Verification

- [x] T26. **Run full test suite**: `ddev exec php vendor/bin/phpunit -c plugins/clientes_core/phpunit.xml` — all existing + new tests must pass
- [x] T27. **Smoke test**: Create new client → verify Personalizado assigned → assign different group → verify discounts copied → modify d1 → verify "(Modificado)" shown → reset → verify group defaults restored → change group → verify overwrite
