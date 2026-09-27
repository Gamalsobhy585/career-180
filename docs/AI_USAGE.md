# AI Usage Disclosure

## Tool Used

Claude (Anthropic) was used conversationally during the design and development
of this project.

## How AI Was Used

### Architecture and Design Discussion

AI was used as a sounding board for ambiguous technical decisions, including:

- revenue accrual timing: upfront vs monthly
- rounding remainder distribution
- payout idempotency strategy
- refund behavior
- reasoning about a mid-term subscription upgrade

The final decisions were reviewed and selected by the developer.

### Schema and Code Generation

AI assisted with drafting:

- migrations
- models
- factories
- seeders
- allocation actions
- payout jobs and commands
- refund actions
- Filament resources

Generated code was reviewed and adjusted before use.

Examples of developer-driven changes included:

- switching to UUID primary keys
- moving enums into `App\Enums`
- extracting `CalculateSubscriptionPeriodsAction`
- avoiding unnecessary interfaces for single-implementation actions

### Test Writing

AI assisted with drafting unit and feature tests, especially tests covering:

- double-run payout safety
- queue job retry safety
- payment provider timeout safety
- allocation idempotency
- refund idempotency

All tests were executed against the actual project implementation.

### Environment and Tooling Troubleshooting

AI was used to troubleshoot development-environment issues, including:

- Horizon's `pcntl` / `posix` requirements
- native Windows PHP limitations
- Composer issues
- moving from a Laragon-based setup to Laravel Sail / Docker
- Redis and PostgreSQL configuration

### Documentation

The project documentation was drafted with AI assistance and edited to match the
actual implementation and design choices.

## Decisions Made by Me

The following decisions were made by the developer:

- monthly revenue accrual instead of upfront allocation
- deterministic first-N remainder distribution
- payout cadence tied to accrual periods
- no automatic clawback of already-paid earnings
- database-level idempotency as the primary correctness guarantee
- UUID primary keys instead of auto-increment integers
- Laravel Sail / Docker instead of native Windows PHP

## What AI Did Not Do

- AI did not independently define the business rules.
- Ambiguous requirements were discussed before implementation.
- AI did not independently verify the final project behavior.
- Test runs were performed against the developer's local environment.
- Filament screenshots and demo recordings were produced from the actual running application.

## Accuracy Note

This disclosure should be updated if additional AI tools were materially used during
the project. The final document should describe the actual development process.
