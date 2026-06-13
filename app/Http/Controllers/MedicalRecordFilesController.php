<?php

namespace App\Http\Controllers;

use App\Enums\{DataAccessPurpose, EntityGate, FeatureKey};
use App\Exceptions\FeatureDeniedException;
use App\Models\{Entity, MedicalRecord, MedicalRecordFile, Patient};
use App\Services\{FeatureGateService, UsageMeterService};
use App\Traits\LogsDataAccess;
use Illuminate\Http\{JsonResponse, Request, Response, StreamedResponse};
use Illuminate\Support\Facades\{Gate, Storage};
use Illuminate\Support\Str;

class MedicalRecordFilesController extends Controller
{
    use LogsDataAccess;

    public function __construct(
        private readonly FeatureGateService $featureGate,
        private readonly UsageMeterService $usageMeter,
    ) {
    }

    /**
     * Upload one or more files attached to a medical record.
     */
    public function store(Request $request, Patient $patient, MedicalRecord $medicalrecord): JsonResponse
    {
        $this->assertRecordBelongsToPatient($patient, $medicalrecord);
        $this->authorizeIssueReport();

        $request->validate([
            'files'   => ['required', 'array', 'min:1', 'max:10'],
            'files.*' => ['file', 'max:10240', 'mimes:jpg,jpeg,png,gif,webp,pdf,doc,docx'],
        ]);

        $this->enforceStorageQuota($request);

        $saved = [];

        foreach ($request->file('files') as $file) {
            $path = $file->storeAs(
                "medical-records/{$medicalrecord->id}",
                Str::uuid() . '.' . $file->getClientOriginalExtension(),
                'private',
            );

            $record = MedicalRecordFile::create([
                'medical_record_id' => $medicalrecord->id,
                'patient_id'        => $patient->id,
                'file_path'         => $path,
                'original_name'     => $file->getClientOriginalName(),
                'mime_type'         => $file->getMimeType(),
                'file_size'         => $file->getSize(),
                'created_by'        => auth()->id(),
            ]);

            $saved[] = [
                'id'            => $record->id,
                'original_name' => $record->original_name,
                'mime_type'     => $record->mime_type,
                'file_size'     => $record->file_size,
                'is_image'      => $record->isImage(),
                'show_url'      => route('panel.patients.medicalrecords.files.show', [$patient, $medicalrecord, $record]),
            ];
        }

        return response()->json([
            'files'   => $saved,
            'storage' => $this->currentStorageStatus(),
        ], 201);
    }

    /**
     * Stream a private file with authentication.
     */
    public function show(Patient $patient, MedicalRecord $medicalrecord, MedicalRecordFile $file): StreamedResponse|Response
    {
        $this->assertRecordBelongsToPatient($patient, $medicalrecord);
        $this->assertFileBelongsToRecord($medicalrecord, $file);

        $this->logAccess($file, DataAccessPurpose::PatientCare, patientId: $patient->id);

        if (! Storage::disk('private')->exists($file->file_path)) {
            abort(404);
        }

        return Storage::disk('private')->response($file->file_path, $file->original_name, [
            'Content-Type'        => $file->mime_type,
            'Content-Disposition' => 'inline; filename="' . $file->original_name . '"',
        ]);
    }

    /**
     * Soft-delete a file (record + storage kept for audit).
     */
    public function destroy(Patient $patient, MedicalRecord $medicalrecord, MedicalRecordFile $file): JsonResponse
    {
        $this->assertRecordBelongsToPatient($patient, $medicalrecord);
        $this->assertFileBelongsToRecord($medicalrecord, $file);
        $this->authorizeIssueReport();

        $file->delete();

        return response()->json(['message' => __('actions.medical_records.file_deleted')]);
    }

    private function authorizeIssueReport(): void
    {
        $entityId = session('selected_entity_id');
        Gate::authorize(EntityGate::IssueReport->value, Entity::findOrFail($entityId));
    }

    /**
     * Payload de cota retornado junto com o upload — permite ao modal atualizar
     * o medidor sem nova requisição. Estado autoritativo (server-side), evita
     * divergência se houver upload concorrente em outro dispositivo.
     */
    private function currentStorageStatus(): array
    {
        $entityId   = (string) session('selected_entity_id');
        $status     = $this->featureGate->status($entityId, FeatureKey::MaxStorageGB);
        $usedBytes  = $this->usageMeter->getStorageUsedBytes($entityId);
        $limitBytes = $status->isUnlimited ? 0 : $status->limit * 1024 * 1024 * 1024;
        $percent    = $status->isUnlimited || $limitBytes === 0
            ? 0
            : min(100, (int) round(($usedBytes / $limitBytes) * 100));

        return [
            'used_bytes'      => $usedBytes,
            'limit_bytes'     => $limitBytes,
            'limit_gb'        => $status->limit,
            'is_unlimited'    => $status->isUnlimited,
            'percent'         => $percent,
            'remaining_bytes' => $status->isUnlimited ? null : max(0, $limitBytes - $usedBytes),
        ];
    }

    /**
     * Bloqueia o upload quando a soma dos novos arquivos ultrapassaria o limite
     * de armazenamento configurado no plano. Opera em bytes para máxima precisão —
     * o valor em GB exposto no FeatureStatus é floor do total, mas o bloqueio
     * respeita o limite exato.
     *
     * @throws FeatureDeniedException
     */
    private function enforceStorageQuota(Request $request): void
    {
        $entityId = session('selected_entity_id');
        $status   = $this->featureGate->status($entityId, FeatureKey::MaxStorageGB);

        if ($status->isUnlimited) {
            return;
        }

        $limitBytes = $status->limit * 1024 * 1024 * 1024;
        $usedBytes  = $this->usageMeter->getStorageUsedBytes($entityId);
        $newBytes   = collect($request->file('files'))->sum(fn ($f) => $f->getSize());

        if ($usedBytes + $newBytes > $limitBytes) {
            throw new FeatureDeniedException(FeatureKey::MaxStorageGB, $status);
        }
    }

    private function assertRecordBelongsToPatient(Patient $patient, MedicalRecord $medicalRecord): void
    {
        abort_if($medicalRecord->patient_id !== $patient->id, 404);
    }

    private function assertFileBelongsToRecord(MedicalRecord $medicalRecord, MedicalRecordFile $file): void
    {
        abort_if($file->medical_record_id !== $medicalRecord->id, 404);
        abort_if($file->patient_id !== $medicalRecord->patient_id, 404);
    }
}
