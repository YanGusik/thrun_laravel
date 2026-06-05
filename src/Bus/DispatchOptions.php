<?php

declare(strict_types=1);

namespace Thrun\Laravel\Bus;

final readonly class DispatchOptions
{
    public function __construct(
        public ?string $messageId = null,
        public ?int $delayMs = null,
        public ?array $retryBackoff = null,
        public ?int $maxAttempts = null,
        public ?int $timeoutMs = null,
    ) {
    }
}
