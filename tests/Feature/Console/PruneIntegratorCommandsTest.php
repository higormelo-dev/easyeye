<?php

use App\Models\IntegratorCommand;

beforeEach(function () {
    $this->ctx = setupIntegrator();
});

function makeIntegratorCommand(string $integratorId, string $status, ?Carbon\Carbon $ackedAt): IntegratorCommand
{
    return IntegratorCommand::create([
        'integrator_id' => $integratorId,
        'type'          => 'resync_now',
        'payload'       => [],
        'status'        => $status,
        'acked_at'      => $ackedAt,
    ]);
}

it('apaga comandos terminais com mais de 30 dias', function () {
    $old   = makeIntegratorCommand($this->ctx['integrator']->id, 'completed', now()->subDays(40));
    $fresh = makeIntegratorCommand($this->ctx['integrator']->id, 'failed', now()->subDays(10));

    $this->artisan('integrator-commands:prune')
        ->expectsOutputToContain('Linhas deletadas: 1')
        ->assertSuccessful();

    expect(IntegratorCommand::query()->find($old->id))->toBeNull()
        ->and(IntegratorCommand::query()->find($fresh->id))->not->toBeNull();
});

it('nunca apaga comandos pending, mesmo muito antigos', function () {
    $pending = IntegratorCommand::create([
        'integrator_id' => $this->ctx['integrator']->id,
        'type'          => 'resync_now',
        'payload'       => [],
        'status'        => 'pending',
    ]);
    $pending->forceFill(['created_at' => now()->subDays(400)])->saveQuietly();

    $this->artisan('integrator-commands:prune', ['--days' => 1])->assertSuccessful();

    expect(IntegratorCommand::query()->find($pending->id))->not->toBeNull();
});

it('mantém comandos terminais abaixo do threshold', function () {
    makeIntegratorCommand($this->ctx['integrator']->id, 'completed', now()->subDays(5));
    makeIntegratorCommand($this->ctx['integrator']->id, 'failed', now()->subDays(29));

    $this->artisan('integrator-commands:prune')->assertSuccessful();

    expect(IntegratorCommand::query()->count())->toBe(2);
});

it('dry-run reporta contagem sem deletar', function () {
    makeIntegratorCommand($this->ctx['integrator']->id, 'completed', now()->subDays(45));

    $this->artisan('integrator-commands:prune', ['--dry-run' => true])
        ->expectsOutputToContain('Comandos terminais > 30 dias: 1')
        ->assertSuccessful();

    expect(IntegratorCommand::query()->count())->toBe(1);
});

it('respeita --days customizado', function () {
    makeIntegratorCommand($this->ctx['integrator']->id, 'completed', now()->subDays(15));
    makeIntegratorCommand($this->ctx['integrator']->id, 'completed', now()->subDays(5));

    $this->artisan('integrator-commands:prune', ['--days' => 10])
        ->expectsOutputToContain('Linhas deletadas: 1')
        ->assertSuccessful();

    expect(IntegratorCommand::query()->count())->toBe(1);
});
