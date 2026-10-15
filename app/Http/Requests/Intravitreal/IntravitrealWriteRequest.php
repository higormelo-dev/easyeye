<?php

declare(strict_types=1);

namespace App\Http\Requests\Intravitreal;

use App\Enums\EntityGate;
use App\Models\Entity;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/**
 * Base das ações sobre o curso de injeções intravítreas (encerrar, agendar a próxima): ato clínico, só o médico com
 * cadastro ativo (mesmo Gate IssueReport do procedimento estruturado).
 */
abstract class IntravitrealWriteRequest extends FormRequest
{
    public function authorize(): bool
    {
        $entity = Entity::query()->find((string) session('selected_entity_id'));

        return $entity !== null && Gate::allows(EntityGate::IssueReport->value, $entity);
    }
}
