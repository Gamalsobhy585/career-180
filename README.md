# Instructor Revenue Ledger

A Laravel-based revenue allocation and payout system designed to safely distribute
subscription revenue to instructors while remaining correct under retries,
concurrent execution, refunds, and unreliable payment providers.

## Features

- Monthly instructor revenue allocation
- Integer-based money calculations
- Append-only earnings ledger
- Idempotent payout processing
- Redis queue processing
- Laravel Horizon monitoring
- Refund handling
- Provider timeout reconciliation
- Filament administration panel
- PostgreSQL database
- Docker development environment using Laravel Sail

## Tech Stack

- PHP 8.x
- Laravel 11
- PostgreSQL
- Redis
- Laravel Horizon
- Laravel Sail / Docker
- Filament 3
- Pest

## Architecture

The application uses an event-driven Laravel architecture for revenue allocation
and queued payout processing.

See [Architecture Documentation](docs/ARCHITECTURE.md).

## Quick Start

```bash
git clone [<repository-url> career-180](https://github.com/Gamalsobhy585/career-180.git)
cd career-180

composer install
cp .env.example .env

php artisan sail:install --with=pgsql,redis
./vendor/bin/sail up -d

./vendor/bin/sail artisan key:generate
./vendor/bin/sail artisan migrate --seed
```

Full installation instructions:

[Setup Guide](docs/SETUP.md)

## Admin Panel

Create a Filament user:

```bash
./vendor/bin/sail artisan make:filament-user
```

Open:

```text
http://localhost/admin
```

## Queue Monitoring

Start Horizon:

```bash
./vendor/bin/sail artisan horizon
```

Open:

```text
http://localhost/horizon
```

## Testing

```bash
./vendor/bin/sail artisan test
```

Detailed testing documentation:

[Testing Guide](docs/TESTING.md)

## Documentation

- [Architecture](docs/ARCHITECTURE.md)
- [Setup](docs/SETUP.md)
- [Testing](docs/TESTING.md)
- [Design Decisions](docs/DESIGN_DECISIONS.md)
- [AI Usage Disclosure](docs/AI_USAGE.md)

## Important Design Decisions

The project intentionally uses:

- integer cents instead of floating-point money
- monthly revenue accrual
- database-level idempotency constraints
- deterministic rounding
- an append-oriented financial ledger
- reconciliation instead of blindly retrying unknown payouts

Detailed reasoning:

[Design Decisions](docs/DESIGN_DECISIONS.md)

## License

This project was developed as a technical assessment.
