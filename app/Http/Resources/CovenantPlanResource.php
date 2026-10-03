<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CovenantPlanResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'type'       => 'covenant_plan',
            'id'         => $this->id,
            'attributes' => [
                'entity_id'   => $this->entity_id,
                'covenant_id' => $this->covenant_id,
                'code'        => $this->code,
                'name'        => $this->name,
                'ans_code'    => $this->ans_code,
                'active'      => (bool) $this->active,
                'created_at'  => $this->created_at,
                'updated_at'  => $this->updated_at,
            ],
        ];
    }
}
