<?php

declare(strict_types=1);

namespace Thrun\Laravel\Native;

use Illuminate\Contracts\Queue\Job;
use Illuminate\Queue\Events\JobTimedOut;
use Illuminate\Queue\Worker;
use Illuminate\Queue\WorkerOptions;

use function Async\current_coroutine;

/**
 * Laravel's queue worker, running one job inside a worker thread.
 *
 * `Worker::daemon()` is the loop this package replaces. The timeout comes back
 * from thrun, which already runs every task under a cancellation token, and the
 * between-jobs scope reset is deliberately absent: it fakes a fresh process in a
 * long-lived one, and a coroutine scope already gives every job its own state.
 */
final class NativeWorker extends Worker
{
    /**
     * Run one already reserved job to completion.
     *
     * Deleting, releasing and failing all happen inside, through the framework's
     * own paths — the caller must not touch the reservation. A timeout is one of
     * those paths too: thrun cancels the task, the cancellation surfaces inside
     * the job as an ordinary exception, and Laravel releases a job with attempts
     * left or fails one without.
     */
    public function runReservedJob(Job $job, string $connectionName, WorkerOptions $options): void
    {
        $this->runJob($job, $connectionName, $options);

        if ($this->wasCancelled()) {
            // Only the event: Laravel has already settled the reservation, and a
            // second settle here would delete an entry that release() replaced.
            // `$failOnTimeout` therefore does not apply — see the README.
            $this->events->dispatch(new JobTimedOut($connectionName, $job));
        }
    }

    /**
     * Whether the coroutine running this job was cancelled — the job outran its
     * timeout, or the worker is shutting down. Laravel's own worker cannot ask
     * this: there the answer is a signal that kills the process.
     */
    private function wasCancelled(): bool
    {
        $coroutine = current_coroutine();

        return $coroutine !== null && ($coroutine->isCancelled() || $coroutine->isCancellationRequested());
    }
}
