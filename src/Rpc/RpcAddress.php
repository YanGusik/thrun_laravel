<?php

namespace Thrun\Laravel\Rpc;

use Illuminate\Contracts\Config\Repository as ConfigContract;

final readonly class RpcAddress
{
    public function __construct(public string $dsn) {}

    public static function fromConfig(ConfigContract $config): self
    {
        $rpc = $config->get('thrun.rpc', []);

        return new self(($rpc['transport'] ?? 'unix') === 'tcp'
            ? sprintf('tcp://%s:%d', $rpc['host'] ?? '127.0.0.1', $rpc['port'] ?? 9000)
            : sprintf('unix://%s', $rpc['socket_path'] ?? sys_get_temp_dir().'/thrun_rpc.sock'));
    }
}