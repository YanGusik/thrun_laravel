<?php

declare(strict_types=1);

namespace Thrun\Laravel\Tests\Unit\Compression;

use Illuminate\Container\Container;
use Illuminate\Queue\Connectors\RedisConnector;
use Illuminate\Queue\Console\RetryCommand;
use Illuminate\Queue\RedisQueue;
use ReflectionMethod;
use ReflectionProperty;
use Testo\Assert;
use Testo\Test;
use Thrun\Laravel\Compression\CommandCompressor;
use Thrun\Laravel\Compression\CompressingRedisConnector;
use Thrun\Laravel\Compression\CompressingRedisQueue;
use Thrun\Laravel\Compression\CompressionAwareRetryCommand;
use Thrun\Laravel\Tests\Fixture\SendReportJob;
use Thrun\Laravel\Tests\Fixture\UnusedRedisFactory;

/**
 * The wiring between the framework and the compression classes: the queue the
 * connector hands out, and the command binding surviving Laravel's own.
 */
#[Test]
final class CompressionWiringTest
{
    public function connectorHandsOutAQueueThatCompresses(): void
    {
        $queue = $this->connect(['queue' => 'default']);

        Assert::instanceOf($queue, CompressingRedisQueue::class);

        $payload = json_decode($this->payloadFor($queue), true);

        Assert::true(str_starts_with($payload['data']['command'], 'TLC1:l:'));
    }

    public function connectorPicksTheSameRedisConnectionAsLaravel(): void
    {
        // A queue connection without its own `connection` key must keep talking to
        // the Redis connection it talked to before compression was switched on —
        // otherwise the workers read a different database than the writers write.
        $config = ['queue' => 'bulk'];

        Assert::same(
            $this->redisConnectionOf($this->connect($config)),
            $this->redisConnectionOf(new RedisConnector(new UnusedRedisFactory())->connect($config)),
        );
    }

    public function connectorHonoursAnExplicitRedisConnection(): void
    {
        $queue = $this->connect(['queue' => 'bulk', 'connection' => 'queue-cluster']);

        Assert::same($this->redisConnectionOf($queue), 'queue-cluster');
    }

    public function retryCommandSurvivesLaravelBindingItsOwn(): void
    {
        $container = new Container();
        $container->instance(CommandCompressor::class, new CommandCompressor());

        $container->extend(
            RetryCommand::class,
            fn() => new CompressionAwareRetryCommand($container->make(CommandCompressor::class)),
        );

        // What ArtisanServiceProvider does when the console kernel loads deferred
        // providers, which happens after every provider has registered.
        $container->singleton(RetryCommand::class);

        Assert::instanceOf($container->make(RetryCommand::class), CompressionAwareRetryCommand::class);
    }

    private function connect(array $config): RedisQueue
    {
        $connector = new CompressingRedisConnector(new UnusedRedisFactory(), new CommandCompressor());

        $queue = $connector->connect($config);
        $queue->setContainer(new Container());

        return $queue;
    }

    private function redisConnectionOf(RedisQueue $queue): ?string
    {
        return new ReflectionProperty(RedisQueue::class, 'connection')->getValue($queue);
    }

    private function payloadFor(RedisQueue $queue): string
    {
        $job = new SendReportJob('Monthly report', array_fill(0, 100, 'user@example.com'));

        return new ReflectionMethod($queue, 'createPayload')->invoke($queue, $job, 'default');
    }
}
