<?php

declare(strict_types=1);

namespace Thrun\Laravel\Tests\Fixture;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Support\Arr;

/**
 * Configuration for tests, without pulling illuminate/config into the package.
 * Only dotted reads are exercised, so the writing half is deliberately minimal.
 */
final class ArrayConfig implements Repository
{
    public function __construct(private array $items = [])
    {
    }

    public function has($key): bool
    {
        return Arr::has($this->items, $key);
    }

    public function get($key, $default = null): mixed
    {
        return Arr::get($this->items, $key, $default);
    }

    public function all(): array
    {
        return $this->items;
    }

    public function set($key, $value = null): void
    {
        Arr::set($this->items, $key, $value);
    }

    public function prepend($key, $value): void
    {
        $array = $this->get($key, []);
        array_unshift($array, $value);
        $this->set($key, $array);
    }

    public function push($key, $value): void
    {
        $array = $this->get($key, []);
        $array[] = $value;
        $this->set($key, $array);
    }
}
