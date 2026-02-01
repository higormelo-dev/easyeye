<?php

namespace App\Http\Resources\Api;

use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class EntityIntegratorResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'access_token' => $this->token_session,
            'token_type'   => 'Bearer',
            'expires_at'   => $this->token_session_expires_at->toISOString(),
            'integrator'   => [
                'id'         => $this->id,
                'entity_id'  => $this->entity_id,
                'code'       => $this->code,
                'name'       => $this->name,
                'token'      => $this->token,
                'ip'         => $this->ip,
                'mac'        => $this->mac,
                'active'     => (bool) $this->active,
                'created_at' => $this->created_at,
                'updated_at' => $this->updated_at,
            ],
            'entity' => [
                'code'                   => $this->entity->code,
                'name'                   => $this->entity->name,
                'zipcode'                => $this->entity->zipcode,
                'address'                => $this->entity->address,
                'number'                 => $this->entity->number,
                'complement'             => $this->entity->complement,
                'district'               => $this->entity->district,
                'city'                   => $this->entity->city,
                'state'                  => $this->entity->state,
                'country'                => $this->entity->country,
                'national_registration'  => $this->entity->national_registration,
                'state_registration'     => $this->entity->state_registration,
                'municipal_registration' => $this->entity->municipal_registration,
                'telephone'              => $this->entity->telephone,
                'cellphone'              => $this->entity->cellphone,
                'email'                  => $this->entity->email,
                'website'                => $this->entity->website,
                'active'                 => (bool) $this->entity->active,
                'created_at'             => $this->entity->created_at,
                'updated_at'             => $this->entity->updated_at,
            ],
        ];
    }
}
