<?php

namespace App\Services\Api;

use App\Http\Requests\Api\{ExamRequest, PatientExamRequest};
use App\Models\{Doctor, EntityIntegrator, EntityIntegratorEquipment, ExamType, Patient, PatientExam, Schedule};
use App\Support\IntegratorClinicalIdentifier;
use Closure;
use Illuminate\Database\Eloquent\{Builder, Collection, Model, ModelNotFoundException};
use Illuminate\Http\UploadedFile;
use Illuminate\Support\{Carbon, Str};
use Illuminate\Support\Facades\{DB, Storage};
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

class PatientExamService
{
    // patient_id NUNCA deve entrar aqui: buildUpdateData() espalha este array
    // direto no update() sem re-derivar patient_id (ao contrário de exam_id/
    // doctor_id/schedule_id, que são recalculados logo em seguida). Incluir
    // patient_id permitiria a um integrador reatribuir um exame para outro
    // paciente da mesma clínica só enviando patient_id no body do update
    // (achado de auditoria de segurança — IDOR via mass-assignment).
    private const FILLABLE_FIELDS = ['exam_id', 'doctor_id', 'schedule_id', 'entity_integrator_equipment_id', 'archive', 'name', 'laterality'];

    /** Prefixo do código sequencial de paciente (PAC-0000000042). */
    private const PATIENT_CODE_PREFIX = 'PAC';

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
        $this->assertSchedulePatient($schedule, $patientId);

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
                examPerformedAt: $request->filled('exam_performed_at')
                    ? Carbon::parse($request->exam_performed_at)->setTimezone(config('app.timezone')) : null,
                observation: $request->filled('observation') ? $request->observation : null,
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

        // Data real da captura (do arquivo do equipamento), no fuso da clínica.
        $examPerformedAt = $request->filled('exam_performed_at')
            ? Carbon::parse($request->exam_performed_at)->setTimezone(config('app.timezone'))
            : null;

