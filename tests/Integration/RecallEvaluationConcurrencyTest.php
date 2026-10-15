<?php

declare(strict_types=1);

/**
 * O recall × duas sessões PostgreSQL de verdade (ADR 0002, item 7). Em cada cenário o processo FILHO faz a
 * operação e SEGURA a transação aberta alguns segundos; o principal tenta a operação concorrente nessa janela.
 * Tem que ESPERAR a trava do paciente e decidir pelo estado COMITADO: nunca duas linhas do mesmo retorno, a
 * decisão da equipe (dispensar) nunca sobrescrita pela reavaliação, o dreno da outbox nunca bloqueado nem
 * duplicado.
 *
 * Fica em tests/Integration (DatabaseMigrations, dados comitados) e roda por caminho explícito; depois:
 * php artisan migrate:fresh --env=testing --force.
 */

use App\Domains\Recall\Enums\RecallLifecycle as Life;
use App\Domains\Recall\Exceptions\RecallActionException;
use App\Domains\Recall\Models\{PatientRecall, PatientRecallEvent};
use App\Domains\Recall\Services\{RecallActionService, RecallDirtyDrainer};
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\{DB, Notification, Process};
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class, DatabaseMigrations::class);

/** Ambiente do processo filho: mesmo banco de teste desta suíte. */
function rceChildEnv(): array
{
    $db = config('database.connections.' . config('database.default'));

    return [
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
function rceRaceAgainstChild(string $mode, string $entityId, string $target, callable $mainOperation, float $hold = 2.5): array
{
    $marker = sys_get_temp_dir() . '/rce-' . Str::uuid();

    $child = Process::path(base_path())
        ->env(rceChildEnv())
        ->timeout(60)
        ->start([PHP_BINARY, 'tests/Integration/Support/recall_child.php', $mode, $entityId, $target, $marker, (string) $hold, '2026-02-01']);

    try {
        $deadline = microtime(true) + 30;

        while (! is_file($marker) && microtime(true) < $deadline && $child->running()) {
            usleep(50_000);
        }

        DB::statement("set statement_timeout = '30s'"); // rede de segurança contra pendurar a suíte

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
    $this->patient                                         = rcfPatient($this->entity);
    rcfRecord($this->entity, $this->patient, $this->doctor, '2026-01-10', 90);
    DB::table('recall_dirty_patients')->delete();
});

it('[CORRIDA] duas avaliações do mesmo paciente: a 2ª espera, vê a linha comitada e NÃO a duplica (nem estoura o índice único)', function () {
    [$waited, $thrown, $child] = rceRaceAgainstChild('evaluate', (string) $this->entity->id, (string) $this->patient->id, fn () => rcfEvaluate($this->entity, $this->patient));

    expect($child->successful())->toBeTrue("filho falhou — stderr: {$child->errorOutput()}")
        ->and($waited)->toBeGreaterThan(1.0)
        ->and($thrown)->toBeNull()
        ->and(PatientRecall::query()->withoutGlobalScopes()->where('patient_id', $this->patient->id)->count())->toBe(1)
        ->and(PatientRecallEvent::query()->withoutGlobalScopes()->where('event', 'calculated')->count())->toBe(1);
});

it('[CORRIDA] dispensar × reavaliação em andamento: a dispensa espera, acha a linha e dispensa — a reavaliação seguinte NÃO a reabre', function () {
    [$waited, $thrown, $child] = rceRaceAgainstChild(
        'evaluate',
        (string) $this->entity->id,
        (string) $this->patient->id,
        function () {
            // A linha só existe depois que o filho comita: a ação precisa esperar a trava do paciente para enxergá-la.
            $id = null;

            for ($attempt = 0; $attempt < 40 && $id === null; $attempt++) {
                $id = DB::table('patient_recalls')->where('patient_id', $this->patient->id)->value('id');
                $id ?? usleep(50_000);
            }

            if ($id === null) {
                // Ainda não comitada: o id vem da linha que o filho vai gravar — espera o commit pela trava.
                DB::select('SELECT pg_advisory_lock(hashtext(?), hashtext(?))', ['easyeye.recall.patient', (string) $this->patient->id]);
                DB::select('SELECT pg_advisory_unlock(hashtext(?), hashtext(?))', ['easyeye.recall.patient', (string) $this->patient->id]);
                $id = DB::table('patient_recalls')->where('patient_id', $this->patient->id)->value('id');
            }

            app(RecallActionService::class)->dismiss((string) $this->entity->id, (string) $id, 'Dispensado enquanto reavaliava.');
        },
    );

    $row = PatientRecall::query()->withoutGlobalScopes()->where('patient_id', $this->patient->id)->sole();

    expect($child->successful())->toBeTrue("filho falhou — stderr: {$child->errorOutput()}")
        ->and($waited)->toBeGreaterThan(1.0)
        ->and($thrown)->toBeNull()
        ->and($row->lifecycle)->toBe(Life::Dismissed);

    rcfEvaluate($this->entity, $this->patient);

    expect($row->fresh()->lifecycle)->toBe(Life::Dismissed);
});

it('[CORRIDA] reavaliação × dispensa em andamento: a reavaliação espera e MANTÉM a dispensa (a decisão comitada vale)', function () {
    rcfEvaluate($this->entity, $this->patient);
    $row = PatientRecall::query()->withoutGlobalScopes()->where('patient_id', $this->patient->id)->sole();

    [$waited, $thrown, $child] = rceRaceAgainstChild('dismiss', (string) $this->entity->id, (string) $row->id, fn () => rcfEvaluate($this->entity, $this->patient));

    expect($child->successful())->toBeTrue("filho falhou — stderr: {$child->errorOutput()}")
        ->and($waited)->toBeGreaterThan(1.0)
        ->and($thrown)->toBeNull()
        ->and($row->fresh()->lifecycle)->toBe(Life::Dismissed)
        ->and($row->fresh()->dismissed_reason)->toBe('Dispensado pelo processo filho do teste.');
});

it('[CORRIDA] duas dispensas da mesma linha: a 2ª espera, vê a linha já dispensada e é recusada — um só evento', function () {
    rcfEvaluate($this->entity, $this->patient);
    $row = PatientRecall::query()->withoutGlobalScopes()->where('patient_id', $this->patient->id)->sole();

    [$waited, $thrown, $child] = rceRaceAgainstChild('dismiss', (string) $this->entity->id, (string) $row->id, fn () => app(RecallActionService::class)->dismiss((string) $this->entity->id, (string) $row->id, 'Segunda dispensa, em paralelo.'));

    expect($child->successful())->toBeTrue("filho falhou — stderr: {$child->errorOutput()}")
        ->and($waited)->toBeGreaterThan(1.0)
        ->and($thrown)->toBeInstanceOf(RecallActionException::class)
        ->and($thrown->reason())->toBe(RecallActionException::NOT_WORKABLE)
        ->and(PatientRecallEvent::query()->withoutGlobalScopes()->where('recall_id', $row->id)->where('event', 'dismissed')->count())->toBe(1)
        ->and($row->fresh()->dismissed_reason)->toBe('Dispensado pelo processo filho do teste.');
});

it('[CORRIDA] dois drenos: o 2º NÃO espera o 1º (SKIP LOCKED) e nunca reivindica o paciente que o outro tem em mãos', function () {
    $others = collect(range(1, 2))->map(function () {
        $patient = rcfPatient($this->entity);
        rcfRecord($this->entity, $patient, $this->doctor, '2026-01-10', 90);

        return $patient;
    });

    DB::table('recall_dirty_patients')->delete();

    // Marcas com datas distintas: o filho (ORDER BY marked_at LIMIT 1) reivindica a mais antiga.
    DB::table('recall_dirty_patients')->insert([
        ['entity_id' => $this->entity->id, 'patient_id' => $this->patient->id, 'reason' => 'medical_record', 'marked_at' => now()->subMinutes(10)],
        ['entity_id' => $this->entity->id, 'patient_id' => $others[0]->id, 'reason' => 'medical_record', 'marked_at' => now()->subMinutes(9)],
        ['entity_id' => $this->entity->id, 'patient_id' => $others[1]->id, 'reason' => 'medical_record', 'marked_at' => now()->subMinutes(8)],
    ]);

    $stats = null;

    [$waited, $thrown, $child] = rceRaceAgainstChild('claim', (string) $this->entity->id, '1', function () use (&$stats) {
        $stats = app(RecallDirtyDrainer::class)->drain(100, CarbonImmutable::parse('2026-02-01'));
    });

    $claimedByChild = trim(Str::after($child->output(), 'OK'));

    expect($child->successful())->toBeTrue("filho falhou — stderr: {$child->errorOutput()}")
        ->and($claimedByChild)->toBe((string) $this->patient->id)
        ->and($thrown)->toBeNull()
        ->and($waited)->toBeLessThan(2.0) // não esperou os 2,5 s da trava do filho
        ->and($stats['claimed'])->toBe(2)
        ->and(DB::table('patient_recalls')->where('patient_id', $this->patient->id)->count())->toBe(0) // o do filho, a outra rodada
        ->and(DB::table('patient_recalls')->whereIn('patient_id', $others->pluck('id'))->count())->toBe(2);
});
