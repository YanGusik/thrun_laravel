<?php

declare(strict_types=1);

namespace Thrun\Laravel\Native;

use Closure;

/**
 * The operational controls `queue:work` gets from its daemon loop.
 *
 * They live in `Illuminate\Queue\Worker::daemon()`, which this package does not
 * use, so each of them is answered here instead — a deployment pipeline running
 * `queue:restart` expects the worker to notice, whatever the worker is.
 *
 * Where the signals come from is the caller's business: this class is given two
 * closures and knows nothing about the cache or the application.
 */
final class ProducerGate
{
    private readonly float $startedAt;

    private readonly mixed $restartSignalAtStart;

    private int $reserved = 0;

    private bool $stopped = false;

    /**
     * @param Closure(): mixed $restartSignal        any value that changes when a restart is requested
     * @param Closure(): bool  $isDownForMaintenance
     * @param int              $maxJobs              0 = no limit
     * @param int              $maxTime              seconds, 0 = no limit
     * @param bool             $stopWhenEmpty        stop once the queues run dry, for one-shot runs
     * @param float|null       $now                  start of the run; passed in so tests control the clock
     */
    public function __construct(
        private readonly Closure $restartSignal,
        private readonly Closure $isDownForMaintenance,
        private readonly int $maxJobs = 0,
        private readonly int $maxTime = 0,
        private readonly bool $stopWhenEmpty = false,
        private readonly bool $force = false,
        ?float $now = null,
    ) {
        $this->startedAt = $now ?? microtime(true);
        $this->restartSignalAtStart = ($this->restartSignal)();
    }

    /**
     * Whether the producer should stop reserving jobs. Once true it stays true —
     * the run is ending, and jobs already in flight finish on their own.
     */
    public function shouldStop(): bool
    {
        // Latched, so that a restart signal disappearing again — `cache:clear`
        // during a drain — cannot revive a run that is already ending.
        return $this->stopped = $this->stopped
            || ($this->restartSignal)() !== $this->restartSignalAtStart
            || ($this->maxJobs > 0 && $this->reserved >= $this->maxJobs)
            || ($this->maxTime > 0 && microtime(true) - $this->startedAt >= $this->maxTime);
    }

    /**
     * Whether reserving is paused right now. Unlike stopping this can flip back:
     * maintenance ends and the worker resumes.
     */
    public function shouldPause(): bool
    {
        return !$this->force && ($this->isDownForMaintenance)();
    }

    /**
     * Whether an empty sweep ends the run. False means "keep waiting for work".
     */
    public function shouldStopWhenEmpty(): bool
    {
        return $this->stopWhenEmpty;
    }

    /** Count a reservation. This is what `--max-jobs` is measured against. */
    public function jobReserved(): void
    {
        $this->reserved++;
    }

    /** Reservations made during this run, reported when it ends. */
    public function reservedCount(): int
    {
        return $this->reserved;
    }
}
