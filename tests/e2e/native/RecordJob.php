<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Writes one line per execution, so a job that runs twice is visible in the file.
 */
class RecordJob implements ShouldQueue
{
    use Queueable;

    public function __construct(public int $number, public int $sleepMs = 0)
    {
    }

    public function handle(): void
    {
        if ($this->sleepMs > 0) {
            // usleep, not Async\delay: the fixture has to be a job a stock
            // queue:work could run too, or it proves nothing about compatibility.
            usleep($this->sleepMs * 1000);
        }

        file_put_contents(
            storage_path('e2e/executions.log'),
            $this->number . PHP_EOL,
            FILE_APPEND | LOCK_EX,
        );
    }
}
