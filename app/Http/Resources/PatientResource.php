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
        return [
            'type'       => 'equipment',
            'id'         => $this->id,
            'attributes' => [
                'entity_id'   => $this->entity_id,
                'code'        => $this->code,
                'card_number' => $this->card_number,
                'active'      => (bool) $this->active,
                'person'      => [
                    'id'                     => $this->person->id,
                    'full_name'              => $this->person->full_name,
                    'nickname'               => $this->person->nickname,
                    'birth_date'             => $this->person->birth_date,
                    'gender'                 => $this->person->gender,
                    'marital_status'         => $this->person->marital_status,
                    'email'                  => $this->person->email,
                    'mother_name'            => $this->person->mother_name,
                    'father_name'            => $this->person->father_name,
                    'national_registry'      => $this->person->national_registry,
                    'state_registry'         => $this->person->state_registry,
                    'state_registry_agency'  => $this->person->state_registry_agency,
                    'state_registry_initial' => $this->person->state_registry_initial,
                    'state_registry_date'    => $this->person->state_registry_date,
                    'telephone'              => $this->person->telephone,
                    'cellphone'              => $this->person->cellphone,
                    'whatsapp'               => $this->person->whatsapp,
                    'zipcode'                => $this->person->zipcode,
                    'address'                => $this->person->address,
                    'number'                 => $this->person->number,
                    'complement'             => $this->person->complement,
                    'district'               => $this->person->district,
                    'city'                   => $this->person->city,
                    'state'                  => $this->person->state,
                    'country'                => $this->person->country,
                    'photo'                  => $this->person->photo,
                ],
                'covenant' => [
                    'id'    => $this->covenant?->id,
                    'name'  => $this->covenant?->name,
                    'color' => $this->covenant?->color,
                    'table' => $this->covenant?->table,
                ],
                'skinType' => [
                    'id'   => $this->skinType?->id,
                    'name' => $this->skinType?->name,
                ],
                'irisType' => [
                    'id'   => $this->irisType?->id,
                    'name' => $this->irisType?->name,
                ],
            ],
        ];
    }
}
