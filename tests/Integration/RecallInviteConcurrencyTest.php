<?php

declare(strict_types=1);

/**
 * Convite de retorno por WhatsApp × duas sessões PostgreSQL de verdade (ADR 0002, item 9): o clique de uma pessoa e a
 * rodada automática, duas rodadas e o teto diário do dia decididos SOB trava da clínica; o convite × quem dispensa o
 * retorno no mesmo instante.
 *
 * Em cada cenário o processo FILHO faz a operação e SEGURA a transação aberta alguns segundos (com as travas da
 * clínica e do paciente tomadas); o principal tenta a operação concorrente nessa janela. O serviço tem que ESPERAR a
 * trava e, depois do commit do outro, decidir pelo estado COMITADO: nunca duas ondas do mesmo paciente no dia, nunca
 * um convite acima do teto, nunca um convite para o retorno que acabou de ser dispensado.
 *
 * Fica em tests/Integration (DatabaseMigrations, dados comitados) e roda por caminho explícito; depois:
 * php artisan migrate:fresh --env=testing --force.
 */

use App\Domains\Recall\Enums\InviteBlocker;
use App\Domains\Recall\Exceptions\RecallInviteException;
use App\Domains\Recall\Models\{RecallClinicSettings, RecallInvite};
use App\Domains\Recall\Services\{RecallActionService, RecallInviteService};
use App\Models\WhatsApp\WhatsAppMessage;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\{DB, Notification, Process};
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class, DatabaseMigrations::class);

/** Ambiente do processo filho: mesmo banco de teste desta suíte. */
function ricChildEnv(array $extra = []): array
{
    $db = config('database.connections.' . config('database.default'));

    return $extra + [
        'APP_ENV'          => 'testing',
        'CACHE_STORE'      => 'array',
        'SESSION_DRIVER'   => 'array',
        'QUEUE_CONNECTION' => 'sync',
        'MAIL_MAILER'      => 'array',
        'WHATSAPP_DRIVER'  => 'mock',
        'DB_CONNECTION'    => config('database.default'),
        'DB_HOST'          => (string) ($db['host'] ?? ''),
        'DB_PORT'          => (string) ($db['port'] ?? ''),
        'DB_DATABASE'      => (string) ($db['database'] ?? ''),
        'DB_USERNAME'      => (string) ($db['username'] ?? ''),
        'DB_PASSWORD'      => (string) ($db['password'] ?? ''),
    ];
}

/**
 * Sobe o filho, espera ele estar com a operação feita e a transação aberta (marcador) e roda `$mainOperation` no
 * principal nessa janela.
 *
 * @return array{0: float, 1: ?Throwable, 2: ProcessResult} quanto o principal esperou, o que lançou, o resultado do filho
 */
function ricRaceAgainstChild(string $mode, string $id, callable $mainOperation, array $env = [], float $hold = 2.5): array
{
    $marker = sys_get_temp_dir() . '/ric-' . Str::uuid();

    $child = Process::path(base_path())
        ->env(ricChildEnv($env))
        ->timeout(60)
        ->start([PHP_BINARY, 'tests/Integration/Support/recall_invite_child.php', $mode, $id, $marker, (string) $hold]);

    try {
        $deadline = microtime(true) + 30;

        while (! is_file($marker) && microtime(true) < $deadline && $child->running()) {
            usleep(50_000);
        }

        // Rede de segurança contra pendurar a suíte.
        DB::statement("set statement_timeout = '30s'");

        $startedAt = microtime(true);
        $thrown    = null;

        try {
            $mainOperation();
        } catch (Throwable $e) {
            $thrown = $e;
        }

        return [microtime(true) - $startedAt, $thrown, $child->wait()];
    } finally {
        @unlink($marker);
    }
}

beforeEach(function () {
    Notification::fake();
    config()->set('whatsapp.driver', 'mock');

    ['entity' => $this->entity, 'doctor' => $this->doctor] = rcfClinic(recall: true);

    $this->sender  = rcfInviteChannel($this->entity);
    $this->patient = rcfInvitePatient($this->entity);
    // O relógio é o REAL (o filho é outro processo): o retorno é montado em relação a hoje, não a uma data fixa.
    rcfRecord($this->entity, $this->patient, $this->doctor, now()->subDays(40)->toDateString(), 30);
    rcfEvaluate($this->entity, $this->patient, now()->toDateString());
    $this->recall  = rcfRecalls($this->patient)->firstOrFail();
    $this->service = app(RecallInviteService::class);
    $this->env     = ['RIC_ENTITY' => (string) $this->entity->id];
});

/** Um paciente a mais com retorno vencido (atendimento há ~2 meses, pedido de 30 dias). */
function ricOtherPatient(object $test, string $name, string $cellphone)
{
    $patient = rcfInvitePatient($test->entity, $name, $cellphone);
    rcfRecord($test->entity, $patient, $test->doctor, now()->subDays(60)->toDateString(), 30);
    rcfEvaluate($test->entity, $patient, now()->toDateString());

    return $patient;
}

