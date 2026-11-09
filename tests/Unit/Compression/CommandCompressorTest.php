<?php

declare(strict_types=1);

namespace Thrun\Laravel\Tests\Unit\Compression;

use RuntimeException;
use Testo\Assert;
use Testo\Test;
use Thrun\Laravel\Compression\CommandCompressor;
use Thrun\Laravel\Tests\Fixture\SendReportJob;

#[Test]
final class CommandCompressorTest
{
    public function roundTripRestoresTheBodyByteForByte(): void
    {
        $compressor = new CommandCompressor();
        $body       = $this->serializedJob(2000);

        $compressed = $compressor->compress($body);

        Assert::true($compressor->isCompressed($compressed));
        Assert::same($compressor->decompress($compressed), $body);
    }

    public function compressionActuallyShrinksARealisticBody(): void
    {
        $compressor = new CommandCompressor();
        $body       = $this->serializedJob(2000);

        $compressed = $compressor->compress($body);

        Assert::true(
            strlen($compressed) < strlen($body) / 2,
            sprintf('expected at least half off, got %d from %d bytes', strlen($compressed), strlen($body)),
        );
    }

    public function bodyBelowTheThresholdIsLeftAlone(): void
    {
        $compressor = new CommandCompressor(minBytes: 4096);
        $body       = $this->serializedJob(2000);

        Assert::same($compressor->compress($body), $body);
    }

    public function encryptedBodyIsLeftAlone(): void
    {
        $compressor = new CommandCompressor();
        // Laravel writes encrypted jobs without the `O:` prefix.
        $body = base64_encode(str_repeat('cipher', 500));

        Assert::same($compressor->compress($body), $body);
    }

    public function incompressibleBodyIsLeftAlone(): void
    {
        $compressor = new CommandCompressor();
        $body       = 'O:' . random_bytes(4096);

        Assert::same($compressor->compress($body), $body);
    }

    public function compressingTwiceChangesNothing(): void
    {
        $compressor = new CommandCompressor();
        $once       = $compressor->compress($this->serializedJob(2000));

        Assert::same($compressor->compress($once), $once);
    }

    public function uncompressedBodyPassesThroughDecompression(): void
    {
        $compressor = new CommandCompressor();
        $body       = $this->serializedJob(2000);

        // Jobs queued before the feature was switched on must stay runnable.
        Assert::same($compressor->decompress($body), $body);
    }

    public function corruptCompressedBodyIsReported(): void
    {
        $compressor = new CommandCompressor();
        $corrupt    = 'TLC1:l:' . base64_encode('not lz4 at all');

        try {
            $compressor->decompress($corrupt);
            Assert::fail('expected a RuntimeException for a corrupt body');
        } catch (RuntimeException $e) {
            Assert::true(str_contains($e->getMessage(), 'decompressed'), $e->getMessage());
        }
    }

    public function invalidBase64IsReported(): void
    {
        $compressor = new CommandCompressor();

        try {
            $compressor->decompress('TLC1:l:@@@not base64@@@');
            Assert::fail('expected a RuntimeException for invalid base64');
        } catch (RuntimeException $e) {
            Assert::true(str_contains($e->getMessage(), 'base64'), $e->getMessage());
        }
    }

    /**
     * A body shaped like the one Laravel writes: `serialize()` of a job object,
     * hence the `O:` prefix the compressor keys on.
     */
    private function serializedJob(int $approximateSize): string
    {
        $recipients = array_fill(0, (int) ceil($approximateSize / 40), 'user@example.com');

        return serialize(new SendReportJob('Monthly report', $recipients));
    }
}
