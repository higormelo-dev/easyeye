<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\ExamRequest;
use App\Http\Resources\PatientExamResource;
use App\Models\{Patient, PatientExam};
use App\Services\Api\PatientExamService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ExamsController extends Controller
{
    /**
     * Instance of the standard model.
     */
    protected PatientExam $model;

    protected PatientExamService $service;

    private const FILLABLE_FIELDS = ['patient_id', 'doctor_id', 'schedule_id', 'archive', 'name'];

    public function __construct(PatientExam $patientExam, PatientExamService $patientExamService)
    {
        $this->model   = $patientExam;
        $this->service = $patientExamService;
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(ExamRequest $request): PatientExamResource|JsonResponse
    {
        $integrator = request()->attributes->get('integrator');
        $patient    = $this->findPatient($request, $integrator);

        $recordData  = $this->buildRecordData($request, $patient);
        $archivePath = $this->generateArchivePath($integrator->entity_id, $patient->id, $request->file('archive'));

        $existingRecord = $this->findExistingExam($request->name, $integrator);

        if ($existingRecord) {
            $record = $this->updateExistingRecord($existingRecord, $recordData, $archivePath, $request);
        } else {
            $record = $this->createNewRecord($recordData, $archivePath, $request);
        }

        return new PatientExamResource($record);
    }

    private function findPatient(ExamRequest $request, $integrator): Patient
    {
        return Patient::query()
            ->where('entity_id', $integrator->entity_id)
            ->when(
                Str::isUuid($request->patient_id),
                fn ($q) => $q->where('id', $request->patient_id),
                fn ($q) => $q->where('code', $request->patient_code)
            )
            ->firstOrFail();
    }

    private function buildRecordData(ExamRequest $request, Patient $patient): array
    {
        $data = [
            ...$request->only(self::FILLABLE_FIELDS),
            'patient_id' => $patient->id,
        ];

        if ($request->filled('doctor_code')) {
            $data['doctor_id'] = $this->service->doctorFindByIdOrCode($request->doctor_code)?->id;
        }

        if ($request->filled('schedule_code')) {
            $data['schedule_id'] = $this->service->scheduleFindByIdOrCode($request->schedule_code)?->id;
        }

        return $data;
    }

    private function generateArchivePath(string $entityId, string $patientId, $file): string
    {
        $uuid      = Str::uuid();
        $timestamp = time();
        $extension = $file->getClientOriginalExtension();

        return "{$entityId}/{$patientId}/exams/{$timestamp}_{$uuid}.{$extension}";
    }

    private function findExistingExam(string $name, $integrator): ?PatientExam
    {
        return PatientExam::query()
            ->with('patient')
            ->whereHas('patient', fn ($q) => $q->where('entity_id', $integrator->entity_id)->whereNull('deleted_at'))
            ->where('name', $name)
            ->first();
    }

    private function uploadArchive(string $path, $file): void
    {
        $uploaded = Storage::disk('s3')->put(
            $path,
            file_get_contents($file->getRealPath()),
            'public'
        );

        if (! $uploaded) {
            throw new \RuntimeException('Failed to upload exam archive.');
        }
    }

    private function updateExistingRecord(PatientExam $record, array $data, string $archivePath, ExamRequest $request): PatientExamResource
    {
        if ($record->archive) {
            Storage::disk('s3')->delete($record->archive);
        }

        $this->uploadArchive($archivePath, $request->file('archive'));

        $record->update([...$data, 'archive' => $archivePath]);

        return new PatientExamResource($record->refresh());
    }

    private function createNewRecord(array $data, string $archivePath, ExamRequest $request): PatientExamResource
    {
        $this->uploadArchive($archivePath, $request->file('archive'));

        $record = PatientExam::create([...$data, 'archive' => $archivePath]);

        return new PatientExamResource($record);
    }
}
