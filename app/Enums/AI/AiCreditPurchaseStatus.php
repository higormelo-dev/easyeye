<?php

namespace App\Enums\AI;

enum AiCreditPurchaseStatus: string
{
    case PendingPayment = 'pending_payment';
    case Credited       = 'credited';
    case Cancelled      = 'cancelled';
    case Failed         = 'failed';
    case Refunded       = 'refunded';
}
