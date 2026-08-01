<?php

declare(strict_types=1);

namespace Thrun\Laravel\Compression;

use Illuminate\Queue\Console\RetryCommand;

/**
 * `queue:retry` for compressed payloads.
 *
 * The stock command reads the job body itself, to refresh `retryUntil` before
 * pushing the job back. Without this override every retry of a compressed job
 * dies trying to decrypt a body that was never encrypted.
 */
final class CompressionAwareRetryCommand extends RetryCommand
{
    public function __construct(private readonly CommandCompressor $compressor)
    {
        parent::__construct();
    }

    protected function getInstanceFromPayload($payload)
    {
        $payload['data']['command'] = $this->compressor->decompress($payload['data']['command']);

        return parent::getInstanceFromPayload($payload);
    }
}
