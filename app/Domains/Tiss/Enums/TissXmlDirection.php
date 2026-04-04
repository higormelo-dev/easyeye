<?php

declare(strict_types=1);

namespace App\Domains\Tiss\Enums;

enum TissXmlDirection: string
{
    case Outbound = 'outbound';
    case Inbound  = 'inbound';
}
