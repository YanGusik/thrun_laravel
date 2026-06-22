<?php

namespace Thrun\Laravel\Rpc;

use Thrun\Contract\SerializerInterface;
use Thrun\Envelope\Envelope;
use Thrun\Rpc\Frame;
use Thrun\Rpc\FrameStream;

final readonly class RpcPublisher
{
    public function __construct(
        private RpcAddress $address,
        private SerializerInterface $serializer
    ) {
    }

    public function emit(string $event, array $data): void
    {
        FrameStream::write($this->connection(), Frame::event($event, $data));
    }

    public function job(string $queue, Envelope $envelope): void
    {
        FrameStream::write(
            $this->connection(),
            Frame::job($queue, $this->serializer->serialize($envelope)),
        );
    }

    private function connection(): mixed
    {
        static $conn = null;

        if ($conn === null) {
            $conn = stream_socket_client($this->address->dsn);
        }

        return $conn;
    }
}