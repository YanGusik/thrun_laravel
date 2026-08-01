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
use RuntimeException;
use Testo\Assert;
use Testo\Test;
use Thrun\Laravel\Native\NativeWorker;
use Thrun\Laravel\Tests\Fixture\RecordingExceptionHandler;
use Thrun\Laravel\Tests\Fixture\SettlingJob;

use function Async\delay;
use function Async\spawn;

/**
 * The timeout is the one place where two actors reach for the same reservation:
 * the job, unwinding, and the timeout path, failing it. These tests pin who wins
 * in each case — the failure they guard against is a job that runs twice or a
 * reservation left behind.
 */
#[Test]
final class NativeWorkerTimeoutTest
{
    public function aJobThatSettlesAsTheAlarmFiresIsLeftToFinish(): void
    {
        $job = $this->job();
        $job->fireThrows = new RuntimeException('boom just under the wire');

        $this->worker()->runReservedJob($job, 'redis', new WorkerOptions());
        delay(1200);

        // Its own failure path must complete: cancelling mid-delete would strand
        // the reservation and lose the real error behind a later timeout.
        Assert::contains($job->log, 'delete:done');
        Assert::true(
            (bool) preg_grep('/^failed-callback:RuntimeException$/', $job->log),
            implode(' | ', $job->log),
        );
    }

    public function shutdownIsNotRecordedAsATimeout(): void
    {
        $job = $this->job();
        $job->fireDelayMs = 5000;

        $handler = spawn(function () use ($job): void {
            try {
                $this->worker()->runReservedJob($job, 'redis', new WorkerOptions());
            } catch (AsyncCancellation) {
            }
        });

        delay(300);
        $handler->cancel();
        delay(1500);

        // The job was healthy; the worker was being stopped. Marking it timed out
        // would write a failure record for a job that never failed.
        Assert::false(
            (bool) preg_grep('/TimeoutExceededException/', $job->log),
            implode(' | ', $job->log),
        );
    }

    public function aHungJobWithNoTriesLeftIsFailedExactlyOnce(): void
    {
        $job = $this->job();
        $job->fireDelayMs = 30_000;

        $this->worker()->runReservedJob($job, 'redis', new WorkerOptions());
        delay(500);

        Assert::count(preg_grep('/^failed-callback:/', $job->log), 1);
        Assert::contains($job->log, 'delete:done');
        Assert::false(in_array('release:start', $job->log, true), implode(' | ', $job->log));
    }

    public function aHungJobWithTriesLeftIsLeftForTheReservationToExpire(): void
    {
        $job = $this->job();
        $job->fireDelayMs = 30_000;
        $job->tries = 3;

        $this->worker()->runReservedJob($job, 'redis', new WorkerOptions());
        delay(500);

        // Stock parity: `queue:work` kills its process on timeout, so nothing
        // releases the job — it comes back when Redis expires the reservation.
        Assert::same($job->log, ['fire:start']);
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
