<?php

declare(strict_types=1);

namespace Thrun\Laravel\Compression;

use RuntimeException;

/**
 * Compresses the serialized job body that Laravel keeps in `data.command`.
 *
 * Only the body is touched. The JSON envelope around it stays JSON because Redis
 * parses it server-side — `LuaScripts::pop()` runs `cjson.decode` to increment
 * `attempts` — and Horizon reads the same JSON for its dashboard. A body is the
 * bulk of a payload, so compressing it alone gets nearly all of the saving while
 * leaving both of those untouched.
 */
final class CommandCompressor
{
    /**
     * Format marker: `TLC1` is the payload format, `l` the codec.
     *
     * A body without the marker is returned verbatim, which is what keeps jobs
     * queued before compression was enabled readable afterwards.
     */
    private const PREFIX = 'TLC1:l:';

    /**
     * Prefix Laravel writes for a plain serialized job. Anything else is either
     * already ours or encrypted.
     */
    private const NATIVE_PREFIX = 'O:';

    /**
     * @param int $minBytes Bodies shorter than this stay uncompressed: lz4 plus
     *                      base64 costs more than it saves on small ones.
     */
    public function __construct(private readonly int $minBytes = 512)
    {
    }

    /**
     * Compress a serialized job body, or return it unchanged when compressing it
     * would not pay off.
     *
     * Left alone: an already compressed body, an encrypted one (Laravel writes
     * those without the `O:` prefix, and ciphertext does not compress), a body
     * below the size threshold, and any body whose compressed form is no shorter.
     *
     * @throws RuntimeException when ext-lz4 is missing
     */
    public function compress(string $command): string
    {
        if ($this->isCompressed($command) || !str_starts_with($command, self::NATIVE_PREFIX)) {
            return $command;
        }

        if (strlen($command) < $this->minBytes) {
            return $command;
        }

        $this->requireExtension();

        $packed = lz4_compress($command);

        if ($packed === false) {
            return $command;
        }

        // base64 because the result goes into json_encode(): raw lz4 output is not
        // valid UTF-8 and would make Laravel's payload encoding fail outright.
        $encoded = self::PREFIX . base64_encode($packed);

        return strlen($encoded) < strlen($command) ? $encoded : $command;
    }

    /**
     * Restore a job body written by compress(). A body this class did not write
     * is returned unchanged, so uncompressed and compressed jobs can sit in one
     * queue during a rollout.
     *
     * @throws RuntimeException when ext-lz4 is missing, or the body carries the
     *                          marker but does not survive decoding
     */
    public function decompress(string $command): string
    {
        if (!$this->isCompressed($command)) {
            return $command;
        }

        $this->requireExtension();

        $packed = base64_decode(substr($command, strlen(self::PREFIX)), true);

        if ($packed === false) {
            throw new RuntimeException('Compressed job body is not valid base64.');
        }

        $plain = lz4_uncompress($packed);

        if ($plain === false) {
            throw new RuntimeException('Compressed job body could not be decompressed.');
        }

        return $plain;
    }

    /**
     * Whether this class wrote the given body. False for both plain and
     * encrypted bodies, which are the two other shapes Laravel produces.
     */
    public function isCompressed(string $command): bool
    {
        return str_starts_with($command, self::PREFIX);
    }

    private function requireExtension(): void
    {
        if (!function_exists('lz4_compress')) {
            throw new RuntimeException(
                'Job payload compression needs ext-lz4 (https://github.com/kjdev/php-ext-lz4).'
            );
        }
    }
}
