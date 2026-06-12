<?php

declare(strict_types=1);

namespace App\Domains\AI\Services;

use App\Domains\AI\Models\AiRun;
use App\Enums\AI\AiRunStatus;
use App\Models\Subscription;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\{Cache, DB};

/**
 * Centraliza o cálculo da cota mensal de créditos IA e do consumo do mês
 * corrente para reuso entre AiRunsController::index (dashboard), EyeImagesController
 * e MedicalRecordsController (painel inline).
 *
 * A cota vem do plan_feature `ai_monthly_credits` da subscription ativa; o consumo
 * conta apenas runs em Approved/WaitingApproval do mês — Failed/Cancelled/Rejected
 * têm a reserva estornada e não devem aparecer no medidor.
 */
class AiQuotaService
{
    /**
     * @return array{
     *   monthly_quota: int,
     *   consumed_credits: int,
     *   usage_percent: float|null,
     *   period_label: string,
     *   period_start: string,
     *   period_end: string
     * }
     */
    /**
     * TTL do cache: 5 min. Estende-se naturalmente ao virar o mês porque a chave
     * inclui YYYY-MM. Acerto fica em cache até expirar OU até flush manual.
     */
    public const CACHE_TTL_SECONDS = 300;

    public function currentMonthSnapshot(string $entityId): array
    {
        $now        = Carbon::now();
        $monthStart = $now->copy()->startOfMonth();
        $monthEnd   = $now->copy()->endOfMonth();
        $key        = "ai:quota:{$entityId}:" . $monthStart->format('Y-m');

        return Cache::remember($key, self::CACHE_TTL_SECONDS, function () use ($entityId, $monthStart, $monthEnd): array {
            $quota = $this->planQuota($entityId);

            $consumed = (int) AiRun::query()
                ->where('entity_id', $entityId)
                ->whereBetween('created_at', [$monthStart, $monthEnd])
                ->whereIn('status', [
                    AiRunStatus::Approved->value,
                    AiRunStatus::WaitingApproval->value,
                ])
                ->sum('consumed_credits');

            return [
                'monthly_quota'    => $quota,
                'consumed_credits' => $consumed,
                'usage_percent'    => $quota > 0 ? round(($consumed / $quota) * 100, 1) : null,
                'period_label'     => $monthStart->locale('pt_BR')->isoFormat('MMMM/YYYY'),
                'period_start'     => $monthStart->format('d/m/Y'),
                'period_end'       => $monthEnd->format('d/m/Y'),
            ];
        });
    }

    /**
     * Invalida o cache do snapshot do mês corrente para uma entity. Chamado
     * pelos pontos que mudam o consumed_credits (aprovação, falha, cancel).
     */
    public function invalidate(string $entityId): void
    {
        $key = 'ai:quota:' . $entityId . ':' . Carbon::now()->format('Y-m');
        Cache::forget($key);
    }

    /**
     * Retorna a cota mensal de créditos definida no plano ativo da entity.
     * Retorna 0 se não houver subscription ativa ou se a feature não estiver
     * definida (entity ainda pode usar créditos avulsos comprados).
     */
    public function planQuota(string $entityId): int
    {
        $subscription = Subscription::query()
            ->where('entity_id', $entityId)
            ->whereIn('status', ['active', 'trialing'])
            ->latest('created_at')
            ->first();

        if (! $subscription || ! $subscription->plan_id) {
            return 0;
        }

        $quota = DB::table('plan_features')
            ->where('plan_id', $subscription->plan_id)
            ->where('feature', 'ai_monthly_credits')
            ->value('value');

        return (int) ($quota ?? 0);
    }
}
