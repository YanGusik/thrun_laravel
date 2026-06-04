<?php

declare(strict_types=1);

namespace Thrun\Laravel\Bus;

use Thrun\Envelope\Envelope;
use Thrun\Envelope\Stamp\DelayStamp;
use Thrun\Laravel\Transport\TransportFactory;

final class ThrunMessageBus
{
    public function __construct(private readonly TransportFactory $transportFactory)
    {
    }

    public function dispatch(object $message, string $queue = 'default'): void
    {
        $transport = $this->transportFactory->createSender($queue);
        $transport->send(Envelope::wrap($message));
    }

    public function dispatchCustom(Envelope $envelope, string $queue = 'default'): void
    {
        $transport = $this->transportFactory->createSender($queue);
        $transport->send($envelope);
    }

    public function dispatchLater(object $message, int $delayMs, string $queue = 'default'): void
    {
        $transport = $this->transportFactory->createSender($queue);
        $transport->send(Envelope::wrap($message, new DelayStamp($delayMs)));
    }
}
