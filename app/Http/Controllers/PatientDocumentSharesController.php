<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\{EntityGate, ShareableDocumentType};
use App\Models\{Entity, EntityUser, MedicalRecordDocumentation, MedicalRecordFile, Patient, PatientDocumentShare, PatientExam};
use App\Services\PatientDocumentShareService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\{JsonResponse, RedirectResponse, Request};
use Illuminate\Support\Facades\Gate;

/**
 * Toggle "compartilhar com paciente" disparado pelo staff — drawer do
 * prontuário (laudos) e Gerenciador de Imagens (exames). Único controller
 * genérico pros 3 tipos compartilháveis (mesma lição da auditoria de 38 IDOR
 * desta sessão: um lugar só, não checagem de posse duplicada por tela).
 *
 * Responde JSON pra chamadas fetch/axios diretas (Gerenciador de Imagens) e
 * redirect+flash pra chamadas via Inertia router.post/delete (drawer do
 * prontuário, mesmo padrão de PatientPortalInvitationsController).
 */
class PatientDocumentSharesController extends Controller
{
    public function __construct(private readonly PatientDocumentShareService $service)
    {
    }

    public function store(Request $request): JsonResponse|RedirectResponse
    {
        $validated = $request->validate([
            'shareable_type' => ['required', 'in:laudo,exame,anexo'],
            'shareable_id'   => ['required', 'uuid'],
            'patient_id'     => ['required', 'uuid'],
        ]);

        $type      = ShareableDocumentType::from($validated['shareable_type']);
        $patient   = $this->resolvePatient($validated['patient_id']);
        $shareable = $this->resolveShareable($type, $validated['shareable_id'], $patient);

        if ($type === ShareableDocumentType::Laudo) {
            $this->authorizeLaudoShareManagement();
        }

        $share = $this->service->grant($this->currentEntityUser(), $shareable, $patient);

        if ($request->wantsJson()) {
            return response()->json(['id' => $share->id, 'shared' => true], 201);
        }

        return back()->with('success', 'Documento compartilhado com o paciente.');
    }

    public function destroy(Request $request, PatientDocumentShare $share): JsonResponse|RedirectResponse
    {
        abort_unless((string) $share->entity_id === (string) session('selected_entity_id'), 404);

        if ($share->shareable_type === MedicalRecordDocumentation::class) {
            $this->authorizeLaudoShareManagement();
        }

        $this->service->revoke($this->currentEntityUser(), $share);

        if ($request->wantsJson()) {
            return response()->json(['shared' => false]);
        }

        return back()->with('success', 'Compartilhamento revogado.');
    }

    /**
     * Achado de segurança (mesmo padrão da auditoria panel.* IDOR): resolve o
     * Patient e confere entity_id explicitamente — nunca confiar num UUID
     * vindo do body/payload sozinho.
     */
    private function resolvePatient(string $patientId): Patient
    {
        $patient = Patient::query()->find($patientId);
        abort_unless($patient && (string) $patient->entity_id === (string) session('selected_entity_id'), 404);

        return $patient;
    }

    /**
     * Resolve o model real a partir da chave curta + confere que o documento
     * pertence ao MESMO patient_id informado — nunca confiar em IDs soltos
     * combinados livremente pelo client.
     */
    private function resolveShareable(ShareableDocumentType $type, string $shareableId, Patient $patient): Model
    {
        $shareable = match ($type) {
            ShareableDocumentType::Laudo => MedicalRecordDocumentation::find($shareableId),
            ShareableDocumentType::Exame => PatientExam::find($shareableId),
            ShareableDocumentType::Anexo => MedicalRecordFile::find($shareableId),
        };

        abort_unless($shareable && (string) $shareable->patient_id === (string) $patient->id, 404);

        return $shareable;
    }

    private function currentEntityUser(): EntityUser
    {
        return EntityUser::query()->findOrFail(session('selected_entity_user_id'));
    }

    /**
     * Decisão de produto: secretária não compartilha/revoga LAUDO com o
     * paciente (conteúdo clínico assinado) — só admin/doctor. Exame/anexo
     * continuam no allowlist mais amplo já aplicado pela rota
     * (entity.role:admin,doctor,secretary); este Gate só entra pro tipo laudo.
     */
    private function authorizeLaudoShareManagement(): void
    {
        Gate::authorize(EntityGate::ShareLaudoWithPatient->value, Entity::findOrFail(session('selected_entity_id')));
    }
}
