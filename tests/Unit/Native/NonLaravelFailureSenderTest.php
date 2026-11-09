<?php

declare(strict_types=1);

namespace Thrun\Laravel\Tests\Unit\Native;

use Testo\Assert;
use Testo\Test;
use Thrun\Contract\SenderInterface;
use Thrun\Envelope\Envelope;
use Thrun\Laravel\Native\LaravelQueueTransport;
use Thrun\Laravel\Native\NonLaravelFailureSender;

/**
 * A failure record for an ordinary Laravel job would be the second one written
 * for it, and `thrun:retry` cannot replay it: there is no message class behind
 * the route key.
 */
#[Test]
final class NonLaravelFailureSenderTest
{
    public function aLaravelJobIsNotRecordedAsAThrunFailure(): void
    {
        $store  = $this->store();
        $sender = new NonLaravelFailureSender($store);

        $sender->send(new Envelope(['job' => []], type: LaravelQueueTransport::ROUTE));

        Assert::count($store->sent, 0);
    }

    public function everyOtherFailureStillReachesTheStore(): void
    {
        $store  = $this->store();
        $sender = new NonLaravelFailureSender($store);

        $sender->send(new Envelope(['id' => 1], type: 'App\\Messages\\SendEmailMessage'));

        Assert::count($store->sent, 1);
    }

    private function store(): SenderInterface
    {
        return new class implements SenderInterface {
            /** @var list<Envelope> */
            public array $sent = [];

            public function send(Envelope $envelope): void
            {
                $this->sent[] = $envelope;
            }
        };
    }
}
