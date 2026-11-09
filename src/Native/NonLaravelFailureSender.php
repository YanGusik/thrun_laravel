<?php

declare(strict_types=1);

namespace Thrun\Laravel\Native;

use Thrun\Contract\SenderInterface;
use Thrun\Envelope\Envelope;

/**
 * Keeps thrun's failed-job store free of jobs Laravel has already settled.
 *
 * A worker records a failure for every result that comes back with an error, and
 * for an ordinary Laravel job that record would be the second one: the framework
 * has already released the job or written it to its own `failed_jobs` table
 * inside the thread that ran it. The duplicate is not only noise — the store is
 * replayed by `thrun:retry`, which resolves a record's type to a message class,
 * and a Laravel job has no such class.
 */
final readonly class NonLaravelFailureSender implements SenderInterface
{
    public function __construct(private SenderInterface $inner)
    {
    }

    public function send(Envelope $envelope): void
    {
        if ($envelope->type === LaravelQueueTransport::ROUTE) {
            return;
        }

        $this->inner->send($envelope);
    }
}
