<?php

declare(strict_types=1);

namespace Thrun\Laravel\Worker;

use Illuminate\Contracts\Config\Repository as ConfigContract;
use Illuminate\Contracts\Container\Container;
use Psr\Log\LoggerInterface;
use ReflectionException;
use Spawn\Laravel\Foundation\AsyncApplication;
use Thrun\Laravel\Handler\AsThrunHandler;
use Thrun\Laravel\Handler\Attribute\ThrunJob;
use Thrun\Laravel\Native\LaravelQueueTransport;
use Thrun\Laravel\Native\NativeJobHandler;
use Thrun\Laravel\Native\NonLaravelFailureSender;
use Thrun\Laravel\Native\ThreadBootstrapper;
use Thrun\Laravel\Transport\TransportFactory;
use Thrun\Supervisor\Supervisor;
use Thrun\Supervisor\SupervisorOptions;
use Thrun\Worker\Acknowledger;
use Thrun\Contract\MetricsInterface;
use Thrun\Worker\Metrics\NullMetrics;
use Thrun\Worker\Worker;
use Thrun\Worker\WorkerOptions;

final readonly class ThrunWorkerFactory
{
    /** Seconds a shutdown waits for jobs that do not end on their own. */
    private const DEFAULT_SHUTDOWN_GRACE_SECONDS = 60;

    public function __construct(
        private ConfigContract $config,
        private Container $container,
        private TransportFactory $transportFactory,
        private LoggerInterface $logger,
    ) {
    }

    public function createWorker(
        string $supervisor = 'default',
        ?MetricsInterface $metrics = null,
        array $middleware = [],
        ?\Closure $onDispatch = null,
        ?\Closure $onResult = null,
    ): Worker {
        $supervisorConfig = $this->getSupervisor($supervisor);
        $queueNames       = $supervisorConfig['queues'] ?? [];
        $carriesLaravel   = $this->carriesLaravelJobs($queueNames);
        $handlers         = $this->resolveHandlers($supervisorConfig);

        if ($carriesLaravel) {
            // The only handler an ordinary Laravel job needs, and one no
            // application can register for itself: the route key belongs to the
            // transport, not to any message class.
            $handlers[LaravelQueueTransport::ROUTE] = NativeJobHandler::class;
        }

        if ($handlers === []) {
            throw new \RuntimeException(
                "No message handlers registered for supervisor \"{$supervisor}\". ".
                'Check config/thrun.php supervisors.*.handlers or auto_discover namespaces.'
            );
        }

        $workerConfig = $supervisorConfig['worker'] ?? [];
        $threads      = $workerConfig['threads'] ?? 4;
        $concurrency  = $workerConfig['concurrency'] ?? 10;
        $queueSize    = $workerConfig['queue_size'] ?? 1000;

        if ($carriesLaravel) {
            $concurrency = $this->isolatedConcurrency($concurrency, $supervisor);
        }

        $resolvedMiddleware = array_merge(
            $this->resolveMiddleware($supervisorConfig['middleware'] ?? []),
            $middleware,
        );

        $receiver = $this->transportFactory->createReceiver(
            queueNames: $queueNames,
            strategyConfig: $supervisorConfig['strategy'] ?? [],
            policyConfig: $supervisorConfig['policy'] ?? [],
            maxInFlight: $threads * max(1, $concurrency),
        );

        if ($carriesLaravel) {
            $receiver = new DrainingReceiver(
                $receiver,
                $this->logger,
                $workerConfig['shutdown_grace'] ?? self::DEFAULT_SHUTDOWN_GRACE_SECONDS,
            );
        }

        $failureTransport = $this->transportFactory->createFailedJobSender();

        if ($carriesLaravel) {
            $failureTransport = new NonLaravelFailureSender($failureTransport);
        }

        $worker = new Worker(
            transport: $receiver,
            handlers: $handlers,
            options: new WorkerOptions(
                threads: $threads,
                concurrency: $concurrency,
                queueSize: $queueSize,
                bootloader: $carriesLaravel ? $this->createLaravelBootloader() : $this->createBootloader(),
                middleware: $resolvedMiddleware,
                onDispatch: $onDispatch,
                onResult: $onResult,
            ),
            failureTransport: $failureTransport,
            metrics: $metrics ?? new NullMetrics(),
        );

        if ($receiver instanceof DrainingReceiver) {
            $receiver->attachTo($worker->isIdle(...), $worker->stop(...));
        }

        return $worker;
    }

    /**
     * Whether any of these queues hands out the application's own Laravel jobs.
     *
     * @param list<string> $queueNames
     */
    private function carriesLaravelJobs(array $queueNames): bool
    {
        $queues = $this->config->get('thrun.queues', []);

        foreach ($queueNames as $name) {
            if (($queues[$name]['transport'] ?? null) === 'laravel') {
                return true;
            }
        }

        return false;
    }

    /**
     * Jobs per thread, lowered to one when nothing keeps them apart.
     *
     * Several coroutines in a thread share the container, the log context and the
     * transaction counter. laravel-spawn's async mode gives each of them its own;
     * without it the only safe number is one, and the operator hears why rather
     * than discovering it as mixed-up data.
     *
     * A configured zero is left alone: it means "no coroutines in the thread at
     * all", which is stricter than one and not something to quietly relax.
     */
    private function isolatedConcurrency(int $configured, string $supervisor): int
    {
        if ($configured <= 1 || $this->container instanceof AsyncApplication) {
            return max(0, $configured);
        }

        $this->logger->warning(
            'Thrun: running one job per thread; concurrent jobs share one container unless bootstrap/app.php '
            . 'builds an AsyncApplication (see laravel-spawn). Queues that carry no Laravel jobs keep their '
            . 'concurrency in a supervisor of their own.',
            ['supervisor' => $supervisor, 'configured_concurrency' => $configured],
        );

        return 1;
    }

    public function createSupervisor(
        string $supervisor = 'default',
        ?MetricsInterface $metrics = null,
        array $middleware = [],
        ?\Closure $onDispatch = null,
        ?\Closure $onResult = null,
    ): Supervisor {
        $supervisorConfig = $this->getSupervisor($supervisor);
        $config           = $supervisorConfig['supervisor'] ?? [];

        return new Supervisor(
            workerFactory: fn() => $this->createWorker($supervisor, $metrics, $middleware, $onDispatch, $onResult),
            options: new SupervisorOptions(
                maxCrashes: $config['max_crashes'] ?? 3,
                restartWindow: $config['restart_window'] ?? 300,
                restartBackoff: $config['restart_backoff'] ?? 1.0,
            ),
        );
    }

    /**
     * @param  array<string, mixed>  $supervisorConfig
     * @return array<string, callable(object|array, ?Acknowledger): void>
     */
    private function resolveHandlers(array $supervisorConfig): array
    {
        $handlers = [];

        // Auto-discover from global config
        foreach ($this->config->get('thrun.auto_discover', []) as $namespace) {
            $handlers += $this->discoverNamespace($namespace);
        }

        // Per-supervisor explicit handlers (override auto-discovered)
        foreach ($supervisorConfig['handlers'] ?? [] as $routeKey => $handler) {
            $handlers[$routeKey] = $this->resolveHandler($handler);
        }

        return $handlers;
    }

    /**
     * Auto-discover handlers and self-handling jobs in the given namespace.
     *
     * @return array<string, callable(object|array, ?Acknowledger): void>
     * @throws ReflectionException
     */
    private function discoverNamespace(string $namespace): array
    {
        $handlers      = [];
        $handlerSuffix = 'Handler';
        $messageSuffix = 'Message';

        $dirPath   = str_replace('\\', '/', $namespace);
        $dirPath   = preg_replace('#^App/#', 'app/', $dirPath);
        $directory = $this->container->basePath().'/'.$dirPath;

        if (is_dir($directory)) {
            $handlerFiles = glob($directory.'/*'.$handlerSuffix.'.php');
            $jobFiles     = glob($directory.'/*Job.php');
            foreach (array_merge($handlerFiles, $jobFiles) as $file) {
                require_once $file;
            }
        }

        foreach (get_declared_classes() as $class) {
            if (!str_starts_with($class, $namespace)) {
                continue;
            }

            $reflection = new \ReflectionClass($class);
            if ($reflection->isAbstract() || $reflection->isInterface()) {
                continue;
            }

            // 1. Self-handling job via #[ThrunJob]
            $jobAttributes = $reflection->getAttributes(ThrunJob::class);
            if ($jobAttributes !== []) {
                $handlers[$class] = $this->resolveSelfHandler($class);
                continue;
            }

            // 2. Explicit handler via #[AsThrunHandler]
            $handlerAttributes = $reflection->getAttributes(AsThrunHandler::class);
            if ($handlerAttributes !== []) {
                $attr = $handlerAttributes[0]->newInstance();
                if ($attr->messageClass !== null) {
                    $handlers[$attr->messageClass] = $this->resolveHandler($class);
                    continue;
                }
            }

            // 3. Naming convention: *Handler -> *Message
            if (!str_ends_with($class, $handlerSuffix)) {
                continue;
            }

            $baseName        = substr($class, 0, -strlen($handlerSuffix));
            $possibleMessage = str_replace('\\Handlers\\', '\\Messages\\', $baseName).$messageSuffix;
            if (class_exists($possibleMessage)) {
                $handlers[$possibleMessage] = $this->resolveHandler($class);
            }
        }

        return $handlers;
    }

    /**
     * @param  class-string|\Closure  $handler
     * @return callable(object|array, Acknowledger): void
     */
    private function resolveHandler(string|\Closure $handler): \Closure
    {
        if ($handler instanceof \Closure) {
            return $handler;
        }

        return static function (object|array $message, Acknowledger $ack) use ($handler): void {
            $instance = \Illuminate\Container\Container::getInstance()->make($handler);

            if (!is_callable($instance)) {
                throw new \RuntimeException(sprintf(
                    'Handler "%s" must be callable (__invoke method required)',
                    $handler,
                ));
            }

            $instance($message, $ack);
        };
    }

    /**
     * @param  class-string  $jobClass
     * @return callable(object|array, Acknowledger): void
     */
    private function resolveSelfHandler(string $jobClass): \Closure
    {
        return static function (object|array $message, Acknowledger $ack) use ($jobClass): void {
            if (!$message instanceof $jobClass) {
                throw new \RuntimeException(sprintf(
                    'Expected job "%s", got "%s"',
                    $jobClass,
                    get_debug_type($message),
                ));
            }

            if (!is_callable($message)) {
                throw new \RuntimeException(sprintf(
                    'Job "%s" must be invokable (__invoke method required)',
                    $jobClass,
                ));
            }

            \Illuminate\Container\Container::getInstance()->call(
                [$message, '__invoke'],
                ['ack' => $ack],
            );
        };
    }

    /**
     * @param  list<class-string|object>  $middleware
     * @return list<object>
     */
    private function resolveMiddleware(array $middleware): array
    {
        $resolved = [];
        foreach ($middleware as $item) {
            if (\is_string($item)) {
                $resolved[] = $this->container->make($item);
            } else {
                $resolved[] = $item;
            }
        }

        return $resolved;
    }

    private function createBootloader(): \Closure
    {
        $basePath = $this->container->basePath();
        $autoload = $basePath.'/vendor/autoload.php';

        if (!file_exists($autoload)) {
            throw new \RuntimeException("Cannot find Laravel autoloader at {$autoload}");
        }

        return static function () use ($autoload, $basePath): void {
            require_once $autoload;

            $app = require $basePath.'/bootstrap/app.php';
            $app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
        };
    }

    /**
     * The bootloader for a worker that runs ordinary Laravel jobs.
     *
     * It does everything {@see createBootloader()} does and then some: a job
     * expects the framework's async services, its own log context and a queue
     * worker to run it, none of which a bare bootstrap leaves behind.
     */
    private function createLaravelBootloader(): \Closure
    {
        return ThreadBootstrapper::bootloader(
            $this->container->basePath('vendor/autoload.php'),
            $this->container->bootstrapPath('app.php'),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function getSupervisor(string $name): array
    {
        $supervisors = $this->config->get('thrun.supervisors', []);

        if (!isset($supervisors[$name])) {
            throw new \InvalidArgumentException("Thrun supervisor \"{$name}\" not found in config/thrun.php");
        }

        return $supervisors[$name];
    }
}
