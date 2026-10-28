<?php

declare(strict_types=1);

namespace App\Domains\Recall\Enums;

/** O botão que o paciente tocou no convite de retorno. */
enum InviteResponse: string
{
    case WantsToSchedule  = 'wants_to_schedule';
    case AlreadyScheduled = 'already_scheduled';

    public function label(): string
    {
        return __('recall_invite.response.' . $this->value);
    }
}
