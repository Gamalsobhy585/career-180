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

- successful payout
- failed payout
- provider timeout
- status reconciliation
- idempotency key reuse

## Payout Tests

`PayInstructorJobTest`

The payout tests verify the main financial safety guarantees.

### Double-Run Safety

Running the payout process twice must not transfer the same earnings twice.

### Job-Retry Safety

Retrying the same Laravel queue job must resolve to the same payout instead of
creating a new transfer.

### Timeout Safety

If the provider response is unknown, the system must not resend the payment.

Instead:

```text
Payout
   ↓
Unknown
   ↓
Reconciliation
   ↓
Success / Failure
```

## Refund Tests

`ProcessRefundActionTest`

Covers:

- future pending earnings reversal
- paid earnings protection
- instructor balance adjustment
- repeated refund idempotency

## Recommended Additional Tests

Useful future tests include:

- concurrent workers attempting the same payout
- unique-constraint violation recovery
- balance consistency after failures
- large instructor/course sets
- payout reconciliation after multiple provider responses
- refund attempts after payout completion
