<?php

declare(strict_types=1);

namespace Thrun\Laravel\Console;

use Async\Scope;
use Illuminate\Console\Command;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Queue\RedisQueue;
use RuntimeException;
use Spawn\Laravel\Foundation\AsyncApplication;
use Thrun\Laravel\Native\HorizonSettings;
use Thrun\Laravel\Native\InFlightJobs;
use Thrun\Laravel\Native\LaravelQueueTransport;
use Thrun\Laravel\Native\NativeJobHandler;
use Thrun\Laravel\Native\ProducerGate;
use Thrun\Laravel\Native\ThreadBootstrapper;
use Thrun\Worker\Worker;
use Thrun\Worker\WorkerOptions;

use function Async\delay;

/**
 * Runs the application's ordinary Laravel jobs on thrun's threads.
 *
 * A drop-in replacement for `queue:work`: jobs, `dispatch()` calls and the queue
 * itself are untouched, only the process that drains it changes.
 */
final class ThrunNativeWorkCommand extends Command
{
    protected $signature = 'thrun:work-native
                            {--connection= : Laravel queue connection (default: queue.default)}
                            {--queue= : Comma separated queue names, highest priority first}
                            {--threads= : OS threads}
                            {--concurrency= : Jobs running at once per thread}
                            {--sleep= : Seconds to wait when every queue is empty}
                            {--max-jobs=0 : Stop after reserving this many jobs}
                            {--max-time=0 : Stop after this many seconds}
                            {--stop-when-empty : Stop once the queues run dry}
                            {--force : Keep working while the application is down for maintenance}
                            {--tries=1 : Attempts before a job is marked failed}
                            {--timeout=60 : Seconds a single job may run}
                            {--backoff=0 : Seconds to wait before retrying a failed job}
                            {--shutdown-grace=60 : Seconds to wait for running jobs when stopping}';

    protected $description = 'Process ordinary Laravel jobs on thrun threads';

    /** How often the drain watcher checks whether the run can end. */
    private const DRAIN_POLL_MS = 50;

    public function handle(Application $app, Cache $cache): int
    {
        $config = $app->make('config');
        $connection = $this->option('connection') ?? $config->get('queue.default');

        $this->assertRuntime($app, $connection);

        // Horizon's own supervisor settings are the closest thing to what this
        // application already runs; an explicit option still overrides them.
        $horizon = HorizonSettings::forConnection($config, $connection, $app->environment());
        $queues = $this->queueNames($horizon->queues ?: $config->get('thrun.native.queues', ['default']));

        $inFlight = new InFlightJobs();

        $gate = new ProducerGate(
            // `queue:restart` writes a timestamp here, and every worker started
            // before it is expected to exit so the supervisor replaces it.
            restartSignal: fn() => $cache->get('illuminate:queue:restart'),
            isDownForMaintenance: fn() => $app->isDownForMaintenance(),
            maxJobs: (int) $this->settingOr('max-jobs', $horizon->maxJobs),
            maxTime: (int) $this->settingOr('max-time', $horizon->maxTime),
            stopWhenEmpty: (bool) $this->option('stop-when-empty'),
            force: (bool) $this->option('force'),
        );

        $transport = new LaravelQueueTransport(
            queues: $app->make('queue'),
            connectionName: $connection,
            queueNames: $queues,
            sleepMs: (int) ($this->optionOr('sleep', $config->get('thrun.native.sleep', 1.0)) * 1000),
            gate: $gate,
            exceptions: $app->make(ExceptionHandler::class),
            inFlight: $inFlight,
            workerSettings: [
                'tries' => (int) $this->settingOr('tries', $horizon->tries),
                'timeout' => (int) $this->settingOr('timeout', $horizon->timeout),
                'backoff' => (int) $this->option('backoff'),
            ],
        );

        $threads = (int) $this->optionOr('threads', $config->get('thrun.native.threads', 4));
        $concurrency = (int) $this->optionOr('concurrency', $config->get('thrun.native.concurrency', 10));

        $this->info(sprintf(
            'Working %s on connection [%s] with %d threads x %d jobs.',
            implode(', ', $queues),
            $connection,
            $threads,
            $concurrency,
        ));

        $worker = $this->buildWorker($app, $transport, $inFlight, $threads, $concurrency);

        $this->stopOnSignals($transport);
        $watcher = $this->stopWhenDrained($worker, $transport, $inFlight, (int) $this->option('shutdown-grace'));

        try {
            $worker->run();
        } finally {
            $watcher->cancel();
        }

        $this->info(sprintf('Stopped after %d jobs.', $gate->reservedCount()));

        return self::SUCCESS;
    }

