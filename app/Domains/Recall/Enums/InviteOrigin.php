<?php

declare(strict_types=1);

namespace App\Domains\Recall\Enums;

/** Quem mandou o convite de retorno: a rodada automática ou uma pessoa da clínica. */
enum InviteOrigin: string
{
    case Auto   = 'auto';
    case Manual = 'manual';

    public function label(): string
    {
        return __('recall_invite.origin.' . $this->value);
    }
}
