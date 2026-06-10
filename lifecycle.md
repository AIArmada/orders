# Orders Package — Lifecycle Audit

## 1. Executive Summary

The `orders` package has a well-defined state machine (13 states via `spatie/laravel-model-states`) and a layered data model (Order → Items, Addresses, Payments, Refunds, Notes). However, the schema is **out of sync** with the state machine: several key state transitions have no corresponding `timestampTz` column, `completed_at` is buried in JSON `metadata`, `is_*` booleans exist where `*_at` timestamps should, and child tables (`order_items`) have no lifecycle at all. The audit caps new `*_at` columns at business-critical transitions: `refunded_at` and `payment_failed_at`. Transient states (processing, on_hold, pending_payment) do not get timestamp columns.

**High-impact gaps:**
- Missing `refunded_at` and `payment_failed_at` on orders
- `OrderCompleted` stores `completed_at` only in JSON metadata — should be a column
- `OrderNote.is_customer_visible` is a boolean; should be `visibility` string
- `OrderRefund` reuses `PaymentStatus` enum instead of a dedicated `RefundStatus`
- `OrderItem` has no lifecycle fields — items are immortal

---

## 2. Full Inventory by Table

### 2.1 `orders`

| Column / Concept | Present? | Type | Default | Problem |
|---|---|---|---|---|
| `id` | yes | uuid PK | — | OK |
| `order_number` | yes | string unique | — | OK |
| `status` | yes | string(50) | `processing` | **Default mismatch** — state machine starts at `created`, migration defaults to `processing` |
| `paid_at` | yes | timestampTz | null | OK, set by `PaymentConfirmed` transition |
| `shipped_at` | yes | timestampTz | null | OK, set by `ShipmentCreated` transition |
| `delivered_at` | yes | timestampTz | null | OK, set by `DeliveryConfirmed` transition |
| `canceled_at` | yes | timestampTz | null | OK, set by `OrderCanceled` transition |
| `cancellation_reason` | yes | string | null | OK |
| — | — | — | — | — |
| `created_at` | yes | timestampTz | auto | OK, creation time |
| `payment_failed_at` | **NO** | — | — | Missing. No timestamp for `payment_failed` state entry |
| `refunded_at` | **NO** | — | — | Missing on orders. RefundProcessed transition sets `refunded_at` only on the child `order_refunds` record, never on the order |
| `completed_at` | **NO** | — | — | **Buried in JSON metadata.** `OrderCompleted` writes `metadata.completion.completed_at` as ISO string, not a column |

**State Machine Coverage (13 states):** Only business-critical terminal/failure states and user-facing milestone states get timestamps. Transient states (processing, pending_payment, on_hold, returned, fraud) do not.

| State | Has `*_at` Column? | Notes |
|---|---|---|
| `created` | `created_at` (auto) | OK |
| `pending_payment` | — | Transient — no timestamp |
| `processing` | — | Transient — no timestamp |
| `on_hold` | — | Transient — no timestamp |
| `shipped` | `shipped_at` | OK |
| `delivered` | `delivered_at` | OK |
| `returned` | — | Transient — no timestamp |
| `completed` | **NO** (`completed_at`) | Buried in JSON metadata — promote to column |
| `canceled` | `canceled_at` | OK |
| `refunded` | **NO** (`refunded_at`) | Only on child `order_refunds` table |
| `payment_failed` | **NO** (`payment_failed_at`) | |
| `fraud` | — | Transient — no timestamp |

### 2.2 `order_payments`

| Column / Concept | Present? | Type | Default | Problem |
|---|---|---|---|---|
| `id` | yes | uuid PK | — | OK |
| `order_id` | yes | foreignUuid | — | OK |
| `gateway` | yes | string(50) | — | OK |
| `transaction_id` | yes | string nullable | null | OK |
| `amount` | yes | unsignedBigInt | 0 | OK |
| `currency` | yes | string(3) | MYR | OK |
| `status` | yes | string(20) | pending | OK (PaymentStatus enum) |
| `failure_reason` | yes | text nullable | null | OK |
| `paid_at` | yes | timestampTz | null | OK |
| `failed_at` | **NO** | — | — | Missing. Payment can fail but only `failure_reason` is stored, no timestamp |
| `refunded_at` | **NO** | — | — | Missing. When payment status is `refunded`, no timestamp records when |

