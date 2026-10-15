<?php

declare(strict_types=1);

/**
 * Agendar um retorno × confirmar/editar uma condição do MESMO paciente ao mesmo tempo, com duas conexões PostgreSQL de
 * verdade — achado via revisão adversarial Codex. O agendamento gravava o INSERT primeiro (o MarksRecallDirty prende a
 * linha da outbox do paciente até o commit) e só depois tomava a trava do paciente no recall (linkRecall); o serviço de
 * condições faz o contrário: trava do paciente, depois a outbox. Cada lado segurava o que o outro queria: deadlock
 * (40P01), o PostgreSQL abortava um deles e o agendamento virava 500 (sem nova tentativa).
 *
 * O processo filho faz o lado das condições: toma a trava do paciente, SEGURA e só então grava a outbox. O principal faz o
 * agendamento de verdade (HTTP) por cima. Na ordem nova (trava do paciente antes do INSERT) o agendamento espera o filho
 * terminar e conclui; na antiga os dois travavam. A ordem exata das instruções está em
 * tests/Feature/Recall/RecallScheduleLinkTest.php. Fica em tests/Integration (DatabaseMigrations, dados comitados) e
 * roda por caminho explícito.
 */

use App\Domains\Recall\Models\{PatientRecall, PatientRecallEvent};
use App\Enums\ClientRule;
use App\Models\{Covenant, DoctorWorkSchedule, Patient, People, User};
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\{DB, Notification, Process};
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class, DatabaseMigrations::class);

/** Ambiente do processo filho: mesmo banco de teste desta suíte. */
function srloChildEnv(): array
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

it('[GAP] agendar o retorno e confirmar uma condição do mesmo paciente ao mesmo tempo não deadlockam: o agendamento espera a trava do paciente e conclui (achado via revisão adversarial Codex)', function () {
    Notification::fake();

    ['entity' => $entity, 'doctor' => $doctor] = rcfClinic(recall: true);

    for ($d = 1; $d <= 5; $d++) {
        DoctorWorkSchedule::create(['doctor_id' => $doctor->id, 'day_of_week' => $d, 'starts_at' => '08:00:00', 'ends_at' => '12:00:00']);
    }
    $doctor->update(['schedule_interval' => 15]);

    $secretary = createEntityUser($entity, User::factory()->create(), ClientRule::Secretary->value);
    $patient   = Patient::create([
        'entity_id'   => $entity->id,
        'person_id'   => People::factory()->create(['full_name' => 'MARIA DOS RETORNOS', 'cellphone' => '11977776666'])->id,
        'covenant_id' => Covenant::factory()->create()->id,
        'active'      => true,
    ]);

    rcfRecord($entity, $patient, $doctor, now()->subDays(10)->toDateString(), 90);
    rcfEvaluate($entity, $patient, now()->toDateString());
    $recall = PatientRecall::query()->withoutGlobalScopes()->where('patient_id', $patient->id)->sole();
    $monday = Carbon::now()->next(Carbon::MONDAY)->setTime(9, 0);

    $marker = sys_get_temp_dir() . '/srlo-' . Str::uuid();

    // Filho = confirmação de condição do mesmo paciente: segura a trava do paciente 2,5 s e depois grava a outbox.
    $child = Process::path(base_path())
        ->env(srloChildEnv())
        ->timeout(60)
        ->start([PHP_BINARY, 'tests/Integration/Support/recall_condition_outbox_child.php', (string) $entity->id, (string) $patient->id, $marker, '2.5']);

    $status = null;

    try {
        $deadline = microtime(true) + 30;

        while (! is_file($marker) && microtime(true) < $deadline && $child->running()) {
            usleep(50_000);
        }

        expect(is_file($marker))->toBeTrue("o filho não chegou a travar o paciente — stdout: {$child->output()} | stderr: {$child->errorOutput()}");

        // Rede de segurança contra pendurar a suíte.
        DB::statement("set statement_timeout = '20s'");

        $startedAt = microtime(true);

        $response = $this->actingAs($secretary->user)->withSession(panelSession($secretary))
            ->postJson(route('panel.schedules.store'), [
                'doctor_id' => $doctor->id, 'patient_id' => $patient->id, 'full_name' => 'MARIA DOS RETORNOS',
                'date_time' => $monday->format('Y-m-d H:i'), 'recall_id' => (string) $recall->id,
            ]);

        $waited      = microtime(true) - $startedAt;
        $status      = $response->getStatusCode();
        $childResult = $child->wait();
    } finally {
        @unlink($marker);
    }

    // Deadlock (40P01) derrubaria um dos dois lados: o agendamento viraria 500 ou o filho falharia.
    expect($status)->toBe(201)
        ->and($childResult->errorOutput())->not->toContain('40P01')
        ->and($childResult->successful())->toBeTrue("filho falhou — stderr: {$childResult->errorOutput()}")
        // O agendamento esperou a trava do filho (que a segura 2,5 s) e depois concluiu.
        ->and($waited)->toBeGreaterThan(1.0)
        ->and($recall->fresh()->scheduled_schedule_id)->not->toBeNull()
        ->and(PatientRecallEvent::query()->withoutGlobalScopes()->where('recall_id', $recall->id)->where('event', 'scheduled')->count())->toBe(1);
});
