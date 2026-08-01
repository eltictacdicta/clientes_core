# Archive Report: clientes-descuentos-grupo

```yaml
schema: gentle-ai.archive-report/v1
archived: 2026-07-26
change: clientes-descuentos-grupo
plugin: clientes_core
artifact_store: hybrid
verdict: pass_with_warnings
```

## Summary

Archived after successful implementation and verification. The change
added discount group fields (`d1`–`d4`) to `grupo_clientes` and
`cliente`, implemented group-based discount inheritance with override
and reset mechanics, added a mandatory default "Personalizado" group,
and created UI controls for discount management.

### Specs Synced

| Domain | Action | Details |
|--------|--------|---------|
| clientes | Updated | 3 requirements appended (discount fields, save includes discounts, default group) |
| discount-groups | Created | 4 requirements (discount fields, cascade semantics, CRUD patterns, Personalizado group) |
| client-discount-inheritance | Created | 6 requirements (inherit, override, indicator, reset, group change, mandatory group) |

### Archive Contents

- tasks.md ✅ (27/27 tasks complete)
- verify-report.md ✅ (PASS WITH WARNINGS, 0 CRITICAL)
- specs/discount-groups/spec.md ✅
- specs/client-discount-inheritance/spec.md ✅
- specs/clientes/spec.md ✅

### Source of Truth Updated

The following specs now reflect the new behavior:
- `plugins/clientes_core/openspec/specs/clientes/spec.md`
- `plugins/clientes_core/openspec/specs/discount-groups/spec.md` (new)
- `plugins/clientes_core/openspec/specs/client-discount-inheritance/spec.md` (new)

### Verification Evidence

- **Tests**: 64 passed / 0 failed (11 new + 53 existing)
- **Assertions**: 180 total
- **Coverage**: 23/26 spec scenarios compliant
- **TDD Compliance**: 6/6 checks passed
- **CRITICAL issues**: 0

### Warnings (non-blocking)

1. Discount cascade semantics (D1→D2→D3→D4) are OUT OF SCOPE per proposal — handled by invoicing plugins
2. Twig rendering of "(Modificado)" indicator not tested (no Twig rendering test infrastructure)
3. phpstan fails on unrelated file (`plugins/OidcProvider/controller/admin_oidc_diagnostics.php`) — not a regression

### Suggestions

1. Consider DB integration test for "Group delete cascades to no discount data"
2. Consider DB integration test for "All clients have a group after migration"

### Core Openspec Check

✅ No entries found in `openspec/changes/clientes-descuentos-grupo/` — change was plugin-local.

### Composer Dependencies

No Composer dependency added — change is schema/model/controller/view only.

### SDD Cycle Complete

The change has been fully planned, implemented, verified, and archived.
Ready for the next change.
