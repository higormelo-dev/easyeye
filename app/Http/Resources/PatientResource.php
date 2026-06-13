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
                'created_at'  => $this->created_at,
                'updated_at'  => $this->updated_at,
            ],
        ];

        if (! $request->routeIs('*.index')) {
            $resource['relationships'] = [
                'entity'    => $this->entity->toArray(),
                'person'    => $this->person->toArray(),
                'covenant'  => $this->covenant->toArray(),
                'skin_type' => $this->skinType?->toArray() ?? (object) [],
                'iris_type' => $this->irisType?->toArray() ?? (object) [],
            ];
        }

        return $resource;
    }
}
