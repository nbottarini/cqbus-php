<?php

declare(strict_types=1);

namespace Botta\CQBus;

use Botta\CQBus\Requests\Request;

interface Middleware
{
    public function process(Request $request, callable $next, ExecutionContext $context): mixed;
}
