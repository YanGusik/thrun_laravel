<?php

declare(strict_types=1);

namespace Thrun\Laravel\Tests\Fixture;

use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * An ordinary Laravel job, written the way an application already has them:
 * no thrun attributes, no marker interface beyond ShouldQueue.
 */
final class SendReportJob implements ShouldQueue
{
    /**
     * @param list<string> $recipients
     */
    public function __construct(
        public readonly string $title,
        public readonly array $recipients = [],
    ) {
    }

    public function handle(): void
    {
    }
}
