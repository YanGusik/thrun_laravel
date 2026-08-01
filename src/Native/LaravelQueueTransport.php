<?php

declare(strict_types=1);

namespace Thrun\Laravel\Native;

use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Contracts\Queue\Factory as QueueFactory;
use Illuminate\Queue\Jobs\RedisJob;
use LogicException;
use Throwable;
use Thrun\Contract\TransportInterface;
use Thrun\Envelope\Envelope;
use Thrun\Envelope\Stamp\TimeoutStamp;

use function Async\delay;

/**
 * Feeds thrun's worker from Laravel's own queue.
 *
 * Reserving goes through `Illuminate\Queue\RedisQueue::pop()`, so the Lua
 * scripts, the `:reserved` set and the `attempts` counter behave exactly as they
 * do under `queue:work` — none of that is reimplemented here.
 */
final class LaravelQueueTransport implements TransportInterface
{
    /** Route key of the envelopes this transport produces. */
    public const ROUTE = 'laravel.job';

    private bool $closed = false;

    private bool $drained = false;

    /**
     * @param list<string>                                  $queueNames     polled in order, highest priority first
     * @param int                                           $sleepMs        pause after an empty sweep of every queue
     * @param array{tries: int, timeout: int, backoff: int} $workerSettings travels with every job: the thread that
     *                                                                      runs it cannot see this process's options
     */
    public function __construct(
        private readonly QueueFactory $queues,
        private readonly string $connectionName,
        private readonly array $queueNames,
        private readonly int $sleepMs,
        private readonly ProducerGate $gate,
        private readonly ExceptionHandler $exceptions,
        private readonly array $workerSettings = ['tries' => 1, 'timeout' => 60, 'backoff' => 0],
    ) {
    }

    /**
     * Reserve the next job, waiting for one to appear.
     *
     * Returns null when the run is over: a restart was requested, a limit was
     * reached, or the queues ran dry with `--stop-when-empty`.
     */
    public function receive(): ?Envelope
    {
        while (!$this->closed) {
            if ($this->gate->shouldStop()) {
                return $this->drained();
            }

            if ($this->gate->shouldPause()) {
                delay($this->sleepMs);

                continue;
            }

            $failures = 0;
            $envelope = $this->tryReceive($failures);

            if ($envelope !== null) {
                return $envelope;
            }

            // A sweep that errored is not an empty queue. Ending a one-shot run on
            // a Redis blip would report success over a queue full of work.
            if ($this->gate->shouldStopWhenEmpty() && $failures === 0) {
                return $this->drained();
            }

            delay($this->sleepMs);
        }

        return $this->drained();
    }

    /**
     * One sweep across the configured queues, without waiting.
     *
     * A queue that fails to answer is reported and skipped rather than allowed to
     * end the run: `queue:work` survives a Redis blip, and a worker that dies on
     * one takes every job running on its threads down with it.
     *
     * @param int $failures out: how many queues errored during the sweep
     */
    public function tryReceive(int &$failures = 0): ?Envelope
    {
        $queue = $this->queues->connection($this->connectionName);

        foreach ($this->queueNames as $index => $name) {
            try {
                // The index is what tells RedisQueue whether this queue may block
                // on the connection's `block_for`; only the first one may.
                $job = $queue->pop($name, $index);
            } catch (Throwable $e) {
                $failures++;
                $this->exceptions->report($e);

                continue;
            }

            if ($job === null) {
                continue;
            }

            if (!$job instanceof RedisJob) {
                // pop() has already consumed an attempt and written a reservation,
                // so a job we cannot carry is a job we have damaged. The connection
                // type is checked at startup; reaching here means it lied.
                throw new LogicException(sprintf(
                    'Queue [%s] returned %s; native mode can only carry a RedisJob.',
                    $name,
                    get_debug_type($job),
                ));
            }

            $this->gate->jobReserved();

            $reserved = ReservedJob::fromRedisJob($job, $this->connectionName, $name);

            // The timeout is thrun's own: it already runs each task under a
            // cancellation token, so the adapter does not build a second one.
            return new Envelope(
                ['job' => $reserved->toArray(), 'options' => $this->workerSettings],
                type: self::ROUTE,
                stamps: [new TimeoutStamp($this->timeoutFor($job) * 1000)],
            );
        }

        return null;
    }

    /**
     * Seconds this job may run: its own `$timeout` when it declares one, the
     * run's setting otherwise — the precedence `queue:work` applies.
     */
    private function timeoutFor(RedisJob $job): int
    {
        $declared = $job->payload()['timeout'] ?? null;

        return $declared === null ? $this->workerSettings['timeout'] : (int) $declared;
    }

    /** True once receive() has returned null for good: no more envelopes follow. */
    public function isDrained(): bool
    {
        return $this->drained;
    }

    /**
     * Does nothing, deliberately. The reservation belongs to Laravel, whose worker
     * deleted it inside the thread that ran the job; a second owner here would
     * remove an entry that has since been replaced.
     */
    public function ack(Envelope $envelope): void
    {
    }

    /** Does nothing, for the same reason as {@see ack()}: Laravel already released or failed the job. */
    public function reject(Envelope $envelope): void
    {
    }

    /** Stop reserving. The current wait ends, and receive() returns null from then on. */
    public function close(): void
    {
        $this->closed = true;
    }

    /**
     * @throws LogicException always: jobs enter this queue through Laravel's
     *                        dispatcher, and pushing from here would bypass the
     *                        payload the framework's own workers expect
     */
    public function send(Envelope $envelope): void
    {
        throw new LogicException('Jobs enter this queue through Laravel\'s dispatcher, not through thrun.');
    }

    private function drained(): null
    {
        $this->drained = true;

        return null;
    }
}
