<?php

declare(strict_types=1);

namespace Thrun\Laravel\Console;

use Illuminate\Console\Command;
use Thrun\Contract\FailedJobStoreInterface;
use Thrun\Laravel\Bus\ThrunMessageBus;
use Thrun\Laravel\Transport\TransportFactory;

final class ThrunRetryCommand extends Command
{
    protected $signature = 'thrun:retry
                            {uuid? : Job ID to retry}
                            {--all : Retry all failed jobs}';

    protected $description = 'Retry failed Thrun jobs';

    public function handle(TransportFactory $factory, ThrunMessageBus $bus): int
    {
        $store = $factory->createFailedJobSender();

        if ($this->option('all')) {
            return $this->retryAll($store, $bus);
        }

        $uuid = $this->argument('uuid');
        if (!$uuid) {
            $this->error('Please provide a job UUID or use --all');
            return self::FAILURE;
        }

        return $this->retryOne($store, $bus, $uuid);
    }

    private function retryOne(FailedJobStoreInterface $store, ThrunMessageBus $bus, string $uuid): int
    {
        $record = $store->find($uuid);
        if ($record === null) {
            $this->error("Failed job [{$uuid}] not found.");
            return self::FAILURE;
        }

        $this->dispatchFromRecord($bus, $record);
        $store->forget($uuid);

        $this->info("Retried [{$uuid}].");
        return self::SUCCESS;
    }

    private function retryAll(FailedJobStoreInterface $store, ThrunMessageBus $bus): int
    {
        $records = $store->all();
        if ($records === []) {
            $this->info('No failed jobs to retry.');
            return self::SUCCESS;
        }

        foreach ($records as $record) {
            $this->dispatchFromRecord($bus, $record);
            $store->forget($record['job_id']);
        }

        $this->info('Retried ' . count($records) . ' jobs.');
        return self::SUCCESS;
    }

    private function dispatchFromRecord(ThrunMessageBus $bus, array $record): void
    {
        $type = $record['type'];
        $payload = $record['payload'] ?? [];
        $queue = $record['queue'] ?? 'default';

        if (!class_exists($type)) {
            throw new \RuntimeException("Message class [{$type}] not found.");
        }

        $message = new $type(...$payload);
        $bus->dispatch($message, $queue);
    }
}
