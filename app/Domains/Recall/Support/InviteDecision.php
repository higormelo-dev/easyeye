<?php

declare(strict_types=1);

namespace App\Domains\Recall\Support;

use App\Domains\Recall\Enums\InviteBlocker;
use App\Domains\Recall\Models\PatientRecall;
use Carbon\CarbonImmutable;

/**
 * A decisão sobre um convite de retorno: o que impede (vazio = pode sair) e, quando pode, para QUEM, com QUAL
 * telefone e cobrindo QUAIS retornos. Imutável: a prévia, o envio manual e a rodada automática leem a mesma coisa.
 */
final class InviteDecision
{
    /**
     * @param list<InviteBlocker>                                                                                 $blockers
     * @param list<PatientRecall>                                                                                 $recalls  os retornos que a onda cobre (já travados quando a decisão foi tomada com trava)
     * @param array{last_invite_at: ?CarbonImmutable, next_allowed_on: ?string, used_today: int, cap_today: ?int} $pacing
     */
    public function __construct(
        public readonly array $blockers,
        public readonly array $recalls,
        public readonly ?string $phone,
        public readonly string $firstName,
        public readonly string $clinicName,
        public readonly array $pacing,
    ) {
    }

    public function allowed(): bool
    {
        return $this->blockers === [];
    }

    public function has(InviteBlocker $blocker): bool
    {
        return in_array($blocker, $this->blockers, true);
    }
}
