<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class EntityIntegratorEquipmentResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'type'       => 'equipment',
            'id'         => $this->id,
            'attributes' => [
                'integrator_id' => $this->integrator_id,
                'name'          => $this->name,
                'ip'            => $this->ip,
                'mac'           => $this->mac,
                'serial_number' => $this->serial_number,
                'active'        => (bool) $this->active,
            ],
        ];
    }
}
