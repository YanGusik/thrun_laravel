<?php

declare(strict_types=1);

namespace Thrun\Laravel\Native;

use Closure;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Log\Context\Repository as ContextRepository;
use RuntimeException;
use Spawn\Laravel\Foundation\AsyncApplication;
use Spawn\Laravel\Server\TrueAsyncServer;

/**
 * Boots one Laravel application per worker thread.
 *
 * A thread is a separate PHP interpreter: nothing is shared with the process
 * that reserved the job, so the application is built here from the same
 * bootstrap file artisan would use.
 *
 * Concurrency inside the thread is safe only because laravel-spawn's async mode
 * resolves per-coroutine services from the coroutine context. Without it every
 * coroutine on the thread would share one container, one connection and one
 * event dispatcher.
 */
final class ThreadBootstrapper
{
    /**
     * The closure the thread pool runs once per thread, before its first job.
     *
     * Only the two paths cross the thread boundary, which is why they are
     * parameters rather than anything resolved here.
     *
     * @throws RuntimeException when either path is missing
     */
    public static function bootloader(string $autoloadPath, string $bootstrapPath): Closure
    {
        foreach ([$autoloadPath, $bootstrapPath] as $path) {
            if (!file_exists($path)) {
                // Checked here rather than in the thread: a fatal inside the
                // bootloader fails the whole pool with no readable reason.
                throw new RuntimeException("Cannot boot a worker thread: {$path} does not exist.");
            }
        }

        return static function () use ($autoloadPath, $bootstrapPath): void {
            require $autoloadPath;

            /** @var Application $app */
            $app = require $bootstrapPath;
            $app->make(Kernel::class)->bootstrap();

            // Named in full, not `self`: a closure loses its class scope when it
            // crosses into a worker thread, and `self` there is a fatal error.
            ThreadBootstrapper::prepare($app);
        };
    }

    /**
     * Everything the thread's application needs beyond a plain bootstrap: async
     * mode with its pools and adapters, per-coroutine log context, and the worker
     * that runs the jobs.
     */
    public static function prepare(Application $app): void
    {
        TrueAsyncServer::initializeApp($app);

        if ($app instanceof AsyncApplication) {
            // Laravel binds the log context as `scoped`, meaning "one per request"
            // — and its own listener rehydrates it on every JobProcessing event.
            // Left shared, two concurrent jobs on one thread overwrite each
            // other's context and their log lines swap identities.
            $app->scopedSingleton(
                ContextRepository::class,
                fn() => new ContextRepository($app->make('events')),
            );
        }

        $app->singleton(NativeWorker::class, function ($app) {
            $worker = new NativeWorker(
                $app->make('queue'),
                $app->make('events'),
                $app->make(ExceptionHandler::class),
                fn() => $app->isDownForMaintenance(),
            );

            // Without the cache `$maxExceptions` on a job is silently ignored —
            // the framework counts exceptions there. `queue:work` does the same.
            return $worker->setCache($app->make('cache')->driver());
        });
    }
}
