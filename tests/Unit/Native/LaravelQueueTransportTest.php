<?php

declare(strict_types=1);

namespace Thrun\Laravel\Tests\Unit\Native;

use Illuminate\Container\Container;
use Illuminate\Contracts\Queue\Factory as QueueFactory;
use Illuminate\Queue\Jobs\RedisJob;
use Illuminate\Queue\RedisQueue;
use LogicException;
use Testo\Assert;
use Testo\Test;
use Thrun\Envelope\Envelope;
use Thrun\Laravel\Native\LaravelQueueTransport;
use Thrun\Laravel\Native\ProducerGate;
use Thrun\Laravel\Tests\Fixture\RecordingExceptionHandler;
use Thrun\Laravel\Tests\Fixture\RecordingLogger;
use Thrun\Laravel\Tests\Fixture\UnusedRedisFactory;

/**
 * The transport's job is to reserve through Laravel and hand thrun something a
 * thread can carry. What it must not do is take part in acknowledgement.
 */
#[Test]
final class LaravelQueueTransportTest
{
    public function reservedJobBecomesAnEnvelopeOfScalars(): void
    {
        $transport = $this->transport(['default' => $this->reservedJob('body-1', 'reserved-1')]);

        $envelope = $transport->tryReceive();

        Assert::instanceOf($envelope, Envelope::class);
        Assert::same($envelope->type, LaravelQueueTransport::ROUTE);
        Assert::same($envelope->message['job'], [
            'body' => 'body-1',
            'reserved' => 'reserved-1',
            'connection' => 'redis',
            'queue' => 'default',
        ]);
    }

    public function queuesArePolledInOrder(): void
    {
        $transport = $this->transport(
            ['high' => $this->reservedJob('urgent', 'urgent-reserved'), 'default' => null],
            ['high', 'default'],
        );

        Assert::same($transport->tryReceive()->message['job']['queue'], 'high');
    }

    public function everyReservationIsCounted(): void
    {
        $gate = $this->gate();
        $transport = $this->transport(['default' => $this->reservedJob('body', 'reserved')], gate: $gate);

        $transport->tryReceive();

        Assert::same($gate->reservedCount(), 1);
    }

    public function emptyQueuesEndTheRunWhenAskedTo(): void
    {
        $transport = $this->transport(['default' => null], gate: $this->gate(stopWhenEmpty: true));

        Assert::null($transport->receive());
    }

    public function aStoppedGateEndsTheRunWithoutReserving(): void
    {
        $transport = $this->transport(['default' => $this->reservedJob('body', 'reserved')], gate: $this->gate(
            restartSignal: fn() => microtime(true),
        ));

        Assert::null($transport->receive());
    }

    public function acknowledgementDoesNotTouchTheQueue(): void
    {
        $queue = $this->fakeQueue(['default' => null]);
        $envelope = new Envelope(['body' => 'b', 'reserved' => 'r', 'connection' => 'redis', 'queue' => 'default']);

        $transport = new LaravelQueueTransport(
            queues: $this->queueFactory($queue),
            connectionName: 'redis',
            queueNames: ['default'],
            sleepMs: 1,
            gate: $this->gate(),
            exceptions: new RecordingExceptionHandler(),
            logger: new RecordingLogger(),
        );

        $transport->ack($envelope);
        $transport->reject($envelope);

        // Laravel owns the reservation and its worker has already deleted or
        // released the job inside the thread. A second owner here would remove an
        // entry that has since been replaced, and the job would run twice.
        Assert::same($queue->reservationCalls, 0);
    }

    public function aFailingQueueIsReportedAndSkippedInsteadOfEndingTheRun(): void
    {
        $exceptions = new RecordingExceptionHandler();
        $queue = $this->fakeQueue(['broken' => null, 'default' => $this->reservedJob('body', 'reserved')]);
        $queue->failOn = 'broken';

        $transport = new LaravelQueueTransport(
            queues: $this->queueFactory($queue),
            connectionName: 'redis',
            queueNames: ['broken', 'default'],
            sleepMs: 1,
            gate: $this->gate(),
            exceptions: $exceptions,
            logger: new RecordingLogger(),
        );

        // A Redis blip on one queue must not abort the run: jobs are running on
        // the threads, and ending the producer tears the whole worker down.
        Assert::same($transport->tryReceive()->message['job']['queue'], 'default');
        Assert::count($exceptions->reported, 1);
    }

    public function theRunsWorkerSettingsTravelWithEveryJob(): void
    {
        $transport = new LaravelQueueTransport(
            queues: $this->queueFactory($this->fakeQueue(['default' => $this->reservedJob('b', 'r')])),
            connectionName: 'redis',
            queueNames: ['default'],
            sleepMs: 1,
            gate: $this->gate(),
            exceptions: new RecordingExceptionHandler(),
            logger: new RecordingLogger(),
            workerSettings: ['tries' => 3, 'timeout' => 120, 'backoff' => 5],
        );

        // The thread cannot see this process's command line, so --tries and its
        // neighbours have to be carried in the envelope.
        Assert::same($transport->tryReceive()->message['options'], ['tries' => 3, 'timeout' => 120, 'backoff' => 5]);
    }

