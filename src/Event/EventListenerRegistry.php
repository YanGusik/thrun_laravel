<?php

namespace Thrun\Laravel\Event;

use Thrun\Laravel\Event\Attribute\ThrunEventListener;
use Illuminate\Contracts\Container\Container;
use ReflectionClass;

final class EventListenerRegistry
{
    /** @var array<string, list<class-string>> eventName|pattern => [listenerClass, ...] */
    private array $listeners = [];

    public function __construct(private readonly Container $container)
    {
    }

    public function register(string $event, string $listenerClass): void
    {
        $this->listeners[$event][] = $listenerClass;
    }

    public function discover(array $namespaces): void
    {
        foreach ($namespaces as $namespace) {
            $this->discoverNamespace($namespace);
        }
    }

    public function findFor(string $eventName): array
    {
        $matched = [];

        foreach ($this->listeners as $pattern => $classes) {
            if ($this->matches($pattern, $eventName)) {
                foreach ($classes as $class) {
                    $matched[] = $class;
                }
            }
        }

        return $matched;
    }

    public function dispatch(string $eventName, array $payload): void
    {
        foreach ($this->findFor($eventName) as $listenerClass) {
            $this->container->call(
                [$this->container->make($listenerClass), '__invoke'],
                ['payload' => $payload],
            );
        }
    }

    private function matches(string $pattern, string $eventName): bool
    {
        if ($pattern === $eventName) {
            return true;
        }

        // wildcard: 'order.*' or '*'
        $regex = '/^'.str_replace(['.', '*'], ['\.', '.*'], $pattern).'$/';

        return (bool) preg_match($regex, $eventName);
    }

    private function discoverNamespace(string $namespace): void
    {
        $dirPath   = preg_replace('#^App/#', 'app/', str_replace('\\', '/', $namespace));
        $directory = $this->container->make('path.base').'/'.$dirPath;


        if (is_dir($directory)) {
            foreach (glob($directory.'/*.php') as $file) {
                require_once $file;
            }
        }

        foreach (get_declared_classes() as $class) {
            if (!str_starts_with($class, $namespace)) {
                continue;
            }

            $reflection = new ReflectionClass($class);
            $attributes = $reflection->getAttributes(ThrunEventListener::class);

            if ($attributes === []) {
                continue;
            }

            /** @var ThrunEventListener $attr */
            $attr  = $attributes[0]->newInstance();
            $event = $attr->event ?? $class;

            $this->register($event, $class);
        }
    }
}