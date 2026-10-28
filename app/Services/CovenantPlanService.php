<?php

namespace App\Services;

use App\Models\CovenantPlan;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Planos próprios da clínica (Configurações → Convênios → Planos): convênio
 * próprio ou plano que ainda não está no catálogo global da ANS.
 */
class CovenantPlanService extends BaseSettingService
{
    protected function modelClass(): string
    {
        return CovenantPlan::class;
    }

    /** Total da tela: só os planos da clínica (os ~66 mil da ANS não são listados). */
    public function count(): int
    {
        return CovenantPlan::query()->where('entity_id', session('selected_entity_id'))->count();
    }

    protected function getCreateData(FormRequest $request): array
    {
        return [
            'covenant_id' => $request->covenant_id,
            'name'        => $request->name,
            'ans_code'    => $request->ans_code ?: null,
        ];
    }

    /** O convênio não muda depois de criado (pacientes apontam para o par convênio + plano). */
    protected function getUpdateData(FormRequest $request): array
    {
        $data = parent::getUpdateData($request);

        if ($request->has('ans_code')) {
            $data['ans_code'] = $request->ans_code ?: null;
        }

        return $data;
    }

    /**
     * Mesmo nome pode existir em convênios diferentes ("BÁSICO" da Unimed e
     * da Amil): o "reaproveitar excluído" da base casaria só pelo nome.
     */
    protected function findOrCreate(FormRequest $request): Model
    {
        $existing = CovenantPlan::query()
            ->withTrashed()
            ->where('entity_id', session()->get('selected_entity_id'))
            ->where('covenant_id', $request->covenant_id)
            ->where('name', mb_strtoupper((string) $request->name, 'UTF-8'))
            ->first();

        $data = $this->getCreateData($request);

        if ($existing) {
            if ($existing->trashed()) {
                $existing->restore();
            }
            $existing->update([...$data, 'active' => true]);

            return $existing->fresh();
        }

        return CovenantPlan::create([
            ...$data,
            'entity_id' => session()->get('selected_entity_id'),
            'active'    => true,
        ]);
    }
}