    public function anErroredSweepDoesNotCountAsAnEmptyQueue(): void
    {
        $queue = $this->fakeQueue(['default' => null]);
        $queue->failOn = 'default';

        $transport = new LaravelQueueTransport(
            queues: $this->queueFactory($queue),
            connectionName: 'redis',
            queueNames: ['default'],
            sleepMs: 1,
            gate: $this->gate(stopWhenEmpty: true),
            exceptions: new RecordingExceptionHandler(),
            logger: new RecordingLogger(),
        );

        // --stop-when-empty on a Redis blip would report a clean finish over a
        // queue that is still full, so the run has to keep waiting instead.
        $failures = 0;
        Assert::null($transport->tryReceive($failures));
        Assert::same($failures, 1);
        Assert::false($transport->isDrained());
    }

    public function closingMarksTheRunDrained(): void
    {
        $transport = $this->transport(['default' => null]);

        Assert::false($transport->isDrained());

        // What SIGTERM does. The drain watcher waits for this before stopping.
        $transport->close();

        Assert::null($transport->receive());
        Assert::true($transport->isDrained());
    }

    public function reservingStopsAtTheWorkersCapacity(): void
    {
        $transport = $this->transport(['default' => $this->reservedJob('body', 'reserved')], maxInFlight: 1);

        $envelope = $transport->tryReceive();

        // A reservation ages from the moment pop() returns, so reserving beyond what
        // the worker can start is reserving jobs whose window is already running out.
        Assert::instanceOf($envelope, Envelope::class);
        Assert::null($transport->tryReceive());

        $transport->ack($envelope);

        Assert::instanceOf($transport->tryReceive(), Envelope::class);
    }

    public function aRejectedJobFreesItsCapacityToo(): void
    {
        $transport = $this->transport(['default' => $this->reservedJob('body', 'reserved')], maxInFlight: 1);

        $transport->reject($transport->tryReceive());

        Assert::instanceOf($transport->tryReceive(), Envelope::class);
    }

    public function aSlotWhoseResultNeverArrivesIsTakenBack(): void
    {
        $logger    = new RecordingLogger();
        $now       = 1000.0;
        $transport = $this->transport(
            ['default' => $this->reservedJob('body', 'reserved')],
            maxInFlight: 1,
            logger: $logger,
            // By reference: an arrow function would freeze the clock at 1000.
            clock: static function () use (&$now): float {
                return $now;
            },
        );

        $transport->tryReceive();
        Assert::null($transport->tryReceive());

        // The thread died with the job: no ack and no reject will ever come, and
        // without this the slot would be held for the life of the process.
        $now += 60 + 30 + 1;

        Assert::instanceOf($transport->tryReceive(), Envelope::class);
        Assert::count($logger->messages('warning'), 1);
    }

    public function sendingIntoLaravelsQueueIsRefused(): void
    {
        $transport = $this->transport(['default' => null]);

        try {
            $transport->send(new Envelope([]));
            Assert::fail('expected the transport to refuse sending');
        } catch (LogicException $e) {
            Assert::true(str_contains($e->getMessage(), 'dispatcher'), $e->getMessage());
        }
    }

    /**
     * @param array<string, ?RedisJob> $jobsPerQueue
     * @param list<string>             $queueNames
     */
    private function transport(
        array $jobsPerQueue,
        array $queueNames = ['default'],
        ?ProducerGate $gate = null,
        ?RecordingExceptionHandler $exceptions = null,
        int $maxInFlight = 0,
        ?RecordingLogger $logger = null,
        ?\Closure $clock = null,
    ): LaravelQueueTransport {
        return new LaravelQueueTransport(
            queues: $this->queueFactory($this->fakeQueue($jobsPerQueue)),
            connectionName: 'redis',
            queueNames: $queueNames,
            sleepMs: 1,
            gate: $gate ?? $this->gate(),
            exceptions: $exceptions ?? new RecordingExceptionHandler(),
            logger: $logger ?? new RecordingLogger(),
            maxInFlight: $maxInFlight,
            clock: $clock,
        );
    }

    private function gate(
        ?callable $restartSignal = null,
        bool $stopWhenEmpty = false,
    ): ProducerGate {
        return new ProducerGate(
            restartSignal: $restartSignal !== null ? $restartSignal(...) : fn() => null,
            isDownForMaintenance: fn() => false,
            stopWhenEmpty: $stopWhenEmpty,
        );
    }

    private function reservedJob(string $body, string $reserved): RedisJob
    {
        return new RedisJob(
            new Container(),
            new RedisQueue(new UnusedRedisFactory(), 'default'),
            $body,
            $reserved,
            'redis',
            'default',
        );
    }

    /**
     * @param array<string, ?RedisJob> $jobsPerQueue
     */
    private function fakeQueue(array $jobsPerQueue): RedisQueue
    {
        $queue = new class (new UnusedRedisFactory(), 'default') extends RedisQueue {
            /** @var array<string, ?RedisJob> */
            public array $jobs = [];

            /** How often anything touched the reservation. */
            public int $reservationCalls = 0;

            /** Queue name whose pop() blows up, standing in for a Redis blip. */
            public ?string $failOn = null;

            public function pop($queue = null, $index = 0)
            {
                if ($queue === $this->failOn) {
                    throw new \RuntimeException('Connection refused');
                }

                return $this->jobs[$queue] ?? null;
            }

            public function deleteReserved($queue, $job)
            {
                $this->reservationCalls++;
            }

            public function deleteAndRelease($queue, $job, $delay)
            {
                $this->reservationCalls++;
            }
        };

        $queue->jobs = $jobsPerQueue;

        return $queue;
    }

    private function queueFactory(RedisQueue $queue): QueueFactory
    {
        return new class ($queue) implements QueueFactory {
            public function __construct(private readonly RedisQueue $queue)
            {
            }

            public function connection($name = null)
            {
                return $this->queue;
            }
        };
    }
}
