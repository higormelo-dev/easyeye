<?php

namespace App\Http\Controllers;

use App\Enums\{ClientRule, FeatureKey, Permission, ScheduleSituation};
use App\Models\{Doctor, Entity, EntityProduct, Patient, Schedule};
use App\Services\{ActivationService, FeatureGateService};
use Carbon\Carbon;
use Illuminate\Http\Request;
use Inertia\{Inertia, Response};

class PanelDashboardController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $entityId   = session('selected_entity_id');
        $today      = now()->toDateString();
        $doneValues = [
            ScheduleSituation::Attended->value,
            ScheduleSituation::NoShow->value,
            ScheduleSituation::Cancelled->value,
        ];

        return Inertia::render('Panel/Dashboard', [
            'stats'           => fn () => $this->buildStats($entityId, $today, $doneValues),
            'scheduleToday'   => fn () => $this->buildScheduleToday($entityId, $today),
            'recentPatients'  => fn () => $this->buildRecentPatients($entityId),
            'activation'      => fn () => $this->buildActivation($entityId),
            'activationScore' => fn () => app(ActivationService::class)->getScore($entityId),
            // GAP fechado (revisão pós-Fase 4): estoque tinha alerta próprio
            // (Notice + StockAlertService, ver stock:check-alerts) mas
            // nenhuma presença no Dashboard — quem não abre o mural de
            // recados nunca via nada. null quando a clínica não usa o
            // módulo, MESMO critério de visibilidade de
            // App\Support\PanelNavigation (admin OU permission stock.manage,
            // E feature has_inventory_module) — se o menu Estoque não
            // aparece pro usuário, o card também não aparece.
            'stockAlerts' => fn () => $this->buildStockAlerts($entityId),
            't'           => trans('dashboard'),
        ]);
    }

    private function buildStockAlerts(string $entityId): ?array
    {
        $rule = session('selected_entity_user_rule');
        $user = auth()->user();

        if (! $user) {
            return null;
        }

        $isAdmin       = $rule === ClientRule::Admin->value;
        $entity        = Entity::find($entityId);
        $hasPermission = $entity && $user->hasPermissionInEntity($entity, Permission::StockManage);
        $hasFeature    = app(FeatureGateService::class)->can($entityId, FeatureKey::HasInventoryModule);

        if (! $entity || ! ($isAdmin || $hasPermission) || ! $hasFeature) {
            return null;
        }

        $belowMinimumCount = EntityProduct::where('entity_id', $entityId)->active()->belowMinimum()->count();
        $expiringLotsCount = EntityProduct::where('entity_id', $entityId)->active()->withExpiringLots(30)->count();

        if ($belowMinimumCount === 0 && $expiringLotsCount === 0) {
            return null; // nada crítico — card nem aparece, sem "0 alertas" vazio ocupando espaço
        }

        return [
            'below_minimum_count' => $belowMinimumCount,
            'expiring_lots_count' => $expiringLotsCount,
            'products_url'        => route('panel.stock.products.index', ['low_stock' => 1]),
            'expiring_url'        => route('panel.stock.products.index', ['expiring_lots' => 1]),
        ];
    }

    private function buildStats(string $entityId, string $today, array $doneValues): array
    {
        return [
            'entity_name'    => Entity::find($entityId)?->name ?? config('app.name'),
            'total_patients' => Patient::where('entity_id', $entityId)->where('active', true)->count(),
            'today_count'    => Schedule::where('entity_id', $entityId)->whereDate('date_time', $today)->count(),
            'total_doctors'  => Doctor::query()
                ->join('entity_users', 'entity_users.id', '=', 'doctors.entity_user_id')
                ->where('entity_users.entity_id', $entityId)
                ->where('doctors.active', true)
                ->count(),
            'pending_today' => Schedule::where('entity_id', $entityId)
                ->whereDate('date_time', $today)
                ->whereNotIn('situation', $doneValues)
                ->count(),
            'attended_today' => Schedule::where('entity_id', $entityId)
                ->whereDate('date_time', $today)
                ->where('situation', ScheduleSituation::Attended->value)
                ->count(),
            'cancelled_today' => Schedule::where('entity_id', $entityId)
                ->whereDate('date_time', $today)
                ->whereIn('situation', [
                    ScheduleSituation::NoShow->value,
                    ScheduleSituation::Cancelled->value,
                ])
                ->count(),
        ];
    }

    private function buildRecentPatients(string $entityId): array
    {
        return Patient::with('person')
            ->where('entity_id', $entityId)
            ->where('active', true)
            ->orderByDesc('created_at')
            ->limit(8)
            ->get()
            ->map(fn ($p) => [
                'id'      => $p->id,
                'name'    => $p->person?->full_name ?? '—',
                'phone'   => $p->person?->cellphone ?? $p->person?->telephone ?? '—',
                'code'    => $p->code,
                'initial' => mb_strtoupper(mb_substr($p->person?->full_name ?? '?', 0, 1)),
                'color'   => '#' . substr(md5($p->person?->full_name ?? '?'), 0, 6),
                'url'     => route('panel.patients.show', $p),
            ])
            ->values()
            ->toArray();
    }

    private function buildScheduleToday(string $entityId, string $today): array
    {
        return Schedule::query()
            ->select([
                'schedules.id',
                'schedules.date_time',
                'schedules.full_name',
                'schedules.situation',
                'schedules.arrived_at',
                'users.name as doctor_name',
            ])
            ->leftJoin('doctors', 'doctors.id', '=', 'schedules.doctor_id')
            ->leftJoin('entity_users', 'entity_users.id', '=', 'doctors.entity_user_id')
            ->leftJoin('users', 'users.id', '=', 'entity_users.user_id')
            ->where('schedules.entity_id', $entityId)
            ->whereDate('schedules.date_time', $today)
            ->whereNull('schedules.deleted_at')
            ->orderBy('schedules.date_time')
            ->limit(25)
            ->get()
            ->map(function ($s) {
                $sit = $s->situation instanceof ScheduleSituation
                    ? $s->situation
                    : ScheduleSituation::tryFrom((int) ($s->situation ?? 0));

                return [
                    'id'        => $s->id,
                    'time'      => Carbon::parse($s->date_time)->format('H:i'),
                    'name'      => $s->full_name ?? '—',
                    'doctor'    => $s->doctor_name ?? '—',
                    'situation' => $s->situation,
                    'label'     => $sit?->label() ?? '—',
                    'badge'     => $sit?->badgeClass() ?? 'bg-secondary',
                    'icon'      => $sit?->icon() ?? 'fa-circle',
                    'arrived'   => ! is_null($s->arrived_at),
                    'is_active' => $sit?->isActive() ?? false,
                ];
            })
            ->values()
            ->toArray();
    }

    private function buildActivation(string $entityId): array
    {
        return array_map(
            fn ($s) => [
                'key'      => $s['step'],
                'label'    => $s['label'],
                'done'     => $s['completed'],
                'weight'   => $s['weight'],
                'required' => $s['required'],
            ],
            app(ActivationService::class)->getProgress($entityId),
        );
    }
}
