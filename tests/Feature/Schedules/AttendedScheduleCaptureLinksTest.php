<?php

declare(strict_types=1);

/**
 * Agendamento ATENDIDO com capturas vinculadas, editado no formulário da agenda
 * — achado via revisão adversarial Codex: o vínculo exame → agendamento nasce
 * pela identidade (paciente, dia, médico) e a reconciliação só olhava capturas
 * SEM vínculo, quando o agendamento VIRA Atendido. Editar paciente, médico ou
 * dia de um agendamento que continua Atendido deixava as capturas presas à
 * identidade antiga (o repasse as engolia na competência errada, o laudo
 * ancorava no atendimento de outro paciente) e as capturas da identidade nova
 * sem vínculo.
 *
 * Agora o PUT, na mesma transação, desvincula o que deixou de valer e reconcilia
 * as capturas órfãs da identidade nova (com o lock paciente/dia tomado ANTES do
 * UPDATE, a ordem do resto da agenda).
 */

use App\Enums\{ClientRule, ExamSource, ScheduleSituation};
use App\Models\{Covenant, DoctorWorkSchedule, Entity, ExamType, Patient, PatientExam, Schedule, User};
use Carbon\Carbon;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\{DB, Storage};
use Illuminate\Testing\TestResponse;

beforeEach(function () {
    Storage::fake('s3');

    $this->entity   = Entity::factory()->create(['is_client' => true, 'active' => true]);
    $covenant       = Covenant::factory()->create(['entity_id' => $this->entity->id, 'active' => true]);
    $this->patient  = Patient::factory()->create(['entity_id' => $this->entity->id, 'covenant_id' => $covenant->id]);
    $this->other    = Patient::factory()->create(['entity_id' => $this->entity->id, 'covenant_id' => $covenant->id]);
    $this->doctor   = createDoctorForEntity($this->entity);
    $this->doctor2  = createDoctorForEntity($this->entity);
    $this->examType = ExamType::factory()->create(['entity_id' => null]);

    foreach ([$this->doctor, $this->doctor2] as $doctor) {
        for ($d = 1; $d <= 5; $d++) {
            DoctorWorkSchedule::create(['doctor_id' => $doctor->id, 'day_of_week' => $d, 'starts_at' => '08:00:00', 'ends_at' => '12:00:00']);
        }

        $doctor->update(['schedule_interval' => 15]);
    }

    $this->secretary = createEntityUser($this->entity, User::factory()->create(), ClientRule::Secretary->value);
    $this->monday    = Carbon::now()->next(Carbon::MONDAY)->setTime(9, 0);
    $this->tuesday   = $this->monday->copy()->addDay();

    // Atendido do paciente 1, médico 1, na segunda — com uma captura vinculada.
    $this->schedule = Schedule::create([
        'entity_id' => $this->entity->id, 'doctor_id' => $this->doctor->id, 'patient_id' => $this->patient->id,
        'full_name' => 'PACIENTE VINCULOS', 'date_time' => $this->monday,
        'situation' => ScheduleSituation::Attended->value, 'active' => true,
    ]);
    $this->linked = aclCapture($this, ['schedule_id' => $this->schedule->id]);
});

function aclCapture(object $test, array $attrs = []): PatientExam
{
    return PatientExam::factory()->create(array_merge([
        'patient_id'        => $test->patient->id,
        'exam_id'           => $test->examType->id,
        'doctor_id'         => $test->doctor->id,
        'schedule_id'       => null,
        'source'            => ExamSource::BrowserCapture->value,
        'exam_performed_at' => $test->monday->copy()->setTime(10, 0),
        'active'            => true,
    ], $attrs));
}

function aclPut(object $test, array $overrides = []): TestResponse
{
    return $test->actingAs($test->secretary->user)->withSession(panelSession($test->secretary))
        ->putJson(route('panel.schedules.update', $test->schedule), array_merge([
            'doctor_id'  => $test->doctor->id,
            'patient_id' => $test->patient->id,
            'full_name'  => 'PACIENTE VINCULOS',
            'date_time'  => $test->monday->format('Y-m-d H:i'),
            'situation'  => ScheduleSituation::Attended->value,
        ], $overrides));
}

it('[GAP] editar paciente, médico ou dia de um agendamento que continua Atendido desvincula a captura que deixou de valer', function (string $change) {
    $overrides = match ($change) {
        'paciente' => ['patient_id' => $this->other->id],
        'médico'   => ['doctor_id' => $this->doctor2->id],
        'dia'      => ['date_time' => $this->tuesday->format('Y-m-d H:i')],
    };

    aclPut($this, $overrides)->assertOk();

    expect($this->linked->fresh()->schedule_id)->toBeNull()
        // O exame em si continua: mesmo paciente, médico, dia.
        ->and($this->linked->fresh()->patient_id)->toBe($this->patient->id)
        ->and($this->linked->fresh()->active)->toBeTrue();
})->with(['paciente', 'médico', 'dia']);

