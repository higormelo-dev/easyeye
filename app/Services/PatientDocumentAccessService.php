<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\{MedicalRecordDocumentation, Patient, PatientAccount, PatientDocumentShare};
use Illuminate\Database\Eloquent\Model;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * ÚNICO ponto de checagem de "este paciente pode LER este documento?" pros 3
 * tipos compartilháveis (laudo/exame/anexo). Nasce da lição da auditoria de
 * 38 IDOR desta sessão: checagem de posse duplicada por controller foi o
 * padrão-raiz de quase todos os achados — aqui existe um lugar só, reutilizado
 * pelos controllers do Portal do Paciente.
 */
class PatientDocumentAccessService
{
    /**
     * @throws NotFoundHttpException
     *                               404 sempre que o acesso não é permitido — nunca 403 (não
     *                               revelar a um paciente que um {id} existe mas não é dele).
     */
    public function assertCanView(PatientAccount $account, Model $shareable): void
    {
        abort_unless($this->canView($account, $shareable), 404);
    }

    public function canView(PatientAccount $account, Model $shareable): bool
    {
        $patientId = $shareable->getAttribute('patient_id');

        if (blank($patientId)) {
            return false;
        }

        // Nunca confiar em {id}/{patient} de rota sozinho — resolve a posse
        // SEMPRE a partir do PRÓPRIO $shareable (patient_id direto na tabela,
        // confirmado presente nos 3 models compartilháveis).
        $patient = Patient::query()->find($patientId);

        if (! $patient || (string) $patient->person_id !== (string) $account->person_id) {
            return false;
        }

        $hasActiveGrant = PatientDocumentShare::query()
            ->where('shareable_type', $shareable::class)
            ->where('shareable_id', $shareable->getKey())
            ->whereNull('revoked_at')
            ->exists();

        if (! $hasActiveGrant) {
            return false;
        }

        // Laudo não assinado nunca é legível pelo paciente — defesa em
        // profundidade mesmo que exista grant (o staff-side
        // PatientDocumentShareService também bloqueia isso no momento do grant).
        if ($shareable instanceof MedicalRecordDocumentation) {
            $shareable->loadMissing('medicalRecord');

            return (bool) $shareable->medicalRecord?->isSigned();
        }

        return true;
    }
}
