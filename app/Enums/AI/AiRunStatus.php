<?php

namespace App\Enums\AI;

enum AiRunStatus: string
{
    case Pending         = 'pending';
    case Reserved        = 'reserved';
    case Running         = 'running';
    case WaitingApproval = 'waiting_approval';
    case Approved        = 'approved';
    case Rejected        = 'rejected';
    case Failed          = 'failed';
    case Cancelled       = 'cancelled';
}
