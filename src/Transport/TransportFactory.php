<?php

declare(strict_types=1);

namespace Thrun\Laravel\Transport;

use Illuminate\Contracts\Config\Repository as ConfigContract;
use Illuminate\Contracts\Container\Container;
use Redis;
use Thrun\Contract\FailedJobStoreInterface;
use Thrun\Contract\ReceiverInterface;
use Thrun\Contract\SenderInterface;
use Thrun\Contract\TransportInterface;
use Thrun\Serialization\ClassMapMessageTypeResolver;
use Thrun\Serialization\JsonSerializer;
use Thrun\Transport\Failed\NullFailedJobSender;
use Thrun\Transport\Failed\RedisFailedJobSender;
use Thrun\Transport\InMemory\InMemoryTransport;
use Thrun\Transport\MultiQueueReceiver;
use Thrun\Transport\Policy\ChainPolicy;
use Thrun\Transport\PolicyAwareReceiver;
use Thrun\Transport\Redis\RedisConnection;
use Thrun\Transport\Redis\RedisTransport;
use Thrun\Transport\Strategy\PriorityStrategy;
use Thrun\Transport\Strategy\RoundRobinStrategy;

final class TransportFactory
{
    /** @var array<string, TransportInterface> */
    private array $transports = [];

    /** @var array<string, \Closure(string, array): TransportInterface> */
    private array $drivers = [];

    /** @var array<string, \Closure(array): SenderInterface> */
    private array $failedDrivers = [];

    public function __construct(
        private readonly ConfigContract $config,
        private readonly ?Container $container = null,
    ) {
    }

    /**
     * Register a custom transport resolver.
     *
     * @param \Closure(string $queueName, array $queueConfig): TransportInterface $resolver
     */
    public function extend(string $type, \Closure $resolver): void
    {
        $this->drivers[$type] = $resolver;
    }

    /**
     * Register a custom failed job sender resolver.
     *
     * @param \Closure(array $failedConfig): SenderInterface $resolver
     */
    public function extendFailed(string $driver, \Closure $resolver): void
    {
        $this->failedDrivers[$driver] = $resolver;
    }

    /**
     * Build the composed receiver for a given supervisor.
     *
     * @param list<string> $queueNames
     */
    public function createReceiver(array $queueNames, array $strategyConfig = [], array $policyConfig = []): ReceiverInterface
    {
        if ($queueNames === []) {
            return new InMemoryTransport();
        }

        $receivers = [];
        foreach ($queueNames as $name) {
            $receivers[$name] = $this->getQueueTransport($name);
        }

        if (count($receivers) === 1) {
            $transport = reset($receivers);
        } else {
            $transport = $this->wrapMultiQueue($receivers, $strategyConfig);
        }

        if ($policyConfig['enabled'] ?? false) {
            $transport = $this->wrapPolicy($transport, $policyConfig);
        }

        return $transport;
    }

    /**
     * Create a sender for a specific queue name.
     * Returns the same instance used by receivers for this queue (shared transport).
     */
    public function createSender(string $queue = 'default'): TransportInterface
    {
        return $this->getQueueTransport($queue);
    }

    /**
     * Flush all keys for a given queue (ready, processing, delayed).
     */
    public function flushQueue(string $queue): void
    {
        $redisConfig = $this->config->get('thrun.redis', []);
        $connection = new RedisConnection(
            $this->createRedisClient(),
            $redisConfig['prefix'] ?? 'thrun:queue',
        );
        $connection->purge($queue);
    }

    /**
     * Build the failed job sender based on global config.
     */
    public function createFailedJobSender(): FailedJobStoreInterface
    {
        $failedConfig = $this->config->get('thrun.failed', []);
        $driver = $failedConfig['driver'] ?? 'null';

        if (isset($this->failedDrivers[$driver])) {
            return ($this->failedDrivers[$driver])($failedConfig);
        }

        return match ($driver) {
            'redis' => new RedisFailedJobSender(
                redis: $this->createRedisClient(),
                serializer: new JsonSerializer(new ClassMapMessageTypeResolver()),
                prefix: $failedConfig['redis']['prefix'] ?? 'thrun:failed',
            ),
            default => new NullFailedJobSender(),
        };
    }

