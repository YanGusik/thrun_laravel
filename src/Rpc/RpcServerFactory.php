<?php

namespace Thrun\Laravel\Rpc;

use Illuminate\Contracts\Config\Repository as ConfigContract;
use RuntimeException;
use Thrun\Laravel\Transport\TransportFactory;
use Thrun\Rpc\RpcServer;
use Thrun\Serialization\ClassMapMessageTypeResolver;
use Thrun\Serialization\JsonSerializer;

final readonly class RpcServerFactory
{
    public function __construct(
        private ConfigContract $config,
        private TransportFactory $transportFactory,
        private RpcAddress $address,
    ) {}

    public function create(): RpcServer
    {
        if (str_starts_with($this->address->dsn, 'unix://')) {
            @unlink(substr($this->address->dsn, 7));
        }

        $serverSocket = stream_socket_server($this->address->dsn, $errno, $errStr);
        if ($serverSocket === false) {
            throw new RuntimeException("RPC bind failed: {$errStr} {$errno}");
        }

        $serializer = new JsonSerializer(new ClassMapMessageTypeResolver());
        $server     = new RpcServer($serverSocket, $serializer);

        foreach ($this->config->get('thrun.queues', []) as $name => $queueConfig) {
            if (($queueConfig['transport'] ?? null) === 'memory') {
                $server->registerLocalQueue($name, $this->transportFactory->getQueueTransport($name));
            }
        }

        return $server;
    }
}