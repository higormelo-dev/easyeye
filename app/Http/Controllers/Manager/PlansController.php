<?php

namespace App\Http\Controllers\Manager;

use App\DTOs\ActionPolicy;
use App\Enums\{BillingCycle, FeatureKey};
use App\Http\Controllers\Controller;
use App\Http\Requests\Manager\PlanRequest;
use App\Models\{Plan, PlanFeature};
use Illuminate\Http\{JsonResponse, RedirectResponse, Request};
use Illuminate\Support\Str;
use Inertia\{Inertia, Response};

class PlansController extends Controller
{
    protected string $titleController = 'Planos';

    public function index(Request $request): Response
    {
        $search  = $request->string('search')->trim()->value();
        $sortBy  = $request->string('sort', 'sort_order')->value();
        $sortDir = $request->string('direction', 'asc')->value();

        $allowedSorts = ['sort_order', 'name', 'price', 'billing_cycle', 'active'];
        $sortBy       = in_array($sortBy, $allowedSorts, true) ? $sortBy : 'sort_order';
        $sortDir      = in_array($sortDir, ['asc', 'desc'], true) ? $sortDir : 'asc';

        $query = Plan::query()->select('plans.*');

        if ($search !== '') {
            $lower = mb_strtolower($search, 'UTF-8');
            $query->whereRaw('LOWER(plans.name) LIKE ?', ["%{$lower}%"]);
        }

        $query->orderBy("plans.{$sortBy}", $sortDir);
        $plans = $query->paginate(15)->withQueryString();

        return Inertia::render('Panel/Manager/Plans/Index', [
            'plans'    => $plans->through(fn ($p) => $this->toTableRow($p)),
            'total'    => fn () => Plan::count(),
            'filters'  => $request->only(['search', 'sort', 'direction']),
            'features' => collect(FeatureKey::cases())->map(fn ($f) => [
                'key'        => $f->value,
                'label'      => $f->label(),
                'is_boolean' => $f->isBoolean(),
                'is_numeric' => $f->isNumeric(),
            ])->values()->toArray(),
            'billingCycles' => collect(BillingCycle::cases())->map(fn ($c) => [
                'value' => $c->value,
                'label' => $c->label(),
            ])->values()->toArray(),
            't' => trans('manager_plans'),
        ]);
    }

    public function cards(Request $request): JsonResponse
    {
        $search  = $request->string('search')->trim()->value();
        $perPage = 12;

        $records = Plan::when(
            $search,
            fn ($q) => $q->whereRaw('LOWER(name) LIKE ?', ['%' . mb_strtolower($search, 'UTF-8') . '%']),
        )
            ->orderBy('sort_order')
            ->paginate($perPage);

        return response()->json([
            'data' => $records->map(fn ($r) => [
                'id'            => $r->id,
                'name'          => $r->name,
                'description'   => $r->description,
                'price'         => 'R$ ' . number_format((float) $r->price, 2, ',', '.'),
                'billing_cycle' => $r->billing_cycle->label(),
                'sort_order'    => $r->sort_order,
                ...ActionPolicy::forManager($r)->toArray(),
            ]),
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
        $plan->load('features');
        $featuresMap = $plan->features()->pluck('value', 'feature')->toArray();

        $featuresDisplay = collect($plan->features)->map(function ($f) {
            $key = $f->feature instanceof FeatureKey ? $f->feature : FeatureKey::tryFrom($f->feature);

            return [
                'key'        => $key?->value ?? $f->feature,
                'label'      => $key?->label() ?? $f->feature,
                'value'      => $f->value,
                'is_boolean' => $key?->isBoolean() ?? false,
            ];
        })->values()->toArray();

        return response()->json(['data' => [
            'id'               => $plan->id,
            'name'             => $plan->name,
            'slug'             => $plan->slug,
            'description'      => $plan->description,
            'price'            => $plan->price,
            'price_formatted'  => 'R$ ' . number_format((float) $plan->price, 2, ',', '.'),
            'billing_cycle'    => $plan->billing_cycle->value,
            'billing_label'    => $plan->billing_cycle->label(),
            'active'           => (bool) $plan->active,
            'sort_order'       => $plan->sort_order,
            'created_at'       => $plan->created_at->format('d/m/Y H:i'),
            'features'         => $featuresMap,
            'features_display' => $featuresDisplay,
        ]]);
    }

    public function store(PlanRequest $request): JsonResponse|RedirectResponse
    {
        $plan = Plan::create([
            'name'          => $request->name,
            'slug'          => Str::slug($request->name),
            'description'   => $request->description,
            'price'         => $request->price ?? 0,
            'billing_cycle' => $request->billing_cycle,
            'active'        => $request->boolean('active', true),
            'sort_order'    => $request->sort_order ?? 0,
        ]);

        $this->syncFeatures($plan, $request->input('features', []));

        if ($request->wantsJson()) {
            return response()->json(['message' => 'Plano criado com sucesso.', 'data' => $plan->load('features')], 201);
        }

        return redirect()->route('manager.plans.index')
            ->with('success', 'Plano criado com sucesso.');
    }

    public function update(PlanRequest $request, Plan $plan): JsonResponse|RedirectResponse
    {
        $isToggle = $request->has('active') && count($request->keys()) === 1;

        if ($isToggle) {
            $plan->update(['active' => $request->boolean('active')]);

            if ($request->wantsJson()) {
                return response()->json(['message' => 'Status do plano atualizado.']);
            }

            return redirect()->route('manager.plans.index')
                ->with('success', 'Status do plano atualizado.');
        }

        $plan->update([
            'name'          => $request->name,
            'slug'          => Str::slug($request->name),
            'description'   => $request->description,
            'price'         => $request->price ?? 0,
            'billing_cycle' => $request->billing_cycle,
            'active'        => $request->boolean('active'),
            'sort_order'    => $request->sort_order ?? $plan->sort_order,
        ]);

        $this->syncFeatures($plan, $request->input('features', []));

        if ($request->wantsJson()) {
            return response()->json(['message' => 'Plano atualizado com sucesso.', 'data' => $plan->fresh('features')]);
        }

        return redirect()->route('manager.plans.index')
            ->with('success', 'Plano atualizado com sucesso.');
    }

    public function destroy(Plan $plan): JsonResponse|RedirectResponse
    {
        $plan->delete();

        if (request()->wantsJson()) {
            return response()->json(['message' => 'Plano removido com sucesso.']);
        }

        return redirect()->route('manager.plans.index')
            ->with('success', 'Plano removido com sucesso.');
    }

    private function syncFeatures(Plan $plan, array $features): void
    {
        foreach ($features as $key => $value) {
            if (! FeatureKey::tryFrom($key)) {
                continue;
            }

            PlanFeature::updateOrCreate(
                ['plan_id' => $plan->id, 'feature' => $key],
                ['value' => (string) $value],
            );
        }

        $plan->features()->whereNotIn('feature', array_keys($features))->delete();
    }

    private function toTableRow(Plan $p): array
    {
        $policy = ActionPolicy::forManager($p);

        return [
            'id'            => $p->id,
            'name'          => $p->name,
            'description'   => $p->description,
            'price'         => 'R$ ' . number_format((float) $p->price, 2, ',', '.'),
            'price_raw'     => $p->price,
            'billing_cycle' => $p->billing_cycle->value,
            'billing_label' => $p->billing_cycle->label(),
            'active'        => (bool) $p->active,
            'sort_order'    => $p->sort_order,
            'mode'          => $policy->mode,
            'deleted'       => $policy->deleted,
        ];
    }
}
