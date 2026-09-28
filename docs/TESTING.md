# Testing

## Running Tests

Run the complete test suite:

```bash
./vendor/bin/sail artisan test
```

## Test Structure

```text
tests/
├── Feature/
└── Unit/
```

## Revenue Allocation Tests

`AllocateSubscriptionRevenueActionTest`

Covers:

- monthly revenue calculation
- platform cut calculation
- instructor revenue split
- deterministic remainder distribution
- repeated allocation safety
- instructor balance updates

## Payment Provider Tests

`MockPaymentProviderTest`

Covers:

- a result is returned for every payment attempt
- successful payout
- failed payout
- provider timeout
- status reconciliation (`checkStatus` resolves a timeout to its real outcome)
- `NotFound` for an unknown idempotency key
- idempotency key reuse: repeated calls with the same key always settle to a
  single outcome and a single provider reference

The mock provider runs its ledger check, outcome roll, and ledger write under an
atomic per-key cache lock, so two simultaneous calls with the same idempotency
key can never produce different outcomes or references.

## Payout Tests

`PayInstructorJobTest`

The payout tests verify the main financial safety guarantees.

### Double-Run Safety

Running the payout process twice must not transfer the same earnings twice.
The test also asserts that the instructor balance moves exactly once
(`total_paid_cents` increases, `total_outstanding_cents` decreases).

### Job-Retry Safety

Retrying the same Laravel queue job must not create a second payout or a second
transfer. The earnings are already reserved by the first payout, so the retry
finds nothing left to claim.

### Reservation Before Provider Call

Earnings must be attached to the payout (`payout_items`) inside the same
transaction that creates the payout, before the provider is called.

The test uses a mock provider that, at the moment `pay()` is invoked, asserts:

- the `payout_items` row already exists
- the payout status is already `Processing`

### Single Application of Success

A payout success must be applied to the database only once, even when more
than one code path learns about it (the pay job and reconciliation).

The test finalizes the same payout twice and asserts that:

- the balance changes only once
- the first provider reference is kept
- the earning is `Paid`

### Timeout Safety

If the provider response is unknown, the system must not resend the payment.

Instead:

```text
Payout (earnings reserved)
   ↓
Unknown
   ↓
Reconciliation
   ↓
Success / Failure
```

The test also verifies that while the payout is `Unknown`:

- the earnings stay reserved (not paid, not claimable by another payout)
- running the job again does not create a second payout
- reconciliation confirming success pays exactly the reserved earnings and
  updates the balance once

### Failure Release

A definitive provider failure must release the reserved earnings.

```text
Payout (earnings reserved)
   ↓
Failed
   ↓
payout_items removed
   ↓
Earnings Pending again
   ↓
Next run creates a NEW payout (new idempotency key)
```

Two tests cover this:

- failure returned directly by the provider during the pay job
- failure discovered later by `ReconcilePendingPayoutsJob`

## Refund Tests

`ProcessRefundActionTest`

Covers:

- future pending earnings reversal
- paid earnings protection
- instructor balance adjustment
- repeated refund idempotency

## Recommended Additional Tests

Useful future tests include:

- true multi-process concurrency: several real workers claiming the same
  instructor at once (the current tests cover the guarantees sequentially,
  through reservation and lock-protected finalization)
- unique-constraint violation recovery on `payout_items.instructor_earning_id`
- exception thrown after the provider call (payout must end as `Unknown`)
- balance consistency after repeated failures and retries
- large instructor/course sets
- payout reconciliation after multiple provider responses
- refund attempts after payout completion