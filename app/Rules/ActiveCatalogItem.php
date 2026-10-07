<?php

declare(strict_types=1);

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Item de catálogo (convênio, tipo de consulta…) só entra em vínculo NOVO se
 * estiver ativo — as telas já escondem os inativos, mas o POST/PUT direto
 * gravava. `$currentId` é o valor que o registro em edição JÁ tem: reenviá-lo
 * sem trocar passa mesmo inativado (editar outro campo não trava a recepção).
 *
 * Existência e tenant (clínica ou global, não excluído) seguem com o
 * Rule::exists de cada request; aqui só a regra de "ativo".
 */
class ActiveCatalogItem implements ValidationRule
{
    /** @param class-string<Model> $modelClass */
    public function __construct(
        private readonly string $modelClass,
        private readonly ?string $currentId,
        private readonly string $messageKey,
    ) {
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (blank($value) || ! Str::isUuid((string) $value)) {
            return; // vazio = sem vínculo; formato inválido já cai em 'uuid'
        }

        if ($this->currentId !== null && (string) $value === $this->currentId) {
            return;
        }

        if (! static::activeIn($this->modelClass, (string) $value)) {
            $fail(__($this->messageKey));
        }
    }

    /** @param class-string<Model> $modelClass */
    public static function activeIn(string $modelClass, string $id): bool
    {
        return $modelClass::withoutGlobalScopes()->whereKey($id)->where('active', true)->exists();
    }
}
