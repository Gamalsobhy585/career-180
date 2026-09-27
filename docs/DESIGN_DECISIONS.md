# Design Decisions

## 1. Money Is Stored as Integer Cents

### Decision

All monetary amounts are stored as integer cents.

Examples:

```text
$10.50  -> 1050
$100.00 -> 10000
```

### Reason

Floating-point numbers can introduce rounding inaccuracies in financial calculations.

### Trade-off

Presentation code must convert cents into formatted currency values.

## 2. Append-Oriented Financial Ledger

### Decision

`instructor_earnings` records financial facts instead of continuously replacing
historical values.

### Reason

The system must remain auditable.

A reviewer should be able to trace how an instructor's balance was produced.

## 3. Monthly Revenue Accrual

### Decision

Subscription revenue is allocated monthly instead of allocating the full term upfront.

### Alternatives Considered

- Allocate the full payment immediately
- Accrue revenue monthly

### Reason

Monthly accrual makes refunds safer because future not-yet-earned revenue can be
reversed before it is paid.

### Trade-off

The allocation process creates more ledger rows.

## 4. Deterministic Rounding

### Decision

When an amount cannot be divided evenly between instructors, remainder cents are
assigned to the first N instructors in a stable ordering.

### Reason

No money can be lost or invented, and repeated allocation produces the same result.

## 5. Database-Level Idempotency

### Decision

Idempotency is protected using database constraints in addition to application logic.

Key constraints:

```text
instructor_earnings:
unique(instructor_id, subscription_id, period_start, period_end)

payout_items:
unique(instructor_earning_id)

payouts:
unique(idempotency_key)
```

### Reason

Application checks alone are insufficient under concurrency.

The database remains the final correctness boundary.

## 6. Unknown Payout State

### Decision

A timeout from the payment provider moves the payout to `Unknown`.

The system does not resend the payment automatically.

### Reason

The provider may have processed the transfer before the connection timed out.

Retrying blindly could cause a double payment.

### Resolution

`ReconcilePendingPayoutsJob` calls the provider status endpoint later.

## 7. Paid Earnings Are Not Automatically Reversed

### Decision

Refund processing does not reverse earnings that have already been paid.

### Reason

Changing the ledger would no longer match the real-world money movement.

Recovering already-paid money would require a separate clawback/dispute process.

## 8. UUID Primary Keys

### Decision

Core entities use UUID primary keys through Laravel's `HasUuids`.

### Reason

UUIDs:

- avoid exposing sequential IDs
- work well across distributed systems
- avoid coordination around auto-increment ranges

### Trade-off

UUID indexes are larger than integer indexes.

## 9. Integer-Backed Enums

### Decision

Status and type values use PHP backed enums stored as small integer columns.

Examples include:

- subscription status
- earning status
- payout status
- payment provider outcome
- payout method type

### Reason

This provides type safety in PHP while keeping database storage compact.

## 10. Denormalized Instructor Balances

### Decision

The system maintains an `instructor_balances` table.

### Reason

Calculating balance values by repeatedly aggregating millions of earnings rows would
be expensive.

The balance table acts as a fast read model.

### Trade-off

The system must update balances transactionally to prevent drift.

## 11. Event-Driven Revenue Allocation

### Decision

Revenue allocation is triggered through a `SubscriptionCreated` event and queued listener.

### Reason

Subscriptions may be created from multiple entry points.

The event ensures the allocation behavior remains consistent while keeping the
subscription creation flow focused on its own responsibility.

## Mid-Term Subscription Plan Upgrade

> Discussion only — not implemented.

### Problem

A student may upgrade from one subscription plan to another before the current
subscription term ends.

### Proposed Approach

A safe implementation should avoid mutating previously earned history.

Possible flow:

1. Determine the effective upgrade date.
2. Calculate unused value from the current plan.
3. Create a credit or adjustment record.
4. Close future accrual periods from the old plan.
5. Create a new subscription/version for the upgraded plan.
6. Allocate future revenue according to the new plan.
7. Keep all previous paid/earned rows unchanged.

### Ledger Impact

Already-earned or paid entries should remain immutable.

Future `Pending` entries belonging to the old plan may be reversed and replaced with
new entries representing the upgraded plan.

### Idempotency Considerations

The upgrade operation should use a unique business key or dedicated upgrade record so
the same upgrade cannot be applied twice.

### Why It Was Not Implemented

The feature is outside the required implementation scope and is documented as a
possible extension.
