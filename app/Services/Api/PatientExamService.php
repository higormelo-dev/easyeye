<?php

namespace App\Services\Api;

use App\Http\Requests\Api\PatientExamRequest;
use App\Models\{Doctor, ExamType, PatientExam, Schedule};
use Illuminate\Database\Eloquent\{Builder, ModelNotFoundException};
use Illuminate\Support\Facades\{DB, Storage};
use Illuminate\Support\Str;

class PatientExamService
{
    private const FILLABLE_FIELDS = ['patient_id', 'exam_id', 'doctor_id', 'schedule_id', 'archive', 'name'];

    /**
     * Create a new record with all related entities
     *
     * @throws \Throwable
     */
    public function create(PatientExamRequest $request, string $patientId): PatientExam
    {
        return DB::transaction(fn () => $this->findOrCreate($request, $patientId));
    }

    /**
     * Update existing record and related entities
     *
     * @throws \Throwable
     */
    public function update(PatientExam $patientExam, PatientExamRequest $request): PatientExam
    {
        return DB::transaction(function () use ($patientExam, $request) {
            $data = [
                ...$request->only(self::FILLABLE_FIELDS),
                'exam_id' => $this->examFindByIdOrCode($request->exam_identifier)?->id,
            ];

            if ($request->filled('doctor_identifier')) {
                $data['doctor_id'] = $this->doctorFindByIdOrCode($request->doctor_identifier)?->id;
            }

            if ($request->filled('schedule_identifier')) {
                $data['schedule_id'] = $this->scheduleFindByIdOrCode($request->schedule_identifier)?->id;
            }

            if ($request->hasFile('archive')) {
                $uuid        = Str::uuid();
                $timestamp   = time();
                $integrator  = request()->attributes->get('integrator');
                $file        = $request->file('archive');
                $extension   = $file->getClientOriginalExtension();
                $fileName    = "{$timestamp}_{$uuid}.{$extension}";
                $archivePath = "{$integrator->user->entity_id}/{$patientExam->patient_id}/exams/{$fileName}";

                if ($patientExam->archive) {
                    Storage::disk('s3')->delete($patientExam->archive);
                }

                $uploaded = Storage::disk('s3')
                    ->put(
                        $archivePath,
                        file_get_contents($file->getRealPath()),
                        'public'
                    );

                if (! $uploaded) {
                    throw new \RuntimeException('Failed to upload exam archive.');
                }

                $data['archive'] = $archivePath;
            }

            $patientExam->update(array_filter($data, static fn ($value) => $value !== null));

            return $patientExam->refresh();
        });
    }

    public function destroyByIdOrCode(string $patientId, string $idOrCode): bool
    {
        $query = PatientExam::query()
            ->where('patient_id', $patientId);

        if (Str::isUuid($idOrCode)) {
            $query->where('id', $idOrCode);
        } else {
            $query->where('code', $idOrCode);
        }

        return $query->firstOrFail()->delete();
    }

    /**
     * Find by ID or Code including soft-deleted records
     *
     * @throws ModelNotFoundException
     */
    public function findByIdOrCode(string $patientId, string $idOrCode): ?PatientExam
    {
        $query = PatientExam::query()
            ->with('patient', '')
            ->whereHas('patient', function ($query) use ($patientId) {
                $query->where('id', $patientId)
                    ->where(function ($query) {
                        $query->where('entity_id', request()->attributes->get('integrator')->entity_id)
                            ->whereNull('deleted_at');
                    });
            });

        if (Str::isUuid($idOrCode)) {
            $query->where('id', $idOrCode);
        } else {
            $query->where('code', $idOrCode);
        }

        return $query->firstOrFail();
    }

    /**
     * Find or create record
     */
    private function findOrCreate(PatientExamRequest $request, string $patientId): PatientExam
    {
        $uuid        = Str::uuid();
        $timestamp   = time();
        $integrator  = request()->attributes->get('integrator');
        $extension   = $request->file('archive')->getClientOriginalExtension();
        $fileName    = "{$timestamp}_{$uuid}.{$extension}";
        $archivePath = "{$integrator->user->entity_id}/{$patientId}/exams/{$fileName}";
        $recordData  = [
            ...$request->only(self::FILLABLE_FIELDS),
            'exam_id' => $this->examFindByIdOrCode($request->exam_identifier)?->id,
        ];

        if ($request->filled('doctor_code')) {
            $recordData['doctor_id'] = $this->doctorFindByIdOrCode($request->doctor_code)?->id;
        }

        if ($request->filled('schedule_code')) {
            $recordData['schedule_id'] = $this->scheduleFindByIdOrCode($request->schedule_code)?->id;
        }

        $existingRecord = PatientExam::query()
            ->with('patient')
            ->whereHas('patient', function ($query) {
                $query->where('entity_id', request()->attributes->get('integrator')->entity_id)
                    ->whereNull('deleted_at');
            })
            ->where('name', $request->name)
            ->first();

        if ($existingRecord) {
            if ($existingRecord->archive) {
                Storage::disk('s3')->delete($existingRecord->archive);
            }

            $uploaded = Storage::disk('s3')
                ->put(
                    $archivePath,
                    file_get_contents($request->file('archive')),
                    'public'
                );

            if ($uploaded) {
                $existingRecord->update([
                    ...$recordData,
                    'archive' => $archivePath,
                ]);

                return $existingRecord->refresh();

            }

            throw new \RuntimeException('Failed to upload exam archive.');
        }

        $uploaded = Storage::disk('s3')
            ->put(
                $archivePath,
                file_get_contents($request->file('archive')),
                'public'
            );

        if ($uploaded) {
            return PatientExam::create([
                ...$recordData,
                'patient_id' => $patientId,
                'archive'    => $archivePath,
            ]);
        }

        throw new \RuntimeException('Failed to upload exam archive.');
    }

    /**
     * Find by ID or Code including soft-deleted records
     *
     * @throws ModelNotFoundException
     */
    public function doctorFindByIdOrCode(string $idOrCode): ?Doctor
    {
        $integrator = request()->attributes->get('integrator');
        $query      = Doctor::query()
            ->with('entityUser')
            ->whereHas('entityUser', function ($query) use ($integrator) {
                $query->where('entity_id', $integrator->user->entity_id);
            });

        if (Str::isUuid($idOrCode)) {
            $query->where('id', $idOrCode);
        } else {
            $query->where('code', $idOrCode);
        }

        return $query->first();
    }

    /**
     * Find by ID or Code including soft-deleted records
     *
     * @throws ModelNotFoundException
     */
    public function scheduleFindByIdOrCode(string $idOrCode): ?Schedule
    {
        $integrator = request()->attributes->get('integrator');
        $query      = Schedule::query()
            ->where('entity_id', $integrator->user->entity_id);

        if (Str::isUuid($idOrCode)) {
            $query->where('id', $idOrCode);
        } else {
            $query->where('code', $idOrCode);
        }

        return $query->first();
    }

    /**
     * Find by ID or Code including soft-deleted records
     *
     * @throws ModelNotFoundException
     */
    public function examFindByIdOrCode(string $idOrCode): ?ExamType
    {
        $integrator = request()->attributes->get('integrator');
        $query      = ExamType::query()
            ->where(function (Builder $query) use ($integrator) {
                $query->where('entity_id', $integrator->entity_id)
                    ->orWhereNull('entity_id');
            });

        if (Str::isUuid($idOrCode)) {
            $query->where('id', $idOrCode);
        } else {
            $query->where('code', $idOrCode);
        }

        return $query->first();
    }
}
