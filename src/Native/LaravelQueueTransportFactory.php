<?php

declare(strict_types=1);

namespace Thrun\Laravel\Native;

use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Contracts\Config\Repository as ConfigContract;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Queue\Factory as QueueFactory;
use Illuminate\Queue\RedisQueue;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Builds the transport behind a queue declared with `'transport' => 'laravel'`.
 *
 * Everything the operator would have typed on `queue:work` is read from the
 * queue's own entry in `config/thrun.php`, falling back to what Horizon was
 * already told, and only then to a default. The queue therefore describes a
 * complete worker configuration, and the supervisor that lists it adds only
 * threads and concurrency.
 */
final readonly class LaravelQueueTransportFactory
{
    private const DEFAULT_QUEUE = 'default';

    private const DEFAULT_SLEEP_SECONDS = 1.0;

    private const DEFAULT_TRIES = 1;

    private const DEFAULT_TIMEOUT_SECONDS = 60;

    private const DEFAULT_BACKOFF_SECONDS = 0;

    /** What Laravel assumes when a connection does not say; see config/queue.php. */
    private const DEFAULT_RETRY_AFTER_SECONDS = 60;

    public function __construct(
        private Application $app,
        private ConfigContract $config,
        private QueueFactory $queues,
        private Cache $cache,
        private ExceptionHandler $exceptions,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @param array<string, mixed> $queueConfig the queue's entry from `thrun.queues`
     * @param int                  $maxInFlight jobs the worker that will own this transport can hold at once
     *
     * @throws RuntimeException when the runtime or the queue connection cannot carry native jobs
     */
    public function create(array $queueConfig, int $maxInFlight): LaravelQueueTransport
    {
        $connection = $queueConfig['connection'] ?? $this->config->get('queue.default');

        $this->assertRuntime($connection);

        $horizon = HorizonSettings::forConnection($this->config, $connection, $this->app->environment());

        if ($horizon->supervisors > 1) {
            // Horizon runs each supervisor with its own tries and timeout; one
            // worker cannot, so the operator should know whose numbers these are.
            $this->logger->warning(
                'Thrun: several Horizon supervisors cover this connection; the first one\'s tries and timeout '
                . 'apply to all of its queues.',
                ['connection' => $connection, 'supervisors' => $horizon->supervisors],
            );
        }

        $gate = new ProducerGate(
            // `queue:restart` writes a timestamp here, and every worker started
            // before it is expected to exit so the supervisor replaces it.
            restartSignal: fn() => $this->cache->get('illuminate:queue:restart'),
            isDownForMaintenance: fn() => $this->app->isDownForMaintenance(),
            maxJobs: (int) $this->setting($queueConfig, 'max_jobs', $horizon->maxJobs, 0),
            maxTime: (int) $this->setting($queueConfig, 'max_time', $horizon->maxTime, 0),
            stopWhenEmpty: (bool) ($queueConfig['stop_when_empty'] ?? false),
            force: (bool) ($queueConfig['force'] ?? false),
        );

        $sleepSeconds = (float) ($queueConfig['sleep'] ?? self::DEFAULT_SLEEP_SECONDS);

        return new LaravelQueueTransport(
            queues: $this->queues,
            connectionName: $connection,
            queueNames: $this->queueNames($queueConfig, $horizon),
            sleepMs: (int) ($sleepSeconds * 1000),
            gate: $gate,
            exceptions: $this->exceptions,
            logger: $this->logger,
            maxInFlight: $maxInFlight,
            retryAfter: (int) $this->config->get(
                "queue.connections.{$connection}.retry_after",
                self::DEFAULT_RETRY_AFTER_SECONDS,
            ),
            workerSettings: [
                'tries'   => (int) $this->setting($queueConfig, 'tries', $horizon->tries, self::DEFAULT_TRIES),
                'timeout' => (int) $this->setting(
                    $queueConfig,
                    'timeout',
                    $horizon->timeout,
                    self::DEFAULT_TIMEOUT_SECONDS,
                ),
                'backoff' => (int) ($queueConfig['backoff'] ?? self::DEFAULT_BACKOFF_SECONDS),
            ],
        );
    }

    /**
     * The queues to poll, highest priority first.
     *
     * @param array<string, mixed> $queueConfig
     *
     * @return list<string>
     */
    private function queueNames(array $queueConfig, HorizonSettings $horizon): array
    {
        if (isset($queueConfig['queues'])) {
            return array_values((array) $queueConfig['queues']);
        }

        return $horizon->queues !== [] ? $horizon->queues : [self::DEFAULT_QUEUE];
    }

    /**
     * A setting the operator wrote down wins; otherwise the value Horizon was
     * configured with; otherwise ours.
     *
     * Presence decides, not the value: `'tries' => 1` in the config file is an
     * instruction, and silently preferring Horizon's 3 over it would make the
     * file a suggestion.
     *
     * @param array<string, mixed> $queueConfig
     */
    private function setting(array $queueConfig, string $key, ?int $fromHorizon, int $default): int
    {
        if (array_key_exists($key, $queueConfig)) {
            return (int) $queueConfig[$key];
        }

        return $fromHorizon ?? $default;
    }

    /**
     * Native mode's requirements, checked while the worker is still being built:
     * every one of them fails confusingly — or silently — once it is running.
     *
     * @throws RuntimeException when the runtime or the queue driver is not what
     *                          native mode needs
     */
    private function assertRuntime(string $connection): void
    {
        if (!extension_loaded('true_async')) {
            throw new RuntimeException('A queue with the laravel transport needs the TrueAsync build of PHP.');
        }

        $queue = $this->queues->connection($connection);

        if (!$queue instanceof RedisQueue) {
            // Other drivers reserve differently, and pop() has already consumed
            // an attempt by the time we would find out we cannot use the job.
            throw new RuntimeException(sprintf(
                'The laravel transport supports redis queues only; connection [%s] is %s.',
                $connection,
                $queue::class,
            ));
        }
    }
}
