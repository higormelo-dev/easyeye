<?php

namespace App\Services\Api;

use App\Http\Requests\Api\{ExamRequest, PatientExamRequest};
use App\Models\{Doctor, EntityIntegratorEquipment, ExamType, PatientExam, Schedule};
use Illuminate\Database\Eloquent\{Builder, ModelNotFoundException};
use Illuminate\Support\Facades\{DB, Storage};
use Illuminate\Support\Str;

class PatientExamService
{
    private const FILLABLE_FIELDS = ['patient_id', 'exam_id', 'doctor_id', 'schedule_id', 'entity_integrator_equipment_id', 'archive', 'name', 'laterality'];

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
     * Create a new record resolving patient_id and doctor_id from the schedule
     *
     * @throws \Throwable
     */
    public function createFromScheduleIdentifier(ExamRequest $request): PatientExam
    {
        return DB::transaction(function () use ($request) {
            $integrator = request()->attributes->get('integrator');
            $schedule   = $this->scheduleFindByIdOrCode($request->schedule_identifier);

            abort_unless($schedule !== null, 422, 'Schedule not found.');

            return $this->persistExam(
                patientId: $schedule->patient_id,
                entityId: $integrator->user->entity_id,
                examId: $this->examFindByIdOrCode($request->exam_identifier)?->id,
                doctorId: $schedule->doctor_id,
                scheduleId: $schedule->id,
                equipmentId: $this->equipmentFindByIdOrCode($request->equipment_identifier)?->id,
                name: $request->name,
                archiveFile: $request->file('archive'),
                laterality: $request->laterality !== null ? (int) $request->laterality : null,
            );
        });
    }

