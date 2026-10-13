<?php

declare(strict_types=1);

/**
 * Corrida entre a captura pela câmera (ExternalExamImportService::import())
 * e o agendamento virando Atendido (ScheduleService::changeSituation()) pro
 * MESMO paciente/dia/médico.
 *
 * Sem serializar as duas: o import podia ler "agendamento ainda não
 * atendido" (schedule_id = null), a mudança de situação comitar e já rodar
 * relinkBrowserCapturesForSchedule() ENQUANTO o insert do import ainda
 * estava pendente (nada pra achar ainda), e só DEPOIS o import inserir o
 * PatientExam com o schedule_id nulo que já tinha decidido — órfão pra
 * sempre, já que changeSituation() nunca roda a reconciliação 2x pro mesmo
 * agendamento. DoctorPayoutProductionService então contava o agendamento E
 * a captura "sem vínculo" como 2 atos em vez de 1 (achado via revisão
 * adversarial Codex).
 *
 * Fechado travando (FOR UPDATE) TODOS os agendamentos candidatos do dia
 * dentro de resolveAttendedScheduleId() — não só os já Attended — antes de
 * decidir: isso serializa contra o lock que
 * ScheduleService::refreshSituationUnderLock() já tira na MESMA linha
 * quando ela vira Atendido.
 *
 * Verificação via DB::listen() (mesmo padrão já usado nesta sessão pra
 * provar a forma exata do SQL de um lock) em vez de uma segunda conexão
 * PostgreSQL de verdade: RefreshDatabase embrulha o teste inteiro numa
 * transação nunca comitada, então o agendamento criado no teste NUNCA fica
 * visível pra outra sessão tentar travar — não tem como reproduzir o
 * bloqueio cross-connection aqui. A prova que SOBRA e é 100% decisiva: o
 * código emite um FOR UPDATE nos candidatos SEM filtrar por situation —
 * é exatamente essa ausência de filtro que fecha a corrida (trava mesmo o
 * agendamento ainda Waiting, não só quando já está Attended).
 */

use App\Enums\{ClientRule, ExamSource, ScheduleSituation};
use App\Models\{Entity, ExamType, Patient, User};
use Illuminate\Support\Facades\{DB, Storage};

beforeEach(function () {
    Storage::fake('s3');

    $this->entity           = Entity::factory()->create(['is_client' => true, 'active' => true]);
    $this->doctorUser       = User::factory()->create();
    $this->doctorEntityUser = createEntityUser($this->entity, $this->doctorUser, ClientRule::Doctor->value);
    $this->patient          = Patient::factory()->create(['entity_id' => $this->entity->id]);
    $this->examType         = ExamType::factory()->create(['entity_id' => null]);
});

it('[GAP] captura pela câmera trava (FOR UPDATE) os agendamentos candidatos do dia SEM filtrar por situação — pega o ainda não atendido, não só os já Attended', function () {
    ['doctor' => $doctor] = createScheduleForEntity($this->entity, [
        'patient_id' => $this->patient->id,
        'situation'  => ScheduleSituation::Waiting->value,
        'date_time'  => now()->subDay(),
    ]);

    $lockQueries = [];
    DB::listen(function ($query) use (&$lockQueries) {
        $sql = strtolower($query->sql);

        if (str_contains($sql, 'for update') && str_contains($sql, 'schedules')) {
            $lockQueries[] = $query;
        }
    });

    importExternalExam($this, $this->doctorUser, $this->doctorEntityUser, basePayload($this, [
        'exam_performed_at' => now()->subDay()->toDateString(),
        'files_source'      => [ExamSource::BrowserCapture->value],
        'doctor_id'         => $doctor->id,
    ]))->assertRedirect(route('panel.eye-images.index'));

    expect($lockQueries)->toHaveCount(1);

    $lockQuery = $lockQueries[0];

    // A trava NUNCA filtra por situation — é isso que fecha a corrida:
    // pega o agendamento MESMO enquanto ele ainda não está Attended (igual
    // ao $schedule deste teste, criado como Waiting), não só quando já
    // está.
    expect(strtolower($lockQuery->sql))->not->toContain('situation')
        ->and($lockQuery->bindings)->toContain($this->patient->id)
        ->and($lockQuery->bindings)->toContain((string) $doctor->id);
});
