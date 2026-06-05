<?php

declare(strict_types=1);

namespace Thrun\Laravel\Handler;

use Thrun\Laravel\Handler\Attribute\ThrunJob;
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
     * Auto-discover handlers in the given namespaces using naming convention,
     * #[AsThrunHandler] attribute or #[ThrunJob] attribute.
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

            $reflection = new \ReflectionClass($class);
            if ($reflection->isAbstract() || $reflection->isInterface()) {
                continue;
            }

            // 1. Self-handling job via #[ThrunJob]
            $jobAttributes = $reflection->getAttributes(ThrunJob::class);
            if ($jobAttributes !== []) {
                $this->handlers[$class] = $this->resolveSelfHandler($class);
                continue;
            }

            // 2. Explicit handler via #[AsThrunHandler]
            $handlerAttributes = $reflection->getAttributes(AsThrunHandler::class);
            if ($handlerAttributes !== []) {
                $attr = $handlerAttributes[0]->newInstance();
                if ($attr->messageClass !== null) {
                    $this->handlers[$attr->messageClass] = $this->resolveHandler($class);
                    continue;
                }
            }

            // 3. Naming convention: *Handler -> *Message
            if (!str_ends_with($class, $handlerSuffix)) {
                continue;
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

    /**
     * @param class-string $jobClass
     * @return callable(object, ?Acknowledger): void
     */
    private function resolveSelfHandler(string $jobClass): callable
    {
        return static function (object $message, Acknowledger $ack) use ($jobClass): void {
            if (!$message instanceof $jobClass) {
                throw new \RuntimeException(sprintf(
                    'Expected job "%s", got "%s"',
                    $jobClass,
                    get_debug_type($message),
                ));
            }

            if (!is_callable($message)) {
                throw new \RuntimeException(sprintf(
                    'Job "%s" must be invokable (__invoke method required)',
                    $jobClass,
                ));
            }

            \Illuminate\Container\Container::getInstance()->call(
                [$message, '__invoke'],
                ['ack' => $ack],
            );
        };
    }
}
