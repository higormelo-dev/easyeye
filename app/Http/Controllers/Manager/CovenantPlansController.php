<?php

namespace App\Http\Controllers\Manager;

use App\Enums\CovenantSource;
use App\Http\Controllers\Controller;
use App\Http\Requests\Manager\CovenantPlanRequest;
use App\Models\{Covenant, CovenantPlan};
use App\Services\Audit\AuditLogger;
use App\Support\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\{JsonResponse, Request};
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Planos dos convênios do catálogo GLOBAL (manager → Convênios → detalhes).
 *
 * Plano da ANS: só leitura (vem da sincronização). Plano manual: para
 * convênio fora da ANS (institutos de servidores públicos, por exemplo) ou
 * plano que ainda não está nos dados abertos — cadastro, edição, ativação e
 * exclusão sem uso. Planos próprios das clínicas não aparecem aqui.
 *
 * JSON (a gaveta busca sob demanda) e sem route model binding (o EntityScope
 * fica inerte no manager).
 */
class CovenantPlansController extends Controller
{
    private const STATUSES = ['active', 'inactive'];

    public function __construct(
        private readonly TenantContext $tenant,
        private readonly AuditLogger $audit,
    ) {
    }

    public function index(Request $request, string $covenant): JsonResponse
    {
        $model  = $this->globalCovenant($covenant);
        $search = $request->string('search')->trim()->value();
        $status = in_array($request->input('status'), self::STATUSES, true) ? $request->input('status') : '';

        $plans = $this->globalPlans()
            ->where('covenant_plans.covenant_id', $model->id)
            ->when($search !== '', function (Builder $q) use ($search) {
                $term = addcslashes($search, '\\%_');

                $q->where(fn (Builder $w) => $w->whereLikeUnaccent('covenant_plans.name', $term)
                    ->orWhere('covenant_plans.ans_code', 'like', $term . '%'));
            })
            ->when($status === 'active', fn (Builder $q) => $q->where('covenant_plans.active', true))
            ->when($status === 'inactive', fn (Builder $q) => $q->where('covenant_plans.active', false))
            ->orderByDesc('covenant_plans.active')
            ->orderBy('covenant_plans.name')
            ->orderBy('covenant_plans.id')
            ->paginate(15)
            ->through(fn (CovenantPlan $p) => $this->toRow($p));

        return response()->json([
            ...$plans->toArray(),
            'counts' => [
                'active' => $this->globalPlans()->where('covenant_id', $model->id)->where('active', true)->count(),
                'total'  => $this->globalPlans()->where('covenant_id', $model->id)->count(),
            ],
        ]);
    }

    public function store(CovenantPlanRequest $request, string $covenant): JsonResponse
    {
        $model = $this->globalCovenant($covenant);
        $data  = $request->validated();

        // Particular não tem plano (o cadastro do paciente zera).
        abort_if($model->isParticular(), 422, __('covenant_plans.particular'));

        $plan = $this->tenant->withoutScope(function () use ($model, $data) {
            $plan            = new CovenantPlan([...$data, 'covenant_id' => $model->id]);
            $plan->entity_id = null;
            $plan->source    = CovenantSource::Manual;
            $plan->save();

            return $plan;
        });

        return response()->json(['message' => __('covenant_plans.saved'), 'data' => $this->toRow($plan)], 201);
    }

    public function update(CovenantPlanRequest $request, string $plan): JsonResponse
    {
        $model = $this->globalPlans()->findOrFail($plan);

        // Dados da ANS vêm da sincronização (situação define se é escolhível).
        abort_if($model->source === CovenantSource::Ans, 422, __('covenant_plans.ans_readonly'));

        $this->tenant->withoutScope(fn () => $model->update($request->validated()));

        return response()->json(['message' => __('covenant_plans.saved'), 'data' => $this->toRow($model->fresh())]);
    }

    public function destroy(Request $request, string $plan): JsonResponse
    {
        $model = $this->globalPlans()->findOrFail($plan);

        abort_if($model->source === CovenantSource::Ans, 422, __('covenant_plans.ans_readonly'));

        if (DB::table('patients')->where('covenant_plan_id', $model->id)->exists()) {
            throw ValidationException::withMessages(['reason' => __('covenant_plans.in_use')]);
        }

        // Mesma regra das demais exclusões do manager: justificativa + trilha.
        $request->validate([
            'reason' => ['required', 'string', 'min:20', 'max:1000'],
        ], [
            'reason.required' => __('manager_hardening.reason_required'),
            'reason.min'      => __('manager_hardening.reason_min', ['min' => 20]),
            'reason.max'      => __('manager_hardening.reason_max', ['max' => 1000]),
        ]);

        $this->tenant->withoutScope(fn () => $model->delete());

        $this->audit->recordAdminAction(
            event: 'manager.covenant_plan.destroy',
            targetEntityId: null,
            targetUserId: null,
            auditableType: 'covenant_plan',
            auditableId: (string) $model->id,
            reason: trim((string) $request->input('reason')),
            newValues: ['name' => $model->name, 'covenant_id' => $model->covenant_id],
            request: $request,
        );

        return response()->json(['message' => __('covenant_plans.deleted')]);
    }

    private function globalCovenant(string $id): Covenant
    {
        return Covenant::withoutGlobalScopes()->whereNull('entity_id')->whereNull('deleted_at')->findOrFail($id);
    }

    /** @return Builder<CovenantPlan> */
    private function globalPlans(): Builder
    {
        return CovenantPlan::withoutGlobalScopes()->whereNull('covenant_plans.entity_id')->whereNull('covenant_plans.deleted_at');
    }

    /** @return array<string, mixed> */
    private function toRow(CovenantPlan $p): array
    {
        return [
            ...$p->toOption(),
            'regulation_label'  => $p->regulation ? __('covenant_plans.regulation_' . $p->regulation) : null,
            'ans_status_at'     => $p->ans_status_at?->isoFormat('L'),
            'ans_registered_at' => $p->ans_registered_at?->isoFormat('L'),
            'source'            => ($p->source ?? CovenantSource::Manual)->value,
            'source_label'      => ($p->source ?? CovenantSource::Manual)->label(),
        ];
    }
}
