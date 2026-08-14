<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\{CommissionStatus, PartnerLeadStatus, SubscriptionStatus};
use App\Models\{Doctor, Entity, MedicalRecord, Partner, PartnerCommission, PartnerLead, Patient, ReferralCode, ReferralEvent, Schedule, Subscription, User};
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\{Cache, DB};

class ManagerDashboardService
{
    private const CACHE_TTL = 600; // 10 minutos

    // ── KPIs Primários ────────────────────────────────────────────────────────

    public function getPrimaryKpis(): array
    {
        return Cache::remember('mgr_dashboard.primary_kpis', self::CACHE_TTL, function () {
            $now   = Carbon::now();
            $today = $now->toDateString();

            return [
                'totalEntities'       => Entity::where('is_client', true)->count(),
                'totalEntitiesActive' => Entity::where('is_client', true)->where('active', true)->count(),
                'totalPatients'       => Patient::count(),
                'totalDoctors'        => Doctor::where('active', true)->count(),
                'totalSchedules'      => Schedule::count(),
                'totalMedicalRecords' => MedicalRecord::count(),
                'totalUsers'          => User::count(),
                'schedulesToday'      => Schedule::whereDate('date_time', $today)->count(),
                'newEntitiesToday'    => Entity::where('is_client', true)->whereDate('created_at', $today)->count(),
                'newEntitiesWeek'     => Entity::where('is_client', true)->where('created_at', '>=', $now->copy()->startOfWeek())->count(),
                'newEntitiesMonth'    => Entity::where('is_client', true)->where('created_at', '>=', $now->copy()->startOfMonth())->count(),
            ];
        });
    }

    // ── KPIs de Assinatura ────────────────────────────────────────────────────

    public function getSubscriptionKpis(): array
    {
        return Cache::remember('mgr_dashboard.subscription_kpis', self::CACHE_TTL, function () {
            $byStatus = Subscription::query()
                ->select('status', DB::raw('COUNT(*) as total'))
                ->groupBy('status')
                ->pluck('total', 'status')
                ->toArray();

            $subscriptionCounts = [];

            foreach (SubscriptionStatus::cases() as $status) {
                $subscriptionCounts[$status->value] = $byStatus[$status->value] ?? 0;
            }

            $mrr = $this->computeMrr();

            return [
                'subscriptionCounts' => $subscriptionCounts,
                'totalSubscriptions' => array_sum($subscriptionCounts),
                'mrr'                => $mrr,
            ];
        });
    }

    // ── KPIs Financeiros SaaS ─────────────────────────────────────────────────

    public function getFinancialKpis(): array
    {
        return Cache::remember('mgr_dashboard.financial_kpis', self::CACHE_TTL, function () {
            $now         = Carbon::now();
            $mrr         = $this->computeMrr();
            $activeCount = Subscription::where('status', SubscriptionStatus::Active->value)->count();

            $arr  = $mrr * 12;
            $arpu = $activeCount > 0 ? round((float) $mrr / $activeCount, 2) : 0.0;

            // Receita em risco: assinaturas past_due
            $revenueAtRisk = Subscription::query()
                ->where('subscriptions.status', SubscriptionStatus::PastDue->value)
                ->join('plans', 'subscriptions.plan_id', '=', 'plans.id')
                ->sum('plans.price');

            // Churn mensal: cancelamentos no mês corrente
            $cancelledThisMonth = Subscription::query()
                ->where('status', SubscriptionStatus::Cancelled->value)
                ->where('cancelled_at', '>=', $now->copy()->startOfMonth())
                ->count();

            // Base aproximada: ativas agora + as que cancelaram neste mês
            $base      = $activeCount + $cancelledThisMonth;
            $churnRate = $base > 0 ? round($cancelledThisMonth / $base * 100, 1) : 0.0;

            return compact('mrr', 'arr', 'arpu', 'revenueAtRisk', 'churnRate', 'cancelledThisMonth', 'activeCount');
        });
    }

    // ── Tendência MRR (12 meses) ──────────────────────────────────────────────

