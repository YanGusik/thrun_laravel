<?php

declare(strict_types=1);

namespace Thrun\Laravel\Worker;

use Closure;
use Psr\Log\LoggerInterface;
use Thrun\Contract\ReceiverInterface;
use Thrun\Envelope\Envelope;

use function Async\delay;

/**
 * Ends the run once the transport underneath has no more work to hand out.
 *
 * thrun's worker keeps waiting for results after its producer has stopped, so a
 * transport that finishes — as one draining Laravel's queue does, on
 * `queue:restart` or a job limit — would otherwise leave the worker running with
 * nothing left to run. A transport that never finishes never involves this class.
 *
 * The jobs already handed over are waited for first: they hold reservations that
 * only the thread running them can settle. "Handed over" is answered by the pool,
 * which reports itself idle as soon as its last task returns — a result still on
 * its way from a thread can therefore be cut off by the stop, so a queue whose
 * acknowledgement matters must not rely on this class to deliver the last one.
 */
final class DrainingReceiver implements ReceiverInterface
{
    /** How often the wait for the running jobs looks again. */
    private const POLL_MS = 50;

    /** @var Closure(): bool */
    private Closure $isIdle;

    /** @var Closure(): void */
    private Closure $stop;

    private int $dispatched = 0;

    /**
     * @param int $graceSeconds how long a job that will not end on its own may hold up the shutdown
     */
    public function __construct(
        private readonly ReceiverInterface $inner,
        private readonly LoggerInterface $logger,
        private readonly int $graceSeconds,
    ) {
        $this->isIdle = static fn(): bool => true;
        $this->stop   = static function (): void {
        };
    }

    /**
     * Tell the receiver which worker it is feeding.
     *
     * Separate from the constructor because the worker is built around the
     * receiver: neither can be the other's constructor argument. Call it before
     * the worker runs; until then a drained transport simply ends the producer.
     *
     * @param Closure(): bool $isIdle whether every job handed over has come back
     * @param Closure(): void $stop   ends the worker, cancelling whatever still runs
     */
    public function attachTo(Closure $isIdle, Closure $stop): void
    {
        $this->isIdle = $isIdle;
        $this->stop   = $stop;
    }

    public function receive(): ?Envelope
    {
        $envelope = $this->inner->receive();

        return $envelope === null ? $this->finish() : $this->dispatch($envelope);
    }

    /**
     * Unlike {@see receive()}, an empty answer here does not end the run: this one
     * means "nothing right now", not "nothing ever".
     */
    public function tryReceive(): ?Envelope
    {
        $envelope = $this->inner->tryReceive();

        return $envelope === null ? null : $this->dispatch($envelope);
    }

    public function ack(Envelope $envelope): void
    {
        $this->inner->ack($envelope);
    }

    public function reject(Envelope $envelope): void
    {
        $this->inner->reject($envelope);
    }

    public function close(): void
    {
        $this->inner->close();
    }

    private function dispatch(Envelope $envelope): Envelope
    {
        $this->dispatched++;

        return $envelope;
    }

    /**
     * Wait for the jobs in flight, then stop the worker.
     *
     * A run that dispatched nothing has nothing to wait for, and asking would be
     * worse than not asking: the pool reports itself idle only once something has
     * completed on it.
     *
     * Stopping happens here, in the coroutine the worker is about to cancel, and
     * nothing suspends between the call and the return: the cancellation is only
     * delivered at a suspension point, so the producer reaches its own end first.
     * Were one raised anyway it would be a Cancellation, which the worker's run
     * loop swallows — the supervisor never sees a crash where a run merely ended.
     */
    private function finish(): null
    {
        $deadline = microtime(true) + $this->graceSeconds;

        while ($this->dispatched > 0 && !($this->isIdle)() && microtime(true) < $deadline) {
            delay(self::POLL_MS);
        }

        if ($this->dispatched > 0 && !($this->isIdle)()) {
            $this->logger->warning('Thrun: gave up waiting for running jobs and stopped the worker.', [
                'grace_seconds' => $this->graceSeconds,
            ]);
        }

        ($this->stop)();

        return null;
    }
}