    private function getQueueTransport(string $name): TransportInterface
    {
        if (isset($this->transports[$name])) {
            return $this->transports[$name];
        }

        $globalQueues = $this->config->get('thrun.queues', []);

        if (!isset($globalQueues[$name])) {
            throw new \InvalidArgumentException("Queue \"{$name}\" not found in config/thrun.php [queues]");
        }

        $this->transports[$name] = $this->buildQueueTransport($name, $globalQueues[$name]);

        return $this->transports[$name];
    }

    /**
     * @param array<string, mixed> $queueConfig
     */
    private function buildQueueTransport(string $name, array $queueConfig): TransportInterface
    {
        $type = $queueConfig['transport'] ?? 'redis';

        // 1. Custom closure driver
        if (isset($this->drivers[$type])) {
            return ($this->drivers[$type])($name, $queueConfig);
        }

        // 2. Config-based class driver
        if (isset($queueConfig['driver']) && \is_string($queueConfig['driver'])) {
            $driverClass = $queueConfig['driver'];

            if (!\class_exists($driverClass)) {
                throw new \InvalidArgumentException("Transport driver class \"{$driverClass}\" does not exist");
            }

            if ($this->container !== null) {
                return $this->container->make($driverClass, ['name' => $name, 'config' => $queueConfig]);
            }

            return new $driverClass($name, $queueConfig);
        }

        // 3. Built-in drivers
        return match ($type) {
            'redis' => $this->createRedisTransport($name),
            'memory' => new InMemoryTransport(),
            default => throw new \InvalidArgumentException("Unknown transport type: {$type}"),
        };
    }

    private function createRedisClient(): \Redis
    {
        $redisConfig = $this->config->get('thrun.redis', []);

        $redis = new Redis([
            'pool' => [
                'enabled' => true,
                'min'     => 0,
                'max'     => 1,
                'mux'     => 0,
            ],
        ]);
        $redis->connect(
            $redisConfig['host'] ?? '127.0.0.1',
            (int) ($redisConfig['port'] ?? 6379),
            $redisConfig['timeout'] ?? 1.0,
        );

        return $redis;
    }

    private function createRedisTransport(string $queue): RedisTransport
    {
        $redisConfig = $this->config->get('thrun.redis', []);

        $connection = new RedisConnection(
            $this->createRedisClient(),
            $redisConfig['prefix'] ?? 'thrun:queue',
        );

        return new RedisTransport(
            connection: $connection,
            serializer: new JsonSerializer(new ClassMapMessageTypeResolver()),
            queue: $queue,
        );
    }

    /**
     * @param array<string, TransportInterface> $receivers
     */
    private function wrapMultiQueue(array $receivers, array $strategyConfig): MultiQueueReceiver
    {
        $strategyClass = $strategyConfig['class'] ?? PriorityStrategy::class;
        $priorities = $strategyConfig['priorities'] ?? [];

        $strategy = match ($strategyClass) {
            RoundRobinStrategy::class => new RoundRobinStrategy(),
            PriorityStrategy::class => new PriorityStrategy(),
            default => new $strategyClass(),
        };

        return new MultiQueueReceiver(
            receivers: $receivers,
            strategy: $strategy,
            priorities: $priorities,
        );
    }

    private function wrapPolicy(ReceiverInterface $inner, array $policyConfig): PolicyAwareReceiver
    {
        $policyClass = $policyConfig['class'] ?? ChainPolicy::class;
        $options = $policyConfig['options'] ?? [];

        $policy = match ($policyClass) {
            \Thrun\Transport\Policy\MaxConcurrencyPolicy::class => new \Thrun\Transport\Policy\MaxConcurrencyPolicy(
                maxPerPartition: $options['max_per_partition'] ?? 5,
            ),
            default => new $policyClass($options),
        };

        return new PolicyAwareReceiver(inner: $inner, policy: $policy);
    }
}
