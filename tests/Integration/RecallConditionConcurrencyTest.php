<?php

declare(strict_types=1);

/**
 * Condições crônicas do paciente × duas sessões PostgreSQL de verdade (ADR 0002, item 8).
 *
 * Em cada cenário o processo FILHO faz a operação e SEGURA a transação aberta alguns segundos (com a trava do
 * paciente e a linha da condição); o principal tenta a operação concorrente nessa janela. O serviço tem que ESPERAR
 * a trava e, depois do commit do outro, decidir pelo estado COMITADO: nunca duas decisões sobre a mesma sugestão,
 * nunca uma condição aberta duplicada, nunca o avaliador lendo a condição pela metade.
 *
 * Fica em tests/Integration (DatabaseMigrations, dados comitados) e roda por caminho explícito; depois:
 * php artisan migrate:fresh --env=testing --force.
 */

use App\Domains\Recall\Enums\ConditionStatus;
use App\Domains\Recall\Exceptions\PatientConditionException as Refused;
use App\Domains\Recall\Models\PatientCondition;
use App\Domains\Recall\Services\PatientConditionService;
use App\Enums\Eye;
use App\Models\{EntityUser, User};
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\{DB, Notification, Process};
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class, DatabaseMigrations::class);

/** Ambiente do processo filho: mesmo banco de teste desta suíte. */
function rccdChildEnv(array $extra = []): array
{
    $db = config('database.connections.' . config('database.default'));

    return $extra + [
        'APP_ENV'          => 'testing',
        'CACHE_STORE'      => 'array',
        'SESSION_DRIVER'   => 'array',
        'QUEUE_CONNECTION' => 'sync',
        'MAIL_MAILER'      => 'array',
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
function rccdRaceAgainstChild(string $mode, string $id, callable $mainOperation, array $env, float $hold = 2.5): array
{
    $marker = sys_get_temp_dir() . '/rccd-' . Str::uuid();

    $child = Process::path(base_path())
        ->env(rccdChildEnv($env))
        ->timeout(60)
        ->start([PHP_BINARY, 'tests/Integration/Support/recall_condition_child.php', $mode, $id, $marker, (string) $hold]);

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

    ['entity' => $this->entity, 'doctor' => $this->doctor] = rcfClinic(recall: true);
    $this->actor                                           = User::query()->findOrFail(EntityUser::query()->findOrFail($this->doctor->entity_user_id)->user_id);
    $this->patient                                         = rcfPatient($this->entity);
    $this->service                                         = app(PatientConditionService::class);
    $this->today                                           = CarbonImmutable::parse('2026-02-01');
    $this->env                                             = ['RCC_ENTITY' => (string) $this->entity->id, 'RCC_USER' => (string) $this->actor->id, 'RCC_PATIENT' => (string) $this->patient->id];

    // Uma condição que o sistema detectou e ninguém decidiu (CID forte em prontuário editável → sugerida).
    rcfAdopt($this->entity, 'glaucoma');
    rcfRecord($this->entity, $this->patient, $this->doctor, '2026-01-10', cids: ['H40.1'], signed: false);
    rcfEvaluate($this->entity, $this->patient);

    $this->condition = PatientCondition::query()->withoutGlobalScopes()->where('patient_id', $this->patient->id)->sole();
});

it('[CORRIDA] confirmar × descartar: o descarte espera, vê a condição ATIVA e é recusado — fica a decisão de quem chegou primeiro', function () {
    [$waited, $thrown, $child] = rccdOnCondition('confirm', fn () => $this->service->reject((string) $this->entity->id, (string) $this->condition->id, $this->actor, $this->today));

    expect($child->successful())->toBeTrue("filho falhou — stderr: {$child->errorOutput()}")
        ->and($waited)->toBeGreaterThan(1.0)
        ->and($thrown)->toBeInstanceOf(Refused::class)
        ->and($thrown->reason())->toBe(Refused::NOT_SUGGESTED)
        ->and($this->condition->fresh()->status)->toBe(ConditionStatus::Active)
        ->and($this->condition->fresh()->rejected_at)->toBeNull();
});

it('[CORRIDA] descartar × confirmar: a confirmação espera, vê a condição DESCARTADA e é recusada — nada vira ativo', function () {
    [$waited, $thrown, $child] = rccdOnCondition('reject', fn () => $this->service->confirm((string) $this->entity->id, (string) $this->condition->id, $this->actor, $this->today));

    expect($child->successful())->toBeTrue("filho falhou — stderr: {$child->errorOutput()}")
        ->and($waited)->toBeGreaterThan(1.0)
        ->and($thrown)->toBeInstanceOf(Refused::class)
        ->and($thrown->reason())->toBe(Refused::NOT_SUGGESTED)
        ->and($this->condition->fresh()->status)->toBe(ConditionStatus::Rejected)
        ->and($this->condition->fresh()->confirmed_at)->toBeNull();
});

it('[CORRIDA] duas altas: a 2ª espera, vê a condição com alta e é recusada — o motivo gravado é o da 1ª', function () {
    $this->service->confirm((string) $this->entity->id, (string) $this->condition->id, $this->actor, $this->today);

    [$waited, $thrown, $child] = rccdOnCondition('discharge', fn () => $this->service->discharge((string) $this->entity->id, (string) $this->condition->id, 'Segunda alta, que não pode valer.', $this->actor, $this->today));

    expect($child->successful())->toBeTrue("filho falhou — stderr: {$child->errorOutput()}")
        ->and($waited)->toBeGreaterThan(1.0)
        ->and($thrown)->toBeInstanceOf(Refused::class)
        ->and($thrown->reason())->toBe(Refused::NOT_ACTIVE)
        ->and($this->condition->fresh()->discharge_reason)->toBe('Alta dada pelo processo filho do teste.');
});

it('[CORRIDA] confirmar × avaliação do paciente: o avaliador espera e lê a condição JÁ ativa — os retornos nascem uma vez só', function () {
    [$waited, $thrown, $child] = rccdOnCondition('confirm', fn () => rcfEvaluate($this->entity, $this->patient));

    $recalls = DB::table('patient_recalls')->where('patient_id', $this->patient->id)->where('source', 'protocol')->get();

    expect($child->successful())->toBeTrue("filho falhou — stderr: {$child->errorOutput()}")
        ->and($waited)->toBeGreaterThan(1.0)
        ->and($thrown)->toBeNull()
        ->and($this->condition->fresh()->status)->toBe(ConditionStatus::Active)
        ->and($recalls->pluck('task_key')->sort()->values()->all())->toBe(['campimetria', 'consulta']) // sem duplicar
        ->and($recalls->pluck('lifecycle')->unique()->all())->not->toContain('closed');
});

it('[CORRIDA] dois registros à mão do mesmo olho: o 2º espera, vê a condição COMITADA e recebe o erro de negócio — nunca violação de índice', function () {
    // A condição sugerida do beforeEach cobre AO: remove para o cenário ser só o do registro à mão.
    $this->service->reject((string) $this->entity->id, (string) $this->condition->id, $this->actor, $this->today);

    [$waited, $thrown, $child] = rccdRaceAgainstChild(
        'add',
        (string) $this->patient->id,
        fn () => $this->service->add((string) $this->entity->id, (string) $this->patient->id, 'glaucoma', Eye::Right, null, $this->actor, $this->today),
        $this->env,
    );

    expect($child->successful())->toBeTrue("filho falhou — stderr: {$child->errorOutput()}")
        ->and($waited)->toBeGreaterThan(1.0)
        ->and($thrown)->toBeInstanceOf(Refused::class)
        ->and($thrown->reason())->toBe(Refused::ALREADY_OPEN)
        ->and(PatientCondition::query()->withoutGlobalScopes()->where('patient_id', $this->patient->id)->open()->count())->toBe(1);
});

it('[CORRIDA] avaliador já dentro × confirmar: ordem das travas paciente → linha — sem deadlock; a confirmação espera e vê a condição que a avaliação ativou', function () {
    // Prontuário assinado depois da sugestão (escrita direta, sem evento): a próxima avaliação ATIVA a condição.
    rcfRecord($this->entity, $this->patient, $this->doctor, '2026-01-05', cids: ['H40.1'], signed: true);

    [$waited, $thrown, $child] = rccdRaceAgainstChild(
        'evaluate-late',
        (string) $this->patient->id,
        fn () => $this->service->confirm((string) $this->entity->id, (string) $this->condition->id, $this->actor, $this->today),
        $this->env,
        4.0,
    );

    expect($child->successful())->toBeTrue("filho falhou — stderr: {$child->errorOutput()}")
        // Sem a trava do paciente PRIMEIRO, a confirmação segurava a linha e esperava o paciente enquanto o avaliador
        // esperava a linha: o PostgreSQL abortava um dos dois (40P01).
        ->and($thrown)->toBeInstanceOf(Refused::class)
        ->and($thrown->reason())->toBe(Refused::NOT_SUGGESTED)
        ->and($waited)->toBeGreaterThan(1.0)
        ->and($this->condition->fresh()->status)->toBe(ConditionStatus::Active);
});

/** Atalho: o filho age sobre a condição do cenário. */
function rccdOnCondition(string $mode, callable $main): array
{
    return rccdRaceAgainstChild($mode, (string) test()->condition->id, $main, test()->env);
}
