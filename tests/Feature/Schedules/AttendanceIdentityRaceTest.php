<?php

declare(strict_types=1);

/**
 * Atendido sobre um agendamento cuja IDENTIDADE (paciente, dia, médico) mudou
 * depois que a requisição o carregou — achado via revisão adversarial Codex.
 *
 * changeSituation(Atendido) toma o lock paciente/dia e reconcilia as capturas
 * órfãs (relinkBrowserCapturesForSchedule) com paciente/data/médico da
 * INSTÂNCIA carregada no route binding; refreshSituationUnderLock() relia só
 * situação e chegada. Se outra requisição reagenda / troca o médico / troca o
 * paciente nesse intervalo, a linha vira Atendido mas a busca de capturas (e o
 * lock) olham a identidade ANTIGA: as capturas do agendamento de verdade ficam
 * sem vínculo — e DoctorPayoutProductionService conta o agendamento de exame E
 * a captura "sem vínculo" como 2 atos. Repetir "Atendido" é no-op, não repara.
 *
 * Aqui, "outra requisição" = UPDATE cru na mesma conexão DEPOIS de carregar a
 * instância (a staleness é de memória; o bloqueio entre duas sessões de
 * verdade está em tests/Integration/AttendanceIdentityRaceTest.php).
 */

use App\Enums\{ExamSource, ScheduleSituation};
use App\Models\{Covenant, Entity, ExamType, Patient, PatientExam, Procedure, Schedule, VisitType};
use App\Services\Financial\DoctorPayouts\DoctorPayoutProductionService;
use App\Services\ScheduleService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\{DB, Storage};

beforeEach(function () {
    Storage::fake('s3');

    $this->entity   = Entity::factory()->create(['is_client' => true, 'active' => true]);
    $covenant       = Covenant::factory()->create(['entity_id' => $this->entity->id, 'active' => true, 'name' => 'OPERADORA TESTE', 'ans_registry' => '326305']);
    $this->patient  = Patient::factory()->create(['entity_id' => $this->entity->id, 'covenant_id' => $covenant->id]);
    $this->other    = Patient::factory()->create(['entity_id' => $this->entity->id, 'covenant_id' => $covenant->id]);
    $this->doctor   = createDoctorForEntity($this->entity);
    $this->doctor2  = createDoctorForEntity($this->entity);
    $this->examType = ExamType::factory()->create(['entity_id' => null]);

    // Agendamento de EXAME (procedimento tratamento 3): a captura vinculada a
    // ele não conta de novo no repasse.
    $this->visitType = VisitType::create([
        'entity_id'    => $this->entity->id, 'name' => 'EXAME', 'active' => true,
        'procedure_id' => Procedure::factory()->create(['treatment' => 3, 'name' => 'RETINOGRAFIA'])->id,
    ]);

    $this->dayX = '2026-06-09 09:00:00';
    $this->dayY = '2026-06-10 09:00:00';
});

/** Agendamento de exame ainda Aguardando (o que o route binding carregou). */
function aidSchedule(object $test, array $attrs = []): Schedule
{
    return Schedule::create(array_merge([
        'entity_id'  => $test->entity->id,
        'doctor_id'  => $test->doctor->id,
        'patient_id' => $test->patient->id,
        'visit_id'   => $test->visitType->id,
        'full_name'  => 'Paciente Identidade',
        'date_time'  => $test->dayX,
        'situation'  => ScheduleSituation::Waiting->value,
        'active'     => true,
    ], $attrs));
}

function aidCapture(object $test, array $attrs = []): PatientExam
{
    return PatientExam::factory()->create(array_merge([
        'patient_id'        => $test->patient->id,
        'exam_id'           => $test->examType->id,
        'doctor_id'         => $test->doctor->id,
        'schedule_id'       => null,
        'source'            => ExamSource::BrowserCapture->value,
        'exam_performed_at' => $test->dayX,
        'active'            => true,
    ], $attrs));
}

/** Atos do repasse do médico no mês — 1 = só o agendamento; 2 = agendamento + captura "sem vínculo". */
function aidPayoutActs(object $test, object $doctor): int
{
    return app(DoctorPayoutProductionService::class)
        ->acts($test->entity->id, $doctor->id, CarbonImmutable::parse('2026-06-01'), CarbonImmutable::parse('2026-06-30'))
        ->count();
}

it('[GAP] Atendido reconcilia as capturas pela identidade ATUAL do agendamento — reagendado, médico trocado ou paciente trocado depois de carregado', function (string $change) {
    // Instância carregada antes (route binding): dia X, médico 1, paciente 1.
    $schedule = aidSchedule($this);
    $stale    = Schedule::query()->findOrFail($schedule->id);

    // "Outra requisição" muda a identidade e comita; a captura é da identidade NOVA.
    [$update, $capture, $payoutDoctor] = match ($change) {
        'data'     => [['date_time' => $this->dayY], ['exam_performed_at' => $this->dayY], $this->doctor],
        'médico'   => [['doctor_id' => $this->doctor2->id], ['doctor_id' => $this->doctor2->id], $this->doctor2],
        'paciente' => [['patient_id' => $this->other->id], ['patient_id' => $this->other->id], $this->doctor],
    };

    DB::table('schedules')->where('id', $schedule->id)->update($update);
    $exam = aidCapture($this, $capture);

    expect($stale->doctor_id)->toBe($this->doctor->id); // a instância continua velha em memória

    app(ScheduleService::class)->changeSituation($stale, ScheduleSituation::Attended, null);

    expect($schedule->fresh()->situation)->toBe(ScheduleSituation::Attended)
        // A captura da identidade nova foi vinculada...
        ->and($exam->fresh()->schedule_id)->toBe($schedule->id)
        // ...e o repasse conta UM ato (o agendamento de exame), não dois.
        ->and(aidPayoutActs($this, $payoutDoctor))->toBe(1);
})->with(['data', 'médico', 'paciente']);

