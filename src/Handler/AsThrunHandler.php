<?php

declare(strict_types=1);

namespace Thrun\Laravel\Handler;

#[\Attribute(\Attribute::TARGET_CLASS)]
final readonly class AsThrunHandler
{
    /**
     * @param class-string<object>|null $messageClass
     */
    public function __construct(
        public ?string $messageClass = null,
    ) {
    }
}
