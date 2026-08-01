<?php

declare(strict_types=1);

namespace Thrun\Laravel\Native;

use Async\AsyncCancellation;
use Async\OperationCanceledException;
use Async\Scope;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Queue\Events\JobTimedOut;
use Illuminate\Queue\Worker;
use Illuminate\Queue\WorkerOptions;

use function Async\await;
use function Async\timeout;

/**
 * Laravel's queue worker, running one job inside a coroutine.
 *
 * `Worker::daemon()` is the loop this package replaces, and the timeout alarm
 * lives only there, so it is rebuilt here on the async runtime. The between-jobs
 * scope reset is deliberately absent: it fakes a fresh process in a long-lived
 * one, and a coroutine scope already gives every job its own state.
 *
 * The timeout is cooperative. A job blocked in a C call or spinning on the CPU
 * cannot be interrupted the way `pcntl_alarm` interrupts a process, so a hung
 * job holds its thread until it returns.
 */
final class NativeWorker extends Worker
{
    /**
     * How long the timeout path waits for a job to finish unwinding before it
     * settles the reservation itself. Bounded because a job stuck in C code
     * never acknowledges the cancellation.
     */
    private const SETTLE_GRACE_MS = 2000;

    /**
     * Run one already reserved job to completion, applying the job's timeout.
     *
     * Deleting, releasing and failing all happen inside, through the framework's
     * own code paths — the caller must not touch the reservation.
     *
     * A job that outlives its timeout with attempts left is left reserved, the
     * same outcome `queue:work` produces when the alarm kills its process: the
     * reservation expires and the job comes back on its own.
     *
     * @throws AsyncCancellation when the worker itself is being shut down; the
     *                           job unwinds through Laravel's normal exception
     *                           path and this is not a timeout
     */
    public function runReservedJob(Job $job, string $connectionName, WorkerOptions $options): void
    {
        $timeout = $this->timeoutForJob($job, $options);

        if ($timeout <= 0) {
            $this->runJob($job, $connectionName, $options);

            return;
        }

        // Inherit, so that cancelling the pool reaches the job as well: a job in
        // a root scope would keep running after its worker was told to stop.
        $scope = Scope::inherit();
        $future = $scope->spawn(fn() => $this->runJob($job, $connectionName, $options));

        try {
            await($future, timeout($timeout * 1000));

            return;
        } catch (OperationCanceledException) {
            // The timeout token fired. Handled below.
        } catch (AsyncCancellation $shutdown) {
            $this->cancelAndSettle($scope);

            throw $shutdown;
        }

        // The job settled itself just as the timeout fired. Its delete or release
        // is already in flight — cancelling now would cut that Redis round-trip in
        // half and leave the reservation behind. The flags are set before the I/O,
        // and nothing suspends between here and the cancel below, so this reads a
        // stable state.
        if ($job->isDeletedOrReleased() || $job->hasFailed()) {
            $this->awaitSettled($future);
            $this->events->dispatch(new JobTimedOut($connectionName, $job));

            return;
        }

        // Claim the job before cancelling: Laravel's own exception path checks
        // this flag and will then skip its release(), leaving the timeout path as
        // the single owner of the reservation.
        $job->markAsFailed();

        $this->cancelAndSettle($scope);
        $this->failTimedOutJob($job, $connectionName, $options);
    }

    /**
     * What `registerTimeoutHandler()` does when the alarm fires, minus killing
     * the process: a thread serves other jobs and must survive this one.
     */
    private function failTimedOutJob(Job $job, string $connectionName, WorkerOptions $options): void
    {
        $exception = $this->timeoutExceededException($job);

        $this->markJobAsFailedIfWillExceedMaxAttempts($connectionName, $job, (int) $options->maxTries, $exception);
        $this->markJobAsFailedIfWillExceedMaxExceptions($connectionName, $job, $exception);
        $this->markJobAsFailedIfItShouldFailOnTimeout($connectionName, $job, $exception);

        $this->events->dispatch(new JobTimedOut($connectionName, $job));
    }

    /**
     * Cancel the job and wait out its unwind, so that no `finally` of Laravel's
     * is still talking to Redis when the caller acts on the same job.
     */
    private function cancelAndSettle(Scope $scope): void
    {
        $scope->asNotSafely()->cancel();

        try {
            $scope->awaitAfterCancellation(null, timeout(self::SETTLE_GRACE_MS));
        } catch (AsyncCancellation) {
            // Stuck in code that cannot be interrupted. The flags set before the
            // cancellation still keep a late unwind from settling twice.
        }
    }

    private function awaitSettled(mixed $future): void
    {
        try {
            await($future, timeout(self::SETTLE_GRACE_MS));
        } catch (AsyncCancellation) {
        }
    }
}
