<?php

declare(strict_types=1);

namespace Thrun\Laravel\Tests\Fixture;

use Illuminate\Contracts\Debug\ExceptionHandler;
use Throwable;

/**
 * Keeps what was reported, so a test can assert that a swallowed failure was at
 * least written down somewhere.
 */
final class RecordingExceptionHandler implements ExceptionHandler
{
    /** @var list<Throwable> */
    public array $reported = [];

    public function report(Throwable $e)
    {
        $this->reported[] = $e;
    }

    public function shouldReport(Throwable $e)
    {
        return true;
    }

    public function render($request, Throwable $e)
    {
        return null;
    }

    public function renderForConsole($output, Throwable $e)
    {
    }
}
