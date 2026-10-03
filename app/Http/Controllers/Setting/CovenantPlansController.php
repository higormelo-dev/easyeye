<?php

declare(strict_types=1);

namespace App\Http\Controllers\Setting;

use App\Http\Requests\CovenantPlanRequest;
use App\Http\Resources\CovenantPlanResource;
use App\Models\Covenant;
use App\Services\CovenantPlanService;
use Illuminate\Database\Eloquent\{Builder, Model};

/**
 * Configurações → Convênios → Planos: planos PRÓPRIOS da clínica (convênio
 * próprio ou plano que ainda não está no catálogo global da ANS). Os ~66 mil
 * planos da ANS não são listados aqui — aparecem direto no cadastro do
 * paciente, filtrados pelo convênio.
 */
class CovenantPlansController extends BaseSettingController
{
    public function __construct(CovenantPlanService $service)
    {
        $this->titleController = __('covenant_plans.settings_title');
        $this->service         = $service;
        $this->resourceClass   = CovenantPlanResource::class;
        $this->routePrefix     = 'panel.setting.covenant-plans';
        $this->viewSlot        = 'covenant-plans';
        $this->crudFields      = ['covenant_id' => null, 'name' => '', 'ans_code' => '', 'active' => true];
        $this->tabsGroup       = CovenantsController::tabs();
    }

    protected function getColumns(): array
    {
        return [
            ['key' => 'code', 'label' => __('actions.code'), 'type' => 'code'],
            ['key' => 'name', 'label' => __('covenant_plans.field_name'), 'type' => 'text', 'sortable' => true],
            ['key' => 'covenant_name', 'label' => __('covenant_plans.field_covenant'), 'type' => 'text'],
            ['key' => 'ans_code', 'label' => __('covenant_plans.field_ans_code'), 'type' => 'text'],
        ];
    }

    protected function getFormFields(): array
    {
        return [
            ['key' => 'covenant_id', 'label' => __('covenant_plans.field_covenant'), 'type' => 'select', 'required' => true, 'options' => $this->covenantOptions()],
            ['key' => 'name', 'label' => __('covenant_plans.field_name'), 'type' => 'text', 'required' => true],
            ['key' => 'ans_code', 'label' => __('covenant_plans.field_ans_code'), 'type' => 'text', 'hint' => __('covenant_plans.field_ans_code_hint')],
        ];
    }

    /** Só os planos da clínica (os da ANS não são editáveis aqui). */
    protected function listQuery(string $search): Builder
    {
        return parent::listQuery($search)
            ->where('covenant_plans.entity_id', session('selected_entity_id'))
            ->with(['covenant' => fn ($q) => $q->withoutGlobalScopes()->select('id', 'name')]);
    }

    protected function serializeRecord(Model $record): array
    {
        $data                  = parent::serializeRecord($record);
        $data['covenant_name'] = $record->covenant?->name ?? '—';

        return $data;
    }

    /** @return list<array{value: string, label: string}> */
    private function covenantOptions(): array
    {
        $entityId = session('selected_entity_id');

        return Covenant::query()
            ->where(fn ($q) => $q->where('entity_id', $entityId)->orWhereNull('entity_id'))
            ->where('active', true)
            // Particular não tem plano.
            ->whereRaw("upper(trim(name)) <> 'PARTICULAR'")
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (Covenant $c) => ['value' => $c->id, 'label' => $c->name])
            ->all();
    }

    public function store(CovenantPlanRequest $request)
    {
        return $this->genericStore($request);
    }

    public function update(CovenantPlanRequest $request, string $id)
    {
        return $this->genericUpdate($request, $id);
    }
}
