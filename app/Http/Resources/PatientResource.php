<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PatientResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $resource = [
            'type'       => 'patient',
            'id'         => $this->id,
            'attributes' => [
                'entity_id'   => $this->entity_id,
                'person_id'   => $this->person_id,
                'covenant_id' => $this->covenant_id,
                'skin_id'     => $this->skin_id,
                'iris_id'     => $this->iris_id,
                'code'        => $this->code,
                'card_number' => $this->card_number,
                'active'      => (bool) $this->active,
            ],
            'relationships' => [
                'entity'   => $this->entity->toArray(),
                'person'   => $this->person->toArray(),
                'covenant' => $this->covenant->toArray(),
            ],
        ];

        if ($this->skin_id !== null) {
            $resource['relationships']['skin_type'] = $this->skinType->toArray();
        }

        if ($this->iris_id !== null) {
            $resource['relationships']['iris_type'] = $this->irisType->toArray();
        }

        return $resource;
    }
}
