<?php

declare(strict_types=1);

namespace Botta\CQBus\Internal;

use Closure;

final readonly class HandlerDefinition
{
    public function __construct(
        public string $handlerClass,
        public bool $contextAware,
        public ?Closure $factory = null,
    ) {
    }
}
