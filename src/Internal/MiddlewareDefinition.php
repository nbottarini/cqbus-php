<?php

declare(strict_types=1);

namespace Botta\CQBus\Internal;

use Botta\CQBus\Middleware;
use Closure;

final readonly class MiddlewareDefinition
{
    public function __construct(
        public ?string $middlewareClass = null,
        public ?Middleware $instance = null,
        public ?Closure $factory = null,
    ) {
    }
}
