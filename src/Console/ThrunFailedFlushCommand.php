<?php

declare(strict_types=1);

namespace Thrun\Laravel\Console;

use Illuminate\Console\Command;
use Thrun\Laravel\Transport\TransportFactory;

final class ThrunFailedFlushCommand extends Command
{
    protected $signature = 'thrun:failed:flush';

    protected $description = 'Remove all failed Thrun jobs';

    public function handle(TransportFactory $factory): int
    {
        if (!$this->confirm('This will delete ALL failed jobs. Are you sure?')) {
            $this->info('Cancelled.');
            return self::SUCCESS;
        }

        $store = $factory->createFailedJobSender();
        $store->flush();

        $this->info('All failed jobs have been removed.');
        return self::SUCCESS;
    }
}
