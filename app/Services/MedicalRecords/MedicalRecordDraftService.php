<?php

declare(strict_types=1);

namespace App\Services\MedicalRecords;

use App\Exceptions\LockedMedicalRecordException;
use App\Models\{MedicalRecord, MedicalRecordDraft, Patient, Schedule, User};
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Autosave do prontuário (Fase 1 / B2) — rascunho SEPARADO do prontuário.
 *
 *  - Dono: autor (usuário) + clínica da sessão. Nenhuma consulta lê rascunho
 *    de outro usuário ou de outra clínica.
 *  - Uma "tela" por rascunho (`context_key`): edição de um prontuário
 *    (`record:{id}`), novo atendimento aberto pela Agenda (`schedule:{id}`)
 *    ou novo sem agendamento (`new`).
 *  - Prontuário assinado (bloqueado) nunca recebe rascunho, e o que existir
 *    para ele é descartado.
 *  - Só os campos do formulário do prontuário entram (FIELDS); o conteúdo vai
 *    criptografado (cast encrypted). Não toca medical_records: sem versão,
 *    sem auditoria, sem updated_at do prontuário a cada gravação.
 *  - Sai ao salvar/finalizar (clearAfterSave) e na limpeza agendada
 *    (prune, RETENTION_DAYS).
 */
class MedicalRecordDraftService
{
    /** Rascunho mais velho que isso é apagado pela limpeza agendada. */
    public const RETENTION_DAYS = 7;

    /** Teto do conteúdo (JSON) de um rascunho. */
    public const MAX_PAYLOAD_BYTES = 512 * 1024;

    /**
     * Campos do formulário do prontuário que o rascunho guarda (mesmos do
     * StoreMedicalRecordRequest, sem médico/agendamento/intenções de fluxo —
     * esses vêm da tela).
     */
    public const FIELDS = [
        'main_complaint', 'hda', 'diabetic', 'diabetic_family', 'hypertensive', 'hypertensive_family',
        'glaucomatous', 'glaucomatous_family', 'others_history', 'ocular_surgical_history', 'medications_in_use',
        'visual_acuity_type_id', 'visual_acuity_without_correction_right_id', 'visual_acuity_without_correction_left_id',
        'visual_acuity_with_correction_right_id', 'visual_acuity_with_correction_left_id', 'near_point_convergence_id',
        'cover_test_type_id', 'color_vision_type_id', 'addition_type_id', 'lens_away_id', 'lens_near_id',
        'lens_away_ids', 'lens_near_ids',
        'dynamic_spherical_right', 'dynamic_spherical_left', 'dynamic_cylindrical_right', 'dynamic_cylindrical_left',
        'dynamic_axis_right', 'dynamic_axis_left', 'static_spherical_right', 'static_spherical_left',
        'static_cylindrical_right', 'static_cylindrical_left', 'static_axis_right', 'static_axis_left',
        'ocular_motility', 'tonometer_right', 'tonometer_left', 'tonometer_time', 'pachymetry_right', 'pachymetry_left',
        'gonioscopy_right', 'gonioscopy_left', 'biomicroscopy_right', 'biomicroscopy_left', 'fundoscopy_right',
        'fundoscopy_left', 'observation_general', 'observation_of_lenses', 'contact_lens_calculation',
        'diagnosis_cids', 'clinical_conduct', 'follow_up_days',
    ];

    public static function contextKey(?MedicalRecord $record, ?Schedule $schedule): string
    {
        return match (true) {
            $record !== null   => 'record:' . $record->getKey(),
            $schedule !== null => 'schedule:' . $schedule->getKey(),
            default            => 'new',
        };
    }

    /**
     * Rascunho do autor para a tela, se houver um mais novo que o prontuário
     * (edição) — o que a tela oferece recuperar. Prontuário assinado: o
     * rascunho é descartado e nada é oferecido.
     */
    public function find(User $user, string $entityId, Patient $patient, ?MedicalRecord $record, ?Schedule $schedule): ?MedicalRecordDraft
    {
        $draft = $this->owned($user, $entityId, $patient)
            ->where('context_key', self::contextKey($record, $schedule))
            ->first();

        if ($draft === null) {
            return null;
        }

        if ($record?->isLocked()) {
            $draft->delete();

            return null;
        }

        if ($record !== null && $record->updated_at !== null && $draft->updated_at->lte($record->updated_at)) {
            return null; // o prontuário salvo já é mais novo que o rascunho
        }

        return $draft;
    }

    /**
     * Grava (cria ou substitui) o rascunho do autor para a tela.
     *
     * @param array<string, mixed> $payload campos do formulário
     *
     * @throws LockedMedicalRecordException prontuário assinado
     */
    public function save(User $user, string $entityId, Patient $patient, ?MedicalRecord $record, ?Schedule $schedule, array $payload): MedicalRecordDraft
    {
        if ($record?->isLocked()) {
            throw new LockedMedicalRecordException(__('actions.medical_records.locked'));
        }

        $owner = [
            'entity_id'   => $entityId,
            'user_id'     => $user->getKey(),
            'patient_id'  => $patient->getKey(),
            'context_key' => self::contextKey($record, $schedule),
        ];
        $values = [
            'schedule_id'       => $record?->schedule_id ?? $schedule?->getKey(),
            'medical_record_id' => $record?->getKey(),
            'payload'           => self::onlyFormFields($payload),
        ];

        try {
            return $this->write($owner, $values);
        } catch (UniqueConstraintViolationException) {
            // Duas gravações da mesma tela ao mesmo tempo (intervalo + aba
            // oculta): a segunda vira atualização do rascunho que acabou de nascer.
            return $this->write($owner, $values);
        }
    }

    /**
     * @param array<string, mixed> $owner
     * @param array<string, mixed> $values
     */
    private function write(array $owner, array $values): MedicalRecordDraft
    {
        // Transação própria (SAVEPOINT dentro de outra): a violação de unicidade
        // desfaz só esta tentativa e a nova tentativa segue válida.
        return DB::transaction(function () use ($owner, $values): MedicalRecordDraft {
            $draft = MedicalRecordDraft::query()->firstOrNew($owner);
            $draft->fill($values);
            // Mesmo conteúdo de novo ainda conta como "o médico estava aqui agora".
            $draft->updated_at = now();
            $draft->save();

            return $draft;
        });
    }

    public function discard(User $user, string $entityId, Patient $patient, ?MedicalRecord $record, ?Schedule $schedule): void
    {
        $this->owned($user, $entityId, $patient)
            ->where('context_key', self::contextKey($record, $schedule))
            ->delete();
    }

    /**
     * Prontuário salvo/finalizado: some o rascunho da tela de onde veio —
     * edição (`record:{id}`) ou o novo atendimento (`schedule:{id}` / `new`).
     */
    public function clearAfterSave(User $user, string $entityId, MedicalRecord $record, bool $created, ?string $scheduleId): void
    {
        $keys = ['record:' . $record->getKey()];

        if ($created) {
            $keys[] = $scheduleId ? 'schedule:' . $scheduleId : 'new';
        }

        MedicalRecordDraft::query()
            ->where('entity_id', $entityId)
            ->where('user_id', $user->getKey())
            ->where('patient_id', $record->patient_id)
            ->whereIn('context_key', $keys)
            ->delete();
    }

    /** Prontuário assinado: nenhum rascunho dele sobrevive (de nenhum autor). */
    public function purgeForRecord(MedicalRecord $record): void
    {
        MedicalRecordDraft::query()->where('medical_record_id', $record->getKey())->delete();
    }

    /** Limpeza agendada: rascunhos parados há mais de `$days` dias. */
    public function prune(int $days = self::RETENTION_DAYS): int
    {
        return MedicalRecordDraft::query()
            ->where('updated_at', '<', now()->subDays(max(1, $days)))
            ->delete();
    }

    /**
     * Só os campos do formulário do prontuário (nada de chave arbitrária no
     * rascunho).
     *
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    public static function onlyFormFields(array $payload): array
    {
        return array_intersect_key($payload, array_flip(self::FIELDS));
    }

    /** @return Builder<MedicalRecordDraft> */
    private function owned(User $user, string $entityId, Patient $patient): Builder
    {
        return MedicalRecordDraft::query()
            ->where('entity_id', $entityId)
            ->where('user_id', $user->getKey())
            ->where('patient_id', $patient->getKey());
    }
}
