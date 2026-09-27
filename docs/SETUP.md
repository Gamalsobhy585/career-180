# Development Setup

## Requirements

Before running the project, make sure the following are installed:

- Docker Desktop
- Git
- Composer

Docker Desktop must be running before Laravel Sail is started.

## 1. Clone the Repository

```bash
git clone <repository-url> career-180
cd career-180
```

## 2. Install PHP Dependencies

```bash
composer install
```

## 3. Configure Environment

Copy the example environment file:

```bash
cp .env.example .env
```

Configure PostgreSQL and Redis:

```dotenv
DB_CONNECTION=pgsql
DB_HOST=pgsql
DB_PORT=5432
DB_DATABASE=career_180
DB_USERNAME=sail
DB_PASSWORD=password

REDIS_CLIENT=predis
REDIS_HOST=redis
REDIS_PORT=6379

QUEUE_CONNECTION=redis
CACHE_STORE=redis
SESSION_DRIVER=redis
```

## 4. Install Laravel Sail Services

```bash
php artisan sail:install --with=pgsql,redis
```

This configures Docker containers for:

- Laravel
- PostgreSQL
- Redis

## 5. Start Containers

```bash
./vendor/bin/sail up -d
```

Verify running containers:

```bash
docker ps
```

## 6. Generate Application Key

```bash
./vendor/bin/sail artisan key:generate
```

## 7. Run Database Migrations

```bash
./vendor/bin/sail artisan migrate --seed
```

## 8. Create Filament Administrator

```bash
./vendor/bin/sail artisan make:filament-user
```

## 9. Start Horizon

```bash
./vendor/bin/sail artisan horizon
```

Horizon processes Redis-backed queue jobs used by revenue allocation and payout processing.

## Application URLs

| Service | URL |
|---|---|
| Application | `http://localhost` |
| Filament Admin | `http://localhost/admin` |
| Horizon | `http://localhost/horizon` |

## Useful Sail Commands

Start:

```bash
./vendor/bin/sail up -d
```

Stop:

```bash
./vendor/bin/sail down
```

Run migrations:

```bash
./vendor/bin/sail artisan migrate
```

Run tests:

```bash
./vendor/bin/sail artisan test
```

Open application container:

```bash
./vendor/bin/sail shell
```

View logs:

```bash
./vendor/bin/sail logs
```

## Optional Sail Alias

```bash
alias sail='sh $([ -f sail ] && echo sail || echo vendor/bin/sail)'
```

Then:

```bash
sail up -d
sail artisan migrate
sail artisan test
sail artisan horizon
```
