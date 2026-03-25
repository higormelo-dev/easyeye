<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ClinicResourceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'type'       => 'clinic_resource',
            'id'         => $this->id,
            'attributes' => [
                'entity_id'   => $this->entity_id,
                'code'        => $this->code,
                'name'        => $this->name,
                'type'        => $this->type,
                'type_label'  => $this->typeLabel(),
                'description' => $this->description,
                'active'      => (bool) $this->active,
                'created_at'  => $this->created_at,
                'updated_at'  => $this->updated_at,
            ],
        ];
    }
}