        if ($schedule) {
            if ($schedule->patient_id === null) {
                $this->clinicalError('schedule_patient_unresolved', 'schedule_identifier', 'O agendamento precisa de um paciente identificado antes do envio.');
            }

            if ($request->filled('patient_identifier')) {
                $explicit = $this->patientFindByIdOrCode($request->patient_identifier, $entityId);
                abort_unless($explicit !== null, 422);
                $this->assertSchedulePatient($schedule, $explicit->id);
            }
            $this->assertSchedulePatient($schedule, $schedule->patient_id);
            // Fluxo original: schedule_identifier informado
            $patientId  = $schedule->patient_id;
            $doctorId   = $schedule->doctor_id;
            $scheduleId = $schedule->id;
        } else {
            // Fluxo alternativo: resolve pelo patient_identifier
            $patient = $this->patientFindByIdOrCode($request->patient_identifier, $entityId);
            abort_unless($patient !== null, 422, __('validation.custom.validation_invalid.not_patient_identifier'));

            $patientId = $patient->id;

            // Patient-only means exactly that. An absent schedule is never
            // inferred from the day of upload or appointment ordering.
            $doctorId   = null;
            $scheduleId = null;
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
                examPerformedAt: $examPerformedAt,
                observation: $request->filled('observation') ? $request->observation : null,
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
        if ($patientExam->capture_id !== null && $request->hasFile('archive')) {
            $this->clinicalError('capture_immutable', 'archive', 'O original desta aquisição é imutável. Envie um novo identificador de captura para um novo original.');
        }
        $this->assertSchedulePatient($this->scheduleFindByIdOrCode($request->schedule_identifier), $patientExam->patient_id);

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
                $oldPath     = $patientExam->archive;
                $patientExam = PatientExam::whereKey($patientExam->id)->lockForUpdate()->firstOrFail();

                if ($patientExam->capture_id !== null) {
                    $this->clinicalError('capture_immutable', 'archive', 'O original desta aquisição é imutável. Envie um novo identificador de captura para um novo original.');
                }
                $oldPath = $patientExam->archive;
                $patientExam->update([
                    ...$this->buildUpdateData($request, $archivePath),
                    'content_sha256' => hash_file('sha256', $request->file('archive')->getRealPath()),
                    'content_bytes'  => $request->file('archive')->getSize(),
                ]);
                $this->outbox($patientExam->id, 'derivatives', $archivePath);

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
     * Envia um novo original privado antes de publicar o registro. Novas
     * aquisições sempre criam linhas distintas; somente registros legados
     * sem capture_id aceitam substituição explícita do original.
     * O registro, a integridade e as intenções de derivados/limpeza entram
     * na mesma transação. O publicador só remove o antigo após o commit.
     * Falha síncrona tenta limpar o upload órfão sem apagar um original
     * referenciado. Interrupção do processo ou falha do storage ainda pode
     * deixar órfãos; o banco e o storage não compartilham uma transação.
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
            $record = DB::transaction(function () use ($persist, $archivePath) {
                [$record, $oldPath] = $persist($archivePath);

                if ($oldPath !== null && $oldPath !== $archivePath) {
                    $this->outbox($record->id, 'delete_archive', $oldPath);
                }

                return $record;
            });
        } catch (Throwable $e) {
            if (! PatientExam::where('archive', $archivePath)->exists()) {
                Storage::disk('s3')->delete($archivePath);
            }

            throw $e;
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
     * Cria uma aquisição com original já enviado e intenção durável de
     * derivados. Nome e hash não identificam uma aquisição clínica.
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
        ?Carbon $examPerformedAt = null,
        ?string $observation = null,
    ): array {
        // Display names/content hashes do not identify clinical acquisitions.
        // The receipt replays a capture; every fresh acquisition creates a row.
        if (request()->filled('capture_id')) {
            $integratorId = request()->attributes->get('integrator')->id;
            EntityIntegrator::whereKey($integratorId)->lockForUpdate()->firstOrFail();

            if (PatientExam::where('capture_integrator_id', $integratorId)->where('capture_id', request()->input('capture_id'))->exists()
                || DB::table('integrator_api_receipts')->where('integrator_id', $integratorId)->where('capture_id', request()->input('capture_id'))->whereNotNull('status')->exists()) {
                $this->clinicalError('capture_conflict', 'capture_id', 'Aquisição já registrada. Reutilize a chave e o endpoint originais.');
            }
        }
        $patient = Patient::whereKey($patientId)->lockForUpdate()->first();

        if ($patient === null || $patient->entity_id !== $entityId || ! $patient->active) {
            $this->clinicalError('patient_unavailable', 'patient_identifier', 'O paciente está inativo ou indisponível na clínica autenticada.');
        }

        if ($scheduleId !== null) {
            $schedule = Schedule::whereKey($scheduleId)->lockForUpdate()->first();

            if ($schedule === null || $schedule->entity_id !== $entityId) {
                $this->clinicalError('schedule_unavailable', 'schedule_identifier', 'O agendamento está indisponível na clínica autenticada.');
            }
            $this->assertSchedulePatient($schedule, $patientId);
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
            'exam_performed_at'              => $examPerformedAt,
            'observation'                    => $observation,
            // Exame capturado nasce habilitado (a coluna tinha default false e
            // o 'active' => true saiu num refactor de 02/02/2026): inativo é
            // "desabilitado/cancelado" — fica fora de laudo, IA e repasse.
            'active'                => true,
            'capture_id'            => request()->input('capture_id'),
            'capture_integrator_id' => request()->input('capture_id') ? request()->attributes->get('integrator')->id : null,
            'content_sha256'        => hash_file('sha256', request()->file('archive')->getRealPath()),
            'content_bytes'         => request()->file('archive')->getSize(),
        ]);

        $this->outbox($record->id, 'derivatives', $record->archive);

