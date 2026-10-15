<?php

namespace App\Providers;

use App\Domains\Recall\Intravitreal\Models\IntravitrealCourse;
use App\Domains\Recall\Models\{PatientCondition, RecallClinicProtocol, RecallClinicSettings, RecallExamMapping};
use App\Domains\Recall\Observers\MarksRecallDirty;
use App\Domains\Recall\Services\RecallOutbox;
use App\Models\{MedicalRecord, Patient, PatientExam, PatientMedication, Schedule};
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\ServiceProvider;

/**
 * Recall de pacientes (Fase 6): liga os atos clínicos à outbox do recall. Cada ato que pode mexer no retorno
 * de um paciente marca esse paciente na mesma transação (MarksRecallDirty); mudar a adoção do protocolo, o
 * mapeamento de exames ou a configuração da clínica marca a clínica inteira. Quem reavalia é o comando
 * `recall:evaluate-dirty` (a cada minuto); a conciliação noturna cobre o que o evento não pega.
 */
class RecallServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->watch(MedicalRecord::class, new MarksRecallDirty('medical_record', ['patient_id', 'schedule_id', 'follow_up_days', 'diagnosis_cids', 'signed_at', 'deleted_at']));
        $this->watch(PatientExam::class, new MarksRecallDirty('exam', ['patient_id', 'exam_id', 'laterality', 'exam_performed_at', 'active']));
        $this->watch(PatientMedication::class, new MarksRecallDirty('medication', ['patient_id', 'medicine_id', 'name', 'active_ingredient', 'started_on', 'ended_on', 'status', 'confirmed_at', 'discarded_at']));
        $this->watch(Schedule::class, new MarksRecallDirty('schedule', ['patient_id', 'date_time', 'situation', 'active']));
        $this->watch(PatientCondition::class, new MarksRecallDirty('condition'));
        // Curso de injeções intravítreas: a próxima aplicação é retorno (data, situação ou olho mudou).
        $this->watch(IntravitrealCourse::class, new MarksRecallDirty('course', ['status', 'next_due_on', 'last_applied_on', 'eye', 'deleted_at']));

        // O próprio paciente: removido/restaurado muda o retorno (a avaliação encerra ou reabre).
        Patient::deleted(static fn (Patient $patient) => RecallOutbox::mark((string) $patient->entity_id, (string) $patient->getKey(), 'patient'));
        Patient::restored(static fn (Patient $patient) => RecallOutbox::mark((string) $patient->entity_id, (string) $patient->getKey(), 'patient'));

        // O que a clínica configurou vale para TODOS os pacientes dela.
        foreach ([RecallClinicProtocol::class, RecallExamMapping::class, RecallClinicSettings::class] as $model) {
            $model::saved(static fn (Model $changed) => RecallOutbox::markClinic((string) $changed->getAttribute('entity_id'), 'clinic_setup'));
            $model::deleted(static fn (Model $changed) => RecallOutbox::markClinic((string) $changed->getAttribute('entity_id'), 'clinic_setup'));
        }
    }

    /**
     * `Model::observe()` instancia o observador pelo contêiner (sem argumentos de construtor): como o mesmo
     * observador serve vários modelos com motivo e colunas próprios, os eventos são ligados um a um.
     *
     * @param class-string<Model> $model
     */
    private function watch(string $model, MarksRecallDirty $observer): void
    {
        $model::created(static fn (Model $changed) => $observer->created($changed));
        $model::updated(static fn (Model $changed) => $observer->updated($changed));
        $model::deleted(static fn (Model $changed) => $observer->deleted($changed));

        if (method_exists($model, 'restored')) {
            $model::restored(static fn (Model $changed) => $observer->restored($changed));
        }
    }
}
