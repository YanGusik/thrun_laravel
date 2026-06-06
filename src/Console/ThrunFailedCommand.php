<?php

declare(strict_types=1);

namespace Thrun\Laravel\Console;

use Illuminate\Console\Command;
use Thrun\Laravel\Transport\TransportFactory;

final class ThrunFailedCommand extends Command
{
    protected $signature = 'thrun:failed
                            {--queue= : Filter by queue name}
                            {--limit=50 : Number of records to show}';

    protected $description = 'List failed Thrun jobs';

    public function handle(TransportFactory $factory): int
    {
        $store = $factory->createFailedJobSender();
        $limit = (int) $this->option('limit');

        $records = $this->option('queue')
            ? $store->allByQueue($this->option('queue'), $limit)
            : $store->all($limit);

        if ($records === []) {
            $this->info('No failed jobs found.');
            return self::SUCCESS;
        }

        $rows = [];
        foreach ($records as $record) {
            $rows[] = [
                $record['job_id'] ?? 'N/A',
                $record['message_id'] ?? '-',
                $record['type'] ?? 'N/A',
                $record['queue'] ?? 'unknown',
                $record['exception'] ?? 'Unknown',
                isset($record['failed_at']) ? date('Y-m-d H:i:s', $record['failed_at']) : '-',
                count($record['stamps']['Thrun\\Envelope\\Stamp\\RedeliveryStamp'] ?? []),
            ];
        }

        $this->table(
            ['Job ID', 'Message ID', 'Type', 'Queue', 'Exception', 'Failed At', 'Attempts'],
            $rows
        );

        return self::SUCCESS;
    }
}
