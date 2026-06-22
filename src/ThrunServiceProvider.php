<?php

declare(strict_types=1);

namespace Thrun\Laravel;

use Illuminate\Support\ServiceProvider;
use Thrun\Laravel\Bus\ThrunMessageBus;
use Thrun\Laravel\Console\ThrunEventCommand;
use Thrun\Laravel\Console\ThrunFailedCommand;
use Thrun\Laravel\Console\ThrunFailedFlushCommand;
use Thrun\Laravel\Console\ThrunFailedShowCommand;
use Thrun\Laravel\Console\ThrunFlushCommand;
use Thrun\Laravel\Console\ThrunRetryCommand;
use Thrun\Laravel\Console\ThrunWorkCommand;
use Thrun\Laravel\Event\EventListener;
use Thrun\Laravel\Event\EventListenerRegistry;
use Thrun\Laravel\Handler\HandlerRegistry;
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

        $this->app->singleton(HandlerRegistry::class, function ($app) {
            $registry = new HandlerRegistry();

            $globalHandlers = $app['config']->get('thrun.handlers', []);
            foreach ($globalHandlers as $messageClass => $handler) {
                $registry->register($messageClass, $handler);
            }

            $autoDiscover = $app['config']->get('thrun.auto_discover', []);
            if ($autoDiscover !== []) {
                $registry->discover($autoDiscover);
            }

            return $registry;
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
    }

    public function boot(): void
    {
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
}
