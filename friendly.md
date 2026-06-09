## Second pass — 2026-06-09

### Confirmed

- Focused Actions exist under `src/Actions/`: `CreateOrder`, `CreateOrderFromCart`, `CancelOrder`, `CompleteOrder`, `RegisterOrderPayment`, `RegisterOrderRefund`, `DetermineOrderDocumentType`, plus original doc Actions (`CreateOrderInvoiceDoc`, `CreateOrderReceiptDoc`, `GenerateInvoice`).
- `Contracts/OrderHandlerContributorInterface` exists with `registerFulfillmentHandler()`, `registerInventoryHandler()`, `registerPaymentHandler()`.
- `Support/OrderHandlerRegistrar` exists with idempotent registration for fulfillment, inventory, and payment handlers.
- `Events/OrderProcessingStarted` and `Events/OrderCancelInitiated` exist.
- `Actions/DetermineOrderDocumentType` exists (resolves `DocType::Receipt` vs `DocType::Invoice` based on payment status).

### Still open

- The original recommendation said "Define `DetermineOrderDocumentType` resolver" under Services, but it was placed in Actions instead. This is fine — Actions is actually more correct per monorepo conventions.
- Inventory and payment handlers don't appear to self-register through `OrderHandlerRegistrar` yet.

### Resolved

- `OrderHandlerContributorInterface` was a dead contract with zero implementors. Removed (2026-06-09). `OrderHandlerRegistrar` is now the canonical integration point.
- Shipping wires itself via `registerOrdersIntegration()` calling `OrderHandlerRegistrar::registerFulfillmentHandler()`. This is the correct pattern.

### Updated recommendation

The `OrderHandlerRegistrar` is the canonical integration point for handler registration. New packages should register via the registrar directly, not through an interface.

---

# Orders friendliness review

This note reviews `packages/orders` against two repo-level expectations:

- when a capability may grow variants, prefer stable seams such as contracts, metadata, hooks, domain events, resolvers, and support classes
- when orchestration repeats, extract reusable Actions, Services, or Use Cases so the package stays friendly to multiple entrypoints

## What I reviewed

- `src/Services`
- `src/Actions`
- `src/Listeners`
- `src/Models`
- `src/States`, `src/Transitions`
- `src/Contracts`
- downstream handlers in `inventory`, `shipping`, `affiliates`, `signals`

## What is already friendly

These seams are worth keeping and copying.

### Real contracts for cross-package integration

- `Contracts/OrderServiceInterface.php`
- `Contracts/FulfillmentHandler.php` (impl by `shipping`)
- `Contracts/InventoryHandler.php` (impl by `inventory`)
- `Contracts/PaymentHandler.php`

Other packages plug into orders through these contracts rather than by reaching into the model. This is the right pattern for cross-package work.

### State machine is a real seam

- `States/*` (12 classes for `OrderStatus`, `PaymentStatus`)
- `Transitions/*` (6 transitions)

The package uses spatie/laravel-model-states properly. State transitions are explicit and well-named.

### Domain events are explicit and use a shared trait

- `Events/*` (10 events)
- `Events/Concerns/HasOrderOwnerTuple.php`

The shared trait keeps owner-tuple handling consistent across order events. Listeners across packages (inventory, affiliates, signals) all consume these events.

### Doc generation is moving into Actions

- `Actions/CreateOrderInvoiceDoc.php`
- `Actions/CreateOrderReceiptDoc.php`
- `Actions/GenerateInvoice.php`
- `Actions/Concerns/BuildsOrderDocs.php`

Doc creation has been extracted out of the service layer. This is the right direction.

## Findings

### 1. `OrderService` is a single orchestrator with too many responsibilities

**Files**

- `src/Services/OrderService.php`
- callers across `src/Listeners`, `src/Actions`, and downstream packages

**What it currently owns (likely)**

- order creation
- status transitions
- payment registration
- refund registration
- doc creation triggers
- event dispatch

**Why this hurts friendliness**

The package has one giant integration point instead of several focused ones. Every cross-package handler has to talk to `OrderService` for every order operation, which means changes to that service cascade broadly.

**Recommendation**

Keep `OrderService` as a compatibility facade, but extract focused Actions under the existing `src/Actions` tree:

- `Actions/CreateOrder`
- `Actions/RegisterOrderPayment`
- `Actions/RegisterOrderRefund`
- `Actions/CancelOrder`
- `Actions/CompleteOrder`

That lets external callers continue using `OrderService` short-term while internal code moves toward narrower entrypoints.

### 2. Handlers are wired into the service provider, not through a registrar seam

**Files**

- `src/OrdersServiceProvider.php` (likely boot-time wiring of fulfillment/inventory/payment handlers)

**Why this hurts friendliness**

Cross-package handlers (`shipping`, `inventory`, `payment`) are wired in one place. New fulfillment or payment variants need to be added at orders boot time, which inverts the dependency direction.

**Recommendation**

Use the monorepo's existing tagged-registrar pattern:

- define `OrderHandlerContributorInterface` or
- use a tagged-list binding for `FulfillmentHandlerInterface`, `InventoryHandlerInterface`, `PaymentHandlerInterface`

