<?php

declare(strict_types=1);

namespace Thrun\Laravel\Tests\Unit\Native;

use Illuminate\Container\Container;
use Illuminate\Queue\Events\JobFailed;
use RuntimeException;
use Testo\Assert;
use Testo\Test;
use Thrun\Laravel\Native\FailedJobRecorder;
use Thrun\Laravel\Tests\Fixture\SettlingJob;

/**
 * The recorder runs from the `finally` of Job::fail(), and Illuminate's worker
 * swallows whatever reaches it from there. So an error while writing the store
 * has to be reported by the recorder itself, and it must never travel further:
 * on its way out it would replace the exception the job actually failed with.
 */
#[Test]
final class FailedJobRecorderTest
{
    public function writesTheFailedJobToTheStore(): void
    {
        $failer = new class {
            public array $logged = [];

            public function log($connection, $queue, $payload, $exception): void
            {
                $this->logged[] = [$connection, $queue, $exception->getMessage()];
            }
        };

        $this->record($failer, new RuntimeException('job blew up'));

        Assert::same($failer->logged, [['redis', 'default', 'job blew up']]);
    }

    public function reportsAStoreFailureInsteadOfLettingItEscape(): void
    {
        $failer = new class {
            public function log($connection, $queue, $payload, $exception): void
            {
                throw new RuntimeException('database file does not exist');
            }
        };

        $log = tempnam(sys_get_temp_dir(), 'thrun-recorder-');
        $previous = ini_set('error_log', $log);

        try {
            // No exception escapes: reaching the assertion is half the check.
            $this->record($failer, new RuntimeException('job blew up'));

            $written = (string) file_get_contents($log);
        } finally {
            ini_set('error_log', $previous === false ? '' : $previous);
            @unlink($log);
        }

        Assert::true(str_contains($written, 'database file does not exist'), $written);
        // The job's own error is named too — the store write was about to bury it.
        Assert::true(str_contains($written, 'job blew up'), $written);
    }

    private function record(object $failer, RuntimeException $failure): void
    {
        $container = new Container();
        $container->instance('queue.failer', $failer);

        $job = new SettlingJob($container);

        new FailedJobRecorder($container)(new JobFailed('redis', $job, $failure));
    }
}
