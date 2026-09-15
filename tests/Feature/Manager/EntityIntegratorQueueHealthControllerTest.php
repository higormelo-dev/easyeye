<?php

use App\Models\{Entity, EntityIntegrator, EntityUserIntegrator, IntegratorQueueHealth, User};

beforeEach(function () {
    $this->saas  = Entity::factory()->create(['is_client' => false, 'active' => true]);
    $this->admin = User::factory()->create();
    createEntityUser($this->saas, $this->admin, 'admin');
});

function queueHealthAdminSession(Entity $saas): array
{
    return [
        'selected_entity_id'        => $saas->id,
        'selected_entity_is_client' => false,
        'selected_entity_user_rule' => 'admin',
    ];
}

function makeClientChain(): array
{
    $entity         = Entity::factory()->create(['is_client' => true, 'active' => true]);
    $userIntegrator = EntityUserIntegrator::factory()->create(['entity_id' => $entity->id]);
    $integrator     = EntityIntegrator::factory()->create(['entity_user_integrator_id' => $userIntegrator->id]);

    return [$entity, $userIntegrator, $integrator];
}

it('renders the last synced snapshot for the integrator', function () {
    [$entity, $userIntegrator, $integrator] = makeClientChain();

    IntegratorQueueHealth::create([
        'integrator_id'       => $integrator->id,
        'pending_count'       => 4,
        'failed_count'        => 1,
        'blocked_count'       => 2,
        'sent_last_24h_count' => 30,
        'problems'            => [[
            'id'                  => 7, 'file_name' => '<file:aabbccdd>.png', 'status' => 'blocked',
            'schedule_identifier' => 'SDL-0000000722', 'patient_identifier' => null,
            'last_error'          => 'file exceeds the 10 MB upload limit', 'attempts' => 0,
            'api_status'          => null, 'updated_at' => now()->toIso8601String(),
        ]],
        'synced_at' => now()->subMinutes(3),
    ]);

    $url = route('manager.entities.user-integrators.integrators.queue-health', [
        $entity->id, $userIntegrator->id, $integrator->id,
    ]);

    $this->actingAs($this->admin)
        ->withSession(queueHealthAdminSession($this->saas))
        ->get($url)
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Panel/Manager/EntityIntegratorQueueHealth/Index')
            ->where('health.pending_count', 4)
            ->where('health.blocked_count', 2)
            ->where('health.problems.0.schedule_identifier', 'SDL-0000000722'));
});

it('returns a null health payload when the integrator never synced', function () {
    [$entity, $userIntegrator, $integrator] = makeClientChain();

    $url = route('manager.entities.user-integrators.integrators.queue-health', [
        $entity->id, $userIntegrator->id, $integrator->id,
    ]);

    $this->actingAs($this->admin)
        ->withSession(queueHealthAdminSession($this->saas))
        ->get($url)
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Panel/Manager/EntityIntegratorQueueHealth/Index')
            ->where('health', null));
});

it('never shows one integrator\'s queue health under another integrator\'s chain', function () {
    [$entityA, $userIntegratorA, $integratorA] = makeClientChain();
    [, , $integratorB]                         = makeClientChain();

    IntegratorQueueHealth::create([
        'integrator_id' => $integratorB->id,
        'pending_count' => 999, 'failed_count' => 0, 'blocked_count' => 0, 'sent_last_24h_count' => 0,
        'problems'      => [], 'synced_at' => now(),
    ]);

    // URL usa a cadeia da entity A, mas tenta pedir o retrato do integrador B.
    $url = route('manager.entities.user-integrators.integrators.queue-health', [
        $entityA->id, $userIntegratorA->id, $integratorB->id,
    ]);

    $this->actingAs($this->admin)
        ->withSession(queueHealthAdminSession($this->saas))
        ->getJson($url)
        ->assertNotFound();
});

it('requires saas admin authentication', function () {
    [$entity, $userIntegrator, $integrator] = makeClientChain();

    $url = route('manager.entities.user-integrators.integrators.queue-health', [
        $entity->id, $userIntegrator->id, $integrator->id,
    ]);

    $this->get($url)->assertRedirect();
});
