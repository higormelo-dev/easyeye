<?php
declare(strict_types=1);

namespace App\Services;

use App\Models\{EntityIntegrator,EntityUserIntegrator};
use App\Traits\HasBusinessDays;
use Carbon\Carbon;
use Laravel\Sanctum\PersonalAccessToken;

class IntegratorTokenPolicy
{
    use HasBusinessDays;

    public const PROFILES = [
        'capture'  => ['patients:resolve', 'worklist:read', 'exams:write', 'equipment:write', 'telemetry:write', 'commands:read', 'commands:ack', 'updates:read'],
        'worklist' => ['worklist:read', 'equipment:write', 'telemetry:write', 'commands:read', 'commands:ack', 'updates:read'],
        'support'  => ['telemetry:write', 'commands:read', 'commands:ack', 'updates:read'],
    ];

    public function integrator(PersonalAccessToken $token): ?EntityIntegrator
    {
        $model = EntityIntegrator::with('user.entity')->find(EntityIntegrator::idFromTokenAbilities((array) $token->abilities));

        return $model && $token->tokenable_type === EntityUserIntegrator::class && $model->entity_user_integrator_id === $token->tokenable_id ? $model : null;
    }

    public function reason(PersonalAccessToken $token, mixed $lastUsed = null): ?string
    {
        $model = $this->integrator($token);

        if (! $model) {
            return 'auth.token_invalid';
        }

        if ($reason = $model->accessBlockReason()) {
            return $reason;
        }

        if ($token->expires_at && $token->expires_at->isPast()) {
            return 'auth.token_expired';
        }

        if ($this->countBusinessDays($lastUsed ?? $token->last_used_at ?? $token->created_at, Carbon::now()) > 3) {
            return 'auth.token_expired_inactivity';
        }

        return null;
    }

    public function abilities(EntityIntegrator $model, ?string $scope = null, ?string $installation = null): array
    {
        $purposes = self::PROFILES[$model->token_profile] ?? [];

        if ($scope === 'read') {
            $purposes = array_values(array_filter($purposes, fn ($v) => ! in_array($v, ['exams:write', 'equipment:write', 'telemetry:write', 'commands:ack'], true)));
        }

        return ['integrator_id:' . $model->id, 'purpose:v1', ...($installation ? ['installation_id:' . $installation] : []), 'api:read', ...($scope === 'read' ? [] : ['api:write']), ...$purposes];
    }

    public function renew(PersonalAccessToken $token): bool
    {
        if (! $token->expires_at || ! $this->willExpireInOneBusinessDay($token->expires_at)) {
            return false;
        }
        $model   = $this->integrator($token);
        $install = null;

        foreach ((array) $token->abilities as $a) {
            if (str_starts_with($a, 'installation_id:')) {
                $install = substr($a, 16);
            }
        }
        $token->abilities  = $this->abilities($model, in_array('api:read', (array) $token->abilities, true) && ! in_array('api:write', (array) $token->abilities, true) ? 'read' : null, $install);
        $token->expires_at = now()->addDays(7);
        $token->save();

        return true;
    }
}
