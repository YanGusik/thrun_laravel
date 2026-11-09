<?php

declare(strict_types=1);

namespace Thrun\Laravel\Compression;

use Illuminate\Queue\RedisQueue;

/**
 * Laravel's Redis queue with compressed job bodies. Used when Horizon is absent;
 * with Horizon installed the connector picks {@see HorizonCompressingRedisQueue}
 * so its dashboard events keep firing.
 */
final class CompressingRedisQueue extends RedisQueue
{
    use CompressesCommands;
}
