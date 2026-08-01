<?php

declare(strict_types=1);

namespace Thrun\Laravel\Tests\Unit\Native;

use Testo\Assert;
use Testo\Test;
use Thrun\Laravel\Native\InFlightJobs;

/**
 * Shutdown waits on this counter, so a wrong answer either kills running jobs or
 * hangs the worker.
 */
#[Test]
final class InFlightJobsTest
{
    public function startsEmpty(): void
    {
        Assert::true(new InFlightJobs()->isEmpty());
    }

    public function isNotEmptyWhileJobsAreOut(): void
    {
        $jobs = new InFlightJobs();
        $jobs->dispatched();
        $jobs->dispatched();

        Assert::false($jobs->isEmpty());
        Assert::same($jobs->count(), 2);

        $jobs->completed();

        Assert::false($jobs->isEmpty());

        $jobs->completed();

        Assert::true($jobs->isEmpty());
    }

    public function aStrayResultDoesNotHideTheNextJob(): void
    {
        $jobs = new InFlightJobs();
        // A result with no matching dispatch must not drive the count negative:
        // the next job would then look like none, and shutdown would not wait.
        $jobs->completed();
        $jobs->dispatched();

        Assert::same($jobs->count(), 1);
        Assert::false($jobs->isEmpty());
    }
}