it('edição que não muda a identidade (anotação, horário no MESMO dia) mantém a captura vinculada', function () {
    aclPut($this, ['notes' => 'Retorno em 30 dias', 'date_time' => $this->monday->copy()->setTime(9, 30)->format('Y-m-d H:i')])->assertOk();

    expect($this->linked->fresh()->schedule_id)->toBe($this->schedule->id)
        ->and($this->schedule->fresh()->notes)->toBe('Retorno em 30 dias');
});

it('[GAP] mudar o DIA reconcilia as capturas órfãs do dia novo com o agendamento Atendido', function () {
    $orphan = aclCapture($this, ['exam_performed_at' => $this->tuesday->copy()->setTime(11, 0)]);

    aclPut($this, ['date_time' => $this->tuesday->format('Y-m-d H:i')])->assertOk();

    expect($this->linked->fresh()->schedule_id)->toBeNull()
        ->and($orphan->fresh()->schedule_id)->toBe($this->schedule->id);
});

it('[GAP] trocar o PACIENTE reconcilia as capturas órfãs do paciente novo', function () {
    $orphan = aclCapture($this, ['patient_id' => $this->other->id]);

    aclPut($this, ['patient_id' => $this->other->id])->assertOk();

    expect($this->linked->fresh()->schedule_id)->toBeNull()
        ->and($orphan->fresh()->schedule_id)->toBe($this->schedule->id);
});

it('[GAP] ao mesmo tempo deixa de ser Atendido: desvincula o que não vale, NÃO vincula órfãs e NÃO toma lock paciente/dia depois da linha', function () {
    $orphan = aclCapture($this, ['exam_performed_at' => $this->tuesday->copy()->setTime(11, 0)]);

    $log = [];
    DB::listen(function (QueryExecuted $query) use (&$log): void {
        $sql = strtolower($query->sql);

        if (str_contains($sql, 'pg_advisory_xact_lock')) {
            $log[] = 'advisory';
        } elseif (str_starts_with($sql, 'update "schedules"')) {
            $log[] = 'update';
        }
    });

    aclPut($this, ['date_time' => $this->tuesday->format('Y-m-d H:i'), 'situation' => ScheduleSituation::Waiting->value])->assertOk();

    // Só quem fica Atendido reconcilia — e só ele toma o lock paciente/dia: um lock
    // pedido DEPOIS de a linha já estar travada pelo UPDATE inverteria a ordem.
    $firstUpdate = array_search('update', $log, true);

    expect($this->schedule->fresh()->situation)->toBe(ScheduleSituation::Waiting)
        ->and($this->linked->fresh()->schedule_id)->toBeNull()
        ->and($orphan->fresh()->schedule_id)->toBeNull()
        ->and($firstUpdate)->not->toBeFalse()
        ->and(array_slice($log, $firstUpdate))->not->toContain('advisory');
});

it('[GAP] a resposta diz quantos exames deixaram de estar vinculados', function () {
    aclCapture($this, ['schedule_id' => $this->schedule->id, 'exam_performed_at' => $this->monday->copy()->setTime(10, 5)]);

    aclPut($this, ['patient_id' => $this->other->id])
        ->assertOk()
        ->assertJsonPath('message', __('schedules.updated') . ' ' . trans_choice('schedules.exam_links_removed', 2, ['count' => 2]));
});

it('sem nada a desvincular, a resposta é a de sempre', function () {
    aclPut($this, ['notes' => 'só anotação'])
        ->assertOk()
        ->assertJsonPath('message', __('schedules.updated'));
});

it('[GAP] PUT SEM o campo situação num agendamento que já é Atendido: o lock paciente/dia do dia NOVO sai antes do UPDATE e a captura órfã é reconciliada', function () {
    $orphan = aclCapture($this, ['exam_performed_at' => $this->tuesday->copy()->setTime(11, 0)]);
    $newKey = Schedule::patientDayLockKey((string) $this->entity->id, (string) $this->patient->id, $this->tuesday);

    $log = [];
    DB::listen(function (QueryExecuted $query) use (&$log): void {
        $sql = strtolower($query->sql);

        if (str_contains($sql, 'pg_advisory_xact_lock')) {
            $log[] = ['advisory', $query->bindings[0] ?? null];
        } elseif (str_starts_with($sql, 'update "schedules"')) {
            $log[] = ['update', null];
        }
    });

    $payload = [
        'doctor_id' => $this->doctor->id, 'patient_id' => $this->patient->id, 'full_name' => 'PACIENTE VINCULOS',
        'date_time' => $this->tuesday->format('Y-m-d H:i'),
    ];

    $this->actingAs($this->secretary->user)->withSession(panelSession($this->secretary))
        ->putJson(route('panel.schedules.update', $this->schedule), $payload)
        ->assertOk();

    $positions = fn (string $kind, ?int $key = null) => array_keys(array_filter($log, fn (array $e) => $e[0] === $kind && ($key === null || $e[1] === $key)));

    expect($positions('advisory', $newKey))->not->toBeEmpty()
        ->and($positions('update'))->not->toBeEmpty()
        ->and($positions('advisory', $newKey)[0])->toBeLessThan($positions('update')[0])
        ->and($orphan->fresh()->schedule_id)->toBe($this->schedule->id)
        ->and($this->linked->fresh()->schedule_id)->toBeNull();
});
