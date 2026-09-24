<?php

namespace App\Http\Resources\Api;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property string $id
 * @property string $type
 * @property array<string, mixed> $payload
 * @property \Illuminate\Support\Carbon $created_at
 */
class IntegratorCommandResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            // 'type' aqui é o tipo de RECURSO (convenção JSON:API já usada
            // por EquipmentResource/PatientResource/etc) — o tipo de
            // COMANDO ('resync_now'/...) fica em attributes.type.
            'type'       => 'command',
            'id'         => $this->id,
            'attributes' => [
                'type'       => $this->type,
                'payload'    => $this->payload,
                'created_at' => $this->created_at->toIso8601String(),
            ],
        ];
    }
}
