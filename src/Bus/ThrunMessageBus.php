<?php

declare(strict_types=1);

namespace Thrun\Laravel\Bus;

use Thrun\Envelope\Envelope;
use Thrun\Envelope\Stamp\DelayStamp;
use Thrun\Envelope\Stamp\MessageIdStamp;
use Thrun\Envelope\Stamp\RetryStamp;
use Thrun\Envelope\Stamp\TimeoutStamp;
use Thrun\Laravel\Contract\IdentifiableMessage;
use Thrun\Laravel\Handler\Attribute\Delay;
use Thrun\Laravel\Handler\Attribute\Queue;
use Thrun\Laravel\Handler\Attribute\Retry;
use Thrun\Laravel\Handler\Attribute\Timeout;
use Thrun\Laravel\Transport\TransportFactory;

final class ThrunMessageBus
{
    public function __construct(private readonly TransportFactory $transportFactory)
    {
    }

    public function dispatch(object $message, ?string $queue = null, ?DispatchOptions $options = null): void
    {
        $reflection = new \ReflectionClass($message);

        $queue = $queue ?? $this->resolveAttributeProperty($reflection, Queue::class, 'name') ?? 'default';

        $stamps = [];

        // MessageId: DispatchOptions > IdentifiableMessage > null
        $messageId = $options?->messageId;
        if ($messageId === null && $message instanceof IdentifiableMessage) {
            $messageId = $message->getId();
        }
        if ($messageId !== null) {
            $stamps[] = new MessageIdStamp($messageId);
        }

        // Retry
        $retryBackoff = $options?->retryBackoff ?? $this->resolveAttributeProperty($reflection, Retry::class, 'backoff');
        $maxAttempts = $options?->maxAttempts ?? $this->resolveAttributeProperty($reflection, Retry::class, 'maxAttempts');
        if ($retryBackoff !== null || $maxAttempts !== null) {
            $stamps[] = new RetryStamp(
                backoff: $retryBackoff ?? [],
                maxAttempts: $maxAttempts,
            );
        }

        // Delay
        $delayMs = $options?->delayMs ?? $this->resolveAttributeProperty($reflection, Delay::class, 'delayMs');
        if ($delayMs !== null) {
            $stamps[] = new DelayStamp($delayMs);
        }

        // Timeout
        $timeoutMs = $options?->timeoutMs ?? $this->resolveAttributeProperty($reflection, Timeout::class, 'timeoutMs');
        if ($timeoutMs !== null) {
            $stamps[] = new TimeoutStamp($timeoutMs);
        }

        $transport = $this->transportFactory->createSender($queue);
        $transport->send(Envelope::wrap($message, ...$stamps));
    }

    public function builder(): DispatchBuilder
    {
        return new DispatchBuilder($this);
    }

    public function dispatchCustom(Envelope $envelope, string $queue = 'default'): void
    {
        $transport = $this->transportFactory->createSender($queue);
        $transport->send($envelope);
    }

    /**
     * @param \ReflectionClass<object> $reflection
     */
    private function resolveAttributeProperty(\ReflectionClass $reflection, string $attributeClass, string $property): mixed
    {
        foreach ($reflection->getAttributes($attributeClass) as $attribute) {
            $instance = $attribute->newInstance();
            if (property_exists($instance, $property)) {
                return $instance->$property;
            }
        }

        return null;
    }
}
