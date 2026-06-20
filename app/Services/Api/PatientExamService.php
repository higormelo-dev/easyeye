<?php

namespace App\Services\Api;

use App\Http\Requests\Api\{ExamRequest, PatientExamRequest};
use App\Models\{Doctor, EntityIntegratorEquipment, ExamType, Patient, PatientExam, Schedule};
use Closure;
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
        $integrator = request()->attributes->get('integrator');
        $entityId   = $integrator->user->entity_id;
        $schedule   = $this->scheduleFindByIdOrCode($request->schedule_identifier);

        return $this->persistWithArchive(
            $request->file('archive'),
            "{$entityId}/{$patientId}/exams",
            fn (string $archivePath): array => $this->persistExam(
                patientId: $patientId,
                entityId: $entityId,
                examId: $this->examFindByIdOrCode($request->exam_identifier)?->id,
                doctorId: $this->resolveDoctorId($request, $schedule),
                scheduleId: $schedule?->id,
                equipmentId: $this->equipmentFindByIdOrCode($request->equipment_identifier)?->id,
                name: $request->name,
                archivePath: $archivePath,
                laterality: $request->laterality !== null ? (int) $request->laterality : null,
            ),
        );
    }

    /**
     * Create a new record resolving patient_id and doctor_id from the schedule.
     *
     * @throws Throwable
     */
    public function createFromScheduleIdentifier(ExamRequest $request): PatientExam
    {
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

        return $this->persistWithArchive(
            $request->file('archive'),
            "{$entityId}/{$patientId}/exams",
            fn (string $archivePath): array => $this->persistExam(
                patientId: $patientId,
                entityId: $entityId,
                examId: $this->examFindByIdOrCode($request->exam_identifier)?->id,
                doctorId: $doctorId,
                scheduleId: $scheduleId,
                equipmentId: $this->equipmentFindByIdOrCode($request->equipment_identifier)?->id,
                name: $request->name,
                archivePath: $archivePath,
                laterality: $request->laterality !== null ? (int) $request->laterality : null,
            ),
        );
    }

    /**
     * Update existing record and related entities.
     *
     * @throws Throwable
     */
    public function update(PatientExam $patientExam, PatientExamRequest $request): PatientExam
    {
        // Sem arquivo novo: update simples, sem tocar no S3.
        if (! $request->hasFile('archive')) {
            return DB::transaction(function () use ($patientExam, $request) {
                $patientExam->update($this->buildUpdateData($request, null));

                return $patientExam->refresh();
            });
        }

        // Com arquivo novo: sobe o novo ANTES da transação, atualiza o registro
        // e só apaga o antigo após o commit (ver persistWithArchive).
        $integrator = request()->attributes->get('integrator');
        $directory  = "{$integrator->user->entity_id}/{$patientExam->patient_id}/exams";

        return $this->persistWithArchive(
            $request->file('archive'),
            $directory,
            function (string $archivePath) use ($patientExam, $request): array {
                $oldPath = $patientExam->archive;
                $patientExam->update($this->buildUpdateData($request, $archivePath));

                return [$patientExam->refresh(), $oldPath];
            },
        );
    }

    /**
     * Monta o payload de update do exame. Quando $archivePath é informado, inclui
     * o novo caminho do arquivo; nulos são removidos para não sobrescrever colunas
     * com valores ausentes no request.
     *
     * @return array<string, mixed>
     */
    private function buildUpdateData(PatientExamRequest $request, ?string $archivePath): array
    {
        $schedule = $this->scheduleFindByIdOrCode($request->schedule_identifier);
        $data     = [
            ...$request->only(self::FILLABLE_FIELDS),
            'exam_id'                        => $this->examFindByIdOrCode($request->exam_identifier)?->id,
            'entity_integrator_equipment_id' => $this->equipmentFindByIdOrCode($request->equipment_identifier)?->id,
            'doctor_id'                      => $this->resolveDoctorId($request, $schedule),
            'schedule_id'                    => $schedule?->id,
        ];

        if ($archivePath !== null) {
            $data['archive'] = $archivePath;
        } else {
            // Evita que um UploadedFile vaze de request->only() para o update.
            unset($data['archive']);
        }

        return array_filter($data, static fn ($value) => $value !== null);
    }

    /**
     * Orquestra um upsert de exame com troca de arquivo de forma segura:
     *
     *   1. Faz upload do arquivo NOVO ANTES de abrir a transação.
     *   2. Executa o persist (find-or-create/update) dentro da transação; o
     *      callback devolve [PatientExam, ?caminho_do_arquivo_antigo].
     *   3. Em rollback, apaga o arquivo recém-enviado (órfão).
     *   4. Só após o COMMIT apaga o arquivo antigo.
     *
     * Garante a invariante: o registro nunca aponta para um arquivo inexistente.
     * No pior caso sobra um órfão no S3 (limpável por GC), nunca o inverso.
     *
     * @param Closure(string): array{0: PatientExam, 1: ?string} $persist
     *
     * @throws Throwable
     */
    private function persistWithArchive(UploadedFile $file, string $directory, Closure $persist): PatientExam
    {
        $fileName    = sprintf('%d_%s.%s', time(), Str::uuid(), $file->getClientOriginalExtension());
        $archivePath = $this->storeArchive($file, $directory, $fileName);

        try {
            /** @var array{0: PatientExam, 1: ?string} $result */
            $result             = DB::transaction(static fn () => $persist($archivePath));
            [$record, $oldPath] = $result;
        } catch (Throwable $e) {
            Storage::disk('s3')->delete($archivePath);

            throw $e;
        }

        if ($oldPath !== null && $oldPath !== $archivePath) {
            Storage::disk('s3')->delete($oldPath);
        }

        return $record;
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
     * Núcleo do upsert: faz find-or-create do PatientExam usando um arquivo JÁ
     * enviado ao S3 (caminho em $archivePath). NÃO sobe nem apaga arquivos — a
     * orquestração de upload/cleanup fica em persistWithArchive, para manter a
     * ordem segura S3↔DB (upload antes do commit, delete do antigo após o commit).
     *
     * @return array{0: PatientExam, 1: ?string} [registro, caminho_do_arquivo_antigo]
     */
    private function persistExam(
        string $patientId,
        string $entityId,
        ?string $examId,
        ?string $doctorId,
        ?string $scheduleId,
        ?string $equipmentId,
        ?string $name,
        string $archivePath,
        ?int $laterality = null,
    ): array {
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
            $oldPath = $existingRecord->archive;

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

            return [$existingRecord->refresh(), $oldPath];
        }

        $record = PatientExam::create([
            'patient_id'                     => $patientId,
            'exam_id'                        => $examId,
            'doctor_id'                      => $doctorId,
            'schedule_id'                    => $scheduleId,
            'entity_integrator_equipment_id' => $equipmentId,
            'name'                           => $name,
            'laterality'                     => $laterality,
            'archive'                        => $archivePath,
        ]);

        return [$record, null];
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
