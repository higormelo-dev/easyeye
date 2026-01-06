<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DoctorResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'type'       => 'doctor',
            'id'         => $this->id,
            'attributes' => [
                'entity_user_id'   => $this->entity_user_id,
                'person_id'        => $this->person_id,
                'code'             => $this->code,
                'record'           => $this->record,
                'record_specialty' => $this->record_specialty,
                'color'            => $this->color,
                'observation'      => $this->observation,
                'partner'          => (bool) $this->partner,
                'active'           => (bool) $this->active,
            ],
            'relationships' => [
                'entityUser' => $this->entityUser->toArray(),
                'person'     => $this->person->toArray(),
            ],
        ];
    }
}