    /** The thrun worker that runs Laravel jobs. */
    private function buildWorker(
        Application $app,
        LaravelQueueTransport $transport,
        InFlightJobs $inFlight,
        int $threads,
        int $concurrency,
    ): Worker {
        return new Worker(
            transport: $transport,
            handlers: [LaravelQueueTransport::ROUTE => NativeJobHandler::class],
            options: new WorkerOptions(
                threads: $threads,
                concurrency: $concurrency,
                // A deeper buffer would hold jobs reserved but not started, and a
                // reservation expires on Laravel's clock whether or not the job
                // has begun.
                queueSize: $threads,
                bootloader: ThreadBootstrapper::bootloader(
                    $app->basePath('vendor/autoload.php'),
                    $app->bootstrapPath('app.php'),
                ),
                onResult: $inFlight->completed(...),
            ),
        );
    }

    /**
     * End the run once reserving has finished and the jobs it reserved have come
     * back, waiting no longer than the grace period for the stragglers.
     *
     * thrun's result reader waits forever, so somebody has to call stop(), and
     * only once the in-flight count is zero: a running job must finish and let
     * Laravel release its reservation.
     *
     * The grace period bounds that wait. A thread can die without reporting a
     * result — a job calling exit(), an OOM — and the count would never reach
     * zero.
     *
     * @return Scope the watcher's scope, to be cancelled once the run is over
     */
    private function stopWhenDrained(
        Worker $worker,
        LaravelQueueTransport $transport,
        InFlightJobs $inFlight,
        int $graceSeconds,
    ): Scope {
        $scope = new Scope();

        $scope->spawn(function () use ($worker, $transport, $inFlight, $graceSeconds): void {
            while (!$transport->isDrained()) {
                delay(self::DRAIN_POLL_MS);
            }

            $deadline = microtime(true) + $graceSeconds;

            while (!$inFlight->isEmpty() && microtime(true) < $deadline) {
                delay(self::DRAIN_POLL_MS);
            }

            if (!$inFlight->isEmpty()) {
                $this->warn(sprintf('Gave up waiting for %d job(s) after %ds.', $inFlight->count(), $graceSeconds));
            }

            $worker->stop();
        });

        return $scope;
    }

    /**
     * SIGTERM, SIGINT and SIGQUIT stop reserving new jobs; the drain watcher then
     * ends the run once the ones in flight are done. This is the contract a
     * supervisor and a deploy script expect from a queue worker.
     *
     * pcntl rather than `Async\signal()` because the handler must also work in
     * the window before the event loop is running.
     */
    private function stopOnSignals(LaravelQueueTransport $transport): void
    {
        if (!function_exists('pcntl_async_signals')) {
            return;
        }

        pcntl_async_signals(true);

        foreach ([SIGTERM, SIGINT, SIGQUIT] as $signal) {
            pcntl_signal($signal, static function () use ($transport): void {
                $transport->close();
            });
        }
    }

    /**
     * @param list<string>|string $configured
     *
     * @return list<string>
     */
    private function queueNames(array|string $configured): array
    {
        $option = $this->option('queue');

        if ($option !== null) {
            $names = array_map(trim(...), explode(',', $option));

            return array_values(array_filter($names, static fn(string $name): bool => $name !== ''));
        }

        return array_values((array) $configured);
    }

    private function optionOr(string $name, mixed $fallback): mixed
    {
        return $this->option($name) ?? $fallback;
    }

    /**
     * An option the operator typed wins; otherwise the value Horizon was
     * configured with; otherwise the option's own default.
     */
    private function settingOr(string $name, ?int $configured): mixed
    {
        if ($this->input->hasParameterOption("--{$name}")) {
            return $this->option($name);
        }

        return $configured ?? $this->option($name);
    }

    /**
     * Native mode's requirements, checked here because every one of them fails
     * confusingly — or silently — once the worker is running.
     *
     * @throws RuntimeException when the runtime, the application class or the
     *                          queue driver is not what native mode needs
     */
    private function assertRuntime(Application $app, string $connection): void
    {
        if (!extension_loaded('true_async')) {
            throw new RuntimeException('thrun:work-native needs the TrueAsync build of PHP.');
        }

        if (!$app instanceof AsyncApplication) {
            // Without async mode the per-coroutine isolation is off while the
            // coroutines still run: they would share the container, the log
            // context and the transaction counter.
            throw new RuntimeException(
                'thrun:work-native needs bootstrap/app.php to build a '
                . AsyncApplication::class . '. See the laravel-spawn README.'
            );
        }

        $queue = $app->make('queue')->connection($connection);

        if (!$queue instanceof RedisQueue) {
            // Other drivers reserve differently, and pop() has already consumed
            // an attempt by the time we would find out we cannot use the job.
            throw new RuntimeException(sprintf(
                'thrun:work-native supports redis queues only; connection [%s] is %s.',
                $connection,
                $queue::class,
            ));
        }

    }
}
