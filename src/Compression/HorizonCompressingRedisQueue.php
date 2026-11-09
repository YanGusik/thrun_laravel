<?php

declare(strict_types=1);

namespace Thrun\Laravel\Compression;

use Laravel\Horizon\RedisQueue;

/**
 * Horizon's Redis queue with compressed job bodies.
 *
 * Horizon replaces Laravel's queue with its own to emit the dashboard events, so
 * compression has to extend that one instead — otherwise enabling compression
 * would silently switch the dashboard off.
 *
 * Loaded only when Horizon is installed; the connector checks before naming it.
 */
final class HorizonCompressingRedisQueue extends RedisQueue
{
    use CompressesCommands;
}
