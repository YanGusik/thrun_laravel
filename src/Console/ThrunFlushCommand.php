<?php

declare(strict_types=1);

namespace Thrun\Laravel\Console;

use Illuminate\Console\Command;
use Thrun\Laravel\Transport\TransportFactory;

final class ThrunFlushCommand extends Command
{
    protected $signature = 'thrun:flush
                            {queue? : Queue name to flush (default: all queues)}
                            {--failed : Also flush failed jobs}';

    protected $description = 'Flush Thrun queues (ready, processing, delayed)';

    public function handle(TransportFactory $factory): int
    {
        $queue = $this->argument('queue');

        if ($queue === null) {
            $this->flushAllQueues($factory);
        } else {
            $this->flushQueue($factory, $queue);
        }

        if ($this->option('failed')) {
            $store = $factory->createFailedJobSender();
            $store->flush();
            $this->info('Failed jobs flushed.');
        }

        return self::SUCCESS;
    }

    private function flushAllQueues(TransportFactory $factory): void
    {
        $config = config('thrun.queues', []);
        if ($config === []) {
            $this->warn('No queues configured in thrun.queues');
            return;
        }

        foreach (array_keys($config) as $queue) {
            $this->flushQueue($factory, $queue);
        }
    }

    private function flushQueue(TransportFactory $factory, string $queue): void
    {
        $factory->flushQueue($queue);
        $this->info("Queue [{$queue}] flushed.");
    }
}
