<?php

declare(strict_types=1);

namespace Thrun\Laravel\Event\Attribute;

#[\Attribute(\Attribute::TARGET_CLASS)]
final readonly class ThrunEventListener
{
    /**
     * @param  string|null  $event Default self::class
     */
    public function __construct(public ?string $event = null)
    {
    }
}