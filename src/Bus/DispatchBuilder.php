<?php

declare(strict_types=1);

namespace Thrun\Laravel\Bus;

final class DispatchBuilder
{
    private ?string $messageId = null;
    private ?int $delayMs = null;
    private ?array $retryBackoff = null;
    private ?int $maxAttempts = null;
    private ?int $timeoutMs = null;

    public function __construct(private readonly ThrunMessageBus $bus)
    {
    }

    public function id(string $id): static
    {
        $this->messageId = $id;
        return $this;
    }

    public function delay(int $ms): static
    {
        $this->delayMs = $ms;
        return $this;
    }

    public function retry(array $backoff, ?int $maxAttempts = null): static
    {
        $this->retryBackoff = $backoff;
        $this->maxAttempts = $maxAttempts;
        return $this;
    }

    public function timeout(int $ms): static
    {
        $this->timeoutMs = $ms;
        return $this;
    }

    public function send(object $message, ?string $queue = null): void
    {
        $this->bus->dispatch($message, $queue, new DispatchOptions(
            messageId: $this->messageId,
            delayMs: $this->delayMs,
            retryBackoff: $this->retryBackoff,
            maxAttempts: $this->maxAttempts,
            timeoutMs: $this->timeoutMs,
        ));
    }
}
