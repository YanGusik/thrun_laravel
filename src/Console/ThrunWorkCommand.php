<?php

declare(strict_types=1);

namespace Thrun\Laravel\Console;

use Async\Scope;
use Async\TaskSet;
use Illuminate\Console\Command;
use Thrun\Laravel\Rpc\RpcServerFactory;
use Thrun\Laravel\Worker\ThrunWorkerFactory;
use Thrun\Worker\Metrics\InMemoryMetrics;
use Illuminate\Contracts\Config\Repository as ConfigContract;

final class ThrunWorkCommand extends Command
{
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
                $factory->createSupervisor($name, $metrics)->run();
            });
        }

        // Stats
        if ($this->option('stats')) {
            $taskSet->spawn(function () use ($metrics, $supervisors): void {
                $label = implode(', ', $supervisors);
                while (true) {
                    $startTime = hrtime(true);
                    $processed = $metrics->processed;
                    \Async\delay(1000);
                    $line = sprintf(
                        "  INFO  [%s] Processed: %d | Failed: %d | Timeout: %d | Active: %d | %.1f jobs/sec",
                        $label,
                        $metrics->processed,
                        $metrics->failed,
                        $metrics->timedOut,
                        $metrics->active,
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