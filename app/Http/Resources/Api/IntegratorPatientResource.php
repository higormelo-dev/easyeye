<?php

namespace App\Http\Resources\Api;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class IntegratorPatientResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $person     = $this->person;
        $attributes = ['entity_id' => $this->entity_id, 'person_id' => $this->person_id, 'code' => $this->code, 'import_code' => $this->import_code, 'full_name' => $person?->full_name, 'birth_date' => $person?->birth_date?->format('Y-m-d'), 'sex' => match((string) ($person?->gender ?? '')) {
            '0'                    => 'F','1' => 'M',default => null
        }, 'active' => (bool) $this->active, 'created_at' => $this->created_at, 'updated_at' => $this->updated_at];

        return ['type' => 'patient', 'id' => $this->id, 'attributes' => $attributes, 'relationships' => ['person' => ['id' => $this->person_id, 'full_name' => $attributes['full_name'], 'birth_date' => $attributes['birth_date'], 'gender' => $person?->gender], 'entity' => ['id' => $this->entity_id]]];
    }
}
