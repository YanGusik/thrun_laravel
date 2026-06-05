<?php

declare(strict_types=1);

namespace Thrun\Laravel;

use Illuminate\Support\ServiceProvider;
use Thrun\Laravel\Bus\ThrunMessageBus;
use Thrun\Laravel\Console\ThrunWorkCommand;
use Thrun\Laravel\Handler\HandlerRegistry;
use Thrun\Laravel\Transport\TransportFactory;
use Thrun\Laravel\Worker\ThrunWorkerFactory;

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

        $this->app->singleton(ThrunMessageBus::class, function ($app) {
            return new ThrunMessageBus($app->make(TransportFactory::class));
        });
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                ThrunWorkCommand::class,
            ]);

            $this->publishes([
                __DIR__ . '/../config/thrun.php' => config_path('thrun.php'),
            ], 'thrun-config');
        }
    }
}