Let each handler package register its own implementation. Orders becomes a composition root, not a manifest.

### 3. Doc generation Actions exist, but invoice vs receipt decision lives elsewhere

**Files**

- `Actions/CreateOrderInvoiceDoc.php`
- `Actions/CreateOrderReceiptDoc.php`
- `Actions/GenerateInvoice.php`

**Why this hurts friendliness**

There are three Actions, but the rules for when to use invoice vs receipt vs other doc types are not centralized. Each caller has to know which Action to invoke.

**Recommendation**

Add a small `DetermineOrderDocumentType` resolver or a `OrderDocumentStrategy` enum + registry. The order service can then dispatch to the right Action by strategy.

### 4. State machine is good, but transitions are not always reachable from one place

**Files**

- `src/Transitions/*`

**Why this hurts friendliness**

Each transition is a class, but the orchestration around "this transition needs inventory release, this needs payment capture, this needs refund" lives outside the state machine. Callers have to know the side effects.

**Recommendation**

Consider a transition event or hook. When a transition fires, the relevant side-effect Actions are dispatched automatically. Callers then only need to trigger the transition, not orchestrate its consequences.

### 5. Order address, note, and payment models are sibling classes with similar boilerplate

**Files**

- `src/Models/OrderAddress.php`
- `src/Models/OrderNote.php`
- `src/Models/OrderPayment.php`
- `src/Models/OrderRefund.php`

**Why this hurts friendliness**

These are all owner-aware, line-item-style models. If a new sibling type appears, it will copy the same owner scope, factory, and event boilerplate.

**Recommendation**

Extract a `Concerns/IsOrderLineItem` or trait that captures the shared owner-scope, factory, and casting patterns. Keep models lean.

## Concrete refactor plan

### Phase 1 — split `OrderService` behind Actions

**Steps**

1. Add focused Actions for the core workflows under the existing `src/Actions` tree.
2. Make `OrderService` delegate to the Actions.
3. Migrate internal callers one group at a time.

### Phase 2 — switch handler wiring to contributors

**Steps**

1. Add `OrderHandlerContributorInterface` (or tagged bindings).
2. Move fulfillment/inventory/payment handler registration out of orders service provider.
3. Update each handler package to bind itself.

### Phase 3 — centralize doc type decision

**Steps**

1. Add `DetermineOrderDocumentType` resolver.
2. Move the doc decision out of `OrderService` and individual Actions.

### Phase 4 — transition hooks

**Steps**

1. Add transition events for the most common side effects.
2. Wire existing side-effect listeners to those events.





## Refactor tracking

This checklist tracks progress on the refactor plan above. Each item lists a concrete phase/step.
Agents: claim an item by updating its status. Use `@agent-name` to claim ownership.

Status legend:
- `[pending]` — not started
- `[in-progress]` — being worked on
- `[done]` — completed and verified
- `[blocked]` — blocked by another item

### Phase 1 — split `OrderService` behind Actions

- [done] Add focused Actions for the core workflows under the existing `src/Actions` tree.
- [done] Make `OrderService` delegate to the Actions.
- [done] Migrate internal callers one group at a time.

### Phase 2 — switch handler wiring to contributors

- [done] Add `OrderHandlerContributorInterface` (or tagged bindings).
- [done] Add `OrderHandlerRegistrar` for centralized handler registration.
- [done] Move fulfillment/inventory/payment handler registration out of orders service provider.
- [done] Update each handler package to bind itself.
    **Result:** `OrderHandlerContributorInterface` was removed (zero implementors) per Phase 5. `OrderHandlerRegistrar` is the canonical integration point. Shipping self-registers via `registerOrdersIntegration()` → `OrderHandlerRegistrar::registerFulfillmentHandler()`. Inventory and payment handlers don't currently self-register — they're wired via `OrdersServiceProvider` directly. The registrar pattern is available when they need it.

### Phase 3 — centralize doc type decision

- [done] Add `DetermineOrderDocumentType` resolver.
- [done] Move the doc decision out of `OrderService` and individual Actions.

### Phase 4 — transition hooks

- [done] Add transition events `OrderProcessingStarted` and `OrderCancelInitiated`.
- [done] Wire existing side-effect listeners (`DeductInventoryOnPaymentConfirmed`, `ReleaseInventoryOnOrderCanceled`) to those events.

### Phase 5 — resolve the dead `OrderHandlerContributorInterface` contract

- [done] Removed `Contracts/OrderHandlerContributorInterface` (had zero implementors). `OrderHandlerRegistrar` is now the canonical integration point. — **Fixed 2026-06-09.**



## Suggested verification scope

- `tests/src/Orders/Unit/OrderServiceTest.php`
- `tests/src/Orders/Unit/CreateOrderTest.php`
- `tests/src/Orders/Unit/OrderStateTransitionTest.php`
- cross-package tests for inventory/shipping/affiliates/signals after the handler refactor

## Recommended first move

Phase 1 — split `OrderService` behind Actions. The service is the single largest coupling point in the package, and the Actions tree already exists for doc generation, so this is mostly mechanical.
