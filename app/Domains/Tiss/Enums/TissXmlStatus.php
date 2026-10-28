<?php

declare(strict_types=1);

namespace App\Domains\Tiss\Enums;

enum TissXmlStatus: string
{
    case Pending    = 'pending';
    case Generating = 'generating';
    case Generated  = 'generated';
    case Validated  = 'validated';
    case Invalid    = 'invalid';
    case Signed     = 'signed';
    case Archived   = 'archived';
    case Error      = 'error';
}
