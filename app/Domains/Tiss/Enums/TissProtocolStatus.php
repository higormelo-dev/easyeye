<?php

declare(strict_types=1);

namespace App\Domains\Tiss\Enums;

enum TissProtocolStatus: string
{
    case Pending    = 'pending';
    case Received   = 'received';
    case Processing = 'processing';
    case Accepted   = 'accepted';
    case Rejected   = 'rejected';
    case Closed     = 'closed';
    case Error      = 'error';
}
