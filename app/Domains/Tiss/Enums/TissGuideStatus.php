<?php

declare(strict_types=1);

namespace App\Domains\Tiss\Enums;

enum TissGuideStatus: string
{
    case Draft           = 'draft';
    case Authorized      = 'authorized';
    case Batched         = 'batched';
    case XmlGenerated    = 'xml_generated';
    case Sent            = 'sent';
    case Acknowledged    = 'acknowledged';
    case PartiallyDenied = 'partially_denied';
    case Denied          = 'denied';
    case Paid            = 'paid';
    case Cancelled       = 'cancelled';
    case Error           = 'error';
}