### 2.3 `order_refunds`

| Column / Concept | Present? | Type | Default | Problem |
|---|---|---|---|---|
| `id` | yes | uuid PK | — | OK |
| `order_id` | yes | foreignUuid | — | OK |
| `payment_id` | yes | foreignUuid nullable | null | OK |
| `gateway` | yes | string(50) | — | OK |
| `transaction_id` | yes | string nullable | null | OK |
| `amount` | yes | unsignedBigInt | 0 | OK |
| `currency` | yes | string(3) | MYR | OK |
| `status` | yes | string(20) | pending | **Uses `PaymentStatus` enum** (includes `Refunded` case). Should have dedicated `RefundStatus` enum |
| `reason` | yes | string | — | OK |
| `notes` | yes | text nullable | null | OK |
| `refunded_at` | yes | timestampTz | null | OK |
| `failed_at` | **NO** | — | — | Missing. Refunds can fail too |

### 2.4 `order_items`

| Column / Concept | Present? | Type | Default | Problem |
|---|---|---|---|---|
| `status` | **NO** | — | — | **Items have no lifecycle.** Cannot track item-level state (e.g., backordered, shipped, returned, canceled) |
| Any `*_at` | **NO** | — | — | No transition timestamps for items |
| `created_at` | yes | timestampTz | auto | OK |

### 2.5 `order_addresses`

No lifecycle fields — addresses are immutable snapshots. OK.

### 2.6 `order_notes`

| Column / Concept | Present? | Type | Default | Problem |
|---|---|---|---|---|
| `is_customer_visible` | yes | boolean | false | **`is_*` boolean anti-pattern.** Should be `visibility` string/varchar with enum values (`internal`, `customer`) |
| `internal` scope | yes | query scope | — | Coupled to `is_customer_visible` boolean — must update |

---

## 3. Problems Summary

| # | Severity | Table | Issue |
|---|---|---|---|
| P1 | **High** | orders | Missing `payment_failed_at` — no record of when payment failure occurred |
| P2 | **High** | orders | Missing `refunded_at` on orders — only recorded on child `order_refunds` row |
| P3 | **High** | orders | `completed_at` buried in JSON metadata instead of a column — promote to timestampTz |
| P4 | **High** | order_payments | Missing `failed_at` — payment failure only has `failure_reason` text |
| P5 | **Medium** | order_payments | Missing `refunded_at` — when payment is refunded, no timestamp |
| P6 | **Medium** | order_refunds | Missing `failed_at` — refund failure has no timestamp |
| P7 | **Medium** | order_refunds | Reuses `PaymentStatus` enum — should have dedicated `RefundStatus` |
| P8 | **Medium** | order_items | No `status` column — items have no lifecycle at all |
| P9 | **Low** | order_notes | `is_customer_visible` boolean → should be `visibility` string |
| P10 | **Low** | orders | Migration default `status = 'processing'` but state machine starts at `created` |

---

## 4. Recommended Structure

### 4.1 `orders` — Target Schema (new columns only)

```sql
-- Add to orders table (all nullable timestampTz):
payment_failed_at  timestampTz  -- when state → PaymentFailed
refunded_at        timestampTz  -- when state → Refunded
completed_at       timestampTz  -- when state → Completed (promoted from JSON metadata)

-- Change default:
ALTER COLUMN status SET DEFAULT 'created'
```

**State → Timestamp Mapping (business-critical only):**

