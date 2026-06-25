<?php

declare(strict_types=1);

namespace Botta\CQBus\Exceptions;

use RuntimeException;

final class CannotAccessInternalRequest extends RuntimeException
{
    public function __construct(string $requestClass)
    {
        parent::__construct("Cannot access internal request {$requestClass}. Internal requests not enabled");
    }
}
