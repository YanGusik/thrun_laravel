<?php

declare(strict_types=1);

namespace Thrun\Laravel\Compression;

use Illuminate\Queue\Connectors\RedisConnector;
use Laravel\Horizon\RedisQueue as HorizonRedisQueue;
use ReflectionClass;

/**
 * Builds the queue that writes compressed job bodies, picking the Horizon-aware
 * subclass when Horizon is installed so its dashboard events keep firing.
 */
final class CompressingRedisConnector extends RedisConnector
{
    public function __construct(
        $redis,
        private readonly CommandCompressor $compressor,
        $connection = null,
    ) {
        parent::__construct($redis, $connection);
    }

    /**
     * @return CompressingRedisQueue|HorizonCompressingRedisQueue
     */
    public function connect(array $config)
    {
        $class = class_exists(HorizonRedisQueue::class)
            ? HorizonCompressingRedisQueue::class
            : CompressingRedisQueue::class;

        // Laravel's queue constructor has grown across the versions this package
        // supports. Trimming the list to what the class declares keeps one call
        // site correct for all of them.
        $arguments = [
            $this->redis,
            $config['queue'],
            $config['connection'] ?? $this->connection,
            $config['retry_after'] ?? 60,
            $config['block_for'] ?? null,
            $config['after_commit'] ?? null,
            $config['migration_batch_size'] ?? -1,
        ];

        $declared = new ReflectionClass($class)->getConstructor()?->getNumberOfParameters()
            ?? count($arguments);

        $queue = new $class(...array_slice($arguments, 0, $declared));
        $queue->setCompressor($this->compressor);

        return $queue;
    }
}
