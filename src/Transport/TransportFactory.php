<?php

declare(strict_types=1);

namespace Thrun\Laravel\Transport;

use Illuminate\Contracts\Config\Repository as ConfigContract;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Queue\ClearableQueue;
use Redis;
use RuntimeException;
use Thrun\Contract\FailedJobStoreInterface;
use Thrun\Contract\ReceiverInterface;
use Thrun\Contract\SenderInterface;
use Thrun\Contract\TransportInterface;
use Thrun\Laravel\Native\HorizonSettings;
use Thrun\Laravel\Native\LaravelQueueTransportFactory;
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
     * @param int          $maxInFlight jobs the worker will hold at once; only a transport that owns its messages
     *                                  until they are answered for needs it, and 0 leaves such a transport unbounded
     */
    public function createReceiver(
        array $queueNames,
        array $strategyConfig = [],
        array $policyConfig = [],
        int $maxInFlight = 0,
    ): ReceiverInterface {
        if ($queueNames === []) {
            return new InMemoryTransport();
        }

        $receivers = [];
        foreach ($queueNames as $name) {
            $receivers[$name] = $this->getQueueTransport($name, $maxInFlight);
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
     *
     * Returns the same instance used by receivers for this queue, except where the
     * transport describes a run rather than a queue — see {@see isShareable()}.
     * Laravel's own queue is not a destination at all: jobs enter it through the
     * framework's dispatcher, and the transport returned here refuses to send.
     */
    public function createSender(string $queue = 'default'): TransportInterface
    {
        return $this->getQueueTransport($queue);
    }

    /**
     * Flush all keys for a given queue (ready, processing, delayed).
     *
     * A queue on the laravel transport keeps nothing under thrun's own keys —
     * its jobs live in the application's queue, written there by the framework's
     * dispatcher — so it is cleared through the framework instead.
     */
    public function flushQueue(string $queue): void
    {
        $queueConfig = (array) $this->config->get("thrun.queues.{$queue}", []);

        if (($queueConfig['transport'] ?? null) === 'laravel') {
            $this->flushLaravelQueue($queue, $queueConfig);

            return;
        }

        $redisConfig = $this->config->get('thrun.redis', []);
        $connection = new RedisConnection(
            $this->createRedisClient(),
            $redisConfig['prefix'] ?? 'thrun:queue',
        );
        $connection->purge($queue);
    }

    /**
     * @param array<string, mixed> $queueConfig
     */
    private function flushLaravelQueue(string $queue, array $queueConfig): void
    {
        if ($this->container === null) {
            throw new RuntimeException(
                "Queue [{$queue}] uses the laravel transport, which needs the application container to be cleared."
            );
        }

        $connectionName = (string) ($queueConfig['connection'] ?? $this->config->get('queue.default'));
        $connection     = $this->container->make('queue')->connection($connectionName);

        if (!$connection instanceof ClearableQueue) {
            throw new RuntimeException(
                "Queue connection [{$connectionName}] cannot be cleared."
            );
        }

        foreach ($this->laravelQueueNames($queueConfig, $connectionName) as $name) {
            $connection->clear($name);
        }
    }

    /**
     * The queues a laravel-transport entry covers — the same list the worker
     * polls, so a flush cannot leave behind a queue the worker would pick up.
     *
     * @param array<string, mixed> $queueConfig
     *
     * @return list<string>
     */
    private function laravelQueueNames(array $queueConfig, string $connectionName): array
    {
        if (isset($queueConfig['queues'])) {
            return array_values((array) $queueConfig['queues']);
        }

        // The environment is read from configuration rather than the application:
        // a flush may run against a container that binds only what it needs.
        $horizon = HorizonSettings::forConnection(
            $this->config,
            $connectionName,
            (string) $this->config->get('app.env', 'production'),
        );

        if ($horizon->queues !== []) {
            return $horizon->queues;
        }

        return [(string) $this->config->get("queue.connections.{$connectionName}.queue", 'default')];
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

    /**
     * The transport behind a queue name, built once per name where that is safe.
     *
     * @param int $maxInFlight see {@see createReceiver()}; ignored by a transport
     *                         that hands its messages over and forgets them
     */
    public function getQueueTransport(string $name, int $maxInFlight = 0): TransportInterface
    {
        if (isset($this->transports[$name])) {
            return $this->transports[$name];
        }

        $globalQueues = $this->config->get('thrun.queues', []);

        if (!isset($globalQueues[$name])) {
            throw new \InvalidArgumentException("Queue \"{$name}\" not found in config/thrun.php [queues]");
        }

        $transport = $this->buildQueueTransport($name, $globalQueues[$name], $maxInFlight);

        if (self::isShareable($globalQueues[$name])) {
            $this->transports[$name] = $transport;
        }

        return $transport;
    }

    /**
     * Whether two workers may be handed the same transport instance.
     *
     * A transport that keeps track of the messages it has given out — the laravel
     * one counts reservations against a worker's capacity — describes one run, not
     * one queue, and sharing it would have two workers spending one budget.
     *
     * @param array<string, mixed> $queueConfig
     */
    private static function isShareable(array $queueConfig): bool
    {
        return ($queueConfig['transport'] ?? 'redis') !== 'laravel';
    }

    /**
     * @param array<string, mixed> $queueConfig
     * @param int                  $maxInFlight see {@see createReceiver()}
     */
    public function buildQueueTransport(string $name, array $queueConfig, int $maxInFlight = 0): TransportInterface
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
            'laravel' => $this->createLaravelTransport($queueConfig, $maxInFlight),
            default => throw new \InvalidArgumentException("Unknown transport type: {$type}"),
        };
    }

    /**
     * The application's own queue, drained by thrun instead of `queue:work`.
     *
     * @param array<string, mixed> $queueConfig
     */
    private function createLaravelTransport(array $queueConfig, int $maxInFlight): TransportInterface
    {
        if ($this->container === null) {
            throw new \InvalidArgumentException(
                'The laravel transport needs the application container; build the factory with one.'
            );
        }

        return $this->container->make(LaravelQueueTransportFactory::class)->create($queueConfig, $maxInFlight);
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
            'host' => $redisConfig['host'] ?? '127.0.0.1',
            'port' => $redisConfig['port'] ?? 6379,
        ]);
//        $redis->connect(
//            $redisConfig['host'] ?? '127.0.0.1',
//            (int) ($redisConfig['port'] ?? 6379),
//            $redisConfig['timeout'] ?? 1.0,
//        );

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
    public function wrapMultiQueue(array $receivers, array $strategyConfig): MultiQueueReceiver
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

    public function wrapPolicy(ReceiverInterface $inner, array $policyConfig): PolicyAwareReceiver
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
