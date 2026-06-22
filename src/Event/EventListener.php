<?php

declare(strict_types=1);

namespace Thrun\Laravel\Event;

use JsonException;
use Thrun\Rpc\Frame;
use Thrun\Rpc\FrameStream;
use Thrun\Rpc\FrameType;

final class EventListener
{
    private bool $running = false;

    public function __construct(private readonly EventListenerRegistry $registry)
    {
    }

    /**
     * @param  list<string>  $subscriptions
     * @throws JsonException
     */
    public function listen(mixed $connection, array $subscriptions): void
    {
        $this->running = true;

        foreach ($subscriptions as $event) {
            FrameStream::write($connection, Frame::subscribe($event));
        }

        while ($this->running) {
            $frame = FrameStream::read($connection);

            if ($frame === null) {
                break;
            }

            if ($frame->type !== FrameType::Event) {
                continue;
            }

            $eventName = $frame->payload['event'] ?? null;
            $payload   = $frame->payload['data'] ?? [];

            if ($eventName === null) {
                continue;
            }

            try {
                $this->registry->dispatch($eventName, $payload);
            } catch (\Throwable $e) {
                error_log(sprintf(
                    '[ThrunEventListener] %s while handling "%s": %s',
                    get_class($e),
                    $eventName,
                    $e->getMessage(),
                ));
            }
        }
    }

    public function stop(): void
    {
        $this->running = false;
    }
}