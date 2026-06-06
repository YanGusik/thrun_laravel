<?php

declare(strict_types=1);

namespace Thrun\Laravel\Console;

use Illuminate\Console\Command;
use Thrun\Laravel\Transport\TransportFactory;

final class ThrunFailedShowCommand extends Command
{
    protected $signature = 'thrun:failed:show
                            {uuid : Job ID to inspect}';

    protected $description = 'Show details of a failed Thrun job';

    public function handle(TransportFactory $factory): int
    {
        $store = $factory->createFailedJobSender();
        $uuid = $this->argument('uuid');

        $record = $store->find($uuid);
        if ($record === null) {
            $this->error("Failed job [{$uuid}] not found.");
            return self::FAILURE;
        }

        $this->info("Failed Job Details [{$uuid}]");
        $this->newLine();

        $this->line("<comment>Type:</comment>         {$record['type']}");
        $this->line("<comment>Queue:</comment>        {$record['queue']}");
        $this->line("<comment>Message ID:</comment>   " . ($record['message_id'] ?? '-'));
        $this->line("<comment>Failed At:</comment>    " . date('Y-m-d H:i:s', $record['failed_at']));

        $attempts = count($record['stamps']['Thrun\\Envelope\\Stamp\\RedeliveryStamp'] ?? []);
        $this->line("<comment>Attempts:</comment>     {$attempts}");
        $this->newLine();

        $this->line("<comment>Exception:</comment>    " . ($record['exception'] ?? 'Unknown'));
        $this->line("<comment>Message:</comment>      " . ($record['exception_message'] ?? '-'));
        if (!empty($record['file'])) {
            $this->line("<comment>File:</comment>         " . $record['file']);
            $this->line("<comment>Line:</comment>          " . ($record['line'] ?? '-'));
        }
        $this->newLine();

        $this->line("<comment>Trace:</comment>");
        $trace = $record['trace'] ?? 'No trace available';
        foreach (explode("\n", $trace) as $line) {
            $this->line("  {$line}");
        }
        $this->newLine();

        $this->line("<comment>Payload:</comment>");
        $this->line(json_encode($record['payload'] ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        $this->newLine();

        $this->line("<comment>Stamps:</comment>");
        foreach ($record['stamps'] ?? [] as $stampClass => $stampData) {
            $short = class_basename($stampClass);
            $this->line("  <comment>{$short}:</comment> " . json_encode($stampData, JSON_UNESCAPED_UNICODE));
        }

        return self::SUCCESS;
    }
}
