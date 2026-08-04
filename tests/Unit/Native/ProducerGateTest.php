<?php

declare(strict_types=1);

namespace Thrun\Laravel\Tests\Unit\Native;

use Testo\Assert;
use Testo\Test;
use Thrun\Laravel\Native\ProducerGate;

/**
 * Every one of these controls exists because a deployment depends on it, and
 * none of them comes for free once `Worker::daemon()` is not the loop.
 */
#[Test]
final class ProducerGateTest
{
    public function keepsWorkingWhileNothingAsksItToStop(): void
    {
        $gate = $this->gate();

        Assert::false($gate->shouldStop());
        Assert::false($gate->shouldPause());
        Assert::false($gate->shouldStopWhenEmpty());
    }

    public function stopsWhenQueueRestartWasRequested(): void
    {
        $signal = 1000;
        $gate = $this->gate(restartSignal: function () use (&$signal) {
            return $signal;
        });

        Assert::false($gate->shouldStop());

        $signal = 2000;

        Assert::true($gate->shouldStop());
    }

    public function stopsAfterTheJobLimit(): void
    {
        $gate = $this->gate(maxJobs: 2);

        $gate->jobReserved();
        Assert::false($gate->shouldStop());

        $gate->jobReserved();
        Assert::true($gate->shouldStop());
        Assert::same($gate->reservedCount(), 2);
    }

    public function stopsAfterTheTimeLimit(): void
    {
        // Started ten seconds ago, allowed five.
        $gate = $this->gate(maxTime: 5, now: microtime(true) - 10);

        Assert::true($gate->shouldStop());
    }

    public function pausesWhileTheApplicationIsDown(): void
    {
        $gate = $this->gate(isDownForMaintenance: fn() => true);

        Assert::true($gate->shouldPause());
        // Pausing is not stopping: the worker waits for maintenance to end.
        Assert::false($gate->shouldStop());
    }

    public function forceKeepsWorkingThroughMaintenance(): void
    {
        $gate = $this->gate(isDownForMaintenance: fn() => true, force: true);

        Assert::false($gate->shouldPause());
    }

    private function gate(
        ?callable $restartSignal = null,
        ?callable $isDownForMaintenance = null,
        int $maxJobs = 0,
        int $maxTime = 0,
        bool $stopWhenEmpty = false,
        bool $force = false,
        ?float $now = null,
    ): ProducerGate {
        return new ProducerGate(
            restartSignal: $restartSignal !== null ? $restartSignal(...) : fn() => null,
            isDownForMaintenance: $isDownForMaintenance !== null ? $isDownForMaintenance(...) : fn() => false,
            maxJobs: $maxJobs,
            maxTime: $maxTime,
            stopWhenEmpty: $stopWhenEmpty,
            force: $force,
            now: $now,
        );
    }
}
