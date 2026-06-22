<?php

declare(strict_types=1);

namespace Thrun\Laravel\Console;

use Illuminate\Console\Command;
use Thrun\Laravel\Event\EventListener;
use Thrun\Laravel\Event\EventListenerRegistry;
use Thrun\Laravel\Rpc\RpcAddress;
use Illuminate\Contracts\Config\Repository as ConfigContract;

final class ThrunEventCommand extends Command
{
    protected $signature = 'thrun:event
                            {--subscribe=* : Event names/patterns to subscribe to (default: *)}';

    protected $description = 'Connect to Thrun RPC broker and dispatch incoming events to registered listeners';

    public function handle(
        RpcAddress $rpcAddress,
        EventListenerRegistry $registry,
        EventListener $listener,
        ConfigContract $config
    ): int {
        $registry->discover($config->get('thrun.auto_discover', []));

        $subscriptions = $this->option('subscribe') ?: ['*'];

        $this->info(sprintf(
            'Connecting to %s, subscribing to: %s',
            $rpcAddress->dsn,
            implode(', ', $subscriptions),
        ));

        $connection = stream_socket_client($rpcAddress->dsn, $errno, $errstr);

        if ($connection === false) {
            $this->error("Cannot connect to RPC server: $errstr $errno");
            return self::FAILURE;
        }

        $this->info('Event listener started. Waiting for events...');

        $listener->listen($connection, $subscriptions);

        fclose($connection);

        $this->info('Event listener closed.');

        return self::SUCCESS;
    }
}