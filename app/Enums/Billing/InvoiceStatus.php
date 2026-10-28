<?php

namespace App\Enums\Billing;

enum InvoiceStatus: string
{
    case Draft     = 'draft';
    case Pending   = 'pending';
    case Paid      = 'paid';
    case Overdue   = 'overdue';
    case Cancelled = 'cancelled';
    case Refunded  = 'refunded';
    case Failed    = 'failed';
}