it('[CORRIDA] clique de uma pessoa × clique de outra no MESMO paciente: a 2ª espera a trava, vê o convite comitado e é recusada — uma onda só', function () {
    [$waited, $thrown, $child] = ricRaceAgainstChild(
        'invite',
        (string) $this->recall->id,
        fn () => $this->service->inviteManual((string) $this->entity->id, (string) $this->recall->id, null),
        $this->env,
    );

    expect($child->successful())->toBeTrue("filho falhou — stderr: {$child->errorOutput()}")
        ->and($waited)->toBeGreaterThan(1.0)
        ->and($thrown)->toBeInstanceOf(RecallInviteException::class)
        ->and($thrown->blockers())->toContain(InviteBlocker::TooSoon)
        ->and(RecallInvite::query()->withoutGlobalScopes()->count())->toBe(1)
        ->and(WhatsAppMessage::query()->where('kind', 'recall')->where('direction', 'out')->count())->toBe(1);
});

it('[CORRIDA] clique de uma pessoa × rodada automática: a rodada espera, não convida de novo e termina sem erro', function () {
    [$waited, $thrown, $child] = ricRaceAgainstChild(
        'invite',
        (string) $this->recall->id,
        fn () => $this->report = $this->service->runClinic((string) $this->entity->id),
        $this->env,
    );

    expect($child->successful())->toBeTrue("filho falhou — stderr: {$child->errorOutput()}")
        ->and($waited)->toBeGreaterThan(1.0)
        ->and($thrown)->toBeNull()
        ->and($this->report['queued'])->toBe(0)
        ->and($this->report['errors'])->toBe(0)
        ->and(RecallInvite::query()->withoutGlobalScopes()->count())->toBe(1);
});

it('[CORRIDA] duas rodadas automáticas ao mesmo tempo: um convite por paciente, sem erro nem deadlock', function () {
    [$waited, $thrown, $child] = ricRaceAgainstChild(
        'run',
        (string) $this->entity->id,
        fn () => $this->report = $this->service->runClinic((string) $this->entity->id),
        $this->env,
    );

    expect($child->successful())->toBeTrue("filho falhou — stderr: {$child->errorOutput()}")
        ->and($waited)->toBeGreaterThan(1.0)
        ->and($thrown)->toBeNull()
        ->and($this->report['queued'])->toBe(0)
        ->and($this->report['errors'])->toBe(0)
        ->and(RecallInvite::query()->withoutGlobalScopes()->count())->toBe(1)
        ->and(RecallInvite::query()->withoutGlobalScopes()->sole()->origin->value)->toBe('auto');
});

it('[CORRIDA] teto diário: o convite que cabia ANTES de o outro comitar não passa do teto — decidido sob a trava da clínica', function () {
    RecallClinicSettings::query()->withoutGlobalScopes()->where('entity_id', $this->entity->id)->update(['daily_invite_cap' => 1]);
    $other       = ricOtherPatient($this, 'SEGUNDO PACIENTE', '11977770002');
    $otherRecall = DB::table('patient_recalls')->where('patient_id', $other->id)->value('id');

    [$waited, $thrown, $child] = ricRaceAgainstChild(
        'invite',
        (string) $this->recall->id,
        fn () => $this->service->inviteManual((string) $this->entity->id, (string) $otherRecall, null),
        $this->env,
    );

    expect($child->successful())->toBeTrue("filho falhou — stderr: {$child->errorOutput()}")
        ->and($waited)->toBeGreaterThan(1.0)
        ->and($thrown)->toBeInstanceOf(RecallInviteException::class)
        ->and($thrown->blockers())->toContain(InviteBlocker::DailyCap)
        ->and(RecallInvite::query()->withoutGlobalScopes()->count())->toBe(1);
});

it('[CORRIDA] dispensar o retorno × convidar: o convite espera a dispensa comitar, vê o retorno dispensado e não sai', function () {
    [$waited, $thrown, $child] = ricRaceAgainstChild(
        'dismiss',
        (string) $this->recall->id,
        fn () => $this->service->inviteManual((string) $this->entity->id, (string) $this->recall->id, null),
        $this->env,
    );

    expect($child->successful())->toBeTrue("filho falhou — stderr: {$child->errorOutput()}")
        ->and($waited)->toBeGreaterThan(1.0)
        ->and($thrown)->toBeInstanceOf(RecallInviteException::class)
        ->and($thrown->blockers())->toContain(InviteBlocker::NothingToInvite)
        ->and(RecallInvite::query()->withoutGlobalScopes()->count())->toBe(0)
        ->and(WhatsAppMessage::query()->where('kind', 'recall')->count())->toBe(0);
});

it('[CORRIDA] convidar × dispensar: a dispensa espera o convite comitar e vale em seguida — sem deadlock entre clínica, paciente e linha', function () {
    [$waited, $thrown, $child] = ricRaceAgainstChild(
        'invite',
        (string) $this->recall->id,
        fn () => app(RecallActionService::class)->dismiss((string) $this->entity->id, (string) $this->recall->id, 'Dispensado em cima do convite.'),
        $this->env,
    );

    expect($child->successful())->toBeTrue("filho falhou — stderr: {$child->errorOutput()}")
        ->and($waited)->toBeGreaterThan(1.0)
        ->and($thrown)->toBeNull()
        ->and(RecallInvite::query()->withoutGlobalScopes()->count())->toBe(1)
        ->and(DB::table('patient_recalls')->where('id', $this->recall->id)->value('lifecycle'))->toBe('dismissed');
});
