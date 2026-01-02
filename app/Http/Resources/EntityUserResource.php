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
        $resource = [
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
            'relationships' => [
                'entity' => $this->entity->toArray(),
                'user'   => $this->user->toArray(),
            ],
        ];

        if ($this->rule === 'doctor') {
            $resource['relationships']['person'] = $this->doctor->person->toArray();
            $resource['relationships']['doctor'] = $this->doctor->toArray();
        }

        return $resource;
    }
}
