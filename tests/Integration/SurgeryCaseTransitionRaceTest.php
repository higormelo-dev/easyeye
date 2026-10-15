<?php

declare(strict_types=1);

/**
 * Módulo cirúrgico (Fase 4) — corrida real entre duas transições do MESMO caso,
 * com duas conexões PostgreSQL de verdade. O filho toma a trava da linha do caso
 * (FOR UPDATE) e a segura; o processo principal dispara a transição contrária
 * pelo HTTP, com a tela/instância carregada ANTES. A regra: quem chega depois
 * relê o estado ATUAL sob a trava e é recusado (409) — nunca passa por cima, e
 * o log de autorização/histórico fica com UM efeito só.
 *
 * Fica em tests/Integration (DatabaseMigrations, dados comitados) e roda por
 * caminho explícito.
 */

use App\Enums\Surgery\SurgicalCaseStatus;
use App\Models\Surgery\{SurgicalAuthorizationEvent, SurgicalCase};
use App\Services\Surgery\{SurgeryAuthorizationService, SurgicalCaseService};
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\{DB, Process};
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

uses(TestCase::class, DatabaseMigrations::class);

/** Ambiente do processo filho: mesmo banco de teste desta suíte. */
function scrChildEnv(): array
{
    $db = config('database.connections.' . config('database.default'));

    return [
        'APP_ENV'          => 'testing',
        'CACHE_STORE'      => 'array',
        'SESSION_DRIVER'   => 'array',
        'QUEUE_CONNECTION' => 'sync',
        'DB_CONNECTION'    => config('database.default'),
        'DB_HOST'          => (string) ($db['host'] ?? ''),
        'DB_PORT'          => (string) ($db['port'] ?? ''),
        'DB_DATABASE'      => (string) ($db['database'] ?? ''),
        'DB_USERNAME'      => (string) ($db['username'] ?? ''),
        'DB_PASSWORD'      => (string) ($db['password'] ?? ''),
    ];
}

function scrCase(object $w, bool $requested): SurgicalCase
{
    $case = app(SurgicalCaseService::class)->create((string) $w->entity->id, [
        'patient_id'        => (string) $w->patient->id,
        'surgeon_doctor_id' => (string) $w->doctor->id,
        'title'             => 'Facoemulsificação',
        'eye'               => 'od',
        'procedures'        => [['description' => 'Facoemulsificação', 'tuss_code' => '30306019', 'quantity' => 1]],
    ]);

    return $requested ? app(SurgeryAuthorizationService::class)->request($case) : $case;
}

/**
 * Sobe o filho, espera ele segurar a trava, roda a ação do principal e junta os dois.
 *
 * @return array{0: TestResponse, 1: ProcessResult}
 */
function scrRace(object $test, string $mode, SurgicalCase $case, Closure $main): array
{
    $marker = sys_get_temp_dir() . '/scr-' . Str::uuid();

    $child = Process::path(base_path())
        ->env(scrChildEnv())
        ->timeout(60)
        ->start([PHP_BINARY, 'tests/Integration/Support/surgery_case_child.php', $mode, (string) $case->id, $marker, '2.5']);

    try {
        $deadline = microtime(true) + 30;

        while (! is_file($marker) && microtime(true) < $deadline && $child->running()) {
            usleep(50_000);
        }

        expect(is_file($marker))->toBeTrue("o filho não chegou à pausa — stdout: {$child->output()} | stderr: {$child->errorOutput()}");

        // statement_timeout = rede de segurança contra pendurar a suíte.
        DB::statement("set statement_timeout = '20s'");

        $response = $main();
        $result   = $child->wait();
    } finally {
        @unlink($marker);
    }

    return [$response, $result];
}

beforeEach(function () {
    $this->w = surgeryWorld();
});

it('[GAP] resposta da operadora × resposta simultânea: uma vence, a outra relê o estado e é recusada (409)', function () {
    $case = scrCase($this->w, requested: true);

    [$response, $child] = scrRace($this, 'authorize', $case, fn () => surgeryAs($this->w, 'secretary')
        ->postJson(route('panel.surgery.authorization.record', $case), ['type' => 'authorized', 'authorization_number' => 'MAIN-1']));

    expect($child->successful())->toBeTrue("filho falhou — {$child->errorOutput()}")
        ->and($response->getStatusCode())->toBe(409);

    $case->refresh();

    expect($case->status)->toBe(SurgicalCaseStatus::Authorized)
        ->and($case->authorization_number)->toBe('CHILD-1')
        ->and(SurgicalAuthorizationEvent::query()->where('surgical_case_id', $case->id)->where('type', 'authorized')->count())->toBe(1);
});