| State | Timestamp Column |
|---|---|
| `created` | `created_at` (auto) |
| `shipped` | `shipped_at` (exists) |
| `delivered` | `delivered_at` (exists) |
| `completed` | `completed_at` (new — promote from JSON) |
| `canceled` | `canceled_at` (exists) |
| `refunded` | `refunded_at` (new) |
| `payment_failed` | `payment_failed_at` (new) |

Transient states (pending_payment, processing, on_hold, returned, fraud) intentionally have no timestamps — they are not business-critical milestones.

### 4.2 `order_payments` — Target Schema

```sql
failed_at    timestampTz  -- when payment failed
refunded_at  timestampTz  -- when payment was refunded
```

### 4.3 `order_refunds` — Target Schema

```sql
failed_at    timestampTz  -- when refund failed
```

Enum: Create `AIArmada\Orders\Enums\RefundStatus` with cases: `Pending`, `Completed`, `Failed`.

### 4.4 `order_items` — Target Schema

```sql
status       string(30)  -- item lifecycle: active, shipped, delivered, returned, canceled, backordered
shipped_at   timestampTz
delivered_at timestampTz
returned_at  timestampTz
canceled_at  timestampTz
```

### 4.5 `order_notes` — Target Schema

```sql
-- Rename:
is_customer_visible → visibility  string(20)  -- 'internal' | 'customer'
```

---

## 5. Refactoring Plan

### Phase 1: Add Missing Columns to `orders`

- [x] Create migration: add `payment_failed_at`, `refunded_at`, `completed_at` (all `timestampTz()->nullable()`)
- [x] Create migration: change `status` default from `'processing'` to `'created'`
- [x] Update `Order` model `$fillable`, `casts()`, PHPDoc `@property`
- [x] Update `Order::$auditInclude` to include new timestamps
- [x] Backfill: for `completed` orders with JSON metadata, extract `completed_at` from `metadata.completion.completed_at`
- [x] Update `OrderCompleted` transition: use `completed_at` column instead of JSON metadata
- [x] Update `RefundProcessed` transition: set `refunded_at` on the order (in addition to the refund record)
- [x] Add `PaymentFailed` transition that sets `payment_failed_at`

### Phase 2: Fix `order_payments`

- [x] Create migration: add `failed_at`, `refunded_at` (both `timestampTz()->nullable()`)
- [x] Update `OrderPayment` model `$fillable`, `casts()`, PHPDoc
- [x] Update `OrderPayment::markAsFailed()` to set `failed_at = now()`
- [x] Add `OrderPayment::markAsRefunded()` method that sets `refunded_at = now()`
- [x] Update `RefundProcessed::handle()` to mark original payment as refunded with timestamp

### Phase 3: Fix `order_refunds`

- [x] Create migration: add `failed_at` (`timestampTz()->nullable()`)
- [x] Create `RefundStatus` enum: `Pending`, `Completed`, `Failed`
- [x] Update migration: change `status` column to use `RefundStatus` default
- [x] Update `OrderRefund` model: change `status` cast from `PaymentStatus` to `RefundStatus`
- [x] Update `OrderRefund::$fillable`, `casts()`, PHPDoc
- [x] Update `OrderRefund::markAsFailed()` to set `failed_at = now()`
- [x] Update all code that creates refunds: use `RefundStatus` enum instead of `PaymentStatus`
- [x] Update `RefundProcessed::handle()` — use `RefundStatus::Completed`

### Phase 4: Fix `order_notes`

- [x] Create migration: rename `is_customer_visible` → `visibility` (`string('visibility', 20)->default('internal')`)
- [x] Data migration: set `visibility = 'customer'` where `is_customer_visible = true`, `'internal'` otherwise
- [x] Update `OrderNote` model: rename `$fillable` key, update casts
- [x] Update scopes: `scopeInternal()` → `where('visibility', 'internal')`, `scopeCustomerVisible()` → `where('visibility', 'customer')`
- [x] Update all call sites that set `is_customer_visible` → `visibility`

### Phase 5: Add Lifecycle to `order_items`

