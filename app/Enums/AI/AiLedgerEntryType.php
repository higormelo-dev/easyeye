<?php

namespace App\Enums\AI;

enum AiLedgerEntryType: string
{
    case Grant      = 'grant';
    case Purchase   = 'purchase';
    case Reserve    = 'reserve';
    case Consume    = 'consume';
    case Release    = 'release';
    case Refund     = 'refund';
    case Adjustment = 'adjustment';
    case Expire     = 'expire';
}
