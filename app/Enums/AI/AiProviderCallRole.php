<?php

namespace App\Enums\AI;

enum AiProviderCallRole: string
{
    case Generator   = 'generator';
    case Reviewer    = 'reviewer';
    case Adjudicator = 'adjudicator';
    case Fallback    = 'fallback';
}
