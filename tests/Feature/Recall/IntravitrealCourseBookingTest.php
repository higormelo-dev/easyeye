<?php

declare(strict_types=1);

/**
 * O agendamento do retorno de um CURSO de injeções (achado via revisão adversarial Codex): a linha de retorno do curso só
 * se liga à reserva da PRÓPRIA aplicação planejada (`intravitreal_applications.schedule_id`) — ou ao agendamento que a
 * equipe escolheu pela tela de Retornos, enquanto ele estiver aberto —, nunca ao "próximo agendamento aberto do
 * paciente", que vale para as consultas e os exames de protocolo. Paciente com os dois olhos em tratamento agendado
 * só de um lado ligava os DOIS retornos ao mesmo horário: o olho sem horário sumia de "a contatar" e deixava de receber
 * convite por WhatsApp. Relógio fixo: 01/02/2026.
 */

use App\Domains\Recall\Enums\InviteOrigin;
use App\Domains\Recall\Intravitreal\Enums\{ApplicationStatus, AuthorizationStatus};
use App\Domains\Recall\Intravitreal\Models\{IntravitrealApplication, IntravitrealCourse};
use App\Domains\Recall\Models\PatientRecall;
use App\Domains\Recall\Services\RecallInviteGuard;
use App\Enums\ScheduleSituation;
use App\Models\Schedule;
use Carbon\{Carbon, CarbonImmutable};
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    Notification::fake();
    $this->travelTo(Carbon::parse('2026-02-01 10:00:00'));

    ['entity' => $this->entity, 'doctor' => $this->doctor] = rcfClinic(recall: true);

    rcfInviteChannel($this->entity);
    $this->patient = rcfInvitePatient($this->entity);
});

/** Um agendamento do paciente (aberto, salvo se `$overrides` disser o contrário). */
function icbSchedule(object $test, string $when = '2026-03-10 09:00:00', array $overrides = [], ?object $patient = null): Schedule
{
    return Schedule::create(array_replace([
        'entity_id'  => $test->entity->id,
        'doctor_id'  => $test->doctor->id,
        'patient_id' => ($patient ?? $test->patient)->id,
        'full_name'  => 'PACIENTE IVT',
        'date_time'  => CarbonImmutable::parse($when),
        'situation'  => ScheduleSituation::Scheduled->value,
        'active'     => true,
    ], $overrides));
}

/** Curso ativo do olho, em atraso (próxima em 29/12/2025), com a aplicação planejada — opcionalmente já reservada no agendamento. */
function icbCourse(object $test, string $eye, ?string $scheduleId = null): IntravitrealCourse
{
    $course = IntravitrealCourse::query()->withoutGlobalScopes()->create([
        'entity_id'              => $test->entity->id, 'patient_id' => $test->patient->id, 'eye' => $eye, 'drug_key' => 'aflibercept', 'drug_label' => 'Aflibercepte',
        'regimen'                => 'loading_tae', 'status' => 'active', 'started_on' => '2025-10-01', 'last_applied_on' => '2025-12-01',
        'current_interval_weeks' => 4, 'next_due_on' => '2025-12-29',
    ]);

    icbApplication($test, $course, 2, $scheduleId);

    return $course;
}

/** Uma aplicação planejada do curso (a sequência decide qual é a mais antiga). */
function icbApplication(object $test, IntravitrealCourse $course, int $sequence, ?string $scheduleId = null): IntravitrealApplication
{
    return IntravitrealApplication::query()->withoutGlobalScopes()->create([
        'entity_id' => $test->entity->id, 'patient_id' => $test->patient->id, 'course_id' => $course->id, 'sequence_no' => $sequence,
        'phase'     => IntravitrealApplication::PHASE_LOADING, 'status' => ApplicationStatus::Planned->value, 'planned_for' => '2025-12-29',
        'eye'       => $course->eye, 'drug_label' => 'Aflibercepte', 'authorization_status' => AuthorizationStatus::NotRequired->value, 'schedule_id' => $scheduleId,
    ]);
}

function icbEvaluate(object $test): void
{
    rcfEvaluate($test->entity, $test->patient, '2026-02-01');
}

/** A linha de retorno do curso (lida do banco: depois de reavaliar). */
function icbRecall(object $test, IntravitrealCourse $course): PatientRecall
{
    return PatientRecall::query()->withoutGlobalScopes()->where('patient_id', $test->patient->id)->where('task_key', 'course:' . $course->id)->firstOrFail();
}

it('com os dois olhos em tratamento, o agendamento de UM olho não liga o retorno do outro: ele segue "a contatar"', function () {
    $booked = icbSchedule($this);
    $right  = icbCourse($this, 'right', (string) $booked->id); // a reserva da aplicação do OD
    $left   = icbCourse($this, 'left');                        // o OE não tem horário

    icbEvaluate($this);

    expect(icbRecall($this, $right)->scheduled_schedule_id)->toBe((string) $booked->id)
        ->and(icbRecall($this, $left)->scheduled_schedule_id)->toBeNull();
});

it('e o olho sem horário segue convidável por WhatsApp (o agendado, não)', function () {
    $right = icbCourse($this, 'right', (string) icbSchedule($this)->id);
    $left  = icbCourse($this, 'left');

    icbEvaluate($this);

    $decision = app(RecallInviteGuard::class)->decide((string) $this->entity->id, (string) $this->patient->id, InviteOrigin::Manual, CarbonImmutable::parse('2026-02-01 10:00:00'));

    expect(array_map(static fn (PatientRecall $recall): string => (string) $recall->id, $decision->recalls))->toBe([(string) icbRecall($this, $left)->id])
        ->and($decision->allowed())->toBeTrue()
        ->and(icbRecall($this, $right)->scheduled_schedule_id)->not->toBeNull();
});

