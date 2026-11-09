<?php

declare(strict_types=1);

namespace Thrun\Laravel\Tests\Fixture;

use Psr\Log\AbstractLogger;
use Stringable;

/**
 * Keeps what was logged, so a test can assert that the operator was told about
 * something the code decided on its own.
 */
final class RecordingLogger extends AbstractLogger
{
    /** @var list<array{level: mixed, message: string, context: array<string, mixed>}> */
    public array $records = [];

    public function log($level, Stringable|string $message, array $context = []): void
    {
        $this->records[] = ['level' => $level, 'message' => (string) $message, 'context' => $context];
    }

    /** @return list<string> the messages logged at the given level, in order */
    public function messages(string $level): array
    {
        $messages = [];

        foreach ($this->records as $record) {
            if ($record['level'] === $level) {
                $messages[] = $record['message'];
            }
        }

        return $messages;
    }
}
