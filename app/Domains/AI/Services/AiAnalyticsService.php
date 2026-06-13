<?php

declare(strict_types=1);

namespace App\Domains\AI\Services;

use App\Enums\AI\AiRunStatus;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\{Cache, DB};

/**
 * Métricas analíticas exibidas no dashboard /panel/usage (Onda 3, P4):
 *   - byDoctor: top 10 médicos por runs aprovados, com avg de créditos e tempo
 *     médio para aprovar.
 *   - averageApproveSeconds: tempo médio geral entre criação e aprovação.
 *   - averageCostPerRecord: custo médio em créditos por consulta (medical_record).
 *
 * Todas as queries são scoped por entity_id e respeitam o período [start, end].
 */
class AiAnalyticsService
{
    public const CACHE_TTL_SECONDS = 300;

    /**
     * @return array<int, array{
     *   doctor_id: string,
     *   doctor_name: string,
     *   approved: int,
     *   avg_credits: float,
     *   avg_approve_seconds: float
     * }>
     */
    public function byDoctor(string $entityId, Carbon $start, Carbon $end): array
    {
        return Cache::remember(
            $this->cacheKey('by_doctor', $entityId, $start, $end),
            self::CACHE_TTL_SECONDS,
            fn (): array => $this->computeByDoctor($entityId, $start, $end),
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function computeByDoctor(string $entityId, Carbon $start, Carbon $end): array
    {
        $rows = DB::table('ai_runs')
            ->join('users', 'ai_runs.approved_by', '=', 'users.id')
            ->where('ai_runs.entity_id', $entityId)
            ->where('ai_runs.status', AiRunStatus::Approved->value)
            ->whereBetween('ai_runs.created_at', [$start, $end])
            ->selectRaw('
                ai_runs.approved_by as doctor_id,
                users.name as doctor_name,
                count(*) as approved,
                avg(ai_runs.consumed_credits) as avg_credits,
                avg(extract(epoch from (ai_runs.approved_at - ai_runs.created_at))) as avg_approve_seconds
            ')
            ->groupBy('ai_runs.approved_by', 'users.name')
            ->orderByDesc('approved')
            ->limit(10)
            ->get();

        return $rows->map(fn ($row): array => [
            'doctor_id'           => (string) $row->doctor_id,
            'doctor_name'         => (string) $row->doctor_name,
            'approved'            => (int) $row->approved,
            'avg_credits'         => round((float) $row->avg_credits, 1),
            'avg_approve_seconds' => round((float) $row->avg_approve_seconds, 1),
        ])->values()->all();
    }

    /**
     * Tempo médio em segundos entre criação e aprovação dos runs do período.
     * Retorna null se não houver runs aprovados.
     */
    public function averageApproveSeconds(string $entityId, Carbon $start, Carbon $end): ?float
    {
        return Cache::remember(
            $this->cacheKey('avg_approve', $entityId, $start, $end),
            self::CACHE_TTL_SECONDS,
            function () use ($entityId, $start, $end): ?float {
                $value = DB::table('ai_runs')
                    ->where('entity_id', $entityId)
                    ->where('status', AiRunStatus::Approved->value)
                    ->whereBetween('created_at', [$start, $end])
                    ->whereNotNull('approved_at')
                    ->selectRaw('avg(extract(epoch from (approved_at - created_at))) as avg_secs')
                    ->value('avg_secs');

                return $value !== null ? round((float) $value, 1) : null;
            },
        );
    }

    /**
     * Custo médio (créditos consumidos) por consulta — soma por medical_record
     * dividido pela contagem de medical_records distintos.
     * Retorna null se não houver consultas associadas a runs Approved.
     */
    public function averageCostPerRecord(string $entityId, Carbon $start, Carbon $end): ?float
    {
        return Cache::remember(
            $this->cacheKey('avg_cost', $entityId, $start, $end),
            self::CACHE_TTL_SECONDS,
            function () use ($entityId, $start, $end): ?float {
                $sub = DB::table('ai_runs')
                    ->where('entity_id', $entityId)
                    ->where('status', AiRunStatus::Approved->value)
                    ->whereNotNull('medical_record_id')
                    ->whereBetween('created_at', [$start, $end])
                    ->groupBy('medical_record_id')
                    ->selectRaw('medical_record_id, sum(consumed_credits) as total');

                $value = DB::query()
                    ->fromSub($sub, 'sub')
                    ->selectRaw('avg(sub.total) as avg_cost')
                    ->value('avg_cost');

                return $value !== null ? round((float) $value, 1) : null;
            },
        );
    }

    /**
     * Chave de cache única por kind + entity + intervalo. O intervalo entra como
     * dia (YYYYMMDD) — suficiente para os 3 buckets de tempo usados hoje (mês,
     * trimestre, anual). Tudo expira em 5 min, então drift granular não importa.
     */
    private function cacheKey(string $kind, string $entityId, Carbon $start, Carbon $end): string
    {
        return sprintf('ai:analytics:%s:%s:%s:%s', $kind, $entityId, $start->format('Ymd'), $end->format('Ymd'));
    }

    /**
     * Invalida todos os 3 kinds para uma entity no período corrente (mês). Os
     * outros períodos expiram naturalmente em 5 min.
     */
    public function invalidate(string $entityId): void
    {
        $start = Carbon::now()->startOfMonth();
        $end   = Carbon::now()->endOfMonth();

        foreach (['by_doctor', 'avg_approve', 'avg_cost'] as $kind) {
            Cache::forget($this->cacheKey($kind, $entityId, $start, $end));
        }
    }
}