    public function getMrrTrend(): array
    {
        return Cache::remember('mgr_dashboard.mrr_trend', self::CACHE_TTL, function () {
            $now    = Carbon::now();
            $labels = [];
            $values = [];

            for ($i = 11; $i >= 0; $i--) {
                $month        = $now->copy()->subMonths($i);
                $startOfMonth = $month->copy()->startOfMonth();
                $endOfMonth   = $month->copy()->endOfMonth();

                // Snapshot: assinaturas que estavam ativas ao longo deste mês
                // Todas as colunas qualificadas com a tabela para evitar ambiguidade no JOIN com plans
                $snapshot = Subscription::query()
                    ->whereIn('subscriptions.status', [
                        SubscriptionStatus::Active->value,
                        SubscriptionStatus::Cancelled->value,
                        SubscriptionStatus::Expired->value,
                        SubscriptionStatus::PastDue->value,
                    ])
                    ->where(function ($q) use ($endOfMonth) {
                        // Iniciou antes ou durante este mês
                        $q->where('subscriptions.starts_at', '<=', $endOfMonth)
                            ->orWhere(function ($q2) use ($endOfMonth) {
                                $q2->whereNull('subscriptions.starts_at')
                                    ->where('subscriptions.created_at', '<=', $endOfMonth);
                            });
                    })
                    ->where(function ($q) use ($startOfMonth) {
                        // Ainda não havia cancelado/expirado no início do mês
                        $q->whereNull('subscriptions.cancelled_at')
                            ->orWhere('subscriptions.cancelled_at', '>', $startOfMonth);
                    })
                    ->where(function ($q) use ($startOfMonth) {
                        $q->whereNull('subscriptions.ends_at')
                            ->orWhere('subscriptions.ends_at', '>', $startOfMonth);
                    })
                    ->join('plans', 'subscriptions.plan_id', '=', 'plans.id')
                    ->sum('plans.price');

                $labels[] = $month->translatedFormat('M/y');
                $values[] = (float) $snapshot;
            }

            return compact('labels', 'values');
        });
    }

    // ── Funil de Conversão ────────────────────────────────────────────────────

    /**
     * Períodos aceitos pelo seletor do funil (dias). Qualquer valor fora
     * desta lista cai no default de 90 — evita cache poluído por valores
     * arbitrários vindos de query string manipulada.
     */
    public const FUNNEL_PERIODS = [7, 30, 60, 90];

    public function getConversionFunnel(int $days = 90): array
    {
        $days = in_array($days, self::FUNNEL_PERIODS, true) ? $days : 90;

        return Cache::remember("mgr_dashboard.conversion_funnel:{$days}", self::CACHE_TTL, function () use ($days) {
            $now  = Carbon::now();
            $from = $now->copy()->subDays($days);

            // Funil como coorte do período selecionado: leads/trials/ativos
            // são todos filtrados por created_at dentro da janela, para que
            // as taxas entre etapas façam sentido juntas (mesma safra).
            $totalLeads  = PartnerLead::where('created_at', '>=', $from)->count();
            $totalTrials = Subscription::whereNotNull('trial_ends_at')->where('created_at', '>=', $from)->count();
            $totalActive = Subscription::where('status', SubscriptionStatus::Active->value)
                ->where('created_at', '>=', $from)
                ->count();

            $leadToTrialRate = $totalLeads > 0
                ? round(min($totalTrials, $totalLeads) / $totalLeads * 100, 1)
                : 0.0;

            $trialToActiveRate = $totalTrials > 0
                ? round($totalActive / $totalTrials * 100, 1)
                : 0.0;

            // Trials que já finalizaram dentro do período selecionado.
            $trialsEndedPeriod = Subscription::whereNotNull('trial_ends_at')
                ->where('trial_ends_at', '<', $now)
                ->where('created_at', '>=', $from)
                ->count();

            $trialsConvertedPeriod = Subscription::whereNotNull('trial_ends_at')
                ->where('trial_ends_at', '<', $now)
                ->where('created_at', '>=', $from)
                ->where('status', SubscriptionStatus::Active->value)
                ->count();

            $trialToPaidPeriodRate = $trialsEndedPeriod > 0
                ? round($trialsConvertedPeriod / $trialsEndedPeriod * 100, 1)
                : 0.0;

            return compact(
                'days',
                'totalLeads',
                'totalTrials',
                'totalActive',
                'leadToTrialRate',
                'trialToActiveRate',
                'trialsEndedPeriod',
                'trialsConvertedPeriod',
                'trialToPaidPeriodRate',
            );
        });
    }

    // ── Crescimento Mensal de Clínicas (6 meses) ──────────────────────────────

    public function getGrowthTrend(): array
    {
        return Cache::remember('mgr_dashboard.growth_trend', self::CACHE_TTL, function () {
            $now          = Carbon::now();
            $sixMonthsAgo = $now->copy()->subMonths(5)->startOfMonth();
            $dateExpr     = $this->monthExpression('created_at');

            $monthlyGrowth = Entity::query()
                ->where('is_client', true)
                ->where('created_at', '>=', $sixMonthsAgo)
                ->selectRaw("{$dateExpr} as month, COUNT(*) as total")
                ->groupBy('month')
                ->orderBy('month')
                ->pluck('total', 'month')
                ->toArray();

            $labels = [];
            $values = [];

            for ($i = 5; $i >= 0; $i--) {
                $month    = $now->copy()->subMonths($i)->format('Y-m');
                $label    = $now->copy()->subMonths($i)->translatedFormat('M/y');
                $labels[] = $label;
                $values[] = (int) ($monthlyGrowth[$month] ?? 0);
            }

            return compact('labels', 'values');
        });
    }

