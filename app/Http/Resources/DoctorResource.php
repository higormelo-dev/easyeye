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
        $data = [
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
                'created_at'       => $this->created_at,
                'updated_at'       => $this->updated_at,
            ],
        ];

        if (! $request->routeIs('*.index')) {
            $data['relationships'] = [
                'entityUser' => $this->entityUser->toArray() ?? (object) [],
                'person'     => $this->person->toArray() ?? (object) [],
            ];
        }

        return $data;
    }
}
