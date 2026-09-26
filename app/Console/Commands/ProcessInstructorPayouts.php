<?php

namespace App\Console\Commands;

use App\Jobs\PayInstructorJob;
use App\Models\Instructor;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

class ProcessInstructorPayouts extends Command
{
    protected $signature = 'payouts:process';

    protected $description =
        'Dispatch a payout job for every instructor with an outstanding balance.';

    public function handle(): int
    {
        $dispatched = 0;
        $failed = 0;

        try {
            Instructor::whereHas(
                'balance',
                fn ($q) => $q->where(
                    'total_outstanding_cents',
                    '>',
                    0
                )
            )
                ->chunkById(
                    200,
                    function ($instructors) use (
                        &$dispatched,
                        &$failed
                    ) {
                        foreach ($instructors as $instructor) {
                            try {
                                PayInstructorJob::dispatch(
                                    $instructor->id
                                );

                                $dispatched++;
                            } catch (Throwable $exception) {
                                $failed++;

                                Log::channel('payouts')->error(
                                    'Failed to dispatch instructor payout job.',
                                    [
                                        'instructor_id' => $instructor->id,
                                        'exception' => $exception->getMessage(),
                                        'exception_class' => get_class(
                                            $exception
                                        ),
                                    ]
                                );
                            }
                        }
                    }
                );
        } catch (Throwable $exception) {
            Log::channel('payouts')->critical(
                'ProcessInstructorPayouts command failed.',
                [
                    'dispatched_count' => $dispatched,
                    'failed_count' => $failed,
                    'exception' => $exception->getMessage(),
                    'exception_class' => get_class($exception),
                ]
            );

            $this->error('Payout processing command failed.');

            return self::FAILURE;
        }

        $this->info(
            "Dispatched {$dispatched} payout job(s). Failed: {$failed}."
        );

        return $failed > 0
            ? self::FAILURE
            : self::SUCCESS;
    }
}