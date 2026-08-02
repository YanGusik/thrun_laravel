<?php

declare(strict_types=1);

namespace Thrun\Laravel\Tests\Unit\Console;

use Async\Scope;
use Async\TaskSet;
use Exception;
use Testo\Assert;
use Testo\Test;
use Thrun\Laravel\Console\SupervisorTasks;

use function Async\delay;

/**
 * One process runs several supervisors, and they do not end together: one may
 * stop on a job limit or a restart signal while the others still have jobs on
 * their threads.
 */
#[Test]
final class SupervisorTasksTest
{
    /** Long enough for the short task to finish first, short enough not to slow the suite. */
    private const SLOW_TASK_MS = 50;

    public function aSupervisorThatFinishesLeavesTheOthersAlone(): void
    {
        $slowFinished = false;

        $tasks = $this->taskSet(2);
        $tasks->spawn(static function (): void {
        });
        $tasks->spawn(static function () use (&$slowFinished): void {
            delay(self::SLOW_TASK_MS);
            $slowFinished = true;
        });

        $failed = new SupervisorTasks($tasks, new Scope())->awaitAll($this->failNever());

        Assert::false($failed);
        Assert::true($slowFinished, 'the second supervisor was cancelled by the first one finishing');
    }

    public function aSupervisorThatFailsStopsTheRest(): void
    {
        $slowFinished = false;
        $reported     = [];

        $tasks = $this->taskSet(2);
        $tasks->spawn(static function (): void {
            throw new Exception('worker crashed');
        });
        $tasks->spawn(static function () use (&$slowFinished): void {
            delay(self::SLOW_TASK_MS);
            $slowFinished = true;
        });

        $failed = new SupervisorTasks($tasks, new Scope())->awaitAll(
            static function (Exception $e) use (&$reported): void {
                $reported[] = $e->getMessage();
            },
        );

        Assert::true($failed);
        Assert::same($reported, ['worker crashed']);
        Assert::false($slowFinished, 'a crash must take the other supervisors down with it');
    }

    public function theServicesAreStoppedOnceTheWorkIsDone(): void
    {
        $serviceEnded = false;

        $services = new Scope();
        $services->spawn(static function () use (&$serviceEnded): void {
            try {
                // What the RPC server does: runs until somebody stops it.
                while (true) {
                    delay(self::SLOW_TASK_MS);
                }
            } finally {
                $serviceEnded = true;
            }
        });

        $tasks = $this->taskSet(1);
        $tasks->spawn(static function (): void {
        });

        new SupervisorTasks($tasks, $services)->awaitAll($this->failNever());

        // The cancellation is delivered when the service next suspends, so give
        // it that chance before asking.
        delay(self::SLOW_TASK_MS * 2);

        Assert::true($serviceEnded, 'the service outlived the supervisors it serves');
    }

    private function taskSet(int $concurrency): TaskSet
    {
        return new TaskSet(concurrency: $concurrency, scope: new Scope());
    }

    /** @return \Closure(Exception): void */
    private function failNever(): \Closure
    {
        return static function (Exception $e): void {
            Assert::fail('unexpected failure: ' . $e->getMessage());
        };
    }
}
