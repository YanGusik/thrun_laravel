<?php

declare(strict_types=1);

namespace Thrun\Laravel\Tests\Unit\Transport;

use Illuminate\Container\Container;
use Illuminate\Contracts\Queue\ClearableQueue;
use Testo\Assert;
use Testo\Test;
use Thrun\Laravel\Tests\Fixture\ArrayConfig;
use Thrun\Laravel\Transport\TransportFactory;

/**
 * A queue on the laravel transport keeps nothing under thrun's own keys: its
 * jobs are in the application's queue, put there by the framework's dispatcher.
 * Purging thrun's keys for such a queue empties nothing and reports success,
 * which is how a flush used to leave every job in place.
 */
#[Test]
final class TransportFactoryFlushTest
{
    public function aLaravelQueueIsClearedThroughTheFramework(): void
    {
        $queue = $this->clearableQueue();

        $this->factory($queue, [
            'thrun.queues.laravel_jobs' => [
                'transport'  => 'laravel',
                'connection' => 'redis',
                'queues'     => ['default', 'high'],
            ],
        ])->flushQueue('laravel_jobs');

        Assert::same($queue->cleared, ['default', 'high']);
    }

    public function theConnectionsOwnQueueIsUsedWhenTheEntryNamesNone(): void
    {
        $queue = $this->clearableQueue();

        $this->factory($queue, [
            'thrun.queues.laravel_jobs'      => ['transport' => 'laravel'],
            'queue.default'                  => 'redis',
            'queue.connections.redis.queue'  => 'mail',
        ])->flushQueue('laravel_jobs');

        Assert::same($queue->cleared, ['mail']);
    }

    public function aConnectionThatCannotBeClearedIsReported(): void
    {
        // A connection that does not implement ClearableQueue; the sync driver
        // is the real example.
        $queue = new class {
            public function getConnectionName(): string
            {
                return 'sync';
            }
        };

        $factory = $this->factory($queue, [
            'thrun.queues.laravel_jobs' => ['transport' => 'laravel', 'connection' => 'sync'],
        ]);

        $reported = null;

        try {
            $factory->flushQueue('laravel_jobs');
        } catch (\RuntimeException $e) {
            $reported = $e->getMessage();
        }

        // Silence here would be the old defect in a new place: a flush that
        // cleared nothing and said it succeeded.
        Assert::true($reported !== null, 'nothing reported');
        Assert::true(str_contains((string) $reported, 'sync'), (string) $reported);
    }

    private function clearableQueue(): object
    {
        return new class implements ClearableQueue {
            /** @var list<string> */
            public array $cleared = [];

            public function clear($queue): int
            {
                $this->cleared[] = $queue;

                return 0;
            }
        };
    }

    /**
     * @param array<string, mixed> $config
     */
    private function factory(object $queue, array $config): TransportFactory
    {
        $container = new Container();
        $container->instance('queue', new class ($queue) {
            public function __construct(private readonly object $queue) {}

            public function connection($name = null): object
            {
                return $this->queue;
            }
        });

        return new TransportFactory(new ArrayConfig($config), $container);
    }
}
