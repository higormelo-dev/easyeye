<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class EntityUserResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $data = [
            'type'       => 'entity_users',
            'id'         => $this->id,
            'attributes' => [
                'entity_id'  => $this->entity_id,
                'user_id'    => $this->user_id,
                'rule'       => $this->rule,
                'active'     => (bool) $this->active,
                'created_at' => $this->created_at,
                'updated_at' => $this->updated_at,
            ],
        ];

        if (! $request->routeIs('*.index')) {
            $data['relationships'] = [
                'entity' => $this->entity->toArray(),
                'user'   => $this->user->toArray(),
            ];

            // Null-safe: um EntityUser com rule doctor pode não ter registro
            // clínico de Doctor associado (ex.: vínculo criado fora do fluxo
            // de médicos) — sem isso o show/store JSON estoura 500.
            if ($this->rule === 'doctor' && $this->doctor) {
                $data['relationships']['person'] = $this->doctor->person?->toArray();
                $data['relationships']['doctor'] = $this->doctor->toArray();
            }
        }

        return $data;
    }
}
