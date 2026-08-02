<?php

declare(strict_types=1);

namespace Thrun\Laravel\Tests\Unit\Worker;

use Testo\Assert;
use Testo\Test;
use Thrun\Contract\ReceiverInterface;
use Thrun\Envelope\Envelope;
use Thrun\Laravel\Tests\Fixture\RecordingLogger;
use Thrun\Laravel\Worker\DrainingReceiver;

/**
 * The decorator exists for one moment: the transport underneath has finished and
 * the worker, which waits for results forever, has to be told.
 */
#[Test]
final class DrainingReceiverTest
{
    public function aFinishedTransportStopsTheWorker(): void
    {
        $stopped  = false;
        $receiver = $this->receiver([new Envelope(['n' => 1]), null]);

        $receiver->attachTo(
            isIdle: static fn(): bool => true,
            stop: static function () use (&$stopped): void {
                $stopped = true;
            },
        );

        Assert::instanceOf($receiver->receive(), Envelope::class);
        Assert::false($stopped);

        Assert::null($receiver->receive());
        Assert::true($stopped);
    }

    public function jobsStillRunningAreWaitedForBeforeStopping(): void
    {
        $idle     = false;
        $stopped  = false;
        $receiver = $this->receiver([new Envelope(['n' => 1]), null]);

        $receiver->attachTo(
            // The pool goes idle while the drain is waiting, as it does when the
            // last job settles its reservation.
            isIdle: static function () use (&$idle): bool {
                $wasIdle = $idle;
                $idle    = true;

                return $wasIdle;
            },
            stop: static function () use (&$stopped, &$idle): void {
                Assert::true($idle, 'the worker was stopped while a job was still running');
                $stopped = true;
            },
        );

        $receiver->receive();

        Assert::null($receiver->receive());
        Assert::true($stopped);
    }

    public function aRunThatDispatchedNothingStopsAtOnce(): void
    {
        $stopped  = false;
        $receiver = $this->receiver([null]);

        $receiver->attachTo(
            // What the pool answers before anything has completed on it. A run that
            // reserved nothing must not sit out the grace period waiting for it.
            isIdle: static fn(): bool => false,
            stop: static function () use (&$stopped): void {
                $stopped = true;
            },
        );

        Assert::null($receiver->receive());
        Assert::true($stopped);
    }

    /**
     * @param list<?Envelope> $envelopes handed out in order; null ends the stream
     */
    private function receiver(array $envelopes): DrainingReceiver
    {
        $inner = new class ($envelopes) implements ReceiverInterface {
            /** @param list<?Envelope> $envelopes */
            public function __construct(private array $envelopes)
            {
            }

            public function receive(): ?Envelope
            {
                return array_shift($this->envelopes);
            }

            public function tryReceive(): ?Envelope
            {
                return $this->receive();
            }

            public function ack(Envelope $envelope): void
            {
            }

            public function reject(Envelope $envelope): void
            {
            }

            public function close(): void
            {
            }
        };

        return new DrainingReceiver($inner, new RecordingLogger(), graceSeconds: 1);
    }
}
