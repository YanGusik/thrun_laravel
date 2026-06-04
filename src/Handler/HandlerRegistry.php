<?php

declare(strict_types=1);

namespace Thrun\Laravel\Handler;

use Illuminate\Contracts\Container\Container;
use Thrun\Laravel\Contract\HandlerInterface;
use Thrun\Worker\Acknowledger;

final class HandlerRegistry
{
    /** @var array<class-string<object>, callable(object, ?Acknowledger): void> */
    private array $handlers = [];

    /**
     * @param class-string<object>          $messageClass
     * @param class-string|callable-string  $handler
     */
    public function register(string $messageClass, string $handler): void
    {
        $this->handlers[$messageClass] = $this->resolveHandler($handler);
    }

    /**
     * Auto-discover handlers in the given namespaces using naming convention
     * or #[AsThrunHandler] attribute.
     *
     * @param list<string> $namespaces
     */
    public function discover(array $namespaces): void
    {
        foreach ($namespaces as $namespace) {
            $this->discoverNamespace($namespace);
        }
    }

    /**
     * @return array<class-string<object>, callable(object, ?Acknowledger): void>
     */
    public function all(): array
    {
        return $this->handlers;
    }

    private function discoverNamespace(string $namespace): void
    {
        $handlerSuffix = 'Handler';
        $messageSuffix = 'Message';

        $handlers = get_declared_classes();
        foreach ($handlers as $class) {
            if (!str_starts_with($class, $namespace)) {
                continue;
            }

            if (!str_ends_with($class, $handlerSuffix)) {
                continue;
            }

            $reflection = new \ReflectionClass($class);
            if ($reflection->isAbstract() || $reflection->isInterface()) {
                continue;
            }

            $attributes = $reflection->getAttributes(AsThrunHandler::class);
            if ($attributes !== []) {
                $attr = $attributes[0]->newInstance();
                if ($attr->messageClass !== null) {
                    $this->handlers[$attr->messageClass] = $this->resolveHandler($class);
                    continue;
                }
            }

            $possibleMessage = substr($class, 0, -strlen($handlerSuffix)) . $messageSuffix;
            if (class_exists($possibleMessage)) {
                $this->handlers[$possibleMessage] = $this->resolveHandler($class);
            }
        }
    }

    /**
     * @param class-string $handlerClass
     * @return callable(object, ?Acknowledger): void
     */
    private function resolveHandler(string $handlerClass): callable
    {
        return static function (object $message, ?Acknowledger $ack = null) use ($handlerClass): void {
            $instance = \Illuminate\Container\Container::getInstance()->make($handlerClass);

            if (!is_callable($instance)) {
                throw new \RuntimeException(sprintf(
                    'Handler "%s" must be callable (__invoke method required)',
                    $handlerClass,
                ));
            }

            if ($ack !== null) {
                $instance($message, $ack);
            } else {
                $instance($message);
            }
        };
    }
}
