<?php

namespace App\Http\Controllers\Manager;

use App\DataTables\PlansDataTable;
use App\DTOs\ActionPolicy;
use App\Enums\{BillingCycle, FeatureKey};
use App\Http\Controllers\Controller;
use App\Http\Requests\Manager\PlanRequest;
use App\Models\{Plan, PlanFeature};
use Illuminate\Contracts\View\{Factory, View};
use Illuminate\Foundation\Application;
use Illuminate\Http\{JsonResponse, Request};
use Illuminate\Support\Str;

class PlansController extends Controller
{
    protected string $titleController = 'Planos';

    public function index(PlansDataTable $dataTable): Factory|Application|View|JsonResponse
    {
        $total = Plan::count();

        $meta = [
            'title'            => $this->titleController,
            'action'           => __('actions.records'),
            'total'            => $total,
            'cardsUrl'         => route('panel.manager.plans.cards'),
            'breadcrumb_title' => false,
            'breadcrumbs'      => [
                ['label' => __('actions.sidemenu.dashboard'), 'url' => route('panel.dashboard'), 'active' => false],
                ['label' => $this->titleController, 'url' => route('panel.manager.plans.index'), 'active' => true],
            ],
        ];

        $features      = FeatureKey::cases();
        $billingCycles = BillingCycle::cases();
        $storeUrl      = route('panel.manager.plans.store');
        $baseUrl       = url('panel/manager/plans');

        return $dataTable->render('system.manager.plans.index', compact('meta', 'features', 'billingCycles', 'storeUrl', 'baseUrl'));
    }

    public function cards(Request $request): JsonResponse
    {
        $search  = $request->string('search')->trim()->value();
        $perPage = 12;

        $records = Plan::when($search, fn ($q) => $q->whereRaw('LOWER(name) LIKE ?', ['%' . mb_strtolower($search, 'UTF-8') . '%']))
            ->orderBy('sort_order')
            ->paginate($perPage);

        return response()->json([
            'data' => $records->map(fn ($r) => [
                'id'            => $r->id,
                'name'          => $r->name,
                'description'   => $r->description,
                'price'         => 'R$ ' . number_format((float) $r->price, 2, ',', '.'),
                'billing_cycle' => $r->billing_cycle->label(),
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
                ['value' => (string) $value],
            );
        }

        $plan->features()->whereNotIn('feature', array_keys($features))->delete();
    }
}
