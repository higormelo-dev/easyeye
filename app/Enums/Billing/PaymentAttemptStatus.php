<?php

namespace App\Enums\Billing;

enum PaymentAttemptStatus: string
{
    case Pending   = 'pending';
    case Succeeded = 'succeeded';
    case Failed    = 'failed';
    case Timeout   = 'timeout';
    case Cancelled = 'cancelled';
}