it('captura que só bate com a identidade ANTIGA não é vinculada ao agendamento que mudou de dia', function () {
    $schedule = aidSchedule($this);
    $stale    = Schedule::query()->findOrFail($schedule->id);

    DB::table('schedules')->where('id', $schedule->id)->update(['date_time' => $this->dayY]);
    $oldDayCapture = aidCapture($this, ['exam_performed_at' => $this->dayX]);

    app(ScheduleService::class)->changeSituation($stale, ScheduleSituation::Attended, null);

    expect($oldDayCapture->fresh()->schedule_id)->toBeNull();
});

it('[GAP] identidade mudou: o lock paciente/dia da identidade NOVA é tomado ANTES de travar a linha de novo — a ordem que evita o deadlock', function () {
    $schedule = aidSchedule($this);
    $stale    = Schedule::query()->findOrFail($schedule->id);

    DB::table('schedules')->where('id', $schedule->id)->update(['date_time' => $this->dayY]);

    $oldKey = Schedule::patientDayLockKey((string) $this->entity->id, (string) $this->patient->id, $this->dayX);
    $newKey = Schedule::patientDayLockKey((string) $this->entity->id, (string) $this->patient->id, $this->dayY);

    $log = [];
    DB::listen(function (QueryExecuted $query) use (&$log): void {
        $sql = strtolower($query->sql);

        if (str_contains($sql, 'pg_advisory_xact_lock')) {
            $log[] = ['advisory', $query->bindings[0] ?? null];
        } elseif (str_contains($sql, 'for update') && str_contains($sql, '"schedules"')) {
            $log[] = ['row', null];
        }
    });

    app(ScheduleService::class)->changeSituation($stale, ScheduleSituation::Attended, null);

    $positions = fn (string $kind, ?int $key = null) => array_keys(array_filter(
        $log,
        fn (array $entry) => $entry[0] === $kind && ($key === null || $entry[1] === $key),
    ));

    $oldLock = $positions('advisory', $oldKey);
    $newLock = $positions('advisory', $newKey);
    $rows    = $positions('row');

    // 1ª tentativa: lock do dia antigo → linha (vê a identidade nova). 2ª: lock do dia NOVO → linha.
    expect($oldLock)->not->toBeEmpty()
        ->and($newLock)->not->toBeEmpty()
        ->and($oldLock[0])->toBeLessThan($rows[0])
        ->and($rows[0])->toBeLessThan($newLock[0])
        ->and($newLock[0])->toBeLessThan($rows[1]);
});

it('identidade estável: uma única tentativa — nenhum lock extra, nenhuma releitura a mais', function () {
    $schedule = aidSchedule($this);
    $stale    = Schedule::query()->findOrFail($schedule->id);

    $locks = 0;
    DB::listen(function (QueryExecuted $query) use (&$locks): void {
        if (str_contains(strtolower($query->sql), 'pg_advisory_xact_lock')) {
            $locks++;
        }
    });

    app(ScheduleService::class)->changeSituation($stale, ScheduleSituation::Attended, null);

    // 1 do changeSituation() + 1 por captura candidata (nenhuma aqui) => só 1.
    expect($locks)->toBe(1)
        ->and($schedule->fresh()->situation)->toBe(ScheduleSituation::Attended);
});

it('transição que NÃO leva a Atendido não relê nem confere a identidade (nada a reconciliar)', function () {
    $schedule = aidSchedule($this);
    $stale    = Schedule::query()->findOrFail($schedule->id);

    DB::table('schedules')->where('id', $schedule->id)->update(['date_time' => $this->dayY]);

    $locks = 0;
    DB::listen(function (QueryExecuted $query) use (&$locks): void {
        if (str_contains(strtolower($query->sql), 'pg_advisory_xact_lock')) {
            $locks++;
        }
    });

    app(ScheduleService::class)->changeSituation($stale, ScheduleSituation::InProgress, null);

    expect($locks)->toBe(0)
        ->and($schedule->fresh()->situation)->toBe(ScheduleSituation::InProgress)
        // A data gravada por outra requisição não é sobrescrita pela instância velha.
        ->and($schedule->fresh()->date_time->toDateString())->toBe('2026-06-10');
});

it('identidade alterada em memória pelo chamador (ainda não gravada) é dele: a releitura não a sobrescreve e o UPDATE a grava', function () {
    $schedule = aidSchedule($this);
    $pending  = Schedule::query()->findOrFail($schedule->id);

    $pending->doctor_id = $this->doctor2->id; // pendente, não salvo

    app(ScheduleService::class)->changeSituation($pending, ScheduleSituation::Attended, null);

    expect($schedule->fresh()->doctor_id)->toBe($this->doctor2->id)
        ->and($schedule->fresh()->situation)->toBe(ScheduleSituation::Attended);
});
