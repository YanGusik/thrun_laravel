<?php

declare(strict_types=1);

namespace Thrun\Laravel\Tests\Unit\Native;

use ArrayAccess;
use Async\AsyncCancellation;
use Illuminate\Container\Container;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Contracts\Events\Dispatcher as DispatcherContract;
use Illuminate\Events\Dispatcher;
use Illuminate\Queue\QueueManager;
use Illuminate\Queue\WorkerOptions;
use Testo\Assert;
use Testo\Test;
use Thrun\Laravel\Native\NativeWorker;
use Thrun\Laravel\Tests\Fixture\RecordingExceptionHandler;
use Thrun\Laravel\Tests\Fixture\SettlingJob;

use function Async\delay;
use function Async\spawn;

/**
 * thrun cancels a task when it outruns its TimeoutStamp or when the worker is
 * stopping. Either way the Laravel side has to be left in a state its own tools
 * understand — a job that is neither running nor recorded anywhere is the
 * failure these tests guard against.
 */
#[Test]
final class NativeWorkerTimeoutTest
{
    public function anUneventfulJobRunsAndDeletesItsReservation(): void
    {
        $job = $this->job();
        $job->fireDelayMs = 10;

        $this->worker()->runReservedJob($job, 'redis', new WorkerOptions());

        Assert::contains($job->log, 'delete:done');
        Assert::false((bool) preg_grep('/^failed-callback:/', $job->log), implode(' | ', $job->log));
    }

    public function aCancelledJobIsFailedExactlyOnce(): void
    {
        $job = $this->job();
        $job->fireDelayMs = 5000;

        $this->cancelWhileRunning($job);

        // The cancellation reaches Laravel as an ordinary exception, so its own
        // path records the failure and clears the reservation. What matters is
        // that it happens once: a second owner would delete an entry that has
        // already been replaced.
        Assert::count(preg_grep('/^failed-callback:/', $job->log), 1);
        Assert::contains($job->log, 'delete:done');
    }

    public function aCancelledJobWithTriesLeftIsReleasedForAnotherAttempt(): void
    {
        $job = $this->job();
        $job->fireDelayMs = 5000;
        $job->tries = 3;

        $this->cancelWhileRunning($job);

        // Better than `queue:work`, which dies on timeout and leaves the job to
        // wait out `retry_after`: here it goes back for its next attempt at once.
        Assert::contains($job->log, 'release:done');
        Assert::false((bool) preg_grep('/^failed-callback:/', $job->log), implode(' | ', $job->log));
    }

    public function aJobThatSettledItselfIsNotTouchedAgain(): void
    {
        $job = $this->job();
        $job->fireDelayMs = 10;
        $job->settleDelayMs = 5000;

        // Its delete is still in flight when the cancellation lands: settling it
        // a second time would remove a reservation that no longer exists.
        $this->cancelWhileRunning($job, cancelAfterMs: 300);

        Assert::false((bool) preg_grep('/^failed-callback:/', $job->log), implode(' | ', $job->log));
    }

    private function cancelWhileRunning(SettlingJob $job, int $cancelAfterMs = 200): void
    {
        $worker = $this->worker();

        $runner = spawn(function () use ($worker, $job): void {
            try {
                $worker->runReservedJob($job, 'redis', new WorkerOptions());
            } catch (AsyncCancellation) {
            }
        });

        delay($cancelAfterMs);
        $runner->cancel();
        delay(500);
    }

    private function worker(): NativeWorker
    {
        $container = $this->container();

        return new NativeWorker(
            new QueueManager($container),
            $container->make('events'),
            $container->make(ExceptionHandler::class),
            fn() => false,
        );
    }

    private function job(): SettlingJob
    {
        return new SettlingJob($this->container());
    }

    private function container(): Container
    {
        static $container = null;

        if ($container !== null) {
            return $container;
        }

        $container = new Container();
        $events = new Dispatcher($container);
        $container->instance(DispatcherContract::class, $events);
        $container->instance('events', $events);
        $container->instance(ExceptionHandler::class, new RecordingExceptionHandler());
        $container->instance('config', new class implements ArrayAccess {
            public function offsetExists(mixed $offset): bool
            {
                return false;
            }

            public function offsetGet(mixed $offset): mixed
            {
                return null;
            }

            public function offsetSet(mixed $offset, mixed $value): void
            {
            }

            public function offsetUnset(mixed $offset): void
            {
            }

            public function get($key, $default = null): mixed
            {
                return $default;
            }
        });

        return $container;
    }
}
