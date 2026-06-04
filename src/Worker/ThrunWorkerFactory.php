<?php

declare(strict_types=1);

namespace Thrun\Laravel\Worker;

use Illuminate\Contracts\Config\Repository as ConfigContract;
use Illuminate\Contracts\Container\Container;
use Thrun\Laravel\Handler\AsThrunHandler;
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
    public function __construct(
        private ConfigContract $config,
        private Container $container,
        private TransportFactory $transportFactory,
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
        $handlers = $this->resolveHandlers($supervisorConfig);

        if ($handlers === []) {
            throw new \RuntimeException(
                "No message handlers registered for supervisor \"{$supervisor}\". " .
                'Check config/thrun.php supervisors.*.handlers or auto_discover namespaces.'
            );
        }

        $workerConfig = $supervisorConfig['worker'] ?? [];
        $threads = $workerConfig['threads'] ?? 4;
        $concurrency = $workerConfig['concurrency'] ?? 10;

        $receiver = $this->transportFactory->createReceiver(
            queueNames: $supervisorConfig['queues'] ?? [],
            strategyConfig: $supervisorConfig['strategy'] ?? [],
            policyConfig: $supervisorConfig['policy'] ?? [],
        );

        $failureTransport = null;
        if (!empty($supervisorConfig['failure_transport'])) {
            $failureTransport = $this->transportFactory->createSender($supervisorConfig['failure_transport']);
        }

        return new Worker(
            transport: $receiver,
            handlers: $handlers,
            options: new WorkerOptions(
                threads: $threads,
                concurrency: $concurrency,
                bootloader: $this->createBootloader(),
                middleware: $middleware,
                onDispatch: $onDispatch,
                onResult: $onResult,
            ),
            failureTransport: $failureTransport,
            metrics: $metrics ?? new NullMetrics(),
        );
    }

    public function createSupervisor(
        string $supervisor = 'default',
        ?MetricsInterface $metrics = null,
        array $middleware = [],
        ?\Closure $onDispatch = null,
        ?\Closure $onResult = null,
    ): Supervisor {
        $supervisorConfig = $this->getSupervisor($supervisor);
        $config = $supervisorConfig['supervisor'] ?? [];

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
     * @param array<string, mixed> $supervisorConfig
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
     * Auto-discover handlers in the given namespace.
     *
     * @return array<string, callable(object|array, ?Acknowledger): void>
     */
    private function discoverNamespace(string $namespace): array
    {
        $handlers = [];
        $handlerSuffix = 'Handler';
        $messageSuffix = 'Message';

        $dirPath = str_replace('\\', '/', $namespace);
        $dirPath = preg_replace('#^App/#', 'app/', $dirPath);
        $directory = $this->container->basePath() . '/' . $dirPath;

        if (is_dir($directory)) {
            $files = glob($directory . '/*' . $handlerSuffix . '.php');
            // var_dump(['found_files' => $files]);
            foreach ($files as $file) {
                require_once $file;
            }
        }

        foreach (get_declared_classes() as $class) {
            if (!str_starts_with($class, $namespace)) {
                continue;
            }

            if (!str_ends_with($class, $handlerSuffix)) {
                continue;
            }

            $reflection = new \ReflectionClass($class);
            if ($reflection->isAbstract() || $reflection->isInterface()) {
                continue;
            }

            $attributes = $reflection->getAttributes(AsThrunHandler::class);
            if ($attributes !== []) {
                $attr = $attributes[0]->newInstance();
                if ($attr->messageClass !== null) {
                    $handlers[$attr->messageClass] = $this->resolveHandler($class);
                    continue;
                }
            }

            $baseName = substr($class, 0, -strlen($handlerSuffix));
            $possibleMessage = str_replace('\\Handlers\\', '\\Messages\\', $baseName) . $messageSuffix;
            // var_dump(['handler' => $class, 'possibleMessage' => $possibleMessage, 'exists' => class_exists($possibleMessage)]);
            if (class_exists($possibleMessage)) {
                $handlers[$possibleMessage] = $this->resolveHandler($class);
            }
        }

        // var_dump(['discovered_handlers' => array_keys($handlers)]);
        // die("[DEBUG] discoverNamespace done\n");
        return $handlers;
    }

    /**
     * @param class-string|\Closure $handler
     * @return callable(object|array, ?Acknowledger): void
     */
    private function resolveHandler(string|\Closure $handler): \Closure
    {
        if ($handler instanceof \Closure) {
            return $handler;
        }

        return static function (object|array $message, ?Acknowledger $ack = null) use ($handler): void {
            $instance = \Illuminate\Container\Container::getInstance()->make($handler);

            if (!is_callable($instance)) {
                throw new \RuntimeException(sprintf(
                    'Handler "%s" must be callable (__invoke method required)',
                    $handler,
                ));
            }

            if ($ack !== null) {
                $instance($message, $ack);
            } else {
                $instance($message);
            }
        };
    }

    private function createBootloader(): \Closure
    {
        $basePath = $this->container->basePath();
        $autoload = $basePath . '/vendor/autoload.php';

        if (!file_exists($autoload)) {
            throw new \RuntimeException("Cannot find Laravel autoloader at {$autoload}");
        }

        return static function () use ($autoload, $basePath): void {
            require_once $autoload;

            $app = require $basePath . '/bootstrap/app.php';
            $app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
        };
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
