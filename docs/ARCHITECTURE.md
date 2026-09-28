# Architecture

## Overview

The system manages subscription revenue allocation to instructors and safely
processes instructor payouts.

The main financial flow is:

```text
Student Subscription
        ↓
SubscriptionCreated Event
        ↓
Queued Listener
        ↓
AllocateSubscriptionRevenueAction
        ↓
Instructor Earnings Ledger
        ↓
Instructor Balance
        ↓
ProcessInstructorPayouts Command
        ↓
PayInstructorJob
        ↓
Payment Provider
```

The application uses standard Laravel MVC/console architecture. No REST API
layer is required because the main deliverables are internal:

- Artisan commands
- queued jobs
- events/listeners
- domain actions
- Filament administration screens

An API layer would only be required if an external client, mobile application,
or third-party integration needed to interact with the system.

## System Components

Core tables:

| Table | Purpose |
|---|---|
| `students` | Student accounts |
| `instructors` | Instructor accounts |
| `courses` | Courses owned by instructors |
| `subscription_course` | Courses included in a subscription |
| `subscriptions` | Paid student subscription terms |
| `instructor_earnings` | Instructor revenue ledger |
| `instructor_balances` | Denormalized instructor balance read model |
| `payouts` | Payout attempts and final state |
| `payout_items` | Earnings reserved for / included in a payout (immutable snapshot) |
| `refunds` | Refund records |
| `payment_provider_logs` | Provider request/response audit trail |
| `instructor_payout_methods` | Instructor payout destination |
| `platform_settings` | Runtime configuration such as platform cut |

## Subscription Revenue Flow

A subscription creation dispatches a `SubscriptionCreated` event.

A queued listener calls `AllocateSubscriptionRevenueAction`.

This keeps subscription creation independent from revenue allocation logic and
allows the allocation process to be tested separately.

## Revenue Allocation

For each subscription period:

```text
Subscription
    ↓
Courses
    ↓
Instructors
    ↓
Monthly Gross Revenue
    ↓
Platform Cut
    ↓
Instructor Revenue Pool
    ↓
Deterministic Split
    ↓
Instructor Earnings
```

For each month:

1. Calculate the monthly gross amount.
2. Apply the configured platform cut.
3. Resolve instructors attached to the subscription courses.
4. Split the net amount across instructors.
5. Distribute remainder cents deterministically.
6. Create one earning row per instructor and period.
7. Increment the instructor balance only when the earning is newly created.

The unique constraint on earnings protects the system from duplicate allocation.

## Instructor Earnings Ledger

`instructor_earnings` is the primary financial history.

Each row represents a specific instructor's revenue for:

- one subscription
- one accrual period
- one amount
- one state

Typical states:

```text
Pending
Paid
Reversed
```

The system does not overwrite historical financial facts unnecessarily.

## Instructor Balance Read Model

`instructor_balances` stores pre-calculated totals such as:

- total earned
- total paid
- total outstanding

This avoids repeatedly running large aggregate queries over the earnings ledger.

The balance row is updated transactionally alongside ledger operations.

## Payout Processing

Payout processing is split into two phases so that the earnings are claimed
in the database **before** any money movement is attempted.

```text
ProcessInstructorPayouts
        ↓
chunk instructors
        ↓
PayInstructorJob
        ↓
Phase 1: RESERVE (one DB transaction)
   ├─ lock the instructor row
   ├─ resume an existing Pending payout, or:
   ├─ lock unreserved Pending earnings
   ├─ create the payout (status Pending)
   └─ create payout_items (the reservation / immutable snapshot)
        ↓
Phase 2: CLAIM (short transaction with row lock)
   └─ Pending → Processing (only one worker can win)
        ↓
Phase 3: CALL PROVIDER (outside any transaction)
        ↓
payment provider
       /        |         \
 Success     Failure     Timeout / NotFound
    ↓           ↓               ↓
finalize     release          Unknown
 payout      payout              ↓
    ↓           ↓     ReconcilePendingPayoutsJob
 Paid      earnings                ↓
           Pending           Success / Failure
            again          (same finalize / release)
```

### Reservation

`payout_items` rows are created in the same transaction as the payout, before
the provider is called. From that moment the earnings are reserved for exactly
one payout and cannot be selected by another worker
(`whereDoesntHave('payoutItem')`).

The payout therefore owns an immutable snapshot of its earnings. Success and
reconciliation always operate on `payout_items`, never on "whatever earnings
happen to be pending later".

