<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SupplierResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id'           => $this->id,
            'code'         => $this->code,
            'name'         => $this->name,
            'document'     => $this->document,
            'email'        => $this->email,
            'phone'        => $this->phone,
            'contact_name' => $this->contact_name,
            'notes'        => $this->notes,
            'active'       => (bool) $this->active,
            'created_at'   => $this->created_at?->format('d/m/Y H:i'),
        ];
    }
}
