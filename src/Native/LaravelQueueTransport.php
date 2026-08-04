<?php

declare(strict_types=1);

namespace Thrun\Laravel\Native;

use Closure;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Contracts\Queue\Factory as QueueFactory;
use Illuminate\Queue\Jobs\RedisJob;
use LogicException;
use Psr\Log\LoggerInterface;
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
 *
 * Unlike thrun's own transports this one stops reserving once the jobs it has
 * handed over reach the worker's capacity. A reservation ages on Laravel's
 * clock from the moment `pop()` returns, so a job waiting its turn spends the
 * window it was given; everything between here and the thread that runs it —
 * the multi-queue buffer, the pool's own queue — is time the job cannot get
 * back.
 */
final class LaravelQueueTransport implements TransportInterface
{
    /** Route key of the envelopes this transport produces. */
    public const ROUTE = 'laravel.job';

    /** How often a producer parked at capacity looks again. */
    private const CAPACITY_POLL_MS = 5;

    /**
     * Added to the reservation's own lifetime before its slot is taken back.
     *
     * The slot is normally freed by ack() or reject(); this covers the job whose
     * result never arrives at all, a thread that died with it.
     */
    private const LOST_RESULT_GRACE_SECONDS = 30;

    private bool $closed = false;

    private bool $drained = false;

    /**
     * Reservations handed to the worker and not yet answered for.
     *
     * Keyed by the reserved payload, which is what identifies a reservation to
     * Laravel; the value is the point in time after which the slot is presumed
     * lost. Unix seconds, as {@see microtime()} reports them.
     *
     * @var array<string, float>
     */
    private array $inFlight = [];

    /** @var Closure(): float */
    private readonly Closure $clock;

    /**
     * @param list<string>                                  $queueNames     polled in order, highest priority first
     * @param int                                           $sleepMs        pause after an empty sweep of every queue
     * @param int                                           $maxInFlight    reservations allowed out at once; 0 removes
     *                                                                      the limit, which only suits a caller that
     *                                                                      runs each job as it arrives
     * @param int                                           $retryAfter     seconds the connection gives a reservation
     *                                                                      before Laravel hands the job to somebody
     *                                                                      else; the same number as
     *                                                                      `queue.connections.*.retry_after`
     * @param array{tries: int, timeout: int, backoff: int} $workerSettings travels with every job: the thread that
     *                                                                      runs it cannot see this process's options
     * @param (Closure(): float)|null                       $clock          unix seconds; passed in so tests can move
     *                                                                      time instead of waiting for it
     */
    public function __construct(
        private readonly QueueFactory $queues,
        private readonly string $connectionName,
        private readonly array $queueNames,
        private readonly int $sleepMs,
        private readonly ProducerGate $gate,
        private readonly ExceptionHandler $exceptions,
        private readonly LoggerInterface $logger,
        private readonly int $maxInFlight = 0,
        private readonly int $retryAfter = 60,
        private readonly array $workerSettings = ['tries' => 1, 'timeout' => 60, 'backoff' => 0],
        ?Closure $clock = null,
    ) {
        $this->clock = $clock ?? static fn(): float => microtime(true);
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

            if ($this->atCapacity()) {
                delay(self::CAPACITY_POLL_MS);

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
        if ($this->atCapacity()) {
            return null;
        }

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
            $timeout  = $this->timeoutFor($job);

            $this->inFlight[$reserved->reserved] = ($this->clock)() + $this->retryAfter + self::LOST_RESULT_GRACE_SECONDS;

            // The timeout is thrun's own: it already runs each task under a
            // cancellation token, so the adapter does not build a second one.
            return new Envelope(
                ['job' => $reserved->toArray(), 'options' => $this->workerSettings],
                type: self::ROUTE,
                stamps: [new TimeoutStamp($timeout * 1000)],
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
     * Frees the capacity the job occupied, and nothing else.
     *
     * The reservation itself belongs to Laravel, whose worker deleted it inside
     * the thread that ran the job; a second owner here would remove an entry that
     * has since been replaced.
     */
    public function ack(Envelope $envelope): void
    {
        $this->releaseSlot($envelope);
    }

    /** Does the same as {@see ack()}: Laravel already released or failed the job, only the slot is ours. */
    public function reject(Envelope $envelope): void
    {
        $this->releaseSlot($envelope);
    }

    /**
     * Whether reserving must wait for a job to come back.
     *
     * Slots whose job can no longer be running are taken back here rather than on
     * a timer: the count is only consulted from this one place, so a lazy sweep
     * cannot be late.
     */
    private function atCapacity(): bool
    {
        if ($this->maxInFlight <= 0) {
            return false;
        }

        if (count($this->inFlight) < $this->maxInFlight) {
            return false;
        }

        $this->releaseLostSlots();

        return count($this->inFlight) >= $this->maxInFlight;
    }

    /**
     * Take back the slots of reservations that have outlived their own window.
     *
     * A thread that dies mid-job takes the result with it, and its slot would
     * otherwise be held for the life of the process. The deadline is the
     * connection's `retry_after` plus a margin, not the job's timeout, because
     * that is when Laravel stops treating this worker as the owner: past it the
     * job is back in the ready list for anyone to take, so the slot no longer
     * stands for anything. Reclaiming earlier would over-admit against a job this
     * worker is still holding — which is a live risk when the queue shares a
     * worker with others, since the wait before a thread picks a job up is not
     * this transport's to see.
     */
    private function releaseLostSlots(): void
    {
        $now = ($this->clock)();

        foreach ($this->inFlight as $reserved => $deadline) {
            if ($deadline > $now) {
                continue;
            }

            unset($this->inFlight[$reserved]);

            $this->logger->warning('Thrun: a native job\'s result never arrived; its slot was reclaimed.', [
                'connection' => $this->connectionName,
                'overdue_by' => round($now - $deadline, 3),
            ]);
        }
    }

    private function releaseSlot(Envelope $envelope): void
    {
        $message = $envelope->message;

        if (is_array($message) && isset($message['job']['reserved'])) {
            unset($this->inFlight[$message['job']['reserved']]);
        }
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
