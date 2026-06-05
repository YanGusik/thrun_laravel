<?php

declare(strict_types=1);

namespace Thrun\Laravel\Handler\Attribute;

#[\Attribute(\Attribute::TARGET_CLASS)]
final readonly class Retry
{
    public function __construct(
        public array $backoff = [],
        public ?int $maxAttempts = null,
    ) {
    }
}
