<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use App\Jobs\ReconcilePendingPayoutsJob;
use Illuminate\Console\Scheduling\Schedule;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        //
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })
    ->withSchedule(function (Schedule $schedule) {
        // Reconcile any payout stuck in "Unknown" status every 5 minutes —
        // catches cases where the provider timed out but actually succeeded.
        $schedule->job(new ReconcilePendingPayoutsJob())->everyFiveMinutes();

        // Main payout run — see cadence discussion below.
        $schedule->command('payouts:process')->weekly()->sundays()->at('02:00');
    })
    ->create();