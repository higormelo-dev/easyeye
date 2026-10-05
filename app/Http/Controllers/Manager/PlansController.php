<?php

namespace App\Http\Controllers\Manager;

use App\DTOs\ActionPolicy;
use App\Enums\{BillingCycle, FeatureKey, SubscriptionBillingMode, SubscriptionStatus};
use App\Http\Controllers\Controller;
use App\Http\Requests\Manager\PlanRequest;
use App\Models\{Plan, PlanFeature, PlanPrice, Subscription, SubscriptionSetting};
use App\Services\Audit\AuditLogger;
use App\Services\Billing\CheckoutService;
use App\Support\Billing\PlanPricing;
use Illuminate\Http\{JsonResponse, RedirectResponse, Request};
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\{Inertia, Response};
use Ramsey\Uuid\Uuid;

class PlansController extends Controller
{
    protected string $titleController = 'Planos';

    public function __construct(
        private readonly AuditLogger $audit,
    ) {
    }

    public function index(Request $request): Response
    {
        $search  = $request->string('search')->trim()->value();
        $sortBy  = $request->string('sort', 'sort_order')->value();
        $sortDir = $request->string('direction', 'asc')->value();

        $allowedSorts = ['sort_order', 'name', 'price', 'billing_cycle', 'active'];
        $sortBy       = in_array($sortBy, $allowedSorts, true) ? $sortBy : 'sort_order';
        $sortDir      = in_array($sortDir, ['asc', 'desc'], true) ? $sortDir : 'asc';

        $query = Plan::query()->select('plans.*')->with('prices');

        if ($search !== '') {
            $query->whereLikeUnaccent('plans.name', $search);
        }

        $query->orderBy("plans.{$sortBy}", $sortDir);
        $plans       = $query->paginate(15)->withQueryString();
        $subscribers = $this->subscriberCounts($plans->pluck('id')->all());

        return Inertia::render('Panel/Manager/Plans/Index', [
            'plans'    => $plans->through(fn ($p) => $this->toRow($p, $subscribers[$p->id] ?? 0)),
            'total'    => fn () => Plan::count(),
            'filters'  => $request->only(['search', 'sort', 'direction']),
            'features' => collect(FeatureKey::cases())->map(fn ($f) => [
                'key'        => $f->value,
                'label'      => $f->label(),
                'is_boolean' => $f->isBoolean(),
                'is_numeric' => $f->isNumeric(),
                // 0 em créditos de IA = nenhum (nos demais limites, 0 = ilimitado).
                'zero_means_none' => $f === FeatureKey::AiMonthlyCredits,
            ])->values()->toArray(),
            'billingCycles' => $this->sellableCycles(),
            // Trial de toda empresa nova; ao fim dele o acesso é bloqueado.
            'trialDays' => SubscriptionSetting::trialDays(),
            // Teto de parcelas sem juros do checkout (cartão no ciclo anual).
            'checkoutMaxInstallments' => CheckoutService::maxInstallments(),
            't'                       => trans('manager_plans'),
        ]);
    }

    /**
     * Dias de trial de toda empresa nova (cadastro no site e no manager).
     * Não há período de graça: acabou o trial, o acesso é bloqueado até a
     * empresa contratar um plano.
     */
    public function updateTrialSettings(Request $request): JsonResponse
    {
        $validated = $request->validate(
            ['trial_days' => ['required', 'integer', 'min:1', 'max:365']],
            [],
            ['trial_days' => __('manager_plans.trial_days')],
        );

        $old  = SubscriptionSetting::trialDays();
        $days = (int) $validated['trial_days'];

        SubscriptionSetting::setValue('trial_days', $days, 'Duração do período de trial em dias');

        $this->audit->recordAdminAction(
            event: 'manager.settings.trial_days',
            targetEntityId: null,
            targetUserId: null,
            auditableType: 'system_setting',
            // auditable_id é uuid; deriva um UUID estável da chave do setting.
            auditableId: Uuid::uuid5(Uuid::NAMESPACE_OID, 'trial_days')->toString(),
            reason: __('manager_plans.trial_audit_reason'),
            newValues: ['trial_days' => $days],
            request: $request,
            oldValues: ['trial_days' => $old],
        );

        return response()->json([
            'message' => __('manager_plans.trial_saved'),
            'data'    => ['trial_days' => $days],
        ]);
    }

    /**
     * Máximo de parcelas sem juros do checkout transparente (cartão no ciclo
     * anual; o EasyEye absorve a taxa). Sobrescreve
     * billing.checkout.max_installments.
     */
    public function updateCheckoutSettings(Request $request): JsonResponse
    {
        $validated = $request->validate(
            ['max_installments' => ['required', 'integer', 'min:1', 'max:12']],
            [],
            ['max_installments' => __('manager_plans.checkout_max_installments')],
        );

        $old = CheckoutService::maxInstallments();
        $max = (int) $validated['max_installments'];

        SubscriptionSetting::setValue('checkout_max_installments', $max, 'Máximo de parcelas sem juros no checkout (cartão, ciclo anual)');

        $this->audit->recordAdminAction(
            event: 'manager.settings.checkout_max_installments',
            targetEntityId: null,
            targetUserId: null,
            auditableType: 'system_setting',
            auditableId: Uuid::uuid5(Uuid::NAMESPACE_OID, 'checkout_max_installments')->toString(),
            reason: __('manager_plans.checkout_audit_reason'),
            newValues: ['checkout_max_installments' => $max],
            request: $request,
            oldValues: ['checkout_max_installments' => $old],
        );

        return response()->json([
            'message' => __('manager_plans.checkout_saved'),
            'data'    => ['max_installments' => $max],
        ]);
    }

    public function cards(Request $request): JsonResponse
    {
        $search  = $request->string('search')->trim()->value();
        $perPage = 12;

        $records = Plan::with('prices')
            ->when($search, fn ($q) => $q->whereLikeUnaccent('name', $search))
            ->orderBy('sort_order')
            ->paginate($perPage);

        $subscribers = $this->subscriberCounts($records->pluck('id')->all());

        return response()->json([
            'data' => $records->map(fn ($r) => $this->toRow($r, $subscribers[$r->id] ?? 0)),
            'meta' => [
                'total'        => $records->total(),
                'per_page'     => $records->perPage(),
                'current_page' => $records->currentPage(),
                'last_page'    => $records->lastPage(),
            ],
        ]);
    }

    public function show(Plan $plan): JsonResponse
    {
        $plan->load('features', 'prices');
        $featuresMap = $plan->features->mapWithKeys(fn (PlanFeature $f) => [$f->feature->value => $f->value])->toArray();

        $featuresDisplay = $plan->features->map(fn (PlanFeature $f) => [
            'key'             => $f->feature->value,
            'label'           => $f->feature->label(),
            'value'           => $f->value,
            'is_boolean'      => $f->feature->isBoolean(),
            'zero_means_none' => $f->feature === FeatureKey::AiMonthlyCredits,
        ])->values()->toArray();

        return response()->json(['data' => [
            'id'               => $plan->id,
            'name'             => $plan->name,
            'slug'             => $plan->slug,
            'description'      => $plan->description,
            'price'            => (float) $plan->price,
            'billing_cycle'    => $plan->billing_cycle->value,
            'billing_label'    => $plan->billing_cycle->label(),
            'prices'           => PlanPricing::cycles($plan),
            'active'           => (bool) $plan->active,
            'is_featured'      => (bool) $plan->is_featured,
            'sort_order'       => $plan->sort_order,
            'created_at'       => $plan->created_at?->toIso8601String(),
            'features'         => $featuresMap,
            'features_display' => $featuresDisplay,
            'subscribers'      => $this->subscriberBreakdown($plan),
        ]]);
    }

    public function store(PlanRequest $request): JsonResponse|RedirectResponse
    {
        $plan = DB::transaction(function () use ($request): Plan {
            $plan = Plan::create([
                ...$this->planAttributes($request),
                'slug'   => $this->uniqueSlug((string) $request->name),
                'active' => $request->boolean('active', true),
            ]);

            $this->syncPrices($plan, $request->input('prices', []));
            $this->syncFeatures($plan, $request->input('features', []));

            return $plan;
        });

        if ($request->wantsJson()) {
            return response()->json(['message' => __('manager_plans.flash_created'), 'data' => $plan->load('features', 'prices')], 201);
        }

        return redirect()->route('manager.plans.index')
            ->with('success', __('manager_plans.flash_created'));
    }

    public function update(PlanRequest $request, Plan $plan): JsonResponse|RedirectResponse
    {
        if ($request->isToggle()) {
            $plan->update(['active' => $request->boolean('active')]);

            if ($request->wantsJson()) {
                return response()->json(['message' => __('manager_plans.flash_status_updated')]);
            }

            return redirect()->route('manager.plans.index')
                ->with('success', __('manager_plans.flash_status_updated'));
        }

        DB::transaction(function () use ($request, $plan): void {
            // O slug não muda ao renomear: o site e migrações de dados se
            // referem ao plano por ele.
            $plan->update([
                ...$this->planAttributes($request),
                'active'     => $request->boolean('active'),
                'sort_order' => $request->sort_order ?? $plan->sort_order,
            ]);

            $this->syncPrices($plan, $request->input('prices', []));
            $this->syncFeatures($plan, $request->input('features', []));
        });

        if ($request->wantsJson()) {
            return response()->json(['message' => __('manager_plans.flash_updated'), 'data' => $plan->fresh(['features', 'prices'])]);
        }

        return redirect()->route('manager.plans.index')
            ->with('success', __('manager_plans.flash_updated'));
    }

    public function destroy(Request $request, Plan $plan): JsonResponse|RedirectResponse
    {
        $request->validate([
            'reason' => ['required', 'string', 'min:20', 'max:1000'],
        ], [
            'reason.required' => __('manager_hardening.reason_required'),
            'reason.min'      => __('manager_hardening.reason_min', ['min' => 20]),
            'reason.max'      => __('manager_hardening.reason_max', ['max' => 1000]),
        ]);

        $plan->delete();

        $this->audit->recordAdminAction(
            event: 'manager.plan.destroy',
            targetEntityId: null,
            targetUserId: null,
            auditableType: 'plan',
            auditableId: (string) $plan->id,
            reason: trim((string) $request->input('reason')),
            newValues: ['name' => $plan->name, 'price' => (float) $plan->price],
            request: $request,
        );

        if ($request->wantsJson()) {
            return response()->json(['message' => __('manager_plans.flash_deleted')]);
        }

        return redirect()->route('manager.plans.index')
            ->with('success', __('manager_plans.flash_deleted'));
    }

    // ── Persistência ────────────────────────────────────────────────────────

    /**
     * Campos do plano vindos do formulário. `price` é o preço do ciclo padrão
     * (referência para quem ainda lê o plano direto).
     *
     * @return array<string, mixed>
     */
    private function planAttributes(PlanRequest $request): array
    {
        $prices = collect($request->input('prices', []))->pluck('price', 'billing_cycle');

        return [
            'name'          => $request->name,
            'description'   => $request->description,
            'billing_cycle' => $request->billing_cycle,
            'price'         => round((float) ($prices[$request->billing_cycle] ?? 0), 2),
            'is_featured'   => $request->boolean('is_featured'),
            'sort_order'    => $request->sort_order ?? 0,
        ];
    }

