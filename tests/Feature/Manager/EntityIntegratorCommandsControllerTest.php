<?php

use App\Models\{Entity, EntityIntegrator, EntityUserIntegrator, IntegratorCommand, User};

beforeEach(function () {
    $this->saas  = Entity::factory()->create(['is_client' => false, 'active' => true]);
    $this->admin = User::factory()->create();
    createEntityUser($this->saas, $this->admin, 'admin');
});

function commandsAdminSession(Entity $saas): array
{
    return [
        'selected_entity_id'        => $saas->id,
        'selected_entity_is_client' => false,
        'selected_entity_user_rule' => 'admin',
    ];
}

function makeCommandsClientChain(): array
{
    $entity         = Entity::factory()->create(['is_client' => true, 'active' => true]);
    $userIntegrator = EntityUserIntegrator::factory()->create(['entity_id' => $entity->id]);
    $integrator     = EntityIntegrator::factory()->create(['entity_user_integrator_id' => $userIntegrator->id]);

    return [$entity, $userIntegrator, $integrator];
}

it('enqueues a pending command for the integrator', function () {
    [$entity, $userIntegrator, $integrator] = makeCommandsClientChain();

    $url = route('manager.entities.user-integrators.integrators.commands.store', [
        $entity->id, $userIntegrator->id, $integrator->id,
    ]);

    $this->actingAs($this->admin)
        ->withSession(commandsAdminSession($this->saas))
        ->postJson($url, ['type' => 'resync_now'])
        ->assertCreated()
        ->assertJsonPath('type', 'resync_now');

    $command = IntegratorCommand::where('integrator_id', $integrator->id)->sole();
    expect($command->status)->toBe('pending')
        ->and($command->type)->toBe('resync_now');
});

it('rejects a command type outside the known set', function () {
    [$entity, $userIntegrator, $integrator] = makeCommandsClientChain();

    $url = route('manager.entities.user-integrators.integrators.commands.store', [
        $entity->id, $userIntegrator->id, $integrator->id,
    ]);

    $this->actingAs($this->admin)
        ->withSession(commandsAdminSession($this->saas))
        ->postJson($url, ['type' => 'delete_everything'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['type']);

    expect(IntegratorCommand::count())->toBe(0);
});

it('404s when the integrator does not belong to the given user-integrator chain', function () {
    [$entityA, $userIntegratorA] = makeCommandsClientChain();
    [, , $integratorB]           = makeCommandsClientChain();

    $url = route('manager.entities.user-integrators.integrators.commands.store', [
        $entityA->id, $userIntegratorA->id, $integratorB->id,
    ]);

    $this->actingAs($this->admin)
        ->withSession(commandsAdminSession($this->saas))
        ->postJson($url, ['type' => 'resync_now'])
        ->assertNotFound();
});

it('requires saas admin authentication', function () {
    [$entity, $userIntegrator, $integrator] = makeCommandsClientChain();

    $url = route('manager.entities.user-integrators.integrators.commands.store', [
        $entity->id, $userIntegrator->id, $integrator->id,
    ]);

    $this->post($url, ['type' => 'resync_now'])->assertRedirect();
    expect(IntegratorCommand::count())->toBe(0);
});
