<?php

namespace App\Enums\Billing;

enum PaymentStatus: string
{
    case Pending    = 'pending';
    case Authorized = 'authorized';
    case Paid       = 'paid';
    case Failed     = 'failed';
    case Cancelled  = 'cancelled';
    case Refunded   = 'refunded';
    case Chargeback = 'chargeback';
}
