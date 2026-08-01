<?php

declare(strict_types=1);

namespace Thrun\Laravel\Native;

use Async\AsyncCancellation;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Queue\Events\JobTimedOut;
use Illuminate\Queue\Worker;
use Illuminate\Queue\WorkerOptions;

/**
 * Laravel's queue worker, running one job inside a worker thread.
 *
 * `Worker::daemon()` is the loop this package replaces. Of what lives only
 * there, the timeout comes back from thrun — every task already runs under a
 * cancellation token — and the between-jobs scope reset is deliberately absent:
 * it fakes a fresh process in a long-lived one, and a coroutine scope already
 * gives every job its own state.
 */
final class NativeWorker extends Worker
{
    /**
     * Run one already reserved job to completion.
     *
     * Deleting, releasing and failing all happen inside, through the framework's
     * own code paths — the caller must not touch the reservation.
     *
     * @throws AsyncCancellation when thrun cancels the task: either the job
     *                           outran its timeout, or the worker is stopping.
     *                           {@see failTimedOutJob()} settles the Laravel side
     *                           before it propagates.
     */
    public function runReservedJob(Job $job, string $connectionName, WorkerOptions $options): void
    {
        try {
            $this->runJob($job, $connectionName, $options);
        } catch (AsyncCancellation $cancellation) {
            $this->failTimedOutJob($job, $connectionName, $options);

            throw $cancellation;
        }
    }

    /**
     * Settle a job thrun cancelled, the way `registerTimeoutHandler()` settles
     * one the alarm caught — minus killing the process, because a thread serves
     * other jobs and must survive this one.
     *
     * A job that already settled itself is left alone: it got there first, and a
     * second owner would delete a reservation that has since been replaced.
     */
    private function failTimedOutJob(Job $job, string $connectionName, WorkerOptions $options): void
    {
        if ($job->isDeletedOrReleased() || $job->hasFailed()) {
            return;
        }

        $exception = $this->timeoutExceededException($job);

        $this->markJobAsFailedIfWillExceedMaxAttempts($connectionName, $job, (int) $options->maxTries, $exception);
        $this->markJobAsFailedIfWillExceedMaxExceptions($connectionName, $job, $exception);
        $this->markJobAsFailedIfItShouldFailOnTimeout($connectionName, $job, $exception);

        $this->events->dispatch(new JobTimedOut($connectionName, $job));
    }
}
