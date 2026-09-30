<?php

namespace App\Services\Api;

use App\Http\Requests\Api\{ExamRequest, PatientExamRequest};
use App\Jobs\GenerateExamDerivatives;
use App\Models\{Doctor, EntityIntegratorEquipment, ExamType, Patient, PatientExam, Schedule};
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

        // Data real da captura (do arquivo do equipamento), no fuso da clínica.
        $examPerformedAt = $request->filled('exam_performed_at')
            ? Carbon::parse($request->exam_performed_at)->setTimezone(config('app.timezone'))
            : null;

        if ($schedule) {
            // Fluxo original: schedule_identifier informado
            $patientId  = $schedule->patient_id;
            $doctorId   = $schedule->doctor_id;
            $scheduleId = $schedule->id;
        } else {
            // Fluxo alternativo: resolve pelo patient_identifier
            $patient = $this->patientFindByIdOrCode($request->patient_identifier, $entityId);
            abort_unless($patient !== null, 422, __('validation.custom.validation_invalid.not_patient_identifier'));

            $patientId = $patient->id;

            // Tenta vincular ao agendamento mais recente do DIA DO EXAME para
            // esse paciente — não do dia do envio: um exame de ontem enviado
            // hoje (integrador offline, backlog) não pode cair no agendamento
            // de hoje. Sem data do equipamento, mantém o comportamento antigo.
            $todaySchedule = Schedule::where('entity_id', $entityId)
                ->where('patient_id', $patientId)
                ->whereDate('date_time', ($examPerformedAt ?? now())->toDateString())
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
        ?Carbon $examPerformedAt = null,
        ?string $observation = null,
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
                // Reenvio sem esses campos não apaga o que já foi capturado.
                'exam_performed_at' => $examPerformedAt ?? $existingRecord->exam_performed_at,
                'observation'       => $observation ?? $existingRecord->observation,
            ]);

            // Arquivo substituído: regenera JPEG de exibição + miniatura.
            // afterCommit(): este método roda dentro de DB::transaction()
            // (ver persistWithArchive) e QUEUE_CONNECTION=redis não tem
            // after_commit=true por padrão (config/queue.php) — sem isso, um
            // worker pode pegar o job e não achar o registro ainda não
            // commitado, falhando silenciosamente sem gerar a miniatura.
            GenerateExamDerivatives::dispatch($existingRecord->id)->afterCommit();

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
            'exam_performed_at'              => $examPerformedAt,
            'observation'                    => $observation,
            // Exame capturado nasce habilitado (a coluna tinha default false e
            // o 'active' => true saiu num refactor de 02/02/2026): inativo é
            // "desabilitado/cancelado" — fica fora de laudo, IA e repasse.
            'active' => true,
        ]);

        GenerateExamDerivatives::dispatch($record->id)->afterCommit();

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
        $matches    = Schedule::identifierMatches((string) $integrator->user->entity_id, $idOrCode);

        $this->rejectAmbiguous($matches, 'schedule_identifier', 'record_codes.ambiguous_identifier.schedule');

        return $matches->first();
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
     * ou import_code. Mesma política de Schedule::identifierMatches(): código
     * explícito tem precedência sobre import_code; número puro que é PAC-N de
     * um paciente e import_code de OUTRO é ambíguo e é recusado (422 em
     * patient_identifier) — antes o first() sem ordem gravava o exame em
     * qualquer um dos dois pacientes.
     *
     * @throws ValidationException identificador ambíguo
     */
    public function patientFindByIdOrCode(?string $idOrCode, string $entityId): ?Patient
    {
        if ($idOrCode === null) {
            return null;
        }

        $idOrCode = trim($idOrCode);
        $scoped   = static fn (): Builder => Patient::query()->where('entity_id', $entityId);

        if (Str::isUuid($idOrCode)) {
            return $scoped()->whereKey($idOrCode)->first();
        }

        if (ctype_digit($idOrCode)) {
            $code    = sprintf('%s-%010d', self::PATIENT_CODE_PREFIX, (int) $idOrCode);
            $matches = $scoped()
                ->where(fn (Builder $query) => $query->where('code', $code)->orWhere('import_code', $idOrCode))
                ->limit(2)
                ->get();
        } else {
            $code = preg_match('/^' . self::PATIENT_CODE_PREFIX . '-(\d{1,10})$/i', $idOrCode, $match) === 1
                ? sprintf('%s-%010d', self::PATIENT_CODE_PREFIX, (int) $match[1])
                : $idOrCode;

            $matches = $scoped()->where('code', $code)->limit(2)->get();

            if ($matches->isEmpty()) {
                $matches = $scoped()->where('import_code', $idOrCode)->limit(2)->get();
            }
        }

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
            throw ValidationException::withMessages([$field => [__($messageKey)]]);
        }
    }
}
