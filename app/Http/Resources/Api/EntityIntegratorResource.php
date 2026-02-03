<?php

namespace App\Http\Resources\Api;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Laravel\Sanctum\NewAccessToken;

class EntityIntegratorResource extends JsonResource
{
    public function __construct($resource, private ?NewAccessToken $token = null)
    {
        parent::__construct($resource);
    }

    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'access_token' => $this->token?->plainTextToken,
            'token_type'   => $this->token ? 'Bearer' : null,
            'expires_at'   => $this->token?->accessToken->expires_at?->toISOString(),
            'user'         => [
                'id'                => $this->user->id,
                'entity_id'         => $this->user->entity_id,
                'name'              => $this->user->name,
                'email'             => $this->user->email,
                'email_verified_at' => $this->user->email_verified_at,
                'active'            => (bool) $this->user->active,
                'created_at'        => $this->user->created_at,
                'updated_at'        => $this->user->updated_at,
            ],
            'integrator' => [
                'id'                        => $this->id,
                'entity_user_integrator_id' => $this->entity_user_integrator_id,
                'code'                      => $this->code,
                'name'                      => $this->name,
                'ip'                        => $this->ip,
                'mac'                       => $this->mac,
                'active'                    => (bool) $this->active,
                'created_at'                => $this->created_at,
                'updated_at'                => $this->updated_at,
            ],
            'entity' => $this->user->entity->toArray(),
        ];
    }
}
