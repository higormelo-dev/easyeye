<?php

declare(strict_types=1);

/**
 * A outbox do recall (ADR 0002, item 7): o ato clínico marca o paciente NA MESMA transação; o dreno reavalia só
 * os marcados (sem prender quem grava); a conciliação noturna conserta o que o evento não viu. Quem grava
 * prontuário, exame ou agenda nunca é derrubado por causa do recall.
 */

use App\Domains\Recall\Engine\RecallRuleEngine;
use App\Domains\Recall\Enums\ConditionStatus;
use App\Domains\Recall\Models\{PatientRecall, RecallClinicSettings};
use App\Domains\Recall\Services\{RecallCandidates, RecallDirtyDrainer, RecallEvaluator, RecallReconciler};
use App\Domains\Recall\Services\RecallFactsLoader;
use App\Enums\ClinicalSafety\{MedicationSource, MedicationStatus};
use App\Enums\{Eye, ScheduleSituation};
use App\Models\{EntityUser, PatientMedication};
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\{DB, Notification};

beforeEach(function () {
    Notification::fake();

    ['entity' => $this->entity, 'doctor' => $this->doctor] = rcfClinic(recall: true);
    $this->patient                                         = rcfPatient($this->entity);

    DB::table('recall_dirty_patients')->delete(); // a criação do cenário também marca: começa do zero
});

function roMarks(): array
{
    return DB::table('recall_dirty_patients')->orderBy('marked_at')->get()->all();
}

function roClear(): void
{
    DB::table('recall_dirty_patients')->delete();
}

// ── os atos que marcam ──────────────────────────────────────────────────────

it('gravar um atendimento marca o paciente (clínica, paciente, motivo); dois atos, uma só marca', function () {
    rcfRecord($this->entity, $this->patient, $this->doctor, '2026-01-10', 90);
    rcfRecord($this->entity, $this->patient, $this->doctor, '2026-01-20', 30);

    $marks = roMarks();

    expect($marks)->toHaveCount(1)
        ->and($marks[0]->entity_id)->toBe((string) $this->entity->id)
        ->and($marks[0]->patient_id)->toBe((string) $this->patient->id)
        ->and($marks[0]->reason)->toBe('medical_record');
});

it('só o que mexe no retorno marca: trocar a queixa não, o retorno pedido ou o agendamento vinculado sim', function () {
    $record = rcfRecord($this->entity, $this->patient, $this->doctor, '2026-01-10', 90);
    roClear();

    $record->update(['main_complaint' => 'Outra queixa']);
    expect(roMarks())->toBe([]);

    $record->update(['follow_up_days' => 30]);
    expect(roMarks())->toHaveCount(1);
});

it('ato desfeito (rollback) não deixa marca; a marca vai e volta com a transação do ato', function () {
    try {
        DB::transaction(function () {
            rcfRecord($this->entity, $this->patient, $this->doctor, '2026-01-10', 90);

            throw new RuntimeException('o ato falhou depois de gravar');
        });
    } catch (RuntimeException) {
    }

    expect(roMarks())->toBe([]);

    DB::transaction(fn () => rcfRecord($this->entity, $this->patient, $this->doctor, '2026-01-10', 90));
    expect(roMarks())->toHaveCount(1);
});

it('exame, medicação e agendamento marcam; o exame não tem clínica própria (vale a do paciente)', function () {
    $type = rcfExamType();
    $exam = rcfExam($this->patient, $type, '2026-01-10');

    expect(roMarks())->toHaveCount(1)
        ->and(roMarks()[0]->reason)->toBe('exam')
        ->and(roMarks()[0]->entity_id)->toBe((string) $this->entity->id);

    roClear();
    $exam->update(['active' => false]);
    expect(roMarks())->toHaveCount(1);

    roClear();
    PatientMedication::create(['entity_id' => $this->entity->id, 'patient_id' => $this->patient->id, 'name' => 'Hidroxicloroquina 400 mg', 'status' => MedicationStatus::Active, 'source' => MedicationSource::Clinic]);
    expect(roMarks()[0]->reason)->toBe('medication');

    roClear();
    ['schedule' => $schedule] = createScheduleForEntity($this->entity, ['patient_id' => $this->patient->id, 'date_time' => '2026-03-20 09:00:00']);
    expect(roMarks()[0]->reason)->toBe('schedule');

    roClear();
    $schedule->update(['situation' => ScheduleSituation::Cancelled->value]);
    expect(roMarks())->toHaveCount(1);

    roClear();
    $schedule->update(['full_name' => 'Só o nome']);
    expect(roMarks())->toBe([]);
});

it('agendamento sem paciente (encaixe de nome livre) não marca; trocar o paciente marca o anterior e o novo', function () {
    $other = rcfPatient($this->entity);

    ['schedule' => $free] = createScheduleForEntity($this->entity, ['date_time' => '2026-03-20 09:00:00']);
    expect(roMarks())->toBe([]);

    ['schedule' => $schedule] = createScheduleForEntity($this->entity, ['patient_id' => $this->patient->id, 'date_time' => '2026-03-21 09:00:00']);
    roClear();

    $schedule->update(['patient_id' => $other->id]);

    expect(collect(roMarks())->pluck('patient_id')->sort()->values()->all())->toBe(collect([(string) $this->patient->id, (string) $other->id])->sort()->values()->all())
        ->and($free->patient_id)->toBeNull();
});

it('excluir e restaurar um atendimento marcam o paciente', function () {
    $record = rcfRecord($this->entity, $this->patient, $this->doctor, '2026-01-10', 90);
    roClear();

    $record->delete();
    expect(roMarks())->toHaveCount(1);

    roClear();
    $record->restore();
    expect(roMarks())->toHaveCount(1);
});

it('excluir e restaurar um agendamento marcam o paciente', function () {
    ['schedule' => $schedule] = createScheduleForEntity($this->entity, ['patient_id' => $this->patient->id, 'date_time' => '2026-03-20 09:00:00']);
    roClear();

    $schedule->delete();
    expect(roMarks())->toHaveCount(1);

    roClear();
    $schedule->restore();
    expect(roMarks())->toHaveCount(1);
});

it('remover e restaurar o paciente marcam', function () {
    $this->patient->delete();
    expect(roMarks())->toHaveCount(1)
        ->and(roMarks()[0]->reason)->toBe('patient');

    roClear();
    $this->patient->restore();
    expect(roMarks())->toHaveCount(1);
});

it('assinar o prontuário marca (a assinatura grava sem eventos: a marca é explícita)', function () {
    $record = rcfRecord($this->entity, $this->patient, $this->doctor, '2026-01-10', 90);
    roClear();

    $record->sign(EntityUser::query()->findOrFail($this->doctor->entity_user_id));

    expect($record->fresh()->signed_at)->not->toBeNull()
        ->and(roMarks())->toHaveCount(1)
        ->and(roMarks()[0]->reason)->toBe('record_signed');
});

it('criar ou alterar uma condição marca o paciente', function () {
    rcfCondition($this->entity, $this->patient, 'glaucoma', Eye::Both, '2026-01-15', ConditionStatus::Suggested);

    expect(roMarks())->toHaveCount(1)
        ->and(roMarks()[0]->reason)->toBe('condition');
});

it('a marca NUNCA derruba o ato: com a outbox indisponível o prontuário é gravado do mesmo jeito', function () {
    DB::statement('ALTER TABLE recall_dirty_patients RENAME TO recall_dirty_patients_off');

    $record = rcfRecord($this->entity, $this->patient, $this->doctor, '2026-01-10', 90);

    expect($record->fresh()->follow_up_days)->toBe(90);

    DB::statement('ALTER TABLE recall_dirty_patients_off RENAME TO recall_dirty_patients');
});

// ── a clínica inteira ───────────────────────────────────────────────────────

it('mudar a adoção do protocolo marca os pacientes que PODEM ter retorno — e só eles', function () {
    $withCondition = $this->patient;
    rcfCondition($this->entity, $withCondition, 'glaucoma', Eye::Both, '2026-01-15');

    $withFollowUp = rcfPatient($this->entity);
    rcfRecord($this->entity, $withFollowUp, $this->doctor, now()->subDays(10)->toDateString(), 60);

    $withRow = rcfPatient($this->entity);
    rcfRecord($this->entity, $withRow, $this->doctor, '2026-01-10', 90);
    rcfEvaluate($this->entity, $withRow);

    $nothing   = rcfPatient($this->entity);
    $oldRecord = rcfPatient($this->entity);
    rcfRecord($this->entity, $oldRecord, $this->doctor, now()->subDays(RecallCandidates::DOCTOR_ORDER_HORIZON_DAYS + 30)->toDateString(), 60);
    $noFollowUp = rcfPatient($this->entity);
    rcfRecord($this->entity, $noFollowUp, $this->doctor, now()->subDays(5)->toDateString(), null);

    $other = rcfClinic(recall: true);
    rcfRecord($other['entity'], rcfPatient($other['entity']), $other['doctor'], now()->subDays(5)->toDateString(), 60);
    roClear();

    rcfAdopt($this->entity, 'glaucoma');

    $marked = collect(roMarks())->pluck('patient_id')->all();

    expect($marked)->toContain((string) $withCondition->id, (string) $withFollowUp->id, (string) $withRow->id)
        ->and(array_values(array_intersect($marked, [(string) $nothing->id, (string) $oldRecord->id, (string) $noFollowUp->id])))->toBe([])
        ->and(collect(roMarks())->pluck('entity_id')->unique()->all())->toBe([(string) $this->entity->id])
        ->and(collect(roMarks())->pluck('reason')->unique()->all())->toBe(['clinic_setup']);
});

it('mudar o mapeamento de exames ou a configuração da clínica também marca a clínica', function () {
    rcfCondition($this->entity, $this->patient, 'glaucoma', Eye::Both, '2026-01-15');
    roClear();

    rcfMapExam($this->entity, 'visual_field', rcfExamType());
    expect(roMarks())->toHaveCount(1);

    roClear();
    RecallClinicSettings::forEntity((string) $this->entity->id)->fill(['doctor_order_enabled' => false, 'invite_weekdays' => [1, 2, 3, 4, 5]])->save();
    expect(roMarks())->toHaveCount(1);
});

it('os candidatos: removido com linha aberta entra; linha encerrada há muito tempo não; atendimento recente só de quem não foi removido', function () {
    $removed = rcfPatient($this->entity);
    rcfRecord($this->entity, $removed, $this->doctor, '2026-01-10', 90);
    rcfEvaluate($this->entity, $removed);
    $removed->delete();

    // Encerrada há muito tempo e sem nenhum outro motivo para ser candidata (o atendimento dela não pede retorno).
    $closedLongAgo = rcfPatient($this->entity);
    rcfRecord($this->entity, $closedLongAgo, $this->doctor, '2026-01-10', null);
    PatientRecall::query()->withoutGlobalScopes()->create([
        'entity_id' => $this->entity->id, 'patient_id' => $closedLongAgo->id, 'source' => 'doctor_order', 'task_key' => 'doctor_order',
        'kind'      => 'doctor_order', 'lifecycle' => 'closed', 'closed_reason' => 'doctor_order_ended',
    ]);
    PatientRecall::query()->withoutGlobalScopes()->where('patient_id', $closedLongAgo->id)->toBase()->update(['updated_at' => now()->subDays(RecallCandidates::CLOSED_ROW_DAYS + 5)]);

    $removedRecent = rcfPatient($this->entity);
    rcfRecord($this->entity, $removedRecent, $this->doctor, now()->subDays(5)->toDateString(), 60);
    $removedRecent->delete();

    // Encerrada agora, sem nenhum outro motivo para ser candidata (o atendimento dela não pede retorno).
    $closedRecently = rcfPatient($this->entity);
    rcfRecord($this->entity, $closedRecently, $this->doctor, '2026-01-10', null);
    PatientRecall::query()->withoutGlobalScopes()->create([
        'entity_id' => $this->entity->id, 'patient_id' => $closedRecently->id, 'source' => 'doctor_order', 'task_key' => 'doctor_order',
        'kind'      => 'doctor_order', 'lifecycle' => 'closed', 'closed_reason' => 'doctor_order_ended',
    ]);

    // Atendimento com "retorno em 0 dias" não pede retorno.
    $zeroDays = rcfPatient($this->entity);
    rcfRecord($this->entity, $zeroDays, $this->doctor, now()->subDays(5)->toDateString(), 0);

    $ids = RecallCandidates::patients((string) $this->entity->id)->pluck('p.id')->all();

    expect($ids)->toContain((string) $removed->id, (string) $closedRecently->id)
        ->and(array_values(array_intersect($ids, [(string) $closedLongAgo->id, (string) $removedRecent->id, (string) $zeroDays->id])))->toBe([]);
});

// ── o dreno ─────────────────────────────────────────────────────────────────

it('o dreno reavalia os marcados, esvazia a outbox e só a de clínica COM a feature no plano', function () {
    rcfRecord($this->entity, $this->patient, $this->doctor, '2026-01-10', 90);

    $noPlan = rcfClinic(); // sem assinatura com a feature
    rcfGrantRecall($noPlan['entity'], enabled: false);
    $patientNoPlan = rcfPatient($noPlan['entity']);
    rcfRecord($noPlan['entity'], $patientNoPlan, $noPlan['doctor'], '2026-01-10', 90);

    expect(roMarks())->toHaveCount(2);

    $stats = app(RecallDirtyDrainer::class)->drain(100, CarbonImmutable::parse('2026-02-01'));

    expect($stats)->toBe(['claimed' => 2, 'evaluated' => 1, 'skipped' => 1, 'failed' => 0])
        ->and(roMarks())->toBe([])
        ->and(rcfRecalls($this->patient))->toHaveCount(1)
        ->and(rcfRecalls($patientNoPlan))->toHaveCount(0);
});

it('marca feita DEPOIS do início da rodada fica para a próxima (marcação constante não prende o dreno)', function () {
    rcfRecord($this->entity, $this->patient, $this->doctor, '2026-01-10', 90);
    DB::table('recall_dirty_patients')->update(['marked_at' => now()->addMinutes(5)]);

    $stats = app(RecallDirtyDrainer::class)->drain(100, CarbonImmutable::parse('2026-02-01'));

    expect($stats['claimed'])->toBe(0)
        ->and(roMarks())->toHaveCount(1);
});

it('respeita o limite da rodada', function () {
    foreach (range(1, 3) as $ignored) {
        rcfRecord($this->entity, rcfPatient($this->entity), $this->doctor, '2026-01-10', 90);
    }

    $stats = app(RecallDirtyDrainer::class)->drain(2, CarbonImmutable::parse('2026-02-01'));

    expect($stats['claimed'])->toBe(2)
        ->and(roMarks())->toHaveCount(1);
});

it('falha na avaliação remarca UMA vez ("retry"); falhar de novo não vira laço nem enxurrada', function () {
    rcfRecord($this->entity, $this->patient, $this->doctor, '2026-01-10', 90);

    $this->app->bind(RecallEvaluator::class, fn () => new class(app(RecallFactsLoader::class), app(RecallRuleEngine::class)) extends RecallEvaluator {
        public function evaluatePatient(string $entityId, string $patientId, ?CarbonImmutable $now = null): array
        {
            throw new RuntimeException('avaliação quebrou');
        }
    });

    $first = app(RecallDirtyDrainer::class)->drain(100);

    expect($first['failed'])->toBe(1)
        ->and(roMarks())->toHaveCount(1)
        ->and(roMarks()[0]->reason)->toBe('retry');

    // O "retry" só entra numa rodada MAIS TARDE (nunca na mesma): não há laço dentro da rodada.
    expect(app(RecallDirtyDrainer::class)->drain(100)['claimed'])->toBe(0);

    $this->travel(RecallDirtyDrainer::RETRY_AFTER_MINUTES + 1)->minutes();
    $second = app(RecallDirtyDrainer::class)->drain(100);

    expect($second['failed'])->toBe(1)
        ->and(roMarks())->toBe([]); // a conciliação noturna cobre; o dreno não insiste
});

it('o comando recall:evaluate-dirty drena e informa', function () {
    rcfRecord($this->entity, $this->patient, $this->doctor, now()->subDays(10)->toDateString(), 90);

    $this->artisan('recall:evaluate-dirty')->expectsOutputToContain('avaliados: 1')->assertSuccessful();

    expect(roMarks())->toBe([])
        ->and(rcfRecalls($this->patient))->toHaveCount(1);
});

// ── a conciliação ───────────────────────────────────────────────────────────

it('a conciliação cria o que o evento perdeu (gravação sem evento) e conserta a linha que divergiu', function () {
    $record = rcfRecord($this->entity, $this->patient, $this->doctor, '2026-01-10', 90);
    DB::table('medical_records')->where('id', $record->id)->update(['created_at' => now()->subDays(10), 'follow_up_days' => 60]);
    roClear();

    $stats = app(RecallReconciler::class)->reconcileEntity((string) $this->entity->id);

    expect($stats['created'])->toBe(1)
        ->and(rcfRecalls($this->patient)['doctor_order']->meta['follow_up_days'])->toBe(60);

    // Alguém mexeu na linha por fora (ou um bug antigo): a conciliação refaz a conta.
    PatientRecall::query()->withoutGlobalScopes()->where('patient_id', $this->patient->id)->update(['due_at' => '2030-01-01']);

    $again = app(RecallReconciler::class)->reconcileEntity((string) $this->entity->id);

    expect($again['updated'])->toBe(1)
        ->and(rcfRecalls($this->patient)['doctor_order']->due_at->toDateString())->toBe(now()->subDays(10)->addDays(60)->toDateString());
});

it('a conciliação encerra o paciente removido por fora (SQL direto, sem evento)', function () {
    rcfRecord($this->entity, $this->patient, $this->doctor, now()->subDays(10)->toDateString(), 60);
    rcfEvaluate($this->entity, $this->patient, now()->toDateString());
    DB::table('patients')->where('id', $this->patient->id)->update(['deleted_at' => now()]);

    $stats = app(RecallReconciler::class)->reconcileEntity((string) $this->entity->id);

    expect($stats['closed'])->toBe(1)
        ->and(rcfRecalls($this->patient)['doctor_order']->closed_reason)->toBe('patient_removed');
});

it('a conciliação só mexe na PRÓPRIA clínica e, ao varrer todas, só nas que têm a feature', function () {
    rcfRecord($this->entity, $this->patient, $this->doctor, now()->subDays(10)->toDateString(), 60);

    $other        = rcfClinic(recall: true);
    $otherPatient = rcfPatient($other['entity']);
    rcfRecord($other['entity'], $otherPatient, $other['doctor'], now()->subDays(10)->toDateString(), 60);

    $noFeature = rcfClinic();
    rcfGrantRecall($noFeature['entity'], enabled: false);
    $noFeaturePatient = rcfPatient($noFeature['entity']);
    rcfRecord($noFeature['entity'], $noFeaturePatient, $noFeature['doctor'], now()->subDays(10)->toDateString(), 60);

    $one = app(RecallReconciler::class)->reconcileEntity((string) $this->entity->id);

    expect($one['patients'])->toBe(1)
        ->and(rcfRecalls($otherPatient))->toHaveCount(0);

    $all = app(RecallReconciler::class)->reconcileAll();

    expect($all['entities'])->toBe(2)
        ->and(rcfRecalls($otherPatient))->toHaveCount(1)
        ->and(rcfRecalls($noFeaturePatient))->toHaveCount(0);
});

it('a conciliação pagina por blocos e um paciente que falha não interrompe os demais', function () {
    $patients = collect(range(1, 5))->map(function () {
        $patient = rcfPatient($this->entity);
        rcfRecord($this->entity, $patient, $this->doctor, now()->subDays(10)->toDateString(), 60);

        return $patient;
    });
    roClear();

    $broken = (string) $patients[2]->id;

    $this->app->bind(RecallEvaluator::class, fn () => new class(app(RecallFactsLoader::class), app(RecallRuleEngine::class), $broken) extends RecallEvaluator {
        public function __construct(RecallFactsLoader $loader, RecallRuleEngine $engine, private readonly string $broken)
        {
            parent::__construct($loader, $engine);
        }

        public function evaluatePatient(string $entityId, string $patientId, ?CarbonImmutable $now = null): array
        {
            if ($patientId === $this->broken) {
                throw new RuntimeException('este paciente quebra');
            }

            return parent::evaluatePatient($entityId, $patientId, $now);
        }
    });

    $stats = app(RecallReconciler::class)->reconcileEntity((string) $this->entity->id, chunk: 2);

    expect($stats['patients'])->toBe(5)
        ->and($stats['failed'])->toBe(1)
        ->and($stats['created'])->toBe(4);

    $this->artisan('recall:reconcile', ['--entity' => (string) $this->entity->id])->assertFailed(); // o comando falha para o monitoramento ver
});