        return [$record, null];
    }

    /**
     * Faz upload do arquivo de exame em streaming (sem carregar tudo em memória),
     * sempre com visibilidade privada — exame é dado sensível de saúde (LGPD art. 11).
     * O acesso é feito via URL assinada temporária (PatientExam::archiveUrl()).
     *
     * @throws RuntimeException quando o upload falha
     */
    private function assertSchedulePatient(?Schedule $schedule, string $patientId): void
    {
        if ($schedule !== null && $schedule->patient_id !== $patientId) {
            $this->clinicalError('patient_schedule_mismatch', 'schedule_identifier', 'O agendamento pertence a outro paciente.');
        }

        if ($schedule !== null && (! $schedule->active || in_array($schedule->situation?->value, [8, 9], true))) {
            $this->clinicalError('schedule_unavailable', 'schedule_identifier', 'O agendamento está cancelado ou indisponível.');
        }
        $equipment = $this->equipmentFindByIdOrCode(request()->input('equipment_identifier'));

        if ($schedule !== null && $equipment?->clinic_resource_id !== null) {
            $resource = $equipment->clinicResource;

            if ($resource === null || $resource->entity_id !== $schedule->entity_id || ! $resource->active || ! $schedule->resources()->where('clinic_resources.id', $resource->id)->exists()) {
                $this->clinicalError('schedule_equipment_mismatch', 'equipment_identifier', 'O agendamento não está reservado para este recurso de equipamento.');
            }
        }
    }

    private function clinicalError(string $code, string $field, string $message): never
    {
        $exception           = ValidationException::withMessages([$field => [$message]]);
        $exception->response = response()->json(['code' => $code, 'message' => $message, 'errors' => $exception->errors()], 422);

        throw $exception;
    }

    private function outbox(string $examId, string $operation, string $archive): void
    {
        DB::table('integrator_exam_outbox')->insert(['patient_exam_id' => $examId, 'operation' => $operation, 'archive' => $archive, 'created_at' => now(), 'updated_at' => now()]);
    }

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

    /**
     * Agendamento da clínica do integrador pelo identificador externo (UUID,
     * SDL-N, número puro ou import_code — ver Schedule::identifierMatches()).
     *
     * O exame herda paciente e médico do agendamento resolvido: se o
     * identificador casar com MAIS DE UM agendamento, recusa (422 em
     * schedule_identifier) em vez de gravar o exame no paciente de um
     * agendamento arbitrário. PatientExamRequest já recusa antes; este guard
     * cobre ExamRequest e corridas entre a validação e a gravação.
     *
     * @throws ValidationException identificador ambíguo
     */
    public function scheduleFindByIdOrCode(?string $idOrCode): ?Schedule
    {
        if ($idOrCode === null) {
            return null;
        }

        $integrator = request()->attributes->get('integrator');
        $matches    = IntegratorClinicalIdentifier::matches(Schedule::class, (string) $integrator->user->entity_id, $idOrCode, 'SDL', request()->input('schedule_identifier_namespace'));

        $this->rejectAmbiguous($matches, 'schedule_identifier', 'record_codes.ambiguous_identifier.schedule');
        $schedule = $matches->first();

        if ($schedule === null) {
            $this->clinicalError('clinical_identifier_not_found', 'schedule_identifier', 'Agendamento não encontrado na clínica autenticada.');
        }

        return Schedule::whereKey($schedule->id)->lockForUpdate()->firstOrFail();
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

    /**
     * Equipamento DO INTEGRADOR autenticado — mesmo escopo da validação
     * (PatientExamRequest/ExamRequest: integrator_id do token). O código EIQ é
     * numerado por integrador: cada PC da clínica tem seu EIQ-0000000001, então
     * resolver pela entidade inteira (como antes) casava o equipamento de
     * OUTRO integrador e gravava o exame no aparelho errado.
     *
     * @throws ValidationException código duplicado dentro do integrador
     */
    public function equipmentFindByIdOrCode(?string $idOrCode): ?EntityIntegratorEquipment
    {
        if ($idOrCode === null) {
            return null;
        }

        $integrator = request()->attributes->get('integrator');
        $matches    = EntityIntegratorEquipment::query()
            ->where('integrator_id', $integrator->id)
            ->whereIdentifier($idOrCode)
            ->limit(2)
            ->get();

        $this->rejectAmbiguous($matches, 'equipment_identifier', 'record_codes.ambiguous_identifier.equipment');

        return $matches->first();
    }

    /**
     * Paciente da clínica pelo identificador externo: UUID, PAC-N, número puro
     * ou import_code. UUID e namespace explícito têm resolução exata; sem
     * namespace, qualquer colisão entre códigos internos e de importação
     * é recusada. Nunca escolhe arbitrariamente o primeiro paciente.
     *
     * @throws ValidationException identificador ambíguo
     */
    public function patientFindByIdOrCode(?string $idOrCode, string $entityId): ?Patient
    {
        if ($idOrCode === null) {
            return null;
        }

        $matches = IntegratorClinicalIdentifier::matches(Patient::class, $entityId, trim($idOrCode), 'PAC', request()->input('patient_identifier_namespace', request()->query('identifier_namespace')));

        $this->rejectAmbiguous($matches, 'patient_identifier', 'record_codes.ambiguous_identifier.patient');

        return $matches->first();
    }

    /**
     * Identificador externo que casou com mais de um registro: recusa com erro
     * de validação no campo (422 — erro permanente para o desktop). Não usa 409:
     * nas escritas da API de integradores 409 significa "Idempotency-Key em
     * processamento" (ApiIdempotency), que o cliente re-tenta indefinidamente.
     *
     * @param Collection<int, Model> $matches
     *
     * @throws ValidationException
     */
    private function rejectAmbiguous(Collection $matches, string $field, string $messageKey): void
    {
        if ($matches->count() > 1) {
            $this->clinicalError('clinical_identifier_ambiguous', $field, __($messageKey));
        }
    }
}
