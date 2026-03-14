<?php

namespace App\Http\Controllers\Manager;

use App\DataTables\PlansDataTable;
use App\Enums\BillingCycle;
use App\Enums\FeatureKey;
use App\Http\Controllers\Controller;
use App\Http\Requests\Manager\PlanRequest;
use App\Models\Plan;
use App\Models\PlanFeature;
use Illuminate\Contracts\View\{Factory, View};
use Illuminate\Foundation\Application;
use Illuminate\Http\{JsonResponse, RedirectResponse, Request};
use Illuminate\Support\Str;

class PlansController extends Controller
{
    protected string $titleController = 'Planos';

    public function index(PlansDataTable $dataTable): Factory|Application|View|JsonResponse
    {
        $meta = [
            'title'       => $this->titleController,
            'action'      => __('actions.records'),
            'breadcrumbs' => [
                ['label' => __('actions.sidemenu.dashboard'), 'url' => route('panel.dashboard'), 'active' => false],
                ['label' => $this->titleController, 'url' => route('panel.manager.plans.index'), 'active' => false],
                ['label' => __('actions.records'), 'url' => 'javascript:void(0);', 'active' => true],
            ],
        ];

        $features      = FeatureKey::cases();
        $billingCycles = BillingCycle::cases();
        $storeUrl      = route('panel.manager.plans.store');
        $baseUrl       = url('panel/manager/plans');

        return $dataTable->render('system.manager.plans.index', compact('meta', 'features', 'billingCycles', 'storeUrl', 'baseUrl'));
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

    public function show(Plan $plan): \Illuminate\View\View|JsonResponse
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

        return view('system.manager.plans.show', compact('plan', 'featuresMap'));
    }

    public function update(PlanRequest $request, Plan $plan): JsonResponse
    {
        // Toggle rápido de active (vindo do btn-active da DataTable)
        if ($request->has('active') && $request->keys() === ['active']) {
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
