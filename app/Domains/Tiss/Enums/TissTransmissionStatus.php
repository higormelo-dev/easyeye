<?php

declare(strict_types=1);

namespace App\Domains\Tiss\Enums;

enum TissTransmissionStatus: string
{
    case Queued       = 'queued';
    case Sending      = 'sending';
    case Sent         = 'sent';
    case Acknowledged = 'acknowledged';
    case Timeout      = 'timeout';
    case Failed       = 'failed';
}
