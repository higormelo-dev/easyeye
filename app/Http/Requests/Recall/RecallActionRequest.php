<?php

declare(strict_types=1);

namespace App\Http\Requests\Recall;

use App\Enums\EntityGate;
use App\Models\Entity;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/**
 * Base das ações da equipe sobre um retorno (contato, adiar, dispensar, reabrir, "feito fora"): quem pode
 * operar o recall (admin, médico, secretária) — a regra é o Gate OperateRecall, nunca um perfil escrito aqui.
 */
abstract class RecallActionRequest extends FormRequest
{
    public function authorize(): bool
    {
        $entity = Entity::query()->find((string) session('selected_entity_id'));

        return $entity !== null && Gate::allows(EntityGate::OperateRecall->value, $entity);
    }
}
