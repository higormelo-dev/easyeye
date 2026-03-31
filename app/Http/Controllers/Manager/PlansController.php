<?php

namespace App\Http\Controllers\Manager;

use App\Enums\BillingCycle;
use App\Enums\FeatureKey;
use App\Http\Controllers\Controller;
use App\Http\Requests\Manager\PlanRequest;
use App\Models\Plan;
use App\Models\PlanFeature;
use Illuminate\Http\{JsonResponse, Request};
use Illuminate\Support\Str;
use Inertia\Inertia;

class PlansController extends Controller
{
    protected string $titleController = 'Planos';

    public function index(): \Inertia\Response
    {
        $features = collect(FeatureKey::cases())->map(fn ($f) => [
            'key'        => $f->value,
            'label'      => $f->label(),
            'is_boolean' => $f->isBoolean(),
            'is_numeric' => $f->isNumeric(),
        ]);

        $billingCycles = collect(BillingCycle::cases())->map(fn ($c) => [
            'value' => $c->value,
            'label' => $c->label(),
        ]);

        return Inertia::render('Manager/Plans/Index', [
            'featureKeys'   => $features,
            'billingCycles' => $billingCycles,
        ]);
    }

    public function list(Request $request): JsonResponse
    {
        $search  = $request->string('search')->trim()->value();
        $perPage = 15;

        $plans = Plan::query()
            ->with('features')
            ->when($search, function ($q) use ($search) {
                $lower = mb_strtolower($search, 'UTF-8');
                $q->where(function ($inner) use ($lower) {
                    $inner->whereRaw('LOWER(name) LIKE ?', ["%{$lower}%"])
                          ->orWhereRaw('LOWER(description) LIKE ?', ["%{$lower}%"]);
                });
            })
            ->orderBy('sort_order')
            ->orderBy('created_at', 'desc')
            ->paginate($perPage);

        $data = $plans->map(fn (Plan $p) => [
            'id'            => $p->id,
            'name'          => $p->name,
            'slug'          => $p->slug,
            'description'   => $p->description,
            'price'         => $p->price,
            'billing_cycle' => $p->billing_cycle->value,
            'billing_label' => $p->billing_cycle->label(),
            'sort_order'    => $p->sort_order,
            'active'        => (bool) $p->active,
            'features'      => $p->features->pluck('value', 'feature')->toArray(),
        ]);

        return response()->json([
            'data' => $data,
            'meta' => [
                'total'        => $plans->total(),
                'per_page'     => $plans->perPage(),
                'current_page' => $plans->currentPage(),
                'last_page'    => $plans->lastPage(),
            ],
        ]);
    }

    public function store(PlanRequest $request): JsonResponse
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

        return response()->json([
            'message' => 'Plano criado com sucesso.',
            'data'    => $plan->load('features'),
        ], 201);
    }

    public function show(Plan $plan): \Inertia\Response|JsonResponse
    {
        $plan->load('features');
        $featuresMap = $plan->features()->pluck('value', 'feature')->toArray();

        if (request()->wantsJson()) {
            return response()->json(['data' => [
                'id'            => $plan->id,
                'name'          => $plan->name,
                'description'   => $plan->description,
                'price'         => $plan->price,
                'billing_cycle' => $plan->billing_cycle->value,
                'active'        => (bool) $plan->active,
                'sort_order'    => $plan->sort_order,
                'features'      => $featuresMap,
            ]]);
        }

        return Inertia::render('Manager/Plans/Show', [
            'plan'        => $plan,
            'featuresMap' => $featuresMap,
        ]);
    }

    public function update(PlanRequest $request, Plan $plan): JsonResponse
    {
        if ($request->has('active') && count($request->keys()) === 1) {
            $plan->update(['active' => $request->boolean('active')]);

            return response()->json(['message' => 'Status do plano atualizado.']);
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

        return response()->json([
            'message' => 'Plano atualizado com sucesso.',
            'data'    => $plan->fresh('features'),
        ]);
    }

    public function destroy(Plan $plan): JsonResponse
    {
        $plan->delete();

        return response()->json(['message' => 'Plano removido com sucesso.']);
    }

    private function syncFeatures(Plan $plan, array $features): void
    {
        foreach ($features as $key => $value) {
            if (! FeatureKey::tryFrom($key)) {
                continue;
            }

            PlanFeature::updateOrCreate(
                ['plan_id' => $plan->id, 'feature' => $key],
                ['value'   => (string) $value]
            );
        }

        $plan->features()->whereNotIn('feature', array_keys($features))->delete();
    }
}
