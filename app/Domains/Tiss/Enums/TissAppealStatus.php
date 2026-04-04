<?php

declare(strict_types=1);

namespace App\Domains\Tiss\Enums;

enum TissAppealStatus: string
{
    case Opened     = 'opened';
    case Submitted  = 'submitted';
    case InAnalysis = 'in_analysis';
    case Accepted   = 'accepted';
    case Rejected   = 'rejected';
    case Cancelled  = 'cancelled';
}
