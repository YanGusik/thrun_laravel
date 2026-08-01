<?php

declare(strict_types=1);

namespace Thrun\Laravel\Tests\Fixture;

use Illuminate\Container\Container;
use Illuminate\Contracts\Queue\Job as JobContract;
use Illuminate\Queue\Jobs\Job;
use Throwable;

use function Async\delay;

/**
 * A job whose every step is slow and recorded, so a test can see the order in
 * which the job and the timeout touched the reservation.
 *
 * The delays are the point: settling takes long enough to still be in flight
 * when the timeout fires, which is where the two owners used to collide.
 */
final class SettlingJob extends Job implements JobContract
{
    /** @var list<string> what happened, in order */
    public array $log = [];

    public int $fireDelayMs = 900;

    public int $settleDelayMs = 300;

    public int $tries = 1;

    public ?Throwable $fireThrows = null;

    public function __construct(Container $container)
    {
        $this->container = $container;
        $this->connectionName = 'redis';
        $this->queue = 'default';
    }

    public function getJobId(): string
    {
        return 'settling-1';
    }

    public function getRawBody(): string
    {
        return json_encode(['uuid' => 'u-1', 'job' => 'noop', 'data' => [], 'attempts' => 0]);
    }

    public function attempts(): int
    {
        return 1;
    }

    public function maxTries(): int
    {
        return $this->tries;
    }

    /** Seconds, as Laravel reads it. One keeps the tests short. */
    public function timeout(): int
    {
        return 1;
    }

    public function fire(): void
    {
        $this->log[] = 'fire:start';
        delay($this->fireDelayMs);

        if ($this->fireThrows !== null) {
            throw $this->fireThrows;
        }

        $this->log[] = 'fire:done';
        $this->delete();
    }

    public function delete(): void
    {
        parent::delete();
        $this->log[] = 'delete:start';
        delay($this->settleDelayMs);
        $this->log[] = 'delete:done';
    }

    public function release($delay = 0): void
    {
        parent::release($delay);
        $this->log[] = 'release:start';
        delay($this->settleDelayMs);
        $this->log[] = 'release:done';
    }

    protected function failed($e): void
    {
        $this->log[] = 'failed-callback:' . get_class($e);
    }

    protected function resolve($class): mixed
    {
        return $this->container->make($class);
    }
}
