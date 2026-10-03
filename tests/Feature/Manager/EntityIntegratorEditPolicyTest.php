<?php

use App\Models\{Entity, EntityIntegrator, EntityUserIntegrator, User};

it('preserves authorized purposes and pilot rollout during a Manager name-only edit', function (string $profile) {
    $saas  = Entity::factory()->create(['is_client' => false, 'active' => true]);
    $admin = User::factory()->create();
    createEntityUser($saas, $admin, 'admin');
    $entity     = Entity::factory()->create(['is_client' => true]);
    $actor      = EntityUserIntegrator::factory()->create(['entity_id' => $entity->id]);
    $integrator = EntityIntegrator::factory()->create(['entity_user_integrator_id' => $actor->id, 'token_profile' => $profile, 'update_channel' => 'pilot', 'update_cohort' => 'win7-pilot']);
    $parameters = [$entity->id, $actor->id, $integrator->id];
    $this->actingAs($admin)->withSession(['selected_entity_id' => $saas->id, 'selected_entity_is_client' => false, 'selected_entity_user_rule' => 'admin']);
    $response = $this->getJson(route('manager.entities.user-integrators.integrators.edit-data', $parameters))->assertOk()
        ->assertJsonPath('data.token_profile', $profile)->assertJsonPath('data.update_channel', 'pilot')->assertJsonPath('data.update_cohort', 'win7-pilot');
    $this->patchJson(route('manager.entities.user-integrators.integrators.update', $parameters), [...$response->json('data'), 'name' => 'RENAMED SYNTHETIC'])->assertOk();
    expect($integrator->refresh()->token_profile)->toBe($profile)->and($integrator->update_channel)->toBe('pilot')->and($integrator->update_cohort)->toBe('win7-pilot');
})->with(['support', 'worklist']);
