<?php

declare(strict_types=1);

namespace Thrun\Laravel\Compression;

/**
 * Adds body compression to a Laravel Redis queue.
 *
 * The hook is `createObjectPayload()` rather than `Queue::createPayloadUsing()`,
 * because that public hook runs before `command` is written and its result is
 * overwritten. Compression happens after the parent has produced the payload, so
 * none of the framework's payload logic is duplicated here.
 */
trait CompressesCommands
{
    private ?CommandCompressor $compressor = null;

    /**
     * Turn compression on for this queue. Without it the queue behaves exactly
     * like the one it extends.
     *
     * A setter rather than a constructor argument: the parent constructor differs
     * between the framework versions this package supports, and redeclaring it in
     * both subclasses would pin them to one of those versions.
     */
    public function setCompressor(CommandCompressor $compressor): void
    {
        $this->compressor = $compressor;
    }

    protected function createObjectPayload($job, $queue)
    {
        $payload = parent::createObjectPayload($job, $queue);

        if ($this->compressor !== null) {
            $payload['data']['command'] = $this->compressor->compress($payload['data']['command']);
        }

        return $payload;
    }
}
