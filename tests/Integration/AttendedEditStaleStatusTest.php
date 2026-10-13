<?php

declare(strict_types=1);

/**
 * PUT da agenda SEM o campo situação, com o agendamento virando Atendido numa
 * SEGUNDA conexão PostgreSQL entre o route binding e o UPDATE — achado via
 * revisão adversarial Codex: lockForEdit() decidia reconciliar capturas pela
 * situação da instância do binding (Aguardando); com o Atendido já comitado, o
 * PUT não travava paciente/dia nem relia a linha, e a edição de médico/dia
 * deixava a captura da identidade nova sem vínculo — o repasse contava o
 * agendamento de exame E a captura (2 atos).
 *
 * A "outra requisição" é a conexão de apoio (probe): comita o Atendido logo
 * depois da leitura do binding, de dentro de um gancho de DB::listen. Fica em
 * tests/Integration (DatabaseMigrations, dados comitados) e roda por caminho
 * explícito.
 */

use App\Enums\{ClientRule, ExamSource, ScheduleSituation};
use App\Models\{Covenant, DoctorWorkSchedule, Entity, ExamType, Patient, PatientExam, Procedure, Schedule, User, VisitType};
use App\Services\Financial\DoctorPayouts\DoctorPayoutProductionService;
use Carbon\{Carbon, CarbonImmutable};
use Illuminate\Database\Connection;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\{DB, Storage};
use Tests\TestCase;

uses(TestCase::class, DatabaseMigrations::class);

const AES_PROBE_CONNECTION = 'pgsql_probe_stale_status';

/** Segunda sessão PostgreSQL real: o que ela grava já está comitado. */
function aesProbe(): Connection
{
    config(['database.connections.' . AES_PROBE_CONNECTION => config('database.connections.' . config('database.default'))]);

    $probe = DB::connection(AES_PROBE_CONNECTION);

    // O gancho roda síncrono na thread da principal: se a sonda esperar uma trava que a PRÓPRIA
    // principal segura, ninguém acorda. Com lock_timeout o teste falha em segundos em vez de pendurar.
    $probe->statement("SET lock_timeout = '3s'");

    return $probe;
}

afterEach(function () {
    DB::purge(AES_PROBE_CONNECTION);
});

it('[GAP] binding Aguardando, Atendido comitado por OUTRA conexão, PUT de médico e dia SEM situação: a captura da identidade nova é vinculada e o repasse conta UM ato (achado via revisão adversarial Codex)', function () {
    Storage::fake('s3');

    $entity  = Entity::factory()->create(['is_client' => true, 'active' => true]);
    $patient = Patient::factory()->create(['entity_id' => $entity->id, 'covenant_id' => Covenant::factory()->create(['entity_id' => $entity->id, 'active' => true, 'ans_registry' => '326305'])->id]);
    $doctor  = createDoctorForEntity($entity);
    $doctor2 = createDoctorForEntity($entity);

    foreach ([$doctor, $doctor2] as $d) {
        for ($day = 1; $day <= 5; $day++) {
            DoctorWorkSchedule::create(['doctor_id' => $d->id, 'day_of_week' => $day, 'starts_at' => '08:00:00', 'ends_at' => '12:00:00']);
        }
        $d->update(['schedule_interval' => 15]);
    }

    $secretary = createEntityUser($entity, User::factory()->create(), ClientRule::Secretary->value);
    $monday    = Carbon::now()->next(Carbon::MONDAY)->setTime(9, 0);
    $tuesday   = $monday->copy()->addDay();

    $visit = VisitType::create([
        'entity_id'    => $entity->id, 'name' => 'EXAME', 'active' => true,
        'procedure_id' => Procedure::factory()->create(['treatment' => 3, 'name' => 'RETINOGRAFIA'])->id,
    ]);

    // Aguardando na segunda com o médico 1 — o que o route binding vai carregar.
    $schedule = Schedule::create([
        'entity_id' => $entity->id, 'doctor_id' => $doctor->id, 'patient_id' => $patient->id, 'visit_id' => $visit->id,
        'full_name' => 'PACIENTE STATUS', 'date_time' => $monday, 'situation' => ScheduleSituation::Waiting->value, 'active' => true,
    ]);

    // Captura órfã do paciente com o médico 2 na terça: é do agendamento depois da edição.
    $orphan = PatientExam::factory()->create([
        'patient_id'        => $patient->id, 'exam_id' => ExamType::factory()->create(['entity_id' => null])->id,
        'doctor_id'         => $doctor2->id, 'schedule_id' => null, 'source' => ExamSource::BrowserCapture->value,
        'exam_performed_at' => $tuesday->copy()->setTime(11, 0), 'active' => true,
    ]);

    // Outra conexão comita "Atendido" logo depois do route binding (a 1ª leitura do agendamento por id).
    $probe   = aesProbe();
    $flipped = false;
    DB::listen(function (QueryExecuted $query) use (&$flipped, $probe, $schedule): void {
        $sql = strtolower($query->sql);

        if (! $flipped && str_starts_with($sql, 'select') && str_contains($sql, 'from "schedules"') && ! str_contains($sql, 'for update') && in_array($schedule->id, $query->bindings, true)) {
            $flipped = true;
            $probe->table('schedules')->where('id', $schedule->id)->update(['situation' => ScheduleSituation::Attended->value]);
        }
    });

    // Ordem dos locks: origem e destino paciente/dia ANTES da linha ser escrita.
    $sequence = [];
    DB::listen(function (QueryExecuted $query) use (&$sequence): void {
        $sql = strtolower($query->sql);

        if (str_contains($sql, 'pg_advisory_xact_lock')) {
            $sequence[] = ['advisory', $query->bindings[0] ?? null];
        } elseif (str_starts_with($sql, 'update "schedules"') && str_contains($sql, '"date_time"')) { // o UPDATE da edição (não o do "Atendido" da outra requisição)
            $sequence[] = ['update', null];
        }
    });

    DB::statement("set statement_timeout = '20s'");

    $this->actingAs($secretary->user)->withSession(panelSession($secretary))
        ->putJson(route('panel.schedules.update', $schedule), [
            'doctor_id' => $doctor2->id, 'patient_id' => $patient->id, 'full_name' => 'PACIENTE STATUS',
            'date_time' => $tuesday->copy()->setTime(9, 30)->format('Y-m-d H:i'), // sem o campo "situation"
        ])->assertOk();

    $acts = app(DoctorPayoutProductionService::class)->acts(
        $entity->id,
        $doctor2->id,
        CarbonImmutable::parse($tuesday)->startOfMonth(),
        CarbonImmutable::parse($tuesday)->endOfMonth(),
    );

    $firstUpdate = array_search(['update', null], $sequence, true);
    $origin      = Schedule::patientDayLockKey((string) $entity->id, (string) $patient->id, $monday);
    $destination = Schedule::patientDayLockKey((string) $entity->id, (string) $patient->id, $tuesday);

    expect($flipped)->toBeTrue()
        ->and($schedule->fresh()->situation)->toBe(ScheduleSituation::Attended)
        ->and($schedule->fresh()->doctor_id)->toBe($doctor2->id)
        ->and($schedule->fresh()->date_time->toDateString())->toBe($tuesday->toDateString())
        ->and($orphan->fresh()->schedule_id)->toBe($schedule->id)
        ->and($acts)->toHaveCount(1)
        ->and($firstUpdate)->toBeInt()
        ->and(array_search(['advisory', $origin], $sequence, true))->toBeLessThan($firstUpdate)
        ->and(array_search(['advisory', $destination], $sequence, true))->toBeLessThan($firstUpdate);
});