    /**
     * Update existing record and related entities
     *
     * @throws \Throwable
     */
    public function update(PatientExam $patientExam, PatientExamRequest $request): PatientExam
    {
        return DB::transaction(function () use ($patientExam, $request) {
            $schedule = $this->scheduleFindByIdOrCode($request->schedule_identifier);
            $data     = [
                ...$request->only(self::FILLABLE_FIELDS),
                'exam_id'                        => $this->examFindByIdOrCode($request->exam_identifier)?->id,
                'entity_integrator_equipment_id' => $this->equipmentFindByIdOrCode($request->equipment_identifier)?->id,
                'doctor_id'                      => $this->resolveDoctorId($request, $schedule),
                'schedule_id'                    => $schedule?->id,
            ];

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

        [$column, $value] = match (true) {
            Str::isUuid($idOrCode) => ['id',   $idOrCode],
            ctype_digit($idOrCode) => ['code', sprintf('EXM-%010d', (int) $idOrCode)],
            default                => ['code', $idOrCode],
        };

        return $query->where($column, $value)->firstOrFail()->delete();
    }

    /**
     * @throws ModelNotFoundException
     */
    public function findByIdOrCode(string $patientId, string $idOrCode): ?PatientExam
    {
        $query = PatientExam::query()
            ->with('patient')
            ->where('patient_id', $patientId)
            ->whereHas('patient', function ($query) {
                $query->where('entity_id', request()->attributes->get('integrator')->user->entity_id)
                    ->whereNull('deleted_at');
            });

        [$column, $value] = match (true) {
            Str::isUuid($idOrCode) => ['id',   $idOrCode],
            ctype_digit($idOrCode) => ['code', sprintf('EXM-%010d', (int) $idOrCode)],
            default                => ['code', $idOrCode],
        };

        return $query->where($column, $value)->firstOrFail();
    }

    /**
     * Find or create record (used by PatientExamsController store)
     */
    private function findOrCreate(PatientExamRequest $request, string $patientId): PatientExam
    {
        $integrator = request()->attributes->get('integrator');
        $schedule   = $this->scheduleFindByIdOrCode($request->schedule_identifier);

        return $this->persistExam(
            patientId: $patientId,
            entityId: $integrator->user->entity_id,
            examId: $this->examFindByIdOrCode($request->exam_identifier)?->id,
            doctorId: $this->resolveDoctorId($request, $schedule),
            scheduleId: $schedule?->id,
            equipmentId: $this->equipmentFindByIdOrCode($request->equipment_identifier)?->id,
            name: $request->name,
            archiveFile: $request->file('archive'),
            laterality: $request->laterality !== null ? (int) $request->laterality : null,
        );
    }

    /**
     * Core upsert logic: upload archive and find-or-create the PatientExam record.
     */
    private function persistExam(
        string $patientId,
        string $entityId,
        ?string $examId,
        ?string $doctorId,
        ?string $scheduleId,
        ?string $equipmentId,
        ?string $name,
        mixed $archiveFile,
        ?int $laterality = null,
    ): PatientExam {
        $uuid        = Str::uuid();
        $timestamp   = time();
        $extension   = $archiveFile->getClientOriginalExtension();
        $fileName    = "{$timestamp}_{$uuid}.{$extension}";
        $archivePath = "{$entityId}/{$patientId}/exams/{$fileName}";

        $existingRecord = PatientExam::query()
            ->with('patient')
            ->whereHas('patient', function ($query) use ($entityId) {
                $query->where('entity_id', $entityId)->whereNull('deleted_at');
            })
            ->where('name', $name)
            ->first();

        if ($existingRecord) {
            if ($existingRecord->archive) {
                Storage::disk('s3')->delete($existingRecord->archive);
            }

            $uploaded = Storage::disk('s3')
                ->put($archivePath, file_get_contents($archiveFile), 'public');

            if ($uploaded) {
                $existingRecord->update([
                    'patient_id'                     => $patientId,
                    'exam_id'                        => $examId,
                    'doctor_id'                      => $doctorId,
                    'schedule_id'                    => $scheduleId,
                    'entity_integrator_equipment_id' => $equipmentId,
                    'name'                           => $name,
                    'laterality'                     => $laterality,
                    'archive'                        => $archivePath,
                ]);

                return $existingRecord->refresh();
            }

            throw new \RuntimeException('Failed to upload exam archive.');
        }

        $uploaded = Storage::disk('s3')
            ->put($archivePath, file_get_contents($archiveFile), 'public');

        if ($uploaded) {
            return PatientExam::create([
                'patient_id'                     => $patientId,
                'exam_id'                        => $examId,
                'doctor_id'                      => $doctorId,
                'schedule_id'                    => $scheduleId,
                'entity_integrator_equipment_id' => $equipmentId,
                'name'                           => $name,
                'laterality'                     => $laterality,
                'archive'                        => $archivePath,
            ]);
        }

        throw new \RuntimeException('Failed to upload exam archive.');
    }

    /**
     * Resolve o doctor_id: prioriza doctor_identifier do request;
     * caso ausente, usa o doctor_id do schedule (se houver).
     */
    private function resolveDoctorId(PatientExamRequest $request, ?Schedule $schedule): ?string
    {
        if ($request->filled('doctor_identifier')) {
            return $this->doctorFindByIdOrCode($request->doctor_identifier)?->id;
        }

        return $schedule?->doctor_id;
    }

    public function doctorFindByIdOrCode(?string $idOrCode): ?Doctor
    {
        if ($idOrCode === null) {
            return null;
        }

        $integrator = request()->attributes->get('integrator');
        $query      = Doctor::query()
            ->with('entityUser')
            ->whereHas('entityUser', function ($query) use ($integrator) {
                $query->where('entity_id', $integrator->user->entity_id);
            });

        [$column, $value] = match (true) {
            Str::isUuid($idOrCode) => ['id',   $idOrCode],
            ctype_digit($idOrCode) => ['code', sprintf('DOC-%010d', (int) $idOrCode)],
            default                => ['code', $idOrCode],
        };

        return $query->where($column, $value)->first();
    }

    public function scheduleFindByIdOrCode(?string $idOrCode): ?Schedule
    {
        if ($idOrCode === null) {
            return null;
        }

        $integrator = request()->attributes->get('integrator');
        $query      = Schedule::query()
            ->where('entity_id', $integrator->user->entity_id);

        [$column, $value] = match (true) {
            Str::isUuid($idOrCode) => ['id',   $idOrCode],
            ctype_digit($idOrCode) => ['code', sprintf('SDL-%010d', (int) $idOrCode)],
            default                => ['code', $idOrCode],
        };

        return $query->where($column, $value)->first();
    }

    public function examFindByIdOrCode(string $idOrCode): ?ExamType
    {
        $integrator = request()->attributes->get('integrator');
        $query      = ExamType::query()
            ->where(function (Builder $query) use ($integrator) {
                $query->where('entity_id', $integrator->user->entity_id)
                    ->orWhereNull('entity_id');
            });

        [$column, $value] = match (true) {
            Str::isUuid($idOrCode) => ['id',   $idOrCode],
            ctype_digit($idOrCode) => ['code', sprintf('ETP-%010d', (int) $idOrCode)],
            default                => ['code', $idOrCode],
        };

        return $query->where($column, $value)->first();
    }

    public function equipmentFindByIdOrCode(?string $idOrCode): ?EntityIntegratorEquipment
    {
        if ($idOrCode === null) {
            return null;
        }

        $integrator = request()->attributes->get('integrator');
        $query      = EntityIntegratorEquipment::query()
            ->where(function (Builder $query) use ($integrator) {
                $query->whereHas('integrator', fn (Builder $q) => $q
                    ->whereHas('user', fn (Builder $q2) => $q2
                        ->where('entity_id', $integrator->user->entity_id)
                    )
                )->orWhereNull('integrator_id');
            });

        [$column, $value] = match (true) {
            Str::isUuid($idOrCode) => ['id',   $idOrCode],
            ctype_digit($idOrCode) => ['code', sprintf('EIQ-%010d', (int) $idOrCode)],
            default                => ['code', $idOrCode],
        };

        return $query->where($column, $value)->first();
    }
}
