<?php

namespace App\Http\Controllers;

use App\Enums\DataAccessPurpose;
use App\Models\{MedicalRecord, MedicalRecordFile, Patient};
use App\Traits\LogsDataAccess;
use Illuminate\Http\{JsonResponse, Request, Response, StreamedResponse};
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class MedicalRecordFilesController extends Controller
{
    use LogsDataAccess;

    /**
     * Upload one or more files attached to a medical record.
     */
    public function store(Request $request, Patient $patient, MedicalRecord $medicalrecord): JsonResponse
    {
        $request->validate([
            'files'   => ['required', 'array', 'min:1', 'max:10'],
            'files.*' => ['file', 'max:10240', 'mimes:jpg,jpeg,png,gif,webp,pdf,doc,docx'],
        ]);

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

        return response()->json(['files' => $saved], 201);
    }

    /**
     * Stream a private file with authentication.
     */
    public function show(Patient $patient, MedicalRecord $medicalrecord, MedicalRecordFile $file): StreamedResponse|Response
    {
        $this->logAccess($file, DataAccessPurpose::PatientCare, patientId: $patient->id);

        if (!Storage::disk('private')->exists($file->file_path)) {
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
        $file->delete();

        return response()->json(['message' => __('actions.medical_records.file_deleted')]);
    }
}
