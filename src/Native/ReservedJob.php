<?php

declare(strict_types=1);

namespace Thrun\Laravel\Native;

use Illuminate\Queue\Jobs\RedisJob;

/**
 * A job reserved by Laravel, reduced to what can cross a thread boundary.
 *
 * A `RedisJob` holds a container and a live Redis connection, so it belongs to
 * the thread that created it. These four strings do not: the worker thread
 * rebuilds an equivalent job against its own application.
 *
 * `$reserved` is not the same string as `$body` — Laravel stores the reserved
 * copy with an incremented `attempts` and deletes the reservation by matching
 * that exact string, so both have to travel.
 */
final readonly class ReservedJob
{
    public function __construct(
        public string $body,
        public string $reserved,
        public string $connectionName,
        public string $queueName,
    ) {
    }

    public static function fromRedisJob(RedisJob $job, string $connectionName, string $queueName): self
    {
        return new self($job->getRawBody(), $job->getReservedJob(), $connectionName, $queueName);
    }

    /**
     * @param array{body: string, reserved: string, connection: string, queue: string} $payload
     */
    public static function fromArray(array $payload): self
    {
        return new self(
            $payload['body'],
            $payload['reserved'],
            $payload['connection'],
            $payload['queue'],
        );
    }

    /**
     * The form that travels: scalars only, so the thread pool copies it instead
     * of refusing it.
     *
     * @return array{body: string, reserved: string, connection: string, queue: string}
     */
    public function toArray(): array
    {
        return [
            'body' => $this->body,
            'reserved' => $this->reserved,
            'connection' => $this->connectionName,
            'queue' => $this->queueName,
        ];
    }
}
