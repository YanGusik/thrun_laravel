<?php

declare(strict_types=1);

namespace Thrun\Laravel\Tests\Fixture;

use Illuminate\Contracts\Redis\Factory;
use LogicException;

/**
 * Redis for tests that build queues and payloads but never send a command.
 *
 * Payload assembly happens entirely before the first Redis call, so a factory
 * that refuses to connect proves the test stayed on that side of the line.
 */
final class UnusedRedisFactory implements Factory
{
    public function connection($name = null)
    {
        throw new LogicException('Redis must not be touched while building a payload.');
    }
}
