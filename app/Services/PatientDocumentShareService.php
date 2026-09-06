<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\{EntityUser, MedicalRecordDocumentation, Patient, PatientDocumentShare};
use Illuminate\Database\Eloquent\Model;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Lado STAFF do compartilhamento: conceder/revogar visibilidade de um
 * documento clínico pro paciente. Ver App\Services\PatientDocumentAccessService
 * para o lado de LEITURA (portal).
 */
class PatientDocumentShareService
{
    /**
     * Idempotente: reaproveita o grant ativo existente em vez de duplicar
     * linha (staff clicando 2x, ou toggle re-enviado).
     *
     * @throws HttpException 422 se o
     *                       shareable for um laudo de prontuário ainda não assinado — nunca
     *                       compartilhável (CFM Res. 2.227/2018).
     */
    public function grant(EntityUser $by, Model $shareable, Patient $patient): PatientDocumentShare
    {
        if ($shareable instanceof MedicalRecordDocumentation) {
            $shareable->loadMissing('medicalRecord');
            abort_unless((bool) $shareable->medicalRecord?->isSigned(), 422, 'Laudo ainda não assinado não pode ser compartilhado com o paciente.');
        }

        $existing = PatientDocumentShare::query()
            ->where('shareable_type', $shareable::class)
            ->where('shareable_id', $shareable->getKey())
            ->whereNull('revoked_at')
            ->first();

        if ($existing) {
            return $existing;
        }

        return PatientDocumentShare::create([
            'entity_id'      => $patient->entity_id,
            'patient_id'     => $patient->id,
            'shareable_type' => $shareable::class,
            'shareable_id'   => $shareable->getKey(),
            'granted_by'     => $by->getKey(),
            'granted_at'     => now(),
        ]);
    }

    public function revoke(EntityUser $by, PatientDocumentShare $share, ?string $reason = null): void
    {
        if (! $share->isActive()) {
            return;
        }

        $share->revoke($by, $reason);
    }
}
