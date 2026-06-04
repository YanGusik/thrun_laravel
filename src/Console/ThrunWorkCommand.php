<?php

declare(strict_types=1);

namespace Thrun\Laravel\Console;

use Illuminate\Console\Command;
use Async\Scope;
use Async\TaskSet;
use Thrun\Laravel\Worker\ThrunWorkerFactory;
use Thrun\Worker\Metrics\InMemoryMetrics;

final class ThrunWorkCommand extends Command
{
    protected $signature = 'thrun:work
                            {--supervisor= : Run a specific supervisor only}
                            {--stats : Show real-time stats UI}';

    protected $description = 'Start Thrun async queue workers (all supervisors or one)';

    public function handle(ThrunWorkerFactory $factory): int
    {
        $supervisors = $this->resolveSupervisors();

        if ($supervisors === []) {
            $this->error('No supervisors configured in config/thrun.php');
            return self::FAILURE;
        }

        $this->info('Thrun starting ' . count($supervisors) . ' supervisor(s)...');

        if (count($supervisors) === 1) {
            return $this->runSingle($factory, $supervisors[0]);
        }

        return $this->runConcurrently($factory, $supervisors);
    }

    private function runSingle(ThrunWorkerFactory $factory, string $name): int
    {
        $stats = $this->option('stats');

        if (!$stats) {
            $this->info("[{$name}] Starting...");
        }

        $metrics = new InMemoryMetrics();
        $scope = new Scope();
        $taskSet = new TaskSet(concurrency: 3, scope: $scope);

        // Supervisor task
        $taskSet->spawn(function () use ($factory, $name, $metrics): void {
            $factory->createSupervisor($name, $metrics, [])->run();
        });

        // Stats UI task
        if ($stats)
        {
            $taskSet->spawn(function () use ($name, $metrics): void {
                while (true) {
                    $startTime = hrtime(true);
                    $processed = $metrics->processed;
                    \Async\delay(1000);
                    $line = sprintf(
                        "  INFO  [%s] Processed: %d | Failed: %d | Timeout: %d | Active: %d | %.1f jobs/sec",
                        $name,
                        $metrics->processed,
                        $metrics->failed,
                        $metrics->timedOut,
                        $metrics->active,
                        $metrics->throughput($startTime, $processed),
                    );

                    fwrite(STDERR, $line . "\n");
                }
            });
        }

        $failed = false;

        foreach ($taskSet as [$result, $error]) {
            if ($error !== null) {
                $failed = true;
                $this->error('Task failed: ' . $error->getMessage());
            }
            $taskSet->cancel();
        }

        if (!$stats) {
            $this->info('Thrun stopped.');
            $this->info(sprintf(
                "Final stats: processed=%d failed=%d avg_time=%.3fs",
                $metrics->processed,
                $metrics->failed,
                $metrics->averageTime(),
            ));
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    /**
     * @param list<string> $names
     */
    private function runConcurrently(ThrunWorkerFactory $factory, array $names): int
    {
        $scope = new Scope();
        $taskSet = new TaskSet(concurrency: count($names), scope: $scope);

        foreach ($names as $name) {
            $this->line("  [{$name}] spawning...");
            $taskSet->spawn(function () use ($factory, $name): void {
                $factory->createSupervisor($name)->run();
            });
        }

        $failed = false;

        foreach ($taskSet as [$result, $error]) {
            if ($error !== null) {
                $failed = true;
                $this->error('Supervisor failed: ' . $error->getMessage());
            }
        }

        $this->info('All supervisors stopped.');

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    /**
     * @return list<string>
     */
    private function resolveSupervisors(): array
    {
        $config = config('thrun.supervisors', []);
        $all = array_keys($config);

        $filter = $this->option('supervisor');
        if ($filter !== null) {
            if (!isset($config[$filter])) {
                throw new \InvalidArgumentException("Supervisor \"{$filter}\" not found in config/thrun.php");
            }
            return [$filter];
        }

        return $all;
    }
}
