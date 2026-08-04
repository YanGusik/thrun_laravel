<?php

declare(strict_types=1);

namespace Thrun\Laravel\Native;

use Illuminate\Contracts\Container\Container;
use Illuminate\Queue\Events\JobFailed;
use Throwable;

/**
 * Writes every job the framework gives up on to the application's failed-job
 * store.
 *
 * Nothing in the framework does this on its own: the only writer is a
 * `JobFailed` listener that the `queue:work` command installs, and native mode
 * drives the worker without that command. Left out, a job that exhausted its
 * attempts is logged and then forgotten — `queue:failed` shows nothing and
 * `queue:retry` has nothing to replay.
 */
final readonly class FailedJobRecorder
{
    public function __construct(private Container $container) {}

    public function __invoke(JobFailed $event): void
    {
        try {
            $this->container->make('queue.failer')->log(
                $event->connectionName,
                $event->job->getQueue(),
                $event->job->getRawBody(),
                $event->exception,
            );
        } catch (Throwable $e) {
            // This runs from the `finally` of Job::fail(), so anything thrown
            // here replaces the exception the job failed with and is then
            // swallowed by Illuminate's worker — both would vanish. The job
            // itself is already gone: it was deleted before the store was
            // written.
            error_log(sprintf(
                '[Thrun] could not record a failed job on queue "%s": %s: %s; the job failed with %s: %s',
                $event->job->getQueue(),
                $e::class,
                $e->getMessage(),
                $event->exception::class,
                $event->exception->getMessage(),
            ));
        }
    }
}
