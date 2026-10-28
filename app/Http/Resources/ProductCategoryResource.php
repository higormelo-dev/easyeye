<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProductCategoryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'type'       => 'product_category',
            'id'         => $this->id,
            'attributes' => [
                'entity_id'  => $this->entity_id,
                'code'       => $this->code,
                'name'       => $this->name,
                'active'     => (bool) $this->active,
                'created_at' => $this->created_at,
                'updated_at' => $this->updated_at,
            ],
        ];
    }
}