    /** @param array<int, array{billing_cycle: string, price: numeric}> $prices */
    private function syncPrices(Plan $plan, array $prices): void
    {
        $offered = [];

        foreach ($prices as $row) {
            $offered[] = $row['billing_cycle'];

            PlanPrice::updateOrCreate(
                ['plan_id' => $plan->id, 'billing_cycle' => $row['billing_cycle']],
                ['price' => round((float) $row['price'], 2)],
            );
        }

        // Um a um para a auditoria registrar o ciclo removido.
        $plan->prices()->whereNotIn('billing_cycle', $offered)->get()->each->delete();
        $plan->unsetRelation('prices');
    }

    private function syncFeatures(Plan $plan, array $features): void
    {
        foreach ($features as $key => $value) {
            if (! FeatureKey::tryFrom($key)) {
                continue;
            }

            PlanFeature::updateOrCreate(
                ['plan_id' => $plan->id, 'feature' => $key],
                ['value' => (string) (int) $value],
            );
        }

        $plan->features()->whereNotIn('feature', array_keys($features))->delete();
    }

    /** Slug único (inclusive entre planos removidos), gerado só na criação. */
    private function uniqueSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'plano';
        $slug = $base;

        for ($i = 2; Plan::withTrashed()->where('slug', $slug)->exists(); $i++) {
            $slug = "{$base}-{$i}";
        }

        return $slug;
    }

    // ── Apresentação ────────────────────────────────────────────────────────

    private function toRow(Plan $p, int $subscribers): array
    {
        $policy = ActionPolicy::forManager($p);

        return [
            'id'            => $p->id,
            'name'          => $p->name,
            'description'   => $p->description,
            'price_raw'     => (float) $p->price,
            'billing_cycle' => $p->billing_cycle->value,
            'billing_label' => $p->billing_cycle->label(),
            'prices'        => PlanPricing::cycles($p),
            'active'        => (bool) $p->active,
            'is_featured'   => (bool) $p->is_featured,
            'sort_order'    => $p->sort_order,
            'subscribers'   => $subscribers,
            'mode'          => $policy->mode,
            'deleted'       => $policy->deleted,
        ];
    }

    /** @return list<array{value: string, label: string, months: int}> */
    private function sellableCycles(): array
    {
        return array_map(static fn (BillingCycle $c) => [
            'value'  => $c->value,
            'label'  => $c->label(),
            'months' => $c->months(),
        ], PlanPrice::SELLABLE_CYCLES);
    }

    /**
     * Empresas usando o plano agora: a assinatura mais recente de cada uma,
     * com acesso (trial, ativa ou no período de graça).
     *
     * @param list<string> $planIds
     *
     * @return array<string, int>
     */
    private function subscriberCounts(array $planIds): array
    {
        if ($planIds === []) {
            return [];
        }

        return Subscription::query()
            ->latestPerEntity()
            ->accessible()
            ->whereIn('plan_id', $planIds)
            ->selectRaw('plan_id, count(*) as total')
            ->groupBy('plan_id')
            ->pluck('total', 'plan_id')
            ->map(fn ($total) => (int) $total)
            ->all();
    }

    /** @return array<string, int> */
    private function subscriberBreakdown(Plan $plan): array
    {
        $rows = Subscription::query()
            ->latestPerEntity()
            ->accessible()
            ->where('plan_id', $plan->id)
            ->get(['id', 'status', 'billing_mode', 'ends_at']);

        $count = fn (callable $filter) => $rows->filter($filter)->count();

        return [
            'total'         => $rows->count(),
            'trial'         => $count(fn (Subscription $s) => $s->status === SubscriptionStatus::Trial),
            'gateway'       => $count(fn (Subscription $s) => $s->status !== SubscriptionStatus::Trial && $s->billing_mode === SubscriptionBillingMode::Gateway),
            'complimentary' => $count(fn (Subscription $s) => $s->status !== SubscriptionStatus::Trial && $s->billing_mode === SubscriptionBillingMode::Complimentary),
        ];
    }
}
