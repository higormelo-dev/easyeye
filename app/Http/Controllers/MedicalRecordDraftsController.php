<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\DataAccessPurpose;
use App\Exceptions\LockedMedicalRecordException;
use App\Models\{MedicalRecord, Patient, Schedule, User};
use App\Services\MedicalRecords\MedicalRecordDraftService;
use App\Support\CurrentDoctor;
use App\Traits\LogsDataAccess;
use Illuminate\Http\{JsonResponse, Request, Response};
use Illuminate\Support\Str;

/**
 * Autosave do prontuário (Fase 1 / B2): rascunho do autor, separado do
 * prontuário oficial (MedicalRecordDraftService).
 *
 * Rotas no grupo doctor-only do prontuário (entity.role:doctor) + cadastro
 * de médico ativo (quem não pode salvar o prontuário também não guarda
 * rascunho). Paciente e contexto (prontuário/agendamento) sempre da clínica
 * da sessão e do MESMO paciente; o rascunho é só do usuário logado.
 */
class MedicalRecordDraftsController extends Controller
{
    use LogsDataAccess;

    public function __construct(private readonly MedicalRecordDraftService $drafts)
    {
    }

    /** Conteúdo do rascunho para "Recuperar" (só quando é mais novo que o prontuário). */
    public function show(Request $request, Patient $patient): JsonResponse
    {
        [$record, $schedule] = $this->context($request, $patient);

        $draft = $this->drafts->find($this->user(), $this->entityId(), $patient, $record, $schedule);
        abort_if($draft === null, 404);

        // Leitura de dado clínico do paciente (o que foi digitado na consulta).
        $this->logAccess($patient, DataAccessPurpose::PatientCare, patientId: (string) $patient->id);

        return response()->json([
            'payload'    => $draft->payload,
            'updated_at' => $draft->updated_at->toIso8601String(),
        ]);
    }

    public function save(Request $request, Patient $patient): JsonResponse
    {
        $request->validate([
            'payload'           => ['required', 'array'],
            'medical_record_id' => ['nullable', 'uuid'],
            'schedule_id'       => ['nullable', 'uuid'],
        ]);

        $payload = MedicalRecordDraftService::onlyFormFields((array) $request->input('payload'));

        if (strlen((string) json_encode($payload)) > MedicalRecordDraftService::MAX_PAYLOAD_BYTES) {
            return response()->json(['message' => __('actions.medical_records.draft_too_large')], 413);
        }

        [$record, $schedule] = $this->context($request, $patient);

        try {
            $draft = $this->drafts->save($this->user(), $this->entityId(), $patient, $record, $schedule, $payload);
        } catch (LockedMedicalRecordException) {
            return response()->json(['message' => __('actions.medical_records.locked')], 423);
        }

        return response()->json([
            'saved_at'       => $draft->updated_at->toIso8601String(),
            'saved_at_label' => $draft->updated_at->locale(app()->getLocale())->isoFormat('LT'),
        ]);
    }

    public function destroy(Request $request, Patient $patient): Response
    {
        [$record, $schedule] = $this->context($request, $patient);

        $this->drafts->discard($this->user(), $this->entityId(), $patient, $record, $schedule);

        return response()->noContent();
    }

    // ── internos ─────────────────────────────────────────────────────────────

    /**
     * Paciente da clínica da sessão, médico com cadastro ativo, e a tela do
     * rascunho: prontuário DESTE paciente (edição) ou agendamento DESTE
     * paciente (novo pela Agenda) — nunca de outra clínica/paciente.
     *
     * @return array{0: ?MedicalRecord, 1: ?Schedule}
     */
    private function context(Request $request, Patient $patient): array
    {
        abort_unless((string) $patient->entity_id === $this->entityId(), 404);
        abort_unless(CurrentDoctor::canIssue(), 403, CurrentDoctor::missingMessage());

        $recordId   = (string) $request->input('medical_record_id', '');
        $scheduleId = (string) $request->input('schedule_id', '');

        $record = $recordId !== ''
            ? MedicalRecord::query()
                ->where('entity_id', $this->entityId())
                ->where('patient_id', $patient->id)
                ->whereKey(Str::isUuid($recordId) ? $recordId : null)
                ->firstOrFail()
            : null;

        $schedule = $record === null && $scheduleId !== ''
            ? Schedule::query()
                ->where('entity_id', $this->entityId())
                ->where('patient_id', $patient->id)
                ->whereKey(Str::isUuid($scheduleId) ? $scheduleId : null)
                ->firstOrFail()
            : null;

        return [$record, $schedule];
    }

    private function entityId(): string
    {
        return (string) session('selected_entity_id');
    }

    private function user(): User
    {
        $user = auth()->user();
        abort_unless($user instanceof User, 401);

        return $user;
    }
}
