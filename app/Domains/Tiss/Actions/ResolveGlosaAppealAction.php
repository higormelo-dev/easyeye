<?php

declare(strict_types=1);

namespace App\Domains\Tiss\Actions;

use App\Domains\Tiss\Enums\{TissAppealStatus, TissGlosaStatus};
use App\Domains\Tiss\Models\TissGlosaAppeal;
use App\Domains\Tiss\Services\LogTissStatusTransitionService;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class ResolveGlosaAppealAction
{
    /**
     * Idioma do texto gravado no histórico TISS (tiss_status_histories.reason).
     * Antes da i18n dos rótulos era sempre pt_BR; o dado de auditoria não pode
     * mudar conforme o idioma da interface de quem executou a ação.
     */
    private const HISTORY_LOCALE = 'pt_BR';

    public function __construct(
        private readonly LogTissStatusTransitionService $logStatusTransitionService,
    ) {
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @throws InvalidArgumentException quando "Aceito" vem sem valor positivo ou
     *                                  acima do valor glosado (estado contraditório:
     *                                  recurso Aceito com glosa Mantida / recuperado
     *                                  maior que o glosado).
     */
    public function __invoke(TissGlosaAppeal $appeal, TissAppealStatus $decision, array $payload = []): TissGlosaAppeal
    {
        // Rejeitado nunca recupera valor: ignora um accepted_amount que sobrou do formulário.
        // Arredonda para centavos ANTES da guarda e do status: a coluna é numeric(14,2),
        // então 0.004 gravaria 0,00 com glosa "Revertida parcialmente" e 199.995 gravaria
        // 200,00 (recuperado total) com glosa "Revertida parcialmente".
        $acceptedAmount = $decision === TissAppealStatus::Rejected
            ? 0.0
            : round((float) ($payload['accepted_amount'] ?? 0), 2);

        $this->assertValidAcceptedAmount($appeal, $decision, $acceptedAmount);

        return DB::transaction(function () use ($appeal, $decision, $payload, $acceptedAmount): TissGlosaAppeal {
            $previousAppealStatus = $appeal->status;
            $requestedAmount      = (float) $appeal->requested_amount;

            $glosaStatus = match (true) {
                $decision === TissAppealStatus::Rejected => TissGlosaStatus::Maintained,
                $acceptedAmount <= 0                     => TissGlosaStatus::Maintained,
                $acceptedAmount >= $requestedAmount      => TissGlosaStatus::Reversed,
                default                                  => TissGlosaStatus::PartialReversed,
            };

            $appeal->update([
                'status'          => $decision->value,
                'accepted_amount' => $acceptedAmount,
                'resolved_at'     => now(),
                'result_notes'    => $payload['result_notes'] ?? null,
            ]);

            $glosa               = $appeal->glosa;
            $previousGlosaStatus = $glosa->status;

            $glosa->update([
                'status'           => $glosaStatus->value,
                'resolved_at'      => now()->toDateString(),
                'resolution_notes' => $payload['result_notes'] ?? $glosa->resolution_notes,
            ]);

            $this->logStatusTransitionService->record(
                entityId: (string) $appeal->entity_id,
                contextType: 'glosa_appeal',
                contextId: (string) $appeal->id,
                currentStatus: $decision->value,
                previousStatus: $previousAppealStatus->value,
                reason: sprintf('Recurso %s resolvido: %s.', $appeal->appeal_number, $decision->label(self::HISTORY_LOCALE)),
                payload: ['accepted_amount' => $acceptedAmount, 'glosa_status' => $glosaStatus->value],
            );

            $this->logStatusTransitionService->record(
                entityId: (string) $glosa->entity_id,
                contextType: 'glosa',
                contextId: (string) $glosa->id,
                currentStatus: $glosaStatus->value,
                previousStatus: $previousGlosaStatus->value,
                reason: sprintf('Glosa %s após decisão do recurso %s.', $glosaStatus->label(self::HISTORY_LOCALE), $appeal->appeal_number),
            );

            return $appeal->fresh();
        });
    }

    private function assertValidAcceptedAmount(TissGlosaAppeal $appeal, TissAppealStatus $decision, float $acceptedAmount): void
    {
        if ($decision !== TissAppealStatus::Accepted) {
            return;
        }

        if ($acceptedAmount <= 0) {
            throw new InvalidArgumentException('Recurso aceito exige valor aceito maior que zero.');
        }

        $glosaAmount = (float) ($appeal->glosa?->amount ?? $appeal->requested_amount);

        if ($acceptedAmount > round($glosaAmount, 2)) {
            throw new InvalidArgumentException('Valor aceito não pode ser maior que o valor glosado.');
        }
    }
}
