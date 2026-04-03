<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ExamTypeResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $data = [
            'type'       => 'exam_type',
            'id'         => $this->id,
            'attributes' => [
                'entity_id'  => $this->entity_id,
                'code'       => $this->code,
                'name'       => $this->name,
                'suffix'     => $this->suffix,
                'category'   => $this->category,
                'active'     => (bool) $this->active,
                'created_at' => $this->created_at,
                'updated_at' => $this->updated_at,
            ],
        ];

        if (!$request->routeIs('*.index')) {
            $data['relationships'] = [
                'entity' => $this->entity?->toArray() ?? (object) [],
            ];
        }

        return $data;
    }
}
