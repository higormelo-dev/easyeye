<?php

declare(strict_types=1);

namespace App\Http\Controllers\Manager;

use App\Enums\{CommissionStatus, PartnerLeadStatus, SubscriptionStatus};
use App\Http\Controllers\Controller;
use App\Models\{Doctor, Entity, MedicalRecord, Partner, PartnerCommission, PartnerLead, Patient, ReferralCode, ReferralEvent, Schedule, Subscription, User};
use App\Services\ActivationService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class ManagerDashboardController extends Controller
{
    public function __invoke(ActivationService $activationService): View
    {
        $now   = Carbon::now();
        $today = $now->toDateString();

        // ── KPIs Primários ──────────────────────────────────────────────
        $totalEntities       = Entity::where('is_client', true)->count();
        $totalEntitiesActive = Entity::where('is_client', true)->where('active', true)->count();
        $totalPatients       = Patient::count();
        $totalDoctors        = Doctor::where('active', true)->count();
        $totalSchedules      = Schedule::count();
        $totalMedicalRecords = MedicalRecord::count();
        $totalUsers          = User::count();

        // ── Assinaturas por Status ──────────────────────────────────────
        $subscriptionsByStatus = Subscription::query()
            ->select('status', DB::raw('COUNT(*) as total'))
            ->groupBy('status')
            ->pluck('total', 'status')
            ->toArray();

        $subscriptionCounts = [];

        foreach (SubscriptionStatus::cases() as $status) {
            $subscriptionCounts[$status->value] = $subscriptionsByStatus[$status->value] ?? 0;
        }

        $totalSubscriptions = array_sum($subscriptionCounts);

        // ── MRR Estimado ────────────────────────────────────────────────
        $mrr = Subscription::query()
            ->where('status', SubscriptionStatus::Active->value)
            ->join('plans', 'subscriptions.plan_id', '=', 'plans.id')
            ->sum('plans.price');

        // ── Crescimento Mensal (últimos 6 meses) ────────────────────────
        $sixMonthsAgo  = $now->copy()->subMonths(5)->startOfMonth();
        $monthlyGrowth = Entity::query()
            ->where('is_client', true)
            ->where('created_at', '>=', $sixMonthsAgo)
            ->select(DB::raw("TO_CHAR(created_at, 'YYYY-MM') as month"), DB::raw('COUNT(*) as total'))
            ->groupBy('month')
            ->orderBy('month')
            ->pluck('total', 'month')
            ->toArray();

        // Preencher meses sem dados com 0
        $growthLabels = [];
        $growthValues = [];

        for ($i = 5; $i >= 0; $i--) {
            $month          = $now->copy()->subMonths($i)->format('Y-m');
            $label          = $now->copy()->subMonths($i)->translatedFormat('M/y');
            $growthLabels[] = $label;
            $growthValues[] = $monthlyGrowth[$month] ?? 0;
        }

        // ── Top 5 Clínicas por Pacientes ────────────────────────────────
        $topEntities = Entity::query()
            ->where('is_client', true)
            ->where('active', true)
            ->select('entities.id', 'entities.name', 'entities.code', 'entities.created_at')
            ->selectRaw('(SELECT COUNT(*) FROM patients WHERE patients.entity_id = entities.id AND patients.deleted_at IS NULL) as patients_count')
            ->orderByDesc('patients_count')
            ->limit(5)
            ->get();

        // ── Trials Expirando em 7 Dias ──────────────────────────────────
        $trialsExpiring = Subscription::query()
            ->where('status', SubscriptionStatus::Trial->value)
            ->whereBetween('trial_ends_at', [$today, $now->copy()->addDays(7)->toDateString()])
            ->with(['entity:id,name,code', 'plan:id,name'])
            ->orderBy('trial_ends_at')
            ->limit(10)
            ->get();

        // Enriquecer com score de ativação
        $trialsExpiring->each(function ($sub) use ($activationService) {
            $sub->activation_score = $sub->entity
                ? $activationService->getScore($sub->entity->id)
                : 0;
        });

        // ── Últimas 5 Entities Criadas ──────────────────────────────────
        $recentEntities = Entity::query()
            ->where('is_client', true)
            ->with(['subscriptions' => fn ($q) => $q->latest()->limit(1), 'subscriptions.plan:id,name'])
            ->orderByDesc('created_at')
            ->limit(5)
            ->get(['id', 'name', 'code', 'active', 'created_at']);

        $recentEntities->each(function ($entity) use ($activationService) {
            $entity->activation_score = $activationService->getScore($entity->id);
            $entity->latest_sub       = $entity->subscriptions->first();
        });

        // ── Parceiros ───────────────────────────────────────────────────
        $totalPartners = Partner::count();
        $totalLeads    = PartnerLead::count();
        $leadsActive   = PartnerLead::whereIn('status', [
            PartnerLeadStatus::New->value,
            PartnerLeadStatus::Contacted->value,
            PartnerLeadStatus::Trial->value,
        ])->count();
        $leadsConverted     = PartnerLead::where('status', PartnerLeadStatus::Converted->value)->count();
        $pendingCommissions = PartnerCommission::where('status', CommissionStatus::Pending->value)->sum('amount');

        $leadsByStatus = [];

        foreach (PartnerLeadStatus::cases() as $status) {
            $leadsByStatus[$status->value] = PartnerLead::where('status', $status->value)->count();
        }

        // ── Referrals ───────────────────────────────────────────────────
        $activeReferralCodes = ReferralCode::where('active', true)->count();
        $totalReferralEvents = ReferralEvent::count();

        // ── Novos Clientes Hoje / Semana / Mês ──────────────────────────
        $newEntitiesToday = Entity::where('is_client', true)
            ->whereDate('created_at', $today)
            ->count();
        $newEntitiesWeek = Entity::where('is_client', true)
            ->where('created_at', '>=', $now->copy()->startOfWeek())
            ->count();
        $newEntitiesMonth = Entity::where('is_client', true)
            ->where('created_at', '>=', $now->copy()->startOfMonth())
            ->count();

        // ── Agendamentos Hoje (Global) ──────────────────────────────────
        $schedulesToday = Schedule::whereDate('date_time', $today)->count();

        // ── Greeting ────────────────────────────────────────────────────
        $hour     = (int) $now->format('H');
        $greeting = match (true) {
            $hour < 12 => __('manager_dashboard.good_morning'),
            $hour < 18 => __('manager_dashboard.good_afternoon'),
            default    => __('manager_dashboard.good_evening'),
        };

        $stats = compact(
            'totalEntities',
            'totalEntitiesActive',
            'totalPatients',
            'totalDoctors',
            'totalSchedules',
            'totalMedicalRecords',
            'totalUsers',
            'subscriptionCounts',
            'totalSubscriptions',
            'mrr',
            'growthLabels',
            'growthValues',
            'topEntities',
            'trialsExpiring',
            'recentEntities',
            'totalPartners',
            'totalLeads',
            'leadsActive',
            'leadsConverted',
            'pendingCommissions',
            'leadsByStatus',
            'activeReferralCodes',
            'totalReferralEvents',
            'newEntitiesToday',
            'newEntitiesWeek',
            'newEntitiesMonth',
            'schedulesToday',
            'greeting',
        );

        return view('system.manager.dashboard', compact('stats'));
    }
}
