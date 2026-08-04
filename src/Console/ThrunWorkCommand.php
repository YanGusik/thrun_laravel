<?php

declare(strict_types=1);

namespace Thrun\Laravel\Console;

use Async\Scope;
use Async\TaskSet;
use Illuminate\Console\Command;
use Thrun\Envelope\Envelope;
use Thrun\Laravel\Rpc\RpcServerFactory;
use Thrun\Laravel\Worker\ThrunWorkerFactory;
use Thrun\Worker\Metrics\InMemoryMetrics;
use Thrun\Worker\Outcome;
use Illuminate\Contracts\Config\Repository as ConfigContract;

final class ThrunWorkCommand extends Command
{
    /** One `--stats` line per interval, built from readings taken this often. */
    private const int STATS_INTERVAL_MS = 1000;
    private const int STATS_SAMPLE_MS = 50;

    protected $signature = 'thrun:work
                            {--supervisor= : Run a specific supervisor only}
                            {--stats : Show real-time stats UI}
                            {--no-rpc : Disable RPC server for this process}';

    protected $description = 'Start Thrun async queue workers (all supervisors or one)';

    public function handle(ConfigContract $config, ThrunWorkerFactory $factory, RpcServerFactory $rpcFactory): int
    {
        $supervisors = $this->resolveSupervisors($config);

        if ($supervisors === []) {
            $this->error('No supervisors configured in config/thrun.php');

            return self::FAILURE;
        }

        $rpcEnabled = $config->get('thrun.rpc.enabled', true) && !$this->option('no-rpc');

        $this->info(sprintf(
            'Thrun starting %d supervisor(s)%s...',
            count($supervisors),
            $rpcEnabled ? ' + RPC server' : '',
        ));

        $slots = count($supervisors)
            + ($rpcEnabled ? 1 : 0)
            + ($this->option('stats') ? 1 : 0);

        $scope   = new Scope();
        $taskSet = new TaskSet(concurrency: $slots, scope: $scope);
        $metrics = new InMemoryMetrics();

        // rpc
        if ($rpcEnabled) {
            $taskSet->spawn(function () use ($rpcFactory): void {
                $rpcFactory->create()->run();
            });
        }

        // supervisors
        foreach ($supervisors as $name) {
            $this->line("[{$name}] Starting...");
            $taskSet->spawn(function () use ($factory, $name, $metrics): void {
                $factory->createSupervisor($name, $metrics, onResult: $this->reportFailedJob(...))->run();
            });
        }

        // Stats
        if ($this->option('stats')) {
            $taskSet->spawn(function () use ($metrics, $supervisors): void {
                $label = implode(', ', $supervisors);
                while (true) {
                    $startTime = hrtime(true);
                    $processed = $metrics->processed;

                    // Sampled instead of read once at the end of the second: a
                    // job usually lives for milliseconds, so a single reading
                    // almost always lands between two of them and reports an
                    // idle worker that is not idle.
                    $activePeak = $metrics->active;

                    $samples = intdiv(self::STATS_INTERVAL_MS, self::STATS_SAMPLE_MS);

                    for ($sample = 0; $sample < $samples; $sample++) {
                        \Async\delay(self::STATS_SAMPLE_MS);
                        $activePeak = max($activePeak, $metrics->active);
                    }

                    $line = sprintf(
                        "  INFO  [%s] Processed: %d | Failed: %d | Retried: %d | Timeout: %d | Active peak: %d | %.1f jobs/sec",
                        $label,
                        $metrics->processed,
                        $metrics->failed,
                        $metrics->retried,
                        $metrics->timedOut,
                        $activePeak,
                        $metrics->throughput($startTime, $processed),
                    );
                    $this->info($line);
                }
            });
        }

        $failed = false;

        while ($taskSet->count() !== 0) {
            try {
                $taskSet->joinNext()->await();
            } catch (\Cancellation $e) {
            } catch (\Exception $e) {
                $failed = true;
                $this->error(sprintf(
                    'Task failed: %s in %s:%d',
                    $e->getMessage(),
                    $e->getFile(),
                    $e->getLine(),
                ));
            } finally {
                $taskSet->cancel();
            }
        }

        $this->info('Thrun stopped.');
        $this->info(sprintf(
            'Final stats: processed=%d failed=%d avg_time=%.3fs',
            $metrics->processed,
            $metrics->failed,
            $metrics->averageTime(),
        ));

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Prints a job that did not succeed.
     *
     * Without this the console stays silent about failures: a Laravel job
     * settles inside its own thread, so nothing reaches thrun's error path, and
     * `--stats` shows totals that a reader has to be watching to notice.
     *
     * @param array{ok: bool, outcome?: string, envelope: Envelope, error?: array{class: string, message: string}|null} $result
     */
    private function reportFailedJob(array $result): void
    {
        $outcome = $result['outcome'] ?? ($result['ok'] ? Outcome::Success->value : Outcome::Failure->value);

        if ($outcome !== Outcome::Failure->value) {
            return;
        }

        $error = $result['error'] ?? null;

        $this->error(sprintf(
            'Job failed: %s%s',
            $this->describeJob($result['envelope']),
            $error === null ? '' : sprintf(' — %s: %s', $error['class'], $error['message']),
        ));
    }

    /**
     * The job's display name when the envelope carries a Laravel payload, its
     * message type otherwise — the adapter is not the only producer of work.
     */
    private function describeJob(Envelope $envelope): string
    {
        $message = $envelope->message;

        if (is_array($message) && isset($message['job']['body'])) {
            $body = json_decode($message['job']['body'], true);

            if (is_array($body) && isset($body['displayName'])) {
                return (string) $body['displayName'];
            }
        }

        return $envelope->routeKey ?? $envelope->type ?? 'unknown';
    }

    /**
     * @return list<string>
     */
    private function resolveSupervisors(ConfigContract $config): array
    {
        $supervisors = $config->get('thrun.supervisors', []);
        $all         = array_keys($supervisors);

        $filter = $this->option('supervisor');

        if ($filter === null) {
            return $all;
        }

        if (!isset($supervisors[$filter])) {
            throw new \InvalidArgumentException(
                "Supervisor \"{$filter}\" not found in config/thrun.php"
            );
        }

        return [$filter];
    }
}