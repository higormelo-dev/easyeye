<?php

use App\Enums\{FeatureKey, SubscriptionStatus};
use App\Models\{Entity, EntityIntegrator, EntityUserIntegrator, Plan, PlanFeature, Subscription};
use Carbon\Carbon;

describe('Middleware ApiCheckPlanAccess', function () {
    it('returns 403 when entity has no subscription', function () {
        $plan = Plan::factory()->create();
        PlanFeature::create(['plan_id' => $plan->id, 'feature' => FeatureKey::HasApiIntegrator->value, 'value' => '1']);

        // Entity SEM subscription — apaga o trial criado automaticamente pelo EntityObserver
        $entity = Entity::factory()->create(['is_client' => true, 'active' => true]);
        Subscription::where('entity_id', $entity->id)->forceDelete();

        $integratorUser = EntityUserIntegrator::factory()->create(['entity_id' => $entity->id, 'active' => true]);
        $integrator     = EntityIntegrator::factory()->create([
            'entity_user_integrator_id' => $integratorUser->id,
            'active'                    => true,
        ]);

        $token = $integratorUser->createToken(
            'integrator-token',
            ['integrator_id:' . $integrator->id],
            Carbon::now()->addDays(7)
        );

        $this->getJson('/api/integrators/v1/patients', [
            'Authorization' => 'Bearer ' . $token->plainTextToken,
        ])->assertForbidden();
    });

    it('returns 403 when plan does not include api integrator feature', function () {
        $plan = Plan::factory()->create();
        PlanFeature::create(['plan_id' => $plan->id, 'feature' => FeatureKey::HasApiIntegrator->value, 'value' => '0']);

        $entity = Entity::factory()->create(['is_client' => true, 'active' => true]);

        Subscription::create([
            'entity_id' => $entity->id,
            'plan_id'   => $plan->id,
            'status'    => SubscriptionStatus::Active,
            'starts_at' => now()->subDay(),
            'ends_at'   => now()->addMonth(),
        ]);

        $integratorUser = EntityUserIntegrator::factory()->create(['entity_id' => $entity->id, 'active' => true]);
        $integrator     = EntityIntegrator::factory()->create([
            'entity_user_integrator_id' => $integratorUser->id,
            'active'                    => true,
        ]);

        $token = $integratorUser->createToken(
            'integrator-token',
            ['integrator_id:' . $integrator->id],
            Carbon::now()->addDays(7)
        );

        $this->getJson('/api/integrators/v1/patients', [
            'Authorization' => 'Bearer ' . $token->plainTextToken,
        ])->assertForbidden()
            ->assertJsonFragment(['valid' => false]);
    });

    it('returns 200 when plan has api integrator access', function () {
        $ctx = setupIntegrator([FeatureKey::HasApiIntegrator->value => '1']);

        $this->getJson('/api/integrators/v1/patients', $ctx['headers'])
            ->assertOk();
    });

    it('caps per_page at 100 regardless of requested amount', function () {
        $ctx = setupIntegrator();

        $response = $this->getJson('/api/integrators/v1/patients?per_page=500', $ctx['headers'])
            ->assertOk();

        expect($response->json('meta.per_page'))->toBeLessThanOrEqual(100);
    });

    it('caps per_page at 100 even with per_page=1', function () {
        $ctx = setupIntegrator();

        $response = $this->getJson('/api/integrators/v1/patients?per_page=1', $ctx['headers'])
            ->assertOk();

        expect($response->json('meta.per_page'))->toBe(1);
    });
});
