<?php

declare(strict_types=1);

namespace Thrun\Laravel\Handler\Attribute;

#[\Attribute(\Attribute::TARGET_CLASS)]
final readonly class Timeout
{
    public function __construct(public int $timeoutMs) {}
}
