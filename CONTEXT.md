---
title: Orders Context
package: orders
status: current
surface: domain
family: checkout-flow
keywords:
  - order
  - refund
  - payment
  - invoice
  - state-machine
---

# Orders Context

## Snapshot
- Composer: `aiarmada/orders`
- Role: Order records, payments/refunds, notes, invoices, 13-state machine.
- Triggers: order, refund, payment, invoice, state-machine
- Search first: `src/Models, src/Actions, src/States, config, docs`
- Related: `filament-orders`, `checkout`, `shipping`, `docs`
- Paired: `filament-orders` (Filament admin adapter)

## Read next
1. `docs/01-overview.md`
2. `docs/03-configuration.md`
3. `docs/04-usage.md`
4. `docs/99-troubleshooting.md`
5. `../filament-orders/CONTEXT.md` when the change crosses UI/domain
6. `docs/02-installation.md` when setup or publishing changes are involved

## Guardrails
- Owns models, actions, services, events, calculations, and persistence rules.
- If admin UI changes too, audit `filament-orders`.
- Update `docs/*.md` in the same pass when public behavior or config changes.

## Decide fast
- Use when: Order lifecycle or money movement on orders.
- Skip when: Checkout orchestration — see checkout; admin UI — see filament-orders.
- Owner/security: Owner-scoped (all 6 models).

## Key surfaces
- Models: `Order`, `OrderAddress`, `OrderItem`, `OrderNote`, `OrderPayment`, `OrderRefund`
- Actions/Services: `Actions/CancelOrder`, `Actions/CompleteOrder`, `Actions/Concerns/AssertsOrderOwnerBoundary`, `Actions/Concerns/BuildsOrderDocs`, `Actions/Concerns/BuildsOrderPdf`, `Actions/CreateOrder`, `Actions/CreateOrderFromCart`, `Actions/CreateOrderInvoiceDoc`
- Config `orders.php`: `database`, `json_column_type`, `tables`, `orders`, `order_items`, `order_addresses`, `order_payments`, `order_refunds`, `order_notes`, `currency`

## Docs map
- Start: `01-overview` → `03-configuration` → `04-usage` → `99-troubleshooting`
- Deep dives: `05-state-machine.md`, `06-api-reference.md`
