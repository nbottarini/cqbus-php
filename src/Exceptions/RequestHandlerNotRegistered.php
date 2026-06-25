<?php

declare(strict_types=1);

namespace Botta\CQBus\Exceptions;

use RuntimeException;

final class RequestHandlerNotRegistered extends RuntimeException
{
    public function __construct(string $requestClass)
    {
        parent::__construct("Request handler not registered for request {$requestClass}");
    }
}
