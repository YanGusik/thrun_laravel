<?php

declare(strict_types=1);

namespace Thrun\Laravel\Tests\Fixture;

use Illuminate\Contracts\Bus\Dispatcher;
use LogicException;

/**
 * Stands in for the bus in tests that only exercise payload reading.
 *
 * Every method throws: the handler under test must not reach the bus, and a
 * throwing double says so louder than a silent one.
 */
final class UnusedBusDispatcher implements Dispatcher
{
    public function dispatch($command)
    {
        $this->refuse();
    }

    public function dispatchSync($command, $handler = null)
    {
        $this->refuse();
    }

    public function dispatchNow($command, $handler = null)
    {
        $this->refuse();
    }

    public function dispatchAfterResponse($command, $handler = null)
    {
        $this->refuse();
    }

    public function chain($jobs = null)
    {
        $this->refuse();
    }

    public function hasCommandHandler($command)
    {
        $this->refuse();
    }

    public function getCommandHandler($command)
    {
        $this->refuse();
    }

    public function pipeThrough(array $pipes)
    {
        $this->refuse();
    }

    public function map(array $map)
    {
        $this->refuse();
    }

    private function refuse(): never
    {
        throw new LogicException('The bus must not be used in this test.');
    }
}
