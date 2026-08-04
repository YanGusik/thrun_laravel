<?php

declare(strict_types=1);

namespace Thrun\Laravel\Compression;

use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Contracts\Container\Container;
use Illuminate\Queue\CallQueuedHandler;

/**
 * Runs queued jobs whose body may be compressed.
 *
 * Laravel resolves the handler named in the payload through the container, so
 * binding this class in place of {@see CallQueuedHandler} covers every worker —
 * including a stock `queue:work` or Horizon process running alongside, which is
 * what makes a gradual rollout possible.
 */
final class CompressionAwareCallQueuedHandler extends CallQueuedHandler
{
    public function __construct(
        Dispatcher $dispatcher,
        Container $container,
        private readonly CommandCompressor $compressor,
    ) {
        parent::__construct($dispatcher, $container);
    }

    protected function getCommand(array $data)
    {
        $data['command'] = $this->compressor->decompress($data['command']);

        return parent::getCommand($data);
    }
}
