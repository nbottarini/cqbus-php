<?php

declare(strict_types=1);

namespace Botta\CQBus\Requests;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS)]
final class InternalRequest
{
}
