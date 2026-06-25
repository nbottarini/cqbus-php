<?php

declare(strict_types=1);

namespace Botta\CQBus\Exceptions;

use RuntimeException;

final class HandlerAlreadyRegistered extends RuntimeException
{
    public function __construct(string $requestClass)
    {
        parent::__construct("A handler is already registered for request {$requestClass}");
    }
}
