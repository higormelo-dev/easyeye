<?php

declare(strict_types=1);

namespace App\Domains\Tiss\Enums;

enum TissGlosaStatus: string
{
    case Open            = 'open';
    case Appealed        = 'appealed';
    case PartialReversed = 'partial_reversed';
    case Reversed        = 'reversed';
    case Maintained      = 'maintained';
    case Cancelled       = 'cancelled';
}