    // ── Trials Expirando em 7 dias ────────────────────────────────────────────

    public function getTrialsExpiring(ActivationService $activationService): Collection
    {
        $today = now()->toDateString();
        $limit = now()->addDays(7)->toDateString();

        $trials = Subscription::query()
            ->where('status', SubscriptionStatus::Trial->value)
            ->whereBetween('trial_ends_at', [$today, $limit])
            ->with(['entity:id,name,code', 'plan:id,name'])
            ->orderBy('trial_ends_at')
            ->limit(10)
            ->get();

        $entityIds = $trials->pluck('entity.id')->filter()->unique()->values()->toArray();
        $scores    = $this->batchActivationScores($entityIds, $activationService);

        $trials->each(fn ($sub) => $sub->activation_score = $scores[$sub->entity?->id] ?? 0);

        return $trials;
    }

    // ── Últimas Entidades Criadas ─────────────────────────────────────────────

    public function getRecentEntities(ActivationService $activationService): Collection
    {
        $entities = Entity::query()
            ->where('is_client', true)
            ->with(['subscriptions' => fn ($q) => $q->latest()->limit(1), 'subscriptions.plan:id,name'])
            ->orderByDesc('created_at')
            ->limit(5)
            ->get(['id', 'name', 'code', 'active', 'created_at']);

        $entityIds = $entities->pluck('id')->toArray();
        $scores    = $this->batchActivationScores($entityIds, $activationService);

        $entities->each(function ($entity) use ($scores) {
            $entity->activation_score = $scores[$entity->id] ?? 0;
            $entity->latest_sub       = $entity->subscriptions->first();
        });

        return $entities;
    }

    // ── Top 5 Clínicas por Pacientes ──────────────────────────────────────────

    public function getTopEntities(): Collection
    {
        return Cache::remember('mgr_dashboard.top_entities', self::CACHE_TTL, function () {
            return Entity::query()
                ->where('is_client', true)
                ->where('active', true)
                ->select('entities.id', 'entities.name', 'entities.code', 'entities.created_at')
                ->selectRaw('(SELECT COUNT(*) FROM patients WHERE patients.entity_id = entities.id AND patients.deleted_at IS NULL) as patients_count')
                ->orderByDesc('patients_count')
                ->limit(5)
                ->get();
        });
    }

    // ── Resumo de Parceiros ───────────────────────────────────────────────────

    public function getPartnersSummary(): array
    {
        return Cache::remember('mgr_dashboard.partners_summary', self::CACHE_TTL, function () {
            $leadsByStatus = [];

            foreach (PartnerLeadStatus::cases() as $status) {
                $leadsByStatus[$status->value] = PartnerLead::where('status', $status->value)->count();
            }

            return [
                'totalPartners' => Partner::count(),
                'totalLeads'    => PartnerLead::count(),
                'leadsActive'   => PartnerLead::whereIn('status', [
                    PartnerLeadStatus::New->value,
                    PartnerLeadStatus::Contacted->value,
                    PartnerLeadStatus::Trial->value,
                ])->count(),
                'leadsConverted'      => PartnerLead::where('status', PartnerLeadStatus::Converted->value)->count(),
                'pendingCommissions'  => PartnerCommission::where('status', CommissionStatus::Pending->value)->sum('amount'),
                'activeReferralCodes' => ReferralCode::where('active', true)->count(),
                'totalReferralEvents' => ReferralEvent::count(),
                'leadsByStatus'       => $leadsByStatus,
            ];
        });
    }

    // ── Cache invalidation ────────────────────────────────────────────────────

    public function flushCache(): void
    {
        $keys = [
            'mgr_dashboard.primary_kpis',
            'mgr_dashboard.subscription_kpis',
            'mgr_dashboard.financial_kpis',
            'mgr_dashboard.mrr_trend',
            'mgr_dashboard.conversion_funnel',
            'mgr_dashboard.growth_trend',
            'mgr_dashboard.top_entities',
            'mgr_dashboard.partners_summary',
        ];

        foreach ($keys as $key) {
            Cache::forget($key);
        }
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function computeMrr(): float
    {
        return (float) Subscription::query()
            ->where('subscriptions.status', SubscriptionStatus::Active->value)
            ->join('plans', 'subscriptions.plan_id', '=', 'plans.id')
            ->sum('plans.price');
    }

    private function batchActivationScores(array $entityIds, ActivationService $activationService): array
    {
        $scores = [];

        foreach ($entityIds as $id) {
            $scores[$id] = $activationService->getScore($id);
        }

        return $scores;
    }

    private function monthExpression(string $column): string
    {
        return match (config('database.default')) {
            'pgsql'  => "TO_CHAR({$column}, 'YYYY-MM')",
            'sqlite' => "strftime('%Y-%m', {$column})",
            default  => "DATE_FORMAT({$column}, '%Y-%m')",
        };
    }
}