- [x] Define `OrderItemStatus` enum: `Active`, `Shipped`, `Delivered`, `Returned`, `Canceled`, `Backordered`
- [x] Create migration: add `status` (`string('status', 30)->default('active')`), `shipped_at`, `delivered_at`, `returned_at`, `canceled_at` (all `timestampTz()->nullable()`)
- [x] Update `OrderItem` model: `$fillable`, `casts()`, PHPDoc
- [x] Update `OrderItem::getAuditInclude()`
- [x] Wire item state transitions into order-wide transitions (e.g., `ShipmentCreated` sets item `status = shipped` and `shipped_at`, `DeliveryConfirmed` sets item `status = delivered` and `delivered_at`)

---

## 6. Migration Strategy

**No backward compatibility.** This is a beta-status package where breaking changes are allowed.

### Execution order:
1. Run all 5 phases above sequentially (dependencies: Phase 2 updates call sites that Phase 3 also touches; Phase 5 touches transitions modified in Phase 1)
2. Each phase has its own migration file with timestamp prefix after the existing migrations
3. Data backfill migrations go in the same file as schema changes

### Migration file plan:
```
2010_01_01_000001_add_order_lifecycle_timestamps.php           # Phase 1
2010_01_01_000002_add_payment_lifecycle_timestamps.php          # Phase 2
2010_01_01_000003_add_refund_lifecycle_and_status_enum.php      # Phase 3
2010_01_01_000004_rename_order_notes_visibility.php             # Phase 4
2010_01_01_000005_add_order_item_lifecycle.php                  # Phase 5
```

### Rollout:
- Fresh installs: migrations create the final schema
- Existing installs: migrations add columns safely (all nullable, no data loss)
- Backfills use `DB::statement()` for efficiency

---

## 7. Verification Commands

### Schema verification
```bash
# Verify all new columns exist
php artisan migrate:status

# Check orders table for all timestamp columns
php artisan tinker --execute 'dump(Schema::getColumnListing("orders"));'
# Expected: id, order_number, status, ..., paid_at, shipped_at, delivered_at, canceled_at,
#          payment_failed_at, refunded_at, completed_at

# Check order_payments
php artisan tinker --execute 'dump(Schema::getColumnListing("order_payments"));'
# Expected: ..., failed_at, refunded_at

# Check order_refunds
php artisan tinker --execute 'dump(Schema::getColumnListing("order_refunds"));'
# Expected: ..., failed_at

# Check order_notes — verify is_customer_visible is gone
php artisan tinker --execute 'dump(Schema::getColumnListing("order_notes"));'
# Expected: visibility (not is_customer_visible)

# Check order_items
php artisan tinker --execute 'dump(Schema::getColumnListing("order_items"));'
# Expected: status, shipped_at, delivered_at, returned_at, canceled_at
```

### Code verification
```bash
# PHPStan on the package
./vendor/bin/phpstan analyse packages/orders/src --level=6

# Verify no remaining is_customer_visible references
rg -n "is_customer_visible" packages/orders/src packages/orders/database

# Verify no remaining PaymentStatus usage in OrderRefund
rg -n "PaymentStatus" packages/orders/src/Models/OrderRefund.php

# Verify all transitions set the new timestamps
rg -n "payment_failed_at\|refunded_at\|completed_at" packages/orders/src/Transitions/

# Verify Order model cast/PHPDoc includes all new timestamps
rg -n "payment_failed_at\|refunded_at\|completed_at" packages/orders/src/Models/Order.php

# Find call sites using is_customer_visible or PaymentStatus for refunds (outside models)
rg -n "is_customer_visible\b" packages/orders --include='*.php'
rg -n "PaymentStatus" packages/orders/src/Actions packages/orders/src/Transitions
```

### Test verification
```bash
# Run orders package tests
./vendor/bin/pest --parallel packages/orders/tests/

# Run with coverage
./vendor/bin/pest --coverage --parallel packages/orders/tests/
```