it('[GAP] resposta da internação × resposta do OPME ao mesmo tempo: o caso fecha uma vez, com as duas, lido sob a trava', function () {
    $case = app(SurgicalCaseService::class)->create((string) $this->w->entity->id, [
        'patient_id'        => (string) $this->w->patient->id,
        'surgeon_doctor_id' => (string) $this->w->doctor->id,
        'title'             => 'Facoemulsificação com LIO',
        'eye'               => 'od',
        'needs_opme'        => true,
        'procedures'        => [['description' => 'Facoemulsificação', 'tuss_code' => '30306019', 'quantity' => 1]],
        'materials'         => [['description' => 'Lente intraocular', 'quantity' => 1, 'technical_justification' => 'Catarata densa']],
    ]);
    $case = app(SurgeryAuthorizationService::class)->request($case);

    // A instância do processo principal foi carregada ANTES, com as respostas ainda em branco.
    $stale = SurgicalCase::query()->findOrFail($case->id)->load(['procedures', 'materials']);

    [$unused, $child] = scrRace($this, 'authorize_procedures', $case, function () use ($stale) {
        app(SurgeryAuthorizationService::class)->record($stale, [
            'type'                 => 'authorized',
            'authorization_number' => 'MAIN-OPME',
            'scope'                => $stale->materials->map(fn ($m) => ['kind' => 'material', 'id' => (string) $m->getKey()])->all(),
        ]);

        return null;
    });

    expect($child->successful())->toBeTrue("filho falhou — {$child->errorOutput()}");

    $case->refresh();

    // Quem chegou depois leu as respostas do filho SOB a trava e fechou o pedido; ninguém ficou "aguardando" para sempre.
    expect($case->status)->toBe(SurgicalCaseStatus::Authorized)
        ->and($case->authorization_number)->toBe('CHILD-INT / MAIN-OPME')
        ->and($case->procedures->first()->authorized_quantity)->toBe(1)
        ->and($case->materials->first()->authorized_quantity)->toBe(1)
        ->and(SurgicalAuthorizationEvent::query()->where('surgical_case_id', $case->id)->where('type', 'authorized')->count())->toBe(1)
        ->and(SurgicalAuthorizationEvent::query()->where('surgical_case_id', $case->id)->where('type', 'note')->where('authorization_number', 'CHILD-INT')->count())->toBe(1);
});

it('[GAP] enviar o pedido × enviar de novo ao mesmo tempo: um evento "requested" só', function () {
    $case = scrCase($this->w, requested: false);

    [$response, $child] = scrRace($this, 'request', $case, fn () => surgeryAs($this->w, 'secretary')
        ->postJson(route('panel.surgery.authorization.request', $case)));

    expect($child->successful())->toBeTrue("filho falhou — {$child->errorOutput()}")
        ->and($response->getStatusCode())->toBe(409);

    expect(SurgicalAuthorizationEvent::query()->where('surgical_case_id', $case->id)->where('type', 'requested')->count())->toBe(1)
        ->and($case->refresh()->status)->toBe(SurgicalCaseStatus::AwaitingAuthorization);
});

it('[GAP] cancelar × enviar para autorização: o envio vê o caso cancelado e é recusado, sem evento', function () {
    $case = scrCase($this->w, requested: false);

    [$response, $child] = scrRace($this, 'cancel', $case, fn () => surgeryAs($this->w, 'secretary')
        ->postJson(route('panel.surgery.authorization.request', $case)));

    expect($child->successful())->toBeTrue("filho falhou — {$child->errorOutput()}")
        ->and($response->getStatusCode())->toBe(409);

    $case->refresh();

    expect($case->status)->toBe(SurgicalCaseStatus::Cancelled)
        ->and(SurgicalAuthorizationEvent::query()->where('surgical_case_id', $case->id)->count())->toBe(0)
        ->and(collect($case->status_history)->pluck('to')->all())->toBe(['draft', 'cancelled']);
});

it('[GAP] suspender × cancelar: o histórico fica coerente (draft → suspended → cancelled)', function () {
    $case = scrCase($this->w, requested: false);

    [$response, $child] = scrRace($this, 'suspend', $case, fn () => surgeryAs($this->w, 'secretary')
        ->postJson(route('panel.surgery.cancel', $case), ['reason' => 'Paciente desistiu']));

    expect($child->successful())->toBeTrue("filho falhou — {$child->errorOutput()}")
        ->and($response->getStatusCode())->toBe(302);

    $case->refresh();

    // Suspenso pelo filho primeiro; o cancelamento relê o estado atual e parte da suspensão.
    expect($case->status)->toBe(SurgicalCaseStatus::Cancelled)
        ->and(collect($case->status_history)->pluck('to')->all())->toBe(['draft', 'suspended', 'cancelled']);
});

it('[GAP] edição com a tela velha (revisão antiga) depois de o filho mexer no caso: 409 e nada gravado', function () {
    $case     = scrCase($this->w, requested: false);
    $revision = $case->revision;

    [$response, $child] = scrRace($this, 'suspend', $case, fn () => surgeryAs($this->w, 'secretary')
        ->putJson(route('panel.surgery.update', $case), ['notes' => 'edição velha', 'expected_revision' => $revision]));

    expect($child->successful())->toBeTrue("filho falhou — {$child->errorOutput()}")
        ->and($response->getStatusCode())->toBe(409);

    expect($case->refresh()->notes)->toBeNull();
});
