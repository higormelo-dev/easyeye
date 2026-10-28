<?php

namespace App\Http\Controllers\Concerns;

use App\DTOs\ActionPolicy;

/**
 * Helper para controllers que expõem endpoint cards() com Alpine card view.
 *
 * Padroniza a injeção de flags de ação no payload JSON, garantindo que
 * a card view tenha os mesmos modos da DataTable (consumindo a mesma
 * fonte ActionPolicy).
 */
trait SerializesCardActions
{
    /**
     * Retorna o array de policies pronto para spread no item do payload.
     *
     * @return array<string, mixed>
     */
    protected function cardActionPayload(object $record, ?string $entityId = null): array
    {
        $entityId ??= session()->get('selected_entity_id');

        return ActionPolicy::from($record, $entityId)->toArray();
    }
}
