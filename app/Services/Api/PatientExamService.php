<?php

namespace App\Services\Api;

use App\Http\Requests\Api\PatientExamRequest;
use App\Models\PatientExam;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\{DB, Storage};
use Illuminate\Support\Str;

class PatientExamService
{
    private const FILLABLE_FIELDS = ['patient_id', 'doctor_id', 'schedule_id', 'archive', 'name'];

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
        return DB::transaction(static function () use ($patientExam, $request) {
            $data = $request->only(self::FILLABLE_FIELDS);

            if ($request->has('active')) {
                $data['active'] = $request->boolean('active');
            }

            if ($request->hasFile('archive')) {
                $uuid        = Str::uuid();
                $timestamp   = time();
                $integrator  = request()->attributes->get('integrator');
                $file        = $request->file('archive');
                $extension   = $file->getClientOriginalExtension();
                $fileName    = "{$timestamp}_{$uuid}.{$extension}";
                $archivePath = "{$integrator->entity_id}/{$patientExam->patient_id}/exams/{$fileName}";

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
            ->where('patient_id', $patientId);

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
        $archivePath = "{$integrator->entity_id}/{$patientId}/exams/{$fileName}";
        $recordData  = [
            ...$request->only(self::FILLABLE_FIELDS),
            'active' => $request->boolean('active'),
        ];

        $existingRecord = PatientExam::query()
            ->withTrashed()
            ->where('integrator_id', $integrator->id)
            ->where('name', $request->name)
            ->first();

        if ($existingRecord) {
            $existingRecord->trashed() && $existingRecord->restore();

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
                'archive' => $archivePath,
                'active'  => true,
            ]);
        }

        throw new \RuntimeException('Failed to upload exam archive.');
    }
}
