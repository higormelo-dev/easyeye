<?php

declare(strict_types=1);

/**
 * Duas transições para Atendido do MESMO paciente/dia — achado via revisão
 * adversarial Codex: cada uma travava a linha do PRÓPRIO agendamento
 * (refreshSituationUnderLock) e só então a reconciliação de capturas órfãs
 * (relinkBrowserCapturesForSchedule → resolveAttendedScheduleId) tentava
 * travar TODOS os agendamentos do paciente naquele dia. Com 2 agendamentos e
 * uma captura órfã sem médico, cada transação segurava um e esperava o do
 * outro: deadlock. O PostgreSQL aborta uma delas e changeSituation() não
 * tenta de novo — um "Atendido" válido virava erro.
 *
 * Dois PROCESSOS de verdade (duas sessões PostgreSQL): o filho faz a transição
 * de um agendamento e segura a trava da linha; o principal faz a do outro
 * agendamento nessa janela. Por isso este arquivo fica em tests/Integration
 * (DatabaseMigrations, dados comitados) e roda por caminho explícito.
 */

use App\Enums\{ExamSource, ScheduleSituation};
use App\Models\{Entity, Patient, PatientExam, Schedule};
use App\Services\ScheduleService;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\{DB, Process};
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class, DatabaseMigrations::class);

function adlEnv(): array
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

it('[GAP] duas transições para Atendido do mesmo paciente/dia (2 agendamentos + captura órfã sem médico) terminam as duas — sem deadlock', function () {
    $entity  = Entity::factory()->create(['is_client' => true, 'active' => true]);
    $patient = Patient::factory()->create(['entity_id' => $entity->id]);
    $day     = now()->subDay()->setTime(9, 0);

    ['schedule' => $scheduleA] = createScheduleForEntity($entity, [
        'patient_id' => $patient->id, 'situation' => ScheduleSituation::Waiting->value, 'date_time' => $day,
    ]);
    ['schedule' => $scheduleB] = createScheduleForEntity($entity, [
        'patient_id' => $patient->id, 'situation' => ScheduleSituation::Waiting->value, 'date_time' => $day->copy()->addHour(),
    ]);

    // Captura órfã SEM médico: o filtro da reconciliação não restringe por
    // doctor_id e a trava cobre os DOIS agendamentos do dia.
    $capture = PatientExam::factory()->create([
        'patient_id' => $patient->id, 'doctor_id' => null, 'schedule_id' => null,
        'source'     => ExamSource::BrowserCapture->value, 'exam_performed_at' => $day->toDateString(),
    ]);

    $marker = sys_get_temp_dir() . '/adl-' . Str::uuid();

    // Filho: transição do agendamento A, segurando a trava de A por 2,5 s.
    $child = Process::path(base_path())
        ->env(adlEnv())
        ->timeout(60)
        ->start([PHP_BINARY, 'tests/Integration/Support/attended_child.php', (string) $scheduleA->id, $marker, '2.5']);

    try {
        $deadline = microtime(true) + 30;

        while (! is_file($marker) && microtime(true) < $deadline && $child->running()) {
            usleep(50_000);
        }

        expect(is_file($marker))->toBeTrue("o filho não chegou a travar o agendamento — stdout: {$child->output()} | stderr: {$child->errorOutput()}");

        // Principal: transição do agendamento B enquanto o filho segura A.
        // statement_timeout = rede de segurança contra pendurar a suíte.
        DB::statement("set statement_timeout = '20s'");

        $parentError = null;

        try {
            app(ScheduleService::class)->changeSituation(Schedule::query()->withoutGlobalScopes()->findOrFail($scheduleB->id), ScheduleSituation::Attended, null);
        } catch (Throwable $e) {
            $parentError = $e;
        }

        $result = $child->wait();
    } finally {
        @unlink($marker);
    }

    // Nenhum dos dois pode ter sido abortado como vítima de deadlock (40P01).
    expect($parentError?->getMessage())->toBeNull()
        ->and($result->errorOutput())->not->toContain('40P01')
        ->and($result->successful())->toBeTrue("filho falhou — stderr: {$result->errorOutput()}");

    expect(Schedule::query()->withoutGlobalScopes()->whereKey($scheduleA->id)->value('situation'))->toBe(ScheduleSituation::Attended)
        ->and(Schedule::query()->withoutGlobalScopes()->whereKey($scheduleB->id)->value('situation'))->toBe(ScheduleSituation::Attended)
        // Serializadas: a transição que segurava o lock (o filho, agendamento A)
        // termina primeiro e leva o vínculo da captura órfã; a 2ª (B) já não
        // acha órfã nenhuma. Nunca vínculo duplicado nem perdido.
        ->and($capture->fresh()->schedule_id)->toBe((string) $scheduleA->id);
});