it('um agendamento qualquer do paciente (consulta, cirurgia, exame) não liga o retorno do curso: só a reserva da própria aplicação', function () {
    icbSchedule($this, '2026-02-10 09:00:00'); // nada a ver com a injeção
    $course = icbCourse($this, 'right');

    icbEvaluate($this);

    expect(icbRecall($this, $course)->scheduled_schedule_id)->toBeNull();
});

it('reserva da aplicação cancelada, faltada, já atendida, excluída ou no passado solta o retorno — e ele NÃO cai em outro agendamento do paciente', function (string $case) {
    $booking = icbSchedule($this, '2026-03-10 09:00:00');
    icbSchedule($this, '2026-04-10 09:00:00'); // outro agendamento aberto do paciente: antes do conserto, o retorno se ligava a ele
    $course = icbCourse($this, 'right', (string) $booking->id);

    match ($case) {
        'cancelada'  => $booking->forceFill(['situation' => ScheduleSituation::Cancelled->value])->saveQuietly(),
        'faltou'     => $booking->forceFill(['situation' => ScheduleSituation::NoShow->value])->saveQuietly(),
        'atendida'   => $booking->forceFill(['situation' => ScheduleSituation::Attended->value])->saveQuietly(),
        'excluída'   => $booking->delete(),
        'no passado' => $booking->forceFill(['date_time' => CarbonImmutable::parse('2026-01-20 09:00:00')])->saveQuietly(),
    };

    icbEvaluate($this);

    expect(icbRecall($this, $course)->scheduled_schedule_id)->toBeNull();
})->with(['cancelada', 'faltou', 'atendida', 'excluída', 'no passado']);

it('a reserva da aplicação vale mesmo longe: o teto da lista de agendamentos abertos do paciente não a esconde', function () {
    foreach (range(1, 22) as $day) {
        icbSchedule($this, CarbonImmutable::parse('2026-02-02 09:00:00')->addDays($day)->toDateTimeString()); // 22 consultas mais próximas
    }

    $far    = icbSchedule($this, '2026-09-01 09:00:00');
    $course = icbCourse($this, 'right', (string) $far->id);

    icbEvaluate($this);

    expect(icbRecall($this, $course)->scheduled_schedule_id)->toBe((string) $far->id);
});

it('com duas aplicações planejadas vale a reserva da MAIS ANTIGA (a que o agendamento da próxima aplicação usa)', function () {
    $oldest = icbSchedule($this, '2026-03-10 09:00:00');
    $newer  = icbSchedule($this, '2026-03-17 09:00:00');
    $course = icbCourse($this, 'right', (string) $oldest->id); // aplicação 2
    icbApplication($this, $course, 3, (string) $newer->id);

    icbEvaluate($this);

    expect(icbRecall($this, $course)->scheduled_schedule_id)->toBe((string) $oldest->id);
});

it('aplicação planejada excluída não liga o retorno do curso', function () {
    $course = icbCourse($this, 'right', (string) icbSchedule($this)->id);

    IntravitrealApplication::query()->withoutGlobalScopes()->where('course_id', $course->id)->firstOrFail()->delete();

    icbEvaluate($this);

    expect(icbRecall($this, $course)->scheduled_schedule_id)->toBeNull();
});

it('a reserva de OUTRO paciente (série herdada de outra pessoa) nunca liga o retorno do curso', function () {
    $other   = rcfPatient($this->entity);
    $foreign = icbSchedule($this, patient: $other);
    $course  = icbCourse($this, 'right', (string) $foreign->id);

    icbEvaluate($this);

    expect(icbRecall($this, $course)->scheduled_schedule_id)->toBeNull();
});

it('o agendamento que a equipe vinculou pela tela de Retornos vale enquanto está aberto; cancelado, o retorno volta a "a contatar"', function () {
    $course = icbCourse($this, 'right');
    $chosen = icbSchedule($this, '2026-03-20 09:00:00');
    icbSchedule($this, '2026-02-15 09:00:00'); // mais perto, mas é outra coisa

    icbEvaluate($this);
    $row = icbRecall($this, $course);

    expect($row->scheduled_schedule_id)->toBeNull();

    $row->forceFill(['scheduled_schedule_id' => $chosen->id])->save(); // a equipe escolheu este
    icbEvaluate($this);

    expect($row->fresh()->scheduled_schedule_id)->toBe((string) $chosen->id);

    $chosen->forceFill(['situation' => ScheduleSituation::Cancelled->value])->saveQuietly();
    icbEvaluate($this);

    expect($row->fresh()->scheduled_schedule_id)->toBeNull();
});

it('o retorno pedido pelo médico e o de protocolo seguem ligando ao próximo agendamento aberto do paciente (a consulta é o ato)', function () {
    rcfRecord($this->entity, $this->patient, $this->doctor, '2026-01-10', 90);
    $next = icbSchedule($this, '2026-03-20 09:00:00');

    icbEvaluate($this);

    expect(rcfRecalls($this->patient)['doctor_order']->scheduled_schedule_id)->toBe((string) $next->id);
});

it('curso excluído não gera retorno: a linha se encerra como "curso encerrado"', function () {
    $course = icbCourse($this, 'right');

    icbEvaluate($this);
    expect(icbRecall($this, $course)->lifecycle->value)->toBe('open');

    $course->delete();
    icbEvaluate($this);

    expect(icbRecall($this, $course)->lifecycle->value)->toBe('closed')
        ->and(icbRecall($this, $course)->closed_reason)->toBe('course_ended');
});
