<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CovenantResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $data = [
            'type'       => 'doctor',
            'id'         => $this->id,
            'attributes' => [
                'entity_id'  => $this->entity_id,
                'name'       => $this->name,
                'color'      => $this->color,
                'table'      => (bool) $this->table,
                'active'     => (bool) $this->active,
                'created_at' => $this->created_at,
                'updated_at' => $this->updated_at,
            ],
        ];

        if (! $request->routeIs('*.index')) {
            $data['relationships'] = [
                'entity' => $this->entity?->toArray() ?? (object) [],
            ];
        }

        return $data;
    }
}
