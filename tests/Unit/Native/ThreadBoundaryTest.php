<?php

declare(strict_types=1);

namespace Thrun\Laravel\Tests\Unit\Native;

use Async\ThreadChannel;
use Async\ThreadPool;
use Testo\Assert;
use Testo\Test;
use Thrun\Laravel\Native\ReservedJob;

/**
 * The claim the whole native mode rests on: a reserved job can be handed to
 * another OS thread and arrive intact.
 *
 * This has to run through a real thread pool. TrueAsync refuses to transfer some
 * object graphs between threads, so a test that only round-trips an array inside
 * one thread would prove nothing about the thing that can actually fail.
 */
#[Test]
final class ThreadBoundaryTest
{
    public function reservedJobCrossesToAWorkerThreadByteForByte(): void
    {
        $job = new ReservedJob(
            body: '{"uuid":"9f1c","data":{"command":"O:8:\"stdClass\":0:{}"}}',
            // Laravel's reserved copy is the same JSON with attempts bumped; the
            // binary and null bytes are here because a job body may hold anything.
            reserved: "{\"attempts\":1,\"blob\":\"\x00\xff\x7f binary\"}",
            connectionName: 'redis',
            queueName: 'default',
        );

        Assert::same($this->roundTripThroughThread($job->toArray()), $job->toArray());
    }

    /**
     * @param array<string, string> $payload
     *
     * @return array<string, string>
     */
    private function roundTripThroughThread(array $payload): array
    {
        $autoload = dirname(__DIR__, 3) . '/vendor/autoload.php';
        $channel = new ThreadChannel(1);

        $pool = new ThreadPool(
            workers: 1,
            queueSize: 1,
            bootloader: static function () use ($autoload): void {
                require $autoload;
            },
        );

        $pool->submit(static function () use ($payload, $channel): void {
            $channel->send(ReservedJob::fromArray($payload)->toArray());
        });

        $received = $channel->recv();
        $pool->close();

        return $received;
    }
}
