<?php

declare(strict_types=1);

namespace Botta\CQBus;

enum MiddlewarePriority: int
{
    case VeryHigh = 10000;
    case High = 1000;
    case Normal = 0;
    case Low = -1000;
    case VeryLow = -10000;
}
