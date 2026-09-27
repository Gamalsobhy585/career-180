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
| `payout_items` | Earnings included in a payout |
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
- available balance
- pending payout amount

This avoids repeatedly running large aggregate queries over the earnings ledger.

The balance row is updated transactionally alongside ledger operations.

## Payout Processing

The payout flow is:

```text
ProcessInstructorPayouts
        ↓
chunk instructors
        ↓
PayInstructorJob
        ↓
DB transaction
        ↓
lock pending earnings
        ↓
generate idempotency key
        ↓
create/reuse payout
        ↓
payment provider
       /       |       \
Success     Failure    Timeout
   ↓           ↓          ↓
 Paid       Pending     Unknown
                           ↓
             ReconcilePendingPayoutsJob
                           ↓
                   Success / Failure
```

## Payout Idempotency

The job creates a deterministic idempotency key from:

- instructor ID
- sorted earning IDs

The database enforces uniqueness on the payout idempotency key.

This prevents duplicate provider calls caused by:

- repeated command execution
- Laravel job retries
- overlapping schedules
- concurrent workers

## Provider Timeout Handling

A provider timeout does not necessarily mean that payment failed.

Therefore the payout becomes `Unknown`.

The system does not blindly retry the transfer.

`ReconcilePendingPayoutsJob` later calls the provider status endpoint and resolves
the payout to either success or failure.

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

Critical payout operations use database transactions and row locking.

Typical pattern:

```php
DB::transaction(function () {
    // select pending earnings
    // lockForUpdate()
    // create/reuse payout
    // attach payout items
    // update earning state
    // update balance
});
```

This prevents workers from claiming the same earnings concurrently.

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

Correctness is enforced at both application and database levels.

## Scale Considerations

At large scale, `instructor_earnings` can reach tens of millions of rows.

The design addresses this using:

- denormalized instructor balances
- composite indexes on hot query paths
- chunked processing
- queue-based payout execution
- deterministic, indexed idempotency keys

A future high-volume implementation may also use:

- partitioning by accrual period
- archival of old paid ledger rows
- queue sharding
- read replicas for reporting
