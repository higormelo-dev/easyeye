<?php

declare(strict_types=1);

/**
 * Dois agendamentos Atendidos do MESMO paciente trocando de dia em sentidos
 * opostos (X→Y e Y→X) ao mesmo tempo, com capturas órfãs nos dois dias, e duas
 * conexões PostgreSQL de verdade — achado via revisão adversarial Codex: o PUT
 * da agenda só tomava o lock paciente/dia do DESTINO. A reconciliação das
 * capturas trava TODOS os agendamentos do paciente naquele dia (visíveis pela
 * identidade já comitada): cada edição, segurando a linha do PRÓPRIO
 * agendamento, esperava a do outro — que, pro banco, ainda estava no dia
 * original. Deadlock; o PostgreSQL abortava uma e uma edição válida virava 500.
 *
 * Agora o PUT trava o paciente/dia de ORIGEM e o de DESTINO, em ordem
 * determinística, antes de qualquer linha. Aqui: o filho faz uma das edições
 * (fluxo do controller, segurando a transação depois do UPDATE) e o principal
 * faz a oposta pelo HTTP de verdade. Fica em tests/Integration
 * (DatabaseMigrations, dados comitados) e roda por caminho explícito.
 */

use App\Enums\{ClientRule, ExamSource, ScheduleSituation};
use App\Models\{Covenant, DoctorWorkSchedule, Entity, ExamType, Patient, PatientExam, Schedule, User};
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\{DB, Process};
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class, DatabaseMigrations::class);

/** Ambiente do processo filho: mesmo banco de teste desta suíte. */
function aomChildEnv(): array
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

/**
 * Dois Atendidos do mesmo paciente (S1 na segunda, S2 na terça) e capturas
 * órfãs nos dois dias — o que dispara a reconciliação (e as travas de linha) de
 * cada edição.
 *
 * @return array{entity: Entity, patient: Patient, doctor: object, secretary: object, dayX: Carbon, dayY: Carbon, s1: Schedule, s2: Schedule, orphanY: PatientExam}
 */
function aomScenario(): array
{
    $entity  = Entity::factory()->create(['is_client' => true, 'active' => true]);
    $patient = Patient::factory()->create(['entity_id' => $entity->id, 'covenant_id' => Covenant::factory()->create(['entity_id' => $entity->id, 'active' => true])->id]);
    $doctor  = createDoctorForEntity($entity);

    for ($d = 1; $d <= 5; $d++) {
        DoctorWorkSchedule::create(['doctor_id' => $doctor->id, 'day_of_week' => $d, 'starts_at' => '08:00:00', 'ends_at' => '12:00:00']);
    }
    $doctor->update(['schedule_interval' => 15]);

    $secretary = createEntityUser($entity, User::factory()->create(), ClientRule::Secretary->value);
    $dayX      = Carbon::now()->next(Carbon::MONDAY)->setTime(9, 0);
    $dayY      = $dayX->copy()->addDay();

    $attended = fn (Carbon $at) => Schedule::create([
        'entity_id' => $entity->id, 'doctor_id' => $doctor->id, 'patient_id' => $patient->id,
        'full_name' => 'PACIENTE TROCA', 'date_time' => $at, 'situation' => ScheduleSituation::Attended->value, 'active' => true,
    ]);
    $s1 = $attended($dayX); // vai X → Y (principal, pelo HTTP)
    $s2 = $attended($dayY); // vai Y → X (filho)

    $examType = ExamType::factory()->create(['entity_id' => null]);
    $orphan   = fn (Carbon $at) => PatientExam::factory()->create([
        'patient_id' => $patient->id, 'exam_id' => $examType->id, 'doctor_id' => $doctor->id, 'schedule_id' => null,
        'source'     => ExamSource::BrowserCapture->value, 'exam_performed_at' => $at->copy()->setTime(11, 0), 'active' => true,
    ]);
    $orphanY = $orphan($dayY);
    $orphan($dayX);

    return compact('entity', 'patient', 'doctor', 'secretary', 'dayX', 'dayY', 's1', 's2', 'orphanY');
}

it('[GAP] duas edições Atendido em sentidos opostos (X→Y e Y→X) do mesmo paciente, com capturas órfãs nos dois dias, terminam as duas — sem deadlock (achado via revisão adversarial Codex)', function (string $mode) {
    ['patient' => $patient, 'doctor' => $doctor, 'secretary' => $secretary, 'dayX' => $dayX, 'dayY' => $dayY, 's1' => $s1, 's2' => $s2, 'orphanY' => $orphanY] = aomScenario();

    $marker = sys_get_temp_dir() . '/aom-' . Str::uuid();

    // Filho: S2 Y → X às 09:30 (horários de destino distintos), segurando a transação — depois do
    // UPDATE (a linha do S2 na mão) ou logo depois do PRIMEIRO lock paciente/dia (o par pedido na ordem inversa).
    $child = Process::path(base_path())
        ->env(aomChildEnv())
        ->timeout(60)
        ->start([PHP_BINARY, 'tests/Integration/Support/schedule_edit_child.php', (string) $s2->id, $dayX->copy()->setTime(9, 30)->format('Y-m-d H:i:s'), $marker, '2.5', $mode]);

    $status = null;

    try {
        $deadline = microtime(true) + 30;

        while (! is_file($marker) && microtime(true) < $deadline && $child->running()) {
            usleep(50_000);
        }

        expect(is_file($marker))->toBeTrue("o filho não chegou à pausa — stdout: {$child->output()} | stderr: {$child->errorOutput()}");

        // statement_timeout = rede de segurança contra pendurar a suíte.
        DB::statement("set statement_timeout = '20s'");

        $response = $this->actingAs($secretary->user)->withSession(panelSession($secretary))
            ->putJson(route('panel.schedules.update', $s1), [
                'doctor_id' => $doctor->id, 'patient_id' => $patient->id, 'full_name' => 'PACIENTE TROCA',
                'date_time' => $dayY->copy()->setTime(9, 30)->format('Y-m-d H:i'), 'situation' => ScheduleSituation::Attended->value,
            ]);

        $status      = $response->getStatusCode();
        $childResult = $child->wait();
    } finally {
        @unlink($marker);
    }

    // Deadlock (40P01) derrubaria um dos dois lados: a edição viraria 500 ou o filho falharia.
    expect($status)->toBe(200)
        ->and($childResult->errorOutput())->not->toContain('40P01')
        ->and($childResult->successful())->toBeTrue("filho falhou — stderr: {$childResult->errorOutput()}")
        ->and($s1->fresh()->date_time->format('Y-m-d H:i'))->toBe($dayY->copy()->setTime(9, 30)->format('Y-m-d H:i'))
        ->and($s2->fresh()->date_time->format('Y-m-d H:i'))->toBe($dayX->copy()->setTime(9, 30)->format('Y-m-d H:i'))
        // A captura do dia Y foi reconciliada com o agendamento que chegou lá.
        ->and($orphanY->fresh()->schedule_id)->toBe($s1->id);
})->with([
    'filho segura a transação depois do UPDATE (cada um com a própria linha na mão)' => 'after-update',
    'filho segura logo depois do 1º lock (o par de locks pedido na ordem inversa)'   => 'after-first-lock',
]);
