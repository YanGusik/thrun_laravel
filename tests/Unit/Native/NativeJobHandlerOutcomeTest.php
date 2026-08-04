<?php

declare(strict_types=1);

namespace Thrun\Laravel\Tests\Unit\Native;

use Illuminate\Container\Container;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Contracts\Queue\Job as JobContract;
use Illuminate\Queue\Jobs\RedisJob;
use Illuminate\Queue\RedisQueue;
use Illuminate\Queue\WorkerOptions;
use Testo\Assert;
use Testo\Test;
use Thrun\Envelope\Envelope;
use Thrun\Laravel\Native\NativeJobHandler;
use Thrun\Laravel\Native\NativeWorker;
use Thrun\Laravel\Tests\Fixture\ArrayConfig;
use Thrun\Laravel\Tests\Fixture\RecordingExceptionHandler;
use Thrun\Laravel\Tests\Fixture\UnusedRedisFactory;
use Thrun\Worker\Acknowledger;
use Thrun\Worker\Outcome;

/**
 * Laravel settles its own jobs inside the thread, so the adapter reports what
 * the framework did instead of asking thrun to act. Acknowledging without an
 * outcome is what made a failed job count as processed.
 */
#[Test]
final class NativeJobHandlerOutcomeTest
{
    public function aJobLaravelFailedIsReportedAsAFailure(): void
    {
        $ack = $this->run(static fn(JobContract $job) => $job->markAsFailed());

        Assert::same($ack->getObservedOutcome(), Outcome::Failure);
        Assert::true($ack->isAcked());
    }

    public function aJobLaravelReleasedIsReportedAsRetried(): void
    {
        $ack = $this->run(static fn(JobContract $job) => $job->release(5));

        Assert::same($ack->getObservedOutcome(), Outcome::Retried);
    }

    public function aJobThatRanToTheEndIsReportedAsSuccess(): void
    {
        $ack = $this->run(static fn(JobContract $job) => null);

        Assert::same($ack->getObservedOutcome(), Outcome::Success);
    }

    public function aFailedJobIsReportedAsFailedEvenWhenItWasAlsoReleased(): void
    {
        // A job released after an exception carries both flags; its last attempt
        // is a failure, and counting it as a retry would hide it.
        $ack = $this->run(static function (JobContract $job): void {
            $job->release(5);
            $job->markAsFailed();
        });

        Assert::same($ack->getObservedOutcome(), Outcome::Failure);
    }

    public function aLostReservationIsReportedAsSkipped(): void
    {
        $ack = $this->run(static fn(JobContract $job) => null, reservationHeld: false);

        Assert::same($ack->getObservedOutcome(), Outcome::Skipped);
    }

    /**
     * Runs one job through the handler; the callback stands in for whatever
     * Laravel's worker did with it.
     *
     * @param \Closure(JobContract): void $settle
     */
    private function run(\Closure $settle, bool $reservationHeld = true): Acknowledger
    {
        $container = new Container();
        Container::setInstance($container);

        $queue = $this->queue($reservationHeld);

        $container->instance('config', new ArrayConfig([
            'queue.connections.redis.retry_after' => 60,
        ]));
        $container->instance(ExceptionHandler::class, new RecordingExceptionHandler());
        $container->instance('queue', new class ($queue) {
            public function __construct(private readonly RedisQueue $queue) {}

            public function connection($name = null): RedisQueue
            {
                return $this->queue;
            }
        });
        $container->instance(NativeWorker::class, new class ($settle) {
            public function __construct(private readonly \Closure $settle) {}

            public function runReservedJob(JobContract $job, string $connection, WorkerOptions $options): void
            {
                ($this->settle)($job);
            }
        });

        $ack = new Acknowledger(new Envelope(['job' => []]));

        new NativeJobHandler()(
            [
                'job' => [
                    'body'       => json_encode(['uuid' => 'u-1', 'job' => 'noop', 'data' => [], 'attempts' => 0]),
                    'reserved'   => 'reserved-1',
                    'connection' => 'redis',
                    'queue'      => 'default',
                ],
                'options' => ['tries' => 1, 'timeout' => 60, 'backoff' => 0],
            ],
            $ack,
        );

        Container::setInstance(null);

        return $ack;
    }

    /**
     * A queue whose reservation commands answer without Redis. `zscore` decides
     * whether this thread still owns the job.
     */
    private function queue(bool $reservationHeld): RedisQueue
    {
        $connection = new class ($reservationHeld) {
            public function __construct(private readonly bool $held) {}

            public function zadd(...$arguments): int
            {
                return 1;
            }

            public function zscore($key, $member): float|false
            {
                return $this->held ? 1.0 : false;
            }
        };

        return new class (new UnusedRedisFactory(), 'default', $connection) extends RedisQueue {
            public function __construct(UnusedRedisFactory $redis, string $default, private readonly object $fake)
            {
                parent::__construct($redis, $default);
            }

            public function getConnection()
            {
                return $this->fake;
            }

            public function deleteAndRelease($queue, $job, $delay)
            {
            }

            public function deleteReserved($queue, $job)
            {
            }
        };
    }
}
