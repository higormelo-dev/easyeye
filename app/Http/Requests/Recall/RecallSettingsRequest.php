<?php

declare(strict_types=1);

namespace App\Http\Requests\Recall;

use App\Enums\EntityGate;
use App\Models\Entity;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/**
 * Base dos pedidos da tela de configuração dos retornos. `decides()` = ato CLÍNICO (ativar/desativar protocolo):
 * só médico com cadastro ativo (DecideRecall). O resto (retorno pedido pelo médico, mapeamento de exames) é
 * configuração: o administrador da clínica ou o médico.
 */
abstract class RecallSettingsRequest extends FormRequest
{
    /** O ato exige a decisão clínica do médico? */
    protected function decides(): bool
    {
        return false;
    }

    public function authorize(): bool
    {
        $entity = Entity::query()->find((string) session('selected_entity_id'));

        if ($entity === null) {
            return false;
        }

        if ($this->decides()) {
            return Gate::allows(EntityGate::DecideRecall->value, $entity);
        }

        return Gate::allows(EntityGate::ManageSettings->value, $entity) || Gate::allows(EntityGate::DecideRecall->value, $entity);
    }
}
