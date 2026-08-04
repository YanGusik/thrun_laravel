<?php

declare(strict_types=1);

namespace Thrun\Laravel\Native;

use Illuminate\Container\Container as ContainerInstance;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Queue\Jobs\RedisJob;
use Illuminate\Queue\RedisQueue;
use Illuminate\Queue\WorkerOptions;
use Throwable;
use Thrun\Worker\Acknowledger;
use Thrun\Worker\Outcome;

/**
 * Runs one Laravel job inside a worker thread.
 *
 * Instantiated by thrun in the thread that will run the job, so everything it
 * touches — container, Redis connection, job object — belongs to that thread.
 * What crossed the boundary is four strings; the job itself is rebuilt here and
 * unserialized by the framework, which is why jobs holding models or closures
 * work as they always did.
 */
final class NativeJobHandler
{
    /**
     * @param array{job: array<string, string>, options: array{tries: int, timeout: int, backoff: int}} $payload
     */
    public function __invoke(array $payload, Acknowledger $acknowledger): void
    {
        $reserved = ReservedJob::fromArray($payload['job']);

        // The application booted by this thread's bootloader.
        $app = ContainerInstance::getInstance();
        $job = null;

        try {
            $queue = $app->make('queue')->connection($reserved->connectionName);

            if (!$this->extendReservation($app, $queue, $reserved)) {
                return;
            }

            $job = new RedisJob(
                $app,
                $queue,
                $reserved->body,
                $reserved->reserved,
                $reserved->connectionName,
                $reserved->queueName,
            );

            $app->make(NativeWorker::class)->runReservedJob(
                $job,
                $reserved->connectionName,
                $this->workerOptions($payload['options']),
            );
        } finally {
            // Report the outcome rather than act on it. Laravel has already
            // deleted, released or failed the job through its own worker, so
            // failing it here as well would write a second failure record and
            // push a retry copy of a job Laravel is already retrying.
            $acknowledger->observe($this->outcomeOf($job));
        }
    }

    /**
     * Failure comes before release because a job released after an exception
     * carries both flags, and the last attempt of such a job is a failure. A job
     * that never came into being — the reservation was lost, or the queue
     * connection did not open — counts as nothing: nobody ran it.
     */
    private function outcomeOf(?Job $job): Outcome
    {
        if ($job === null) {
            return Outcome::Skipped;
        }

        return match (true) {
            $job->hasFailed()  => Outcome::Failure,
            $job->isReleased() => Outcome::Retried,
            default            => Outcome::Success,
        };
    }

    /**
     * Claim the reservation for this thread by pushing its expiry out to
     * `retry_after` from now.
     *
     * Returns false when the reservation is gone — the job waited long enough in
     * the pool that `migrateExpiredJobs()` handed it back to the ready list and
     * somebody else owns it now. The only safe move then is to walk away: running
     * it would execute the job twice, and deleting or failing it would corrupt the
     * other owner's state.
     *
     * Laravel scores the reservation when the job is reserved, not when it starts,
     * so a job that waits in the pool burns part of its own window. Re-scoring on
     * start gives it back. `XX GT` only raises the score of an existing member, so
     * the member bytes stay identical and the later delete-by-value still matches.
     */
    private function extendReservation(Container $app, RedisQueue $queue, ReservedJob $reserved): bool
    {
        $retryAfter = (int) $app->make('config')
            ->get("queue.connections.{$reserved->connectionName}.retry_after", 60);

        $key = $queue->getQueue($reserved->queueName) . ':reserved';

        try {
            $queue->getConnection()->zadd($key, 'XX', 'GT', time() + $retryAfter, $reserved->reserved);

            // phpredis answers false for a missing member, Predis null.
            return !in_array($queue->getConnection()->zscore($key, $reserved->reserved), [false, null], true);
        } catch (Throwable $e) {
            // Any Redis failure here — GT needs 6.2, the connection may be down —
            // degrades to stock behaviour: the job runs with the window it was
            // reserved with, and the lost-reservation check is skipped with it.
            $app->make(ExceptionHandler::class)->report($e);

            return true;
        }
    }

    /**
     * The per-job settings Laravel's worker reads. They come from the command so
     * that `--tries`, `--timeout` and `--backoff` mean here what they mean for
     * `queue:work`; a job's own properties still win, as they do there.
     *
     * @param array{tries: int, timeout: int, backoff: int} $settings
     */
    private function workerOptions(array $settings): WorkerOptions
    {
        $options = new WorkerOptions();
        $options->maxTries = $settings['tries'];
        $options->timeout = $settings['timeout'];
        $options->backoff = $settings['backoff'];

        return $options;
    }
}
