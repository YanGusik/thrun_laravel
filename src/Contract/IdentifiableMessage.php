<?php

declare(strict_types=1);

namespace Thrun\Laravel\Contract;

interface IdentifiableMessage
{
    public function getId(): string;
}
