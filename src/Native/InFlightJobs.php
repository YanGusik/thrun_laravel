<?php

declare(strict_types=1);

namespace Thrun\Laravel\Native;

/**
 * Counts jobs handed to the threads and not yet reported back.
 *
 * The run may only end when this reaches zero: stopping earlier would kill jobs
 * mid-flight and leave their reservations to expire instead of being released.
 */
final class InFlightJobs
{
    private int $count = 0;

    /** One more job handed to the threads. Called before it is submitted. */
    public function dispatched(): void
    {
        $this->count++;
    }

    /** One job reported back, whatever its outcome. */
    public function completed(): void
    {
        // Never below zero: a stray result would otherwise mask the next job and
        // let the run end while it is still on a thread.
        $this->count = max(0, $this->count - 1);
    }

    /** True when nothing is out; shutdown blocks until it is. */
    public function isEmpty(): bool
    {
        return $this->count === 0;
    }

    /** How many are still out; used when reporting an incomplete shutdown. */
    public function count(): int
    {
        return $this->count;
    }
}
