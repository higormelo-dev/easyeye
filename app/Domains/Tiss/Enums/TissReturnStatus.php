<?php

declare(strict_types=1);

namespace App\Domains\Tiss\Enums;

enum TissReturnStatus: string
{
    case Received  = 'received';
    case Parsed    = 'parsed';
    case Processed = 'processed';
    case Error     = 'error';
}
