<?php

declare(strict_types=1);

namespace App\Domains\Tiss\Enums;

enum TissBatchStatus: string
{
    case Open          = 'open';
    case Closed        = 'closed';
    case XmlGenerating = 'xml_generating';
    case XmlReady      = 'xml_ready';
    case Sending       = 'sending';
    case Sent          = 'sent';
    case Acknowledged  = 'acknowledged';
    case Processed     = 'processed';
    case PartiallyPaid = 'partially_paid';
    case Paid          = 'paid';
    case Error         = 'error';
    case Cancelled     = 'cancelled';
}
