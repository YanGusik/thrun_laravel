<?php

declare(strict_types=1);

namespace Thrun\Laravel\Tests\Unit\Compression;

use Illuminate\Container\Container;
use Illuminate\Contracts\Redis\Factory as RedisFactory;
use Illuminate\Queue\RedisQueue;
use ReflectionMethod;
use Testo\Assert;
use Testo\Test;
use Thrun\Laravel\Compression\CommandCompressor;
use Thrun\Laravel\Compression\CompressingRedisQueue;
use Thrun\Laravel\Compression\CompressionAwareCallQueuedHandler;
use Thrun\Laravel\Tests\Fixture\SendReportJob;
use Thrun\Laravel\Tests\Fixture\UnusedBusDispatcher;
use Thrun\Laravel\Tests\Fixture\UnusedRedisFactory;

/**
 * The write and read sides meeting: what the queue produces is what the handler
 * consumes, and both stay compatible with payloads written by stock Laravel.
 */
#[Test]
final class QueueCompressionTest
{
    public function envelopeStaysJsonWhileTheBodyIsCompressed(): void
    {
        $payload = json_decode($this->payloadFor($this->compressingQueue()), true);

        // Redis parses the envelope server-side to bump `attempts`; it has to
        // survive json_decode with the framework's own fields intact.
        Assert::same($payload['job'], 'Illuminate\Queue\CallQueuedHandler@call');
        Assert::same($payload['data']['commandName'], SendReportJob::class);
        Assert::true(str_starts_with($payload['data']['command'], 'TLC1:l:'));
    }

    public function compressedPayloadIsSmallerThanTheStockOne(): void
    {
        $compressed = strlen($this->payloadFor($this->compressingQueue()));
        $stock      = strlen($this->payloadFor($this->stockQueue()));

        Assert::true(
            $compressed < $stock,
            sprintf('compressed payload %d bytes, stock %d', $compressed, $stock),
        );
    }

    public function handlerRestoresTheJobWrittenByTheQueue(): void
    {
        $payload = json_decode($this->payloadFor($this->compressingQueue()), true);

        $job = $this->readCommand($payload['data']);

        Assert::instanceOf($job, SendReportJob::class);
        Assert::same($job->title, 'Monthly report');
    }

    public function handlerStillReadsPayloadsWrittenBeforeCompression(): void
    {
        $payload = json_decode($this->payloadFor($this->stockQueue()), true);

        $job = $this->readCommand($payload['data']);

        Assert::instanceOf($job, SendReportJob::class);
        Assert::same($job->title, 'Monthly report');
    }

    private function compressingQueue(): CompressingRedisQueue
    {
        $queue = new CompressingRedisQueue($this->redis(), 'default');
        $queue->setCompressor(new CommandCompressor());
        $queue->setContainer(new Container());

        return $queue;
    }

    private function stockQueue(): RedisQueue
    {
        $queue = new RedisQueue($this->redis(), 'default');
        $queue->setContainer(new Container());

        return $queue;
    }

    /**
     * The payload as it would be written to Redis. `createPayload()` is where the
     * framework assembles it, and it is protected — the alternative would be a
     * live Redis, which this test does not need.
     */
    private function payloadFor(RedisQueue $queue): string
    {
        $job    = new SendReportJob('Monthly report', array_fill(0, 100, 'user@example.com'));
        $method = new ReflectionMethod($queue, 'createPayload');

        return $method->invoke($queue, $job, 'default');
    }

    private function readCommand(array $data): object
    {
        $handler = new CompressionAwareCallQueuedHandler(
            new UnusedBusDispatcher(),
            new Container(),
            new CommandCompressor(),
        );

        return new ReflectionMethod($handler, 'getCommand')->invoke($handler, $data);
    }

    private function redis(): RedisFactory
    {
        return new UnusedRedisFactory();
    }
}
