<?php

use App\Enums\{ScheduleAttendanceType, ScheduleSituation};
use App\Models\{Entity, Schedule, VisitType};

/**
 * Migration que preenche schedules.visit_id a partir do antigo "Tipo de
 * atendimento" (removido do formulário por duplicar o "Tipo de consulta").
 */
function runAttendanceBackfill(): void
{
    (require database_path('migrations/2026_10_02_110000_backfill_schedule_visit_id_from_attendance_type.php'))->up();
}

beforeEach(function () {
    $this->entity = Entity::factory()->create(['is_client' => true, 'active' => true]);
    $this->doctor = createDoctorForEntity($this->entity);
});

function legacySchedule($test, ?string $visitId, ScheduleAttendanceType $type): Schedule
{
    return Schedule::create([
        'entity_id'       => $test->entity->id,
        'doctor_id'       => $test->doctor->id,
        'visit_id'        => $visitId,
        'attendance_type' => $type,
        'full_name'       => 'PACIENTE LEGADO',
        'date_time'       => now()->addDay(),
        'situation'       => ScheduleSituation::Scheduled->value,
        'active'          => true,
    ]);
}

it('preenche visit_id nulo com o tipo de consulta global equivalente', function () {
    $schedule = legacySchedule($this, null, ScheduleAttendanceType::PreOpEvaluation);

    runAttendanceBackfill();

    expect($schedule->fresh()->visit_id)
        ->toBe(VisitType::whereNull('entity_id')->where('name', 'AVALIAÇÃO PRÉ-OPERATÓRIA')->value('id'));
});

it('nao sobrescreve tipo de consulta ja escolhido', function () {
    $consulta = VisitType::whereNull('entity_id')->where('name', 'CONSULTA')->value('id');
    $schedule = legacySchedule($this, $consulta, ScheduleAttendanceType::Return);

    runAttendanceBackfill();

    expect($schedule->fresh()->visit_id)->toBe($consulta)
        ->and($schedule->fresh()->attendance_type)->toBe(ScheduleAttendanceType::Return);
});
