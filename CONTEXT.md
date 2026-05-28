---
title: Orders Context
package: orders
status: current
surface: domain
family: checkout-flow
---

# Orders Context

## Snapshot
- Composer: `aiarmada/orders`
- Role: Order records, payments, refunds, notes, invoices, and order state transitions.
- Search first: `src/Models`, `src/Actions`, `src/Services`, `src/Events`, `config`, `docs`
- Related: `filament-orders`, `checkout`, `shipping`, `docs`

## Read next
1. `docs/01-overview.md`
2. `docs/03-configuration.md`
3. `docs/04-usage.md`
4. `docs/99-troubleshooting.md`
5. `../filament-orders/CONTEXT.md` when admin UI changes are involved
6. `docs/02-installation.md` when setup or publishing changes are involved

## Guardrails
- Owns models, actions, services, events, calculations, and persistence rules.
- If admin UI changes too, audit `filament-orders`.
- Update `docs/*.md` in the same pass when public behavior or config changes.
