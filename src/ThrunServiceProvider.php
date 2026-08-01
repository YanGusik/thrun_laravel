<?php

declare(strict_types=1);

namespace Thrun\Laravel;

use Illuminate\Contracts\Bus\Dispatcher as BusDispatcher;
use Illuminate\Queue\CallQueuedHandler;
use Illuminate\Queue\Console\RetryCommand;
use Illuminate\Support\ServiceProvider;
use RuntimeException;
use Thrun\Laravel\Bus\ThrunMessageBus;
use Thrun\Laravel\Compression\CommandCompressor;
use Thrun\Laravel\Compression\CompressingRedisConnector;
use Thrun\Laravel\Compression\CompressionAwareCallQueuedHandler;
use Thrun\Laravel\Compression\CompressionAwareRetryCommand;
use Thrun\Laravel\Console\ThrunEventCommand;
use Thrun\Laravel\Console\ThrunFailedCommand;
use Thrun\Laravel\Console\ThrunFailedFlushCommand;
use Thrun\Laravel\Console\ThrunFailedShowCommand;
use Thrun\Laravel\Console\ThrunFlushCommand;
use Thrun\Laravel\Console\ThrunRetryCommand;
use Thrun\Laravel\Console\ThrunWorkCommand;
use Thrun\Laravel\Event\EventListener;
use Thrun\Laravel\Event\EventListenerRegistry;
use Thrun\Laravel\Rpc\RpcAddress;
use Thrun\Laravel\Rpc\RpcPublisher;
use Thrun\Laravel\Rpc\RpcServerFactory;
use Thrun\Laravel\Transport\TransportFactory;
use Thrun\Laravel\Worker\ThrunWorkerFactory;
use Thrun\Serialization\ClassMapMessageTypeResolver;
use Thrun\Serialization\JsonSerializer;

final class ThrunServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/thrun.php', 'thrun');

        $this->app->singleton(TransportFactory::class, function ($app) {
            return new TransportFactory($app['config'], $app);
        });

        $this->app->singleton(ThrunWorkerFactory::class, function ($app) {
            return new ThrunWorkerFactory(
                config: $app['config'],
                container: $app,
                transportFactory: $app->make(TransportFactory::class),
            );
        });

        $this->app->singleton(RpcAddress::class, fn($app) => RpcAddress::fromConfig($app['config']));
        $this->app->singleton(RpcServerFactory::class, fn($app) => new RpcServerFactory(
            $app['config'], $app->make(TransportFactory::class), $app->make(RpcAddress::class),
        ));

        $this->app->singleton(RpcPublisher::class, fn($app) => new RpcPublisher(
            $app->make(RpcAddress::class),
            new JsonSerializer(new ClassMapMessageTypeResolver()),
        ));

        $this->app->singleton(ThrunMessageBus::class, fn($app) => new ThrunMessageBus(
            $app->make(TransportFactory::class),
            $app->make(RpcPublisher::class),
        ));

        $this->app->singleton(EventListenerRegistry::class, fn($app) => new EventListenerRegistry($app));
        $this->app->singleton(EventListener::class, fn($app) => new EventListener($app->make(EventListenerRegistry::class)));

        $this->registerCompression();
    }

    public function boot(): void
    {
        if ($this->app['config']->get('thrun.compression.enabled', false)) {
            $this->enableCompressedWrites();
        }

        if ($this->app->runningInConsole()) {
            $this->commands([
                ThrunWorkCommand::class,
                ThrunEventCommand::class,
                ThrunFailedCommand::class,
                ThrunFailedShowCommand::class,
                ThrunFailedFlushCommand::class,
                ThrunFlushCommand::class,
                ThrunRetryCommand::class,
            ]);

            $this->publishes([
                __DIR__ . '/../config/thrun.php' => config_path('thrun.php'),
            ], 'thrun-config');
        }
    }

    /**
     * Route the redis queue driver through the connector that compresses bodies.
     *
     * Deferred to `booted()` because Horizon registers a connector of its own from
     * a provider, and the last registration wins.
     *
     * @throws RuntimeException when compression is on but ext-lz4 is absent — the
     *                          alternative is discovering it on the first large job
     *                          inside a web request
     */
    private function enableCompressedWrites(): void
    {
        if (!function_exists('lz4_compress')) {
            throw new RuntimeException(
                'thrun.compression is enabled but ext-lz4 is missing (https://github.com/kjdev/php-ext-lz4).'
            );
        }

        $this->app->booted(function ($app): void {
            $app['queue']->addConnector('redis', fn() => new CompressingRedisConnector(
                $app['redis'],
                $app->make(CommandCompressor::class),
            ));
        });
    }

    /**
     * Reading compressed bodies is always on, writing them is not.
     *
     * A queue holds jobs written before the setting changed, and jobs written by
     * another deployment that still has it on. A worker that cannot read those
     * would fail them one by one, so the reader is installed unconditionally and
     * only the writer follows the setting.
     */
    private function registerCompression(): void
    {
        $this->app->singleton(CommandCompressor::class, function ($app) {
            $minBytes = $app['config']->get('thrun.compression.min_bytes');

            return $minBytes === null
                ? new CommandCompressor()
                : new CommandCompressor((int) $minBytes);
        });

        $this->app->bind(CallQueuedHandler::class, fn($app) => new CompressionAwareCallQueuedHandler(
            $app->make(BusDispatcher::class),
            $app,
            $app->make(CommandCompressor::class),
        ));

        // extend() rather than a binding: `queue:retry` comes from a deferred
        // provider that binds the command to itself when the console kernel loads
        // it, which happens after every provider has registered. A binding made
        // here would be overwritten; an extender is applied on top of whatever
        // binding wins.
        $this->app->extend(
            RetryCommand::class,
            fn() => new CompressionAwareRetryCommand($this->app->make(CommandCompressor::class)),
        );
    }
}
