<?php

namespace App\Enums\AI;

enum AiRunMode: string
{
    case Economy   = 'economy';
    case Validated = 'validated';
    case Consensus = 'consensus';
}
