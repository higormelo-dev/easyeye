<?php

declare(strict_types=1);

use App\Enums\{ExamSource, ScheduleSituation};
use App\Models\{Entity, ExamType, Patient, PatientExam, Schedule};
use App\Services\ConsultationRecordResolver;

/**
 * Âncora do laudo a partir dos exames — achado via revisão adversarial Codex:
 * anchorForExamIds() confiava no vínculo exame → agendamento sem conferir que
 * o agendamento ainda é do paciente (e do dia) do exame. Agendamento trocado
 * de paciente depois da captura ancorava o laudo do paciente 1 no atendimento
 * (e no prontuário do dia) do paciente 2. O vínculo só vale quando paciente e
 * dia batem; fora disso o exame se comporta como sem agendamento.
 */
beforeEach(function () {
    $this->entity   = Entity::factory()->create(['is_client' => true, 'active' => true]);
    $this->patient  = Patient::factory()->create(['entity_id' => $this->entity->id]);
    $this->other    = Patient::factory()->create(['entity_id' => $this->entity->id]);
    $this->doctor   = createDoctorForEntity($this->entity);
    $this->examType = ExamType::factory()->create(['entity_id' => null]);
    $this->resolver = app(ConsultationRecordResolver::class);
});

function anchorSchedule(object $test, array $attrs = []): Schedule
{
    return Schedule::create(array_merge([
        'entity_id' => $test->entity->id, 'doctor_id' => $test->doctor->id, 'patient_id' => $test->patient->id,
        'full_name' => 'Paciente Âncora', 'date_time' => '2026-06-10 09:00:00',
        'situation' => ScheduleSituation::Attended->value, 'active' => true,
    ], $attrs));
}

function anchorExam(object $test, ?Schedule $schedule, array $attrs = []): PatientExam
{
    return PatientExam::factory()->create(array_merge([
        'patient_id'        => $test->patient->id, 'exam_id' => $test->examType->id, 'doctor_id' => $test->doctor->id,
        'schedule_id'       => $schedule?->id, 'source' => ExamSource::BrowserCapture->value,
        'exam_performed_at' => '2026-06-10 11:00:00', 'active' => true,
    ], $attrs));
}

it('vínculo que vale (mesmo paciente e dia): ancora no agendamento e na data dele', function () {
    $schedule = anchorSchedule($this);
    $exam     = anchorExam($this, $schedule);

    expect($this->resolver->anchorForExamIds([$exam->id]))->toBe([(string) $schedule->id, '2026-06-10']);
});

it('[GAP] agendamento trocado de PACIENTE depois da captura: o laudo NÃO ancora no atendimento do outro paciente', function () {
    $schedule = anchorSchedule($this, ['patient_id' => $this->other->id]);
    $exam     = anchorExam($this, $schedule); // exame do paciente 1, vínculo velho

    [$scheduleId] = $this->resolver->anchorForExamIds([$exam->id]);

    expect($scheduleId)->toBeNull();
});

it('[GAP] agendamento remarcado pra OUTRO dia depois da captura: o vínculo velho não puxa a data do agendamento', function () {
    $schedule = anchorSchedule($this, ['date_time' => '2026-07-15 09:00:00']);
    $exam     = anchorExam($this, $schedule);

    [$scheduleId, $date] = $this->resolver->anchorForExamIds([$exam->id]);

    expect($scheduleId)->toBeNull()
        ->and($date)->toBe(now()->toDateString());
});

it('vários exames: usa só o vínculo que ainda vale, mesmo que o vínculo velho seja o mais recente', function () {
    $valid = anchorSchedule($this);
    $stale = anchorSchedule($this, ['patient_id' => $this->other->id, 'date_time' => '2026-06-10 10:00:00']);

    $good = anchorExam($this, $valid);
    anchorExam($this, $stale, ['created_at' => now()->addMinute()]);

    $examIds = PatientExam::query()->where('patient_id', $this->patient->id)->pluck('id')->all();

    expect($this->resolver->anchorForExamIds($examIds)[0])->toBe((string) $valid->id)
        ->and($good->schedule_id)->toBe($valid->id);
});
