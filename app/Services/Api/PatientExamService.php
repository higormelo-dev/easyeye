<?php

namespace App\Services\Api;

use App\Http\Requests\Api\{ExamRequest, PatientExamRequest};
use App\Models\{Doctor, EntityIntegratorEquipment, ExamType, Patient, PatientExam, Schedule};
use Illuminate\Database\Eloquent\{Builder, ModelNotFoundException};
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\{DB, Storage};
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class PatientExamService
{
    private const FILLABLE_FIELDS = ['patient_id', 'exam_id', 'doctor_id', 'schedule_id', 'entity_integrator_equipment_id', 'archive', 'name', 'laterality'];

    /**
     * Create a new record with all related entities.
     *
     * @throws Throwable
     */
    public function create(PatientExamRequest $request, string $patientId): PatientExam
    {
        return DB::transaction(fn () => $this->findOrCreate($request, $patientId));
    }

    /**
     * Create a new record resolving patient_id and doctor_id from the schedule.
     *
     * @throws Throwable
     */
    public function createFromScheduleIdentifier(ExamRequest $request): PatientExam
    {
        return DB::transaction(function () use ($request) {
            $integrator = request()->attributes->get('integrator');
            $entityId   = $integrator->user->entity_id;
            $schedule   = $this->scheduleFindByIdOrCode($request->schedule_identifier);

            if ($schedule) {
                // Fluxo original: schedule_identifier informado
                $patientId  = $schedule->patient_id;
                $doctorId   = $schedule->doctor_id;
                $scheduleId = $schedule->id;
            } else {
                // Fluxo alternativo: resolve pelo patient_identifier
                $patient = $this->patientFindByIdOrCode($request->patient_identifier, $entityId);
                abort_unless($patient !== null, 422, 'Patient not found.');

                $patientId = $patient->id;

                // Tenta vincular ao agendamento mais recente do dia para esse paciente
                $todaySchedule = Schedule::where('entity_id', $entityId)
                    ->where('patient_id', $patientId)
                    ->whereDate('date_time', now()->toDateString())
                    ->whereNull('deleted_at')
                    ->orderByDesc('date_time')
                    ->first();

                $doctorId   = $todaySchedule?->doctor_id;
                $scheduleId = $todaySchedule?->id;
            }

            return $this->persistExam(
                patientId: $patientId,
                entityId: $entityId,
                examId: $this->examFindByIdOrCode($request->exam_identifier)?->id,
                doctorId: $doctorId,
                scheduleId: $scheduleId,
                equipmentId: $this->equipmentFindByIdOrCode($request->equipment_identifier)?->id,
                name: $request->name,
                archiveFile: $request->file('archive'),
                laterality: $request->laterality !== null ? (int) $request->laterality : null,
            );
        });
    }

    /**
     * Update existing record and related entities.
     *
     * @throws Throwable
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
                $integrator = request()->attributes->get('integrator');
                $file       = $request->file('archive');
                $directory  = "{$integrator->user->entity_id}/{$patientExam->patient_id}/exams";
                $fileName   = sprintf('%d_%s.%s', time(), Str::uuid(), $file->getClientOriginalExtension());

                if ($patientExam->archive) {
                    Storage::disk('s3')->delete($patientExam->archive);
                }

                $data['archive'] = $this->storeArchive($file, $directory, $fileName);
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
            Str::isUuid($idOrCode) => ['id', $idOrCode],
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
            ->with(['patient.person', 'doctor.person', 'schedule', 'equipment'])
            ->where('patient_id', $patientId)
            ->whereHas('patient', function ($query) {
                $query->where('entity_id', request()->attributes->get('integrator')->user->entity_id)
                    ->whereNull('deleted_at');
            });

        [$column, $value] = match (true) {
            Str::isUuid($idOrCode) => ['id', $idOrCode],
            ctype_digit($idOrCode) => ['code', sprintf('EXM-%010d', (int) $idOrCode)],
            default                => ['code', $idOrCode],
        };

        return $query->where($column, $value)->firstOrFail();
    }

    /**
     * Find or create record (used by PatientExamsController store).
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
        $directory = "{$entityId}/{$patientId}/exams";
        $fileName  = sprintf('%d_%s.%s', time(), Str::uuid(), $archiveFile->getClientOriginalExtension());

        // Escopo do upsert: o registro existente DEVE pertencer ao mesmo paciente.
        // Sem o filtro por patient_id, um exame de outro paciente com o mesmo
        // `name` seria reassociado e teria o arquivo apagado (corrupção cross-patient).
        $existingRecord = PatientExam::query()
            ->with('patient')
            ->where('patient_id', $patientId)
            ->whereHas('patient', function ($query) use ($entityId) {
                $query->where('entity_id', $entityId)->whereNull('deleted_at');
            })
            ->where('name', $name)
            ->first();

        if ($existingRecord) {
            if ($existingRecord->archive) {
                Storage::disk('s3')->delete($existingRecord->archive);
            }

            $existingRecord->update([
                'patient_id'                     => $patientId,
                'exam_id'                        => $examId,
                'doctor_id'                      => $doctorId,
                'schedule_id'                    => $scheduleId,
                'entity_integrator_equipment_id' => $equipmentId,
                'name'                           => $name,
                'laterality'                     => $laterality,
                'archive'                        => $this->storeArchive($archiveFile, $directory, $fileName),
            ]);

            return $existingRecord->refresh();
        }

        return PatientExam::create([
            'patient_id'                     => $patientId,
            'exam_id'                        => $examId,
            'doctor_id'                      => $doctorId,
            'schedule_id'                    => $scheduleId,
            'entity_integrator_equipment_id' => $equipmentId,
            'name'                           => $name,
            'laterality'                     => $laterality,
            'archive'                        => $this->storeArchive($archiveFile, $directory, $fileName),
        ]);
    }

    /**
     * Faz upload do arquivo de exame em streaming (sem carregar tudo em memória),
     * sempre com visibilidade privada — exame é dado sensível de saúde (LGPD art. 11).
     * O acesso é feito via URL assinada temporária (PatientExam::archiveUrl()).
     *
     * @throws RuntimeException quando o upload falha
     */
    private function storeArchive(UploadedFile $file, string $directory, string $fileName): string
    {
        $path = Storage::disk('s3')->putFileAs($directory, $file, $fileName, 'private');

        if ($path === false) {
            throw new RuntimeException('Failed to upload exam archive.');
        }

        return $path;
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
            Str::isUuid($idOrCode) => ['id', $idOrCode],
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
            Str::isUuid($idOrCode) => ['id', $idOrCode],
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
            Str::isUuid($idOrCode) => ['id', $idOrCode],
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
                $query->whereHas(
                    'integrator',
                    fn (Builder $q) => $q
                        ->whereHas(
                            'user',
                            fn (Builder $q2) => $q2
                                ->where('entity_id', $integrator->user->entity_id),
                        ),
                )->orWhereNull('integrator_id');
            });

        [$column, $value] = match (true) {
            Str::isUuid($idOrCode) => ['id', $idOrCode],
            ctype_digit($idOrCode) => ['code', sprintf('EIQ-%010d', (int) $idOrCode)],
            default                => ['code', $idOrCode],
        };

        return $query->where($column, $value)->first();
    }

    public function patientFindByIdOrCode(?string $idOrCode, string $entityId): ?Patient
    {
        if ($idOrCode === null) {
            return null;
        }

        [$column, $value] = match (true) {
            Str::isUuid($idOrCode) => ['id', $idOrCode],
            ctype_digit($idOrCode) => ['code', sprintf('PAC-%010d', (int) $idOrCode)],
            default                => ['code', $idOrCode],
        };

        return Patient::where('entity_id', $entityId)
            ->where($column, $value)
            ->first();
    }
}
