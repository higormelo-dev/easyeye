<?php

use App\Models\{Entity, EntityIntegrator, EntityUserIntegrator, IntegratorQueueHealthHistory};
use Carbon\Carbon;

function aQueueHealthIntegrator(): EntityIntegrator
{
    $entity         = Entity::factory()->create(['is_client' => true, 'active' => true]);
    $userIntegrator = EntityUserIntegrator::factory()->create(['entity_id' => $entity->id]);

    return EntityIntegrator::factory()->create(['entity_user_integrator_id' => $userIntegrator->id]);
}

function aHistoryPointAt(EntityIntegrator $integrator, Carbon $syncedAt): IntegratorQueueHealthHistory
{
    return IntegratorQueueHealthHistory::create([
        'integrator_id'       => $integrator->id,
        'pending_count'       => 1,
        'failed_count'        => 0,
        'blocked_count'       => 0,
        'sent_last_24h_count' => 0,
        'synced_at'           => $syncedAt,
    ]);
}

it('apaga histórico de queue-health com mais de 7 dias por padrão', function () {
    $integrator = aQueueHealthIntegrator();
    $old        = aHistoryPointAt($integrator, now()->subDays(8));
    $fresh      = aHistoryPointAt($integrator, now()->subDays(2));

    $this->artisan('queue-health:prune-history')
        ->expectsOutputToContain('Linhas deletadas: 1')
        ->assertSuccessful();

    expect(IntegratorQueueHealthHistory::query()->find($old->id))->toBeNull()
        ->and(IntegratorQueueHealthHistory::query()->find($fresh->id))->not->toBeNull();
});

it('mantém histórico dentro do prazo', function () {
    $integrator = aQueueHealthIntegrator();
    aHistoryPointAt($integrator, now()->subDays(6));
    aHistoryPointAt($integrator, now()->subHours(1));

    $this->artisan('queue-health:prune-history')->assertSuccessful();

    expect(IntegratorQueueHealthHistory::query()->count())->toBe(2);
});

it('dry-run reporta contagem sem deletar', function () {
    $integrator = aQueueHealthIntegrator();
    aHistoryPointAt($integrator, now()->subDays(10));
    aHistoryPointAt($integrator, now()->subDays(9));

    $this->artisan('queue-health:prune-history', ['--dry-run' => true])
        ->expectsOutputToContain('Histórico de queue-health > 7 dias: 2')
        ->assertSuccessful();

    expect(IntegratorQueueHealthHistory::query()->count())->toBe(2);
});

it('respeita --days customizado', function () {
    $integrator = aQueueHealthIntegrator();
    aHistoryPointAt($integrator, now()->subDays(3));
    aHistoryPointAt($integrator, now()->subDays(1));

    $this->artisan('queue-health:prune-history', ['--days' => 2])
        ->expectsOutputToContain('Linhas deletadas: 1')
        ->assertSuccessful();

    expect(IntegratorQueueHealthHistory::query()->count())->toBe(1);
});

it('não deleta nada quando não há histórico expirado', function () {
    $integrator = aQueueHealthIntegrator();
    aHistoryPointAt($integrator, now()->subHours(2));

    $this->artisan('queue-health:prune-history')
        ->expectsOutputToContain('Histórico de queue-health > 7 dias: 0')
        ->assertSuccessful();

    expect(IntegratorQueueHealthHistory::query()->count())->toBe(1);
});
