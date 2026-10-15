<?php

declare(strict_types=1);

/**
 * recall:verify-integrity — a verificação noturna: tudo íntegro sai em silêncio e com sucesso;
 * divergência tira a versão de uso, grava o evento e FAZ O COMANDO FALHAR (o monitoramento vê).
 */

use App\Domains\Recall\Enums\ProtocolVersionState as State;
use App\Domains\Recall\Services\ProtocolGovernanceService;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\{Artisan, Exceptions, Notification};

beforeEach(fn () => Notification::fake());

it('sem divergência: sucesso e conta o que conferiu', function () {
    $released = app(ProtocolGovernanceService::class)->release(crfValidatedVersion()['version']);
    crfFrozenVersion('dmri');

    $this->artisan('recall:verify-integrity')
        ->expectsOutput('Versões conferidas: 2')
        ->assertExitCode(0);

    expect($released->fresh()->state)->toBe(State::Released);
});

it('com divergência: suspende, nomeia o protocolo/versão/ação, falha o comando e reporta para o monitoramento', function () {
    Exceptions::fake();

    $released = app(ProtocolGovernanceService::class)->release(crfValidatedVersion()['version']);
    crfTamperVersionHash($released);

    $exit = Artisan::call('recall:verify-integrity');

    expect($exit)->toBe(1)
        ->and(Artisan::output())->toContain('Versões conferidas: 1')->toContain('INTEGRIDADE: glaucoma v1')->toContain('suspended');

    expect($released->fresh()->state)->toBe(State::Suspended);

    Exceptions::assertReported(fn (RuntimeException $e): bool => str_contains($e->getMessage(), 'INTEGRIDADE: glaucoma v1'));
});

it('a 2ª noite, sem novidade, o comando volta a ter sucesso (a divergência já foi tratada)', function () {
    $released = app(ProtocolGovernanceService::class)->release(crfValidatedVersion()['version']);
    crfTamperVersionHash($released);

    $this->artisan('recall:verify-integrity')->assertExitCode(1);
    $this->artisan('recall:verify-integrity')->assertExitCode(0);
});

it('está no agendador, todo dia às 05:15, sem sobreposição', function () {
    $event = collect(app(Schedule::class)->events())->first(fn ($event) => str_contains((string) $event->command, 'recall:verify-integrity'));

    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('15 5 * * *')
        ->and($event->withoutOverlapping)->toBeTrue();
});