### Finalizing Success

Success handling is a single shared, idempotent routine used by both
`PayInstructorJob` and `ReconcilePendingPayoutsJob`:

```php
DB::transaction(function () {
    // lock the payout row
    // if already Succeeded: return (another worker already applied it)
    // mark payout Succeeded + provider reference + confirmed_at
    // mark the payout's own reserved earnings Paid
    // lock the balance row, decrement outstanding, increment paid
});
```

Because the payout row is locked, a second worker waits for the first
transaction, then sees `Succeeded` and returns. The same success can never be
applied to the balance twice.

### Failure Release

When the provider (or reconciliation) confirms a definitive failure:

1. the payout row is locked
2. its `payout_items` are deleted
3. the payout is marked `Failed`

The earnings are `Pending` and unreserved again, so a later run creates a new
payout with a new idempotency key. The failed attempt remains visible through
the `payouts` row and `payment_provider_logs`.

A payout that is already `Succeeded` or `Failed` is never modified by this step.

## Payout Idempotency

Idempotency comes from the reservation, not from the key alone.

- Earnings can be attached to only one payout (unique constraint on
  `payout_items.instructor_earning_id`).
- A payout that was reserved but not yet attempted is resumed instead of
  duplicated.
- Only one worker can move a payout from `Pending` to `Processing`.
- A payout that is `Processing`, `Unknown`, `Succeeded` or `Failed` is never sent
  to the provider again by the pay job.

Each payout gets a unique idempotency key built from:

- instructor ID
- sorted earning IDs
- a random UUID generated at reservation time

The UUID lets a released (failed) earning set be paid again later with a fresh
key, while the key still travels to the provider so provider-side idempotency
protects a repeated call for the same payout. The database enforces uniqueness
on `payouts.idempotency_key`.

This prevents duplicate transfers caused by:

- repeated command execution
- Laravel job retries
- overlapping schedules
- concurrent workers

## Provider Timeout and Exception Handling

A provider timeout does not necessarily mean that payment failed.

Therefore the payout becomes `Unknown`, and its earnings stay reserved.

If an exception is thrown after the provider call started (network error,
database error while finalizing, etc.), the payout is also moved from
`Processing` to `Unknown`, because the real provider outcome cannot be assumed.

The system does not blindly retry the transfer.

`ReconcilePendingPayoutsJob` later calls the provider status endpoint and
resolves the payout to either success or failure using the same finalize/release
routines described above. If the provider still cannot answer, the payout stays
`Unknown` and is logged for the next reconciliation run.

## Refund Processing

Refunds reverse only eligible future `Pending` earnings.

Already-paid earnings remain unchanged because the money has already left the platform.

The refund process:

1. Finds eligible future earnings.
2. Marks them `Reversed`.
3. Updates instructor balances.
4. Records the refund.
5. Avoids applying the same refund twice.

## Concurrency Control

Critical payout operations use database transactions and row locking. The lock
must cover the whole read → reserve sequence; a lock that is released before
the payout and its items are created protects nothing.

Reservation pattern:

```php
DB::transaction(function () use ($instructor) {
    // lock the instructor row (serializes workers per instructor)
    // resume an existing Pending payout, or:
    // select unreserved Pending earnings with lockForUpdate()
    // create the payout
    // create payout_items for every selected earning
});
```

Finalization and failure release lock the payout row (and the balance row
when it is modified), so the two paths cannot double-apply or contradict each
other.

The mock payment provider uses an atomic per-key cache lock around its
ledger check, outcome roll, and ledger write, so concurrent calls with the same
idempotency key always observe the same outcome and reference.

## Database Constraints

Important unique constraints include:

```text
instructor_earnings:
(instructor_id, subscription_id, period_start, period_end)

payout_items:
instructor_earning_id

payouts:
idempotency_key
```

Correctness is enforced at both application and database levels. The unique
constraint on `payout_items.instructor_earning_id` is the last line of defence:
even a bug in the application code cannot attach one earning to two payouts.

## Scale Considerations

At large scale, `instructor_earnings` can reach tens of millions of rows.

The design addresses this using:

- denormalized instructor balances
- composite indexes on hot query paths
- chunked processing
- queue-based payout execution
- indexed, unique idempotency keys
- per-instructor locking, so different instructors are paid in parallel

A future high-volume implementation may also use:

- partitioning by accrual period
- archival of old paid ledger rows
- queue sharding
- read replicas for reporting