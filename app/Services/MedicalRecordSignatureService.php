<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\LockedMedicalRecordException;
use App\Models\{EntityUser, MedicalRecord};

/**
 * Gerencia a assinatura eletrônica de prontuários.
 * CFM Resolução 2.227/2018 — autoria, integridade e imutabilidade.
 */
class MedicalRecordSignatureService
{
    /**
     * Assina um prontuário em nome de um médico (EntityUser).
     * Após a assinatura o prontuário fica bloqueado para edições.
     *
     * @throws LockedMedicalRecordException se o prontuário já estiver assinado
     * @throws \InvalidArgumentException    se o EntityUser não for um médico
     */
    public function sign(MedicalRecord $record, EntityUser $entityUser): MedicalRecord
    {
        if (! $entityUser->doctor) {
            throw new \InvalidArgumentException(
                "O usuário '{$entityUser->user->name}' não é médico e não pode assinar prontuários."
            );
        }

        $record->sign($entityUser);

        return $record->fresh();
    }

    /**
     * Verifica a integridade criptográfica de um prontuário assinado.
     * Detecta adulteração direta no banco de dados.
     */
    public function verifyIntegrity(MedicalRecord $record): bool
    {
        return $record->verifyIntegrity();
    }

    /**
     * Verifica se o prontuário pode ser editado.
     * Prontuários assinados são imutáveis (CFM 2.227/2018).
     */
    public function canEdit(MedicalRecord $record): bool
    {
        return ! $record->isLocked();
    }

    /**
     * Retorna todos os prontuários de um paciente que ainda não foram assinados.
     *
     * @return \Illuminate\Database\Eloquent\Collection<MedicalRecord>
     */
    public function pendingSignature(string $patientId): \Illuminate\Database\Eloquent\Collection
    {
        return MedicalRecord::query()
            ->where('patient_id', $patientId)
            ->where('is_locked', false)
            ->whereNull('signed_at')
            ->get();
    }
}
