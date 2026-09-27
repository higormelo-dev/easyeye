<?php

namespace App\Services;

use App\Enums\{ClientRule, FeatureKey, ImportStatus};
use App\Models\{Doctor, DoctorImport, EntityUser, People, User};
use Illuminate\Support\Facades\{DB, Log, Password, Storage};
use Illuminate\Support\Str;
use PDOException;
use RuntimeException;
use Throwable;

/**
 * Processa importações de médicos via CSV.
 *
 * Estrutura de leitura (delimitador, BOM, mapeamento de colunas) é um mirror
 * de PatientImportService. A criação de cada linha replica os MESMOS 4 passos
 * de DoctorService::create() — User → EntityUser → People → Doctor — dentro
 * de uma transação por linha.
 *
 * Regras de unicidade replicadas manualmente (o FormRequest de médico é para
 * request HTTP single-record, não serve aqui):
 *   - email: único GLOBALMENTE em users (como no DoctorRequest). Login já
 *     existente só é aceito quando já é MÉDICO desta clínica (vínculo não
 *     removido — reimportação da mesma planilha), sem sobrescrever
 *     nome/senha e sem mexer no vínculo. Qualquer outro vira erro de linha: vincular o login
 *     de outra pessoa (admin de outra clínica, staff SaaS) permitia, em
 *     seguida, reescrever o e-mail dele e tomar a conta.
 *   - national_registry (CPF): único em people → se já existe e é desta
 *     clínica (ou de nenhuma), reaproveita A PESSOA COM UPDATE dos dados da
 *     planilha (como DoctorService::findOrCreatePerson). People de OUTRA
 *     clínica vira erro de linha; People desta clínica também usado
 *     (ativo) por outra é reaproveitado SEM sobrescrever.
 *   - record / record_specialty / color: cada um único POR ENTIDADE em
 *     doctors (3 checks independentes, mesma regra de DoctorRequest — não é
 *     uma chave composta), com auto-exclusão do próprio registro ao
 *     atualizar um médico já existente (equivalente ao ->ignore() do
 *     FormRequest).
 *   - import_code: único POR ENTIDADE em doctors, sem constraint de banco
 *     (mesmo padrão de PatientImportService::assignImportCode).
 *
 * Senha: NUNCA vem da planilha. Usuário novo recebe senha aleatória forte
 * (Str::password(24), hasheada pelo cast 'hashed' do model User), é marcado
 * como e-mail verificado, e recebe o link de "esqueci minha senha" via
 * Password::sendResetLink — disparado FORA da transação da linha, e somente
 * se ela commitar com sucesso, para nunca notificar um usuário cuja linha
 * falhou/rollback. Médico reaproveitado (e-mail já existia) nunca recebe o
 * e-mail nem tem nome/senha alterados.
 */
class DoctorImportService
{
    /** Mapa cabeçalho normalizado → campo. Prefixo _ = campo de Doctor (não People). */
    private const COLUMN_MAP = [
        // people.full_name
        'nome'      => 'full_name',
        'medico'    => 'full_name',
        'name'      => 'full_name',
        'full_name' => 'full_name',
        // people.nickname
        'apelido'    => 'nickname',
        'nickname'   => 'nickname',
        'nome_curto' => 'nickname',
        // people.national_registry (CPF)
        'cpf'               => 'national_registry',
        'documento'         => 'national_registry',
        'national_registry' => 'national_registry',
        // people.email / users.email (login)
        'email'  => 'email',
        'e_mail' => 'email',
        // people.telephone
        'telefone'      => 'telephone',
        'telefone_fixo' => 'telephone',
        'telephone'     => 'telephone',
        'fone'          => 'telephone',
        // people.cellphone
        'celular'          => 'cellphone',
        'telefone_celular' => 'cellphone',
        'cellphone'        => 'cellphone',
        'cel'              => 'cellphone',
        // people.whatsapp (boolean)
        'whatsapp' => 'whatsapp',
        // doctors.record (CRM)
        'crm'    => '_record',
        'record' => '_record',
        // doctors.record_specialty
        'crm_especialidade' => '_record_specialty',
        'especialidade'     => '_record_specialty',
        'record_specialty'  => '_record_specialty',
        // doctors.cbo_code
        'cbo'      => '_cbo_code',
        'cbo_code' => '_cbo_code',
        // doctors.color (hex, ex.: #FF0000)
        'cor'   => '_color',
        'color' => '_color',
        // doctors.observation
        'observacoes' => '_observation',
        'observacao'  => '_observation',
        'observation' => '_observation',
        // doctors.import_code (código do sistema anterior)
        'codigo_importacao'       => '_import_code',
        'codigo_sistema_anterior' => '_import_code',
        'codigo_legado'           => '_import_code',
        'import_code'             => '_import_code',
    ];

    /** Rótulos legíveis para cada campo do sistema (usados no preview). */
    private const FIELD_LABELS = [
        'full_name'         => 'Nome completo',
        'nickname'          => 'Apelido',
        'national_registry' => 'CPF',
        'email'             => 'E-mail',
        'telephone'         => 'Telefone',
        'cellphone'         => 'Celular',
        'whatsapp'          => 'WhatsApp',
        '_record'           => 'CRM',
        '_record_specialty' => 'Especialidade do CRM',
        '_cbo_code'         => 'CBO',
        '_color'            => 'Cor',
        '_observation'      => 'Observações',
        '_import_code'      => 'Código de importação',
    ];

    /** Campos obrigatórios por linha — mesmos exigidos por DoctorRequest no create. */
    private const REQUIRED_FIELDS = [
        'full_name',
        'nickname',
        'national_registry',
        'email',
        '_record',
        '_record_specialty',
        '_color',
    ];

    public function __construct(
        private readonly FeatureGateService $featureGate,
        private readonly PatientService $patientService,
    ) {
    }

    // ── Preview (leitura rápida das primeiras linhas) ─────────────────────────

    /**
     * @return array<string, mixed>
     */
    public function generatePreview(DoctorImport $import): array
    {
        $path = Storage::disk('private')->path($import->file_path);

        if (! file_exists($path)) {
            return ['error' => 'Arquivo não encontrado.'];
        }

        $handle = fopen($path, 'r');
        $this->skipBom($handle);
        $delimiter = $this->detectDelimiter($handle, 0);

        $rawHeaders = fgetcsv($handle, 0, $delimiter);

        if (! $rawHeaders) {
            fclose($handle);

            return ['error' => 'CSV sem cabeçalho ou vazio.'];
        }

        $indexFieldMap = $this->buildIndexFieldMap($rawHeaders);

        $mappedColumns   = [];
        $unmappedColumns = [];

        foreach ($rawHeaders as $header) {
            $normalized = $this->normalizeKey($header);
            $field      = self::COLUMN_MAP[$normalized] ?? null;

            if ($field !== null) {
                $mappedColumns[] = [
                    'csv_header' => $header,
                    'field'      => $field,
                    'label'      => self::FIELD_LABELS[$field] ?? $field,
                    'required'   => in_array($field, self::REQUIRED_FIELDS, true),
                ];
            } else {
                $unmappedColumns[] = $header;
            }
        }

        $sampleRows = [];
        $totalRows  = 0;

        while (($row = fgetcsv($handle, 0, $delimiter)) !== false) {
            $totalRows++;

            if ($totalRows <= 5) {
                $mapped = [];

                foreach ($indexFieldMap as $i => $field) {
                    $label          = self::FIELD_LABELS[$field] ?? $field;
                    $mapped[$label] = isset($row[$i]) ? trim((string) $row[$i]) : '';
                }

                $sampleRows[] = $mapped;
            }
        }

        fclose($handle);

        $missingRequired = [];

        foreach (self::REQUIRED_FIELDS as $field) {
            if (! in_array($field, $indexFieldMap, true)) {
                $missingRequired[] = (self::FIELD_LABELS[$field] ?? $field) . ' (obrigatório)';
            }
        }

        return [
            'delimiter'        => $delimiter === "\t" ? 'tab' : $delimiter,
            'total_rows'       => $totalRows,
            'mapped_columns'   => $mappedColumns,
            'unmapped_columns' => $unmappedColumns,
            'sample_rows'      => $sampleRows,
            'missing_required' => $missingRequired,
            'can_proceed'      => empty($missingRequired),
        ];
    }

    // ── Ponto de entrada ──────────────────────────────────────────────────────

    public function process(DoctorImport $import): void
    {
        // Cancelado enquanto ainda estava na fila (worker parado/reiniciado) —
        // não inicia. O controller já marcou o status ao receber o cancelamento.
        if ($import->fresh()->status === ImportStatus::Cancelled) {
            return;
        }

        $import->update(['status' => ImportStatus::Processing, 'started_at' => now()]);

        try {
            $this->doProcess($import);
        } catch (Throwable $e) {
            $import->update([
                'status'       => ImportStatus::Failed,
                'abort_reason' => $this->safeFailureReason($e, $import, null, 'shared_identity.import.failed'),
                'finished_at'  => now(),
            ]);
        }
    }

    // ── Processamento principal ───────────────────────────────────────────────

    private function doProcess(DoctorImport $import): void
    {
        $path = Storage::disk('private')->path($import->file_path);

        if (! file_exists($path)) {
            throw new RuntimeException('Arquivo de importação não encontrado no disco.');
        }

        $handle    = fopen($path, 'r');
        $bomOffset = $this->skipBom($handle);
        $delimiter = $this->detectDelimiter($handle, $bomOffset);

        $rawHeaders = fgetcsv($handle, 0, $delimiter);

        if (! $rawHeaders) {
            fclose($handle);

            throw new RuntimeException('CSV sem cabeçalho ou vazio.');
        }

        $indexFieldMap = $this->buildIndexFieldMap($rawHeaders);

        if (! in_array('full_name', $indexFieldMap, true)) {
            fclose($handle);

            throw new RuntimeException("Coluna obrigatória 'nome' não encontrada no CSV. Verifique o modelo.");
        }

        $totalRows = 0;

        while (fgetcsv($handle, 0, $delimiter) !== false) {
            $totalRows++;
        }

        $import->update(['total_rows' => $totalRows]);

        rewind($handle);
        $this->skipBom($handle);
        fgetcsv($handle, 0, $delimiter); // pula cabeçalho

        $planStatus = $this->featureGate->status($import->entity_id, FeatureKey::MaxDoctors);
        $planLimit  = $planStatus->isUnlimited ? PHP_INT_MAX : $planStatus->limit;

        $usedImportCodes       = $this->loadUsedImportCodes($import->entity_id);
        $usedRecords           = $this->loadUsedFieldMap($import->entity_id, 'record');
        $usedRecordSpecialties = $this->loadUsedFieldMap($import->entity_id, 'record_specialty');
        $usedColors            = $this->loadUsedFieldMap($import->entity_id, 'color');

        $errors     = [];
        $imported   = 0;
        $skipped    = 0;
        $errorCount = 0;
        $processed  = 0;

        while (($row = fgetcsv($handle, 0, $delimiter)) !== false) {
            // Cancelamento solicitado em outra request enquanto este job
            // rodava — para no próximo checkpoint, preservando o que já foi
            // processado (não reverte linhas já importadas).
            if ($import->fresh()->status === ImportStatus::Cancelled) {
                fclose($handle);
                $this->saveErrorsFile($import, $errors);
                $import->update([
                    'processed_rows' => $processed,
                    'imported_rows'  => $imported,
                    'skipped_rows'   => $skipped,
                    'error_rows'     => $errorCount,
                    'finished_at'    => now(),
                ]);

                return;
            }

            $processed++;
            $rowNum = $processed + 1; // +1 pela linha do cabeçalho

            $currentTotal = $this->countActiveDoctors($import->entity_id);

            if ($currentTotal + $imported >= $planLimit) {
                $import->update([
                    'status'         => ImportStatus::Done,
                    'processed_rows' => $processed,
                    'imported_rows'  => $imported,
                    'skipped_rows'   => $skipped,
                    'error_rows'     => $errorCount,
                    'abort_reason'   => "Limite do plano atingido ({$planLimit} médicos). Importe interrompido na linha {$rowNum}.",
                    'finished_at'    => now(),
                ]);
                fclose($handle);
                $this->saveErrorsFile($import, $errors);

                return;
            }

            $data = $this->mapRow($row, $indexFieldMap);

            $missingLabel = $this->firstMissingRequiredField($data);

            if ($missingLabel !== null) {
                $errors[] = $this->errorRow($rowNum, "Campo obrigatório ausente: {$missingLabel}", $data);
                $errorCount++;
                $this->updateProgress($import, $processed, $imported, $skipped, $errorCount);

                continue;
            }

            try {
                $newUserEmail = DB::transaction(
                    function () use ($data, $import, &$usedImportCodes, &$usedRecords, &$usedRecordSpecialties, &$usedColors) {
                        return $this->importRow(
                            $data,
                            (string) $import->entity_id,
                            $usedImportCodes,
                            $usedRecords,
                            $usedRecordSpecialties,
                            $usedColors,
                        );
                    },
                );

                $imported++;

                // Disparado FORA da transação, só quando ela já commitou —
                // nunca notifica um usuário cuja linha falhou.
                if ($newUserEmail !== null) {
                    Password::sendResetLink(['email' => $newUserEmail]);
                }
            } catch (Throwable $e) {
                $errors[] = $this->errorRow($rowNum, $this->safeFailureReason($e, $import, $rowNum, 'shared_identity.import.row_failed'), $data);
                $errorCount++;
            }

            if ($processed % 25 === 0) {
                $this->updateProgress($import, $processed, $imported, $skipped, $errorCount);
            }
        }

        fclose($handle);
        $this->saveErrorsFile($import, $errors);

        $import->update([
            'status'         => ImportStatus::Done,
            'processed_rows' => $processed,
            'imported_rows'  => $imported,
            'skipped_rows'   => $skipped,
            'error_rows'     => $errorCount,
            'finished_at'    => now(),
        ]);
    }

    // ── Criação de User + EntityUser + People + Doctor ────────────────────────

    /**
     * Executa os MESMOS 4 passos de DoctorService::create(), na mesma ordem,
     * dentro da transação por linha aberta em doProcess().
     *
     * @return string|null e-mail do usuário recém-criado (para disparar o
     *                     reset de senha fora da transação), ou null se o
     *                     usuário já existia e foi apenas reaproveitado.
     */
    private function importRow(
        array $data,
        string $entityId,
        array &$usedImportCodes,
        array &$usedRecords,
        array &$usedRecordSpecialties,
        array &$usedColors,
    ): ?string {
        $email = trim((string) $data['email']);

        // 1+2. User (login GLOBAL) + EntityUser (vínculo de médico).
        //      E-mail existente só é aceito se já for médico desta clínica —
        //      e aí nem o login nem o vínculo são alterados (sem reativar
        //      quem foi desativado, sem trocar papel). Senão, erro de linha.
        $existingUser = User::withTrashed()->where('email', $email)->first();
        $newUserEmail = null;

        if ($existingUser) {
            $entityUser = $existingUser->trashed() ? null : EntityUser::query()
                ->where('user_id', $existingUser->id)
                ->where('entity_id', $entityId)
                ->where('rule', ClientRule::Doctor->value)
                ->first();

            if ($entityUser === null) {
                throw new RuntimeException(__('shared_identity.import.email_in_use'));
            }
        } else {
            $user = User::create([
                'name'     => trim((string) $data['nickname']),
                'email'    => $email,
                'password' => Str::password(24),
            ]);
            $user->markEmailAsVerified();
            $newUserEmail = $email;

            $entityUser = EntityUser::create([
                'entity_id' => $entityId,
                'user_id'   => $user->id,
                'rule'      => ClientRule::Doctor->value,
                'active'    => true,
            ]);
        }

        // 3. People — CPF único; se já existe e é desta clínica (ou de
        //    nenhuma), reaproveita COM UPDATE dos dados da planilha (mesmo
        //    comportamento de findOrCreatePerson). CPF de outra clínica é
        //    erro; People também em uso ativo por outra não é sobrescrito.
        $cpf = $this->onlyNumbers((string) $data['national_registry']);

        $cellphone = $this->onlyNumbers((string) ($data['cellphone'] ?? ''))
                  ?: $this->onlyNumbers((string) ($data['telephone'] ?? ''));
        $telephone = $this->onlyNumbers((string) ($data['telephone'] ?? ''));

        $personData = array_filter([
            'full_name'         => mb_strtoupper(trim((string) $data['full_name']), 'UTF-8'),
            'nickname'          => trim((string) $data['nickname']),
            'email'             => $email,
            'national_registry' => $cpf,
            'telephone'         => $telephone !== '' ? $telephone : null,
            'whatsapp'          => $data['whatsapp'] ?? null,
        ], fn ($v) => $v !== null && $v !== '');

        // cellphone é NOT NULL no schema; garante valor mesmo quando ausente.
        $personData['cellphone'] = $cellphone;

        $person = People::withTrashed()->where('national_registry', $cpf)->first();

        if ($person) {
            if (! $this->patientService->personLinkableToEntity($person->id, $entityId)) {
                throw new RuntimeException(__('shared_identity.import.cpf_linked_elsewhere'));
            }

            if ($person->trashed()) {
                $person->restore();
            }

            if (! $this->patientService->personSharedWithOtherEntities($person->id, $entityId)) {
                $person->update($personData);
            }
        } else {
            $person = People::create($personData);
        }

        // 4. Doctor — mesma chave de lookup de DoctorService::findOrCreate
        //    (person_id + record + entity_user_id).
        $record          = trim((string) $data['_record']);
        $recordSpecialty = trim((string) $data['_record_specialty']);
        $color           = mb_strtoupper(trim((string) $data['_color']), 'UTF-8');

        $existingDoctor = Doctor::withTrashed()
            ->where('person_id', $person->id)
            ->where('record', $record)
            ->where('entity_user_id', $entityUser->id)
            ->first();
        $selfId = $existingDoctor?->id;

        // Uniques manuais — 3 checks independentes (mesma regra do
        // DoctorRequest, que valida cada coluna separadamente, não como
        // chave composta), ignorando o próprio registro ao atualizar.
        $this->assertFieldUnique($record, $usedRecords, $selfId, "CRM '{$record}' já utilizado por outro médico desta clínica.");
        $this->assertFieldUnique($recordSpecialty, $usedRecordSpecialties, $selfId, "Especialidade '{$recordSpecialty}' já utilizada por outro médico desta clínica.");
        $this->assertFieldUnique($color, $usedColors, $selfId, "Cor '{$color}' já utilizada por outro médico desta clínica.");

        $doctorData = array_filter([
            'entity_user_id'   => $entityUser->id,
            'person_id'        => $person->id,
            'record'           => $record,
            'record_specialty' => $recordSpecialty,
            'cbo_code'         => $data['_cbo_code'] ?? null,
            'color'            => $color,
            'observation'      => $data['_observation'] ?? null,
        ], fn ($v) => $v !== null && $v !== '');

        if ($existingDoctor) {
            if ($existingDoctor->trashed()) {
                $existingDoctor->restore();
            }

            $existingDoctor->update($doctorData);
            $doctor = $existingDoctor;
        } else {
            // Decisão de design: médico importado já vem ativo (são médicos
            // já em operação na clínica de origem). Ao ATUALIZAR um médico
            // existente, 'active' não é tocado — não reativa quem foi
            // desativado manualmente pelo admin.
            $doctorData['active'] = true;
            $doctor               = Doctor::create($doctorData);
        }

        $usedRecords[$record]                    = $doctor->id;
        $usedRecordSpecialties[$recordSpecialty] = $doctor->id;
        $usedColors[$color]                      = $doctor->id;

        $this->assignImportCode($doctor, $data['_import_code'] ?? null, $usedImportCodes);

        return $newUserEmail;
    }

    /**
     * Grava o código de importação no médico recém-criado/atualizado,
     * garantindo unicidade por entidade (banco + outras linhas do mesmo
     * arquivo). Nunca preenchido por outro caminho — DoctorRequest/tela
     * normal não expõem nem validam este campo.
     */
    private function assignImportCode(Doctor $doctor, ?string $importCode, array &$usedImportCodes): void
    {
        $importCode = trim((string) $importCode);

        if ($importCode === '') {
            return;
        }

        $key = mb_strtolower($importCode, 'UTF-8');

        if (isset($usedImportCodes[$key])) {
            throw new RuntimeException("Código de importação '{$importCode}' já utilizado por outro médico.");
        }

        $doctor->forceFill(['import_code' => $importCode])->save();
        $usedImportCodes[$key] = true;
    }

    /**
     * Verifica um valor contra o mapa de "valor já usado → id do dono atual",
     * ignorando o próprio dono ($selfId) — equivalente ao ->ignore() do
     * FormRequest ao atualizar um registro já existente.
     */
    private function assertFieldUnique(string $value, array &$usedMap, ?string $selfId, string $errorMessage): void
    {
        if ($value === '') {
            return;
        }

        if (isset($usedMap[$value]) && $usedMap[$value] !== $selfId) {
            throw new RuntimeException($errorMessage);
        }
    }

    /** Carrega mapa valor-da-coluna → id do médico (doctors não-trashed) desta entidade. */
    private function loadUsedFieldMap(string $entityId, string $column): array
    {
        return Doctor::query()
            ->join('entity_users', 'doctors.entity_user_id', '=', 'entity_users.id')
            ->where('entity_users.entity_id', $entityId)
            ->whereNull('doctors.deleted_at')
            ->whereNotNull("doctors.{$column}")
            ->pluck('doctors.id', "doctors.{$column}")
            ->map(fn ($id) => (string) $id)
            ->toArray();
    }

    /** Carrega os import_code já em uso nesta entidade (minúsculo → true). */
    private function loadUsedImportCodes(string $entityId): array
    {
        return Doctor::query()
            ->join('entity_users', 'doctors.entity_user_id', '=', 'entity_users.id')
            ->where('entity_users.entity_id', $entityId)
            ->whereNull('doctors.deleted_at')
            ->whereNotNull('doctors.import_code')
            ->pluck('doctors.import_code')
            ->mapWithKeys(fn ($code) => [mb_strtolower((string) $code, 'UTF-8') => true])
            ->toArray();
    }

    /** Mesma contagem usada por UsageMeterService::countDoctors() para o gate de plano. */
    private function countActiveDoctors(string $entityId): int
    {
        return Doctor::whereHas('entityUser', fn ($q) => $q->where('entity_id', $entityId))
            ->whereNull('deleted_at')
            ->count();
    }

    // ── Validação de linha ─────────────────────────────────────────────────────

    private function firstMissingRequiredField(array $data): ?string
    {
        foreach (self::REQUIRED_FIELDS as $field) {
            if (trim((string) ($data[$field] ?? '')) === '') {
                return self::FIELD_LABELS[$field] ?? $field;
            }
        }

        return null;
    }

    // ── Mapeamento de colunas ─────────────────────────────────────────────────

    private function buildIndexFieldMap(array $headers): array
    {
        $map = [];

        foreach ($headers as $i => $header) {
            $normalized = $this->normalizeKey($header);
            $field      = self::COLUMN_MAP[$normalized] ?? null;

            if ($field !== null) {
                $map[$i] = $field;
            }
        }

        return $map;
    }

    private function mapRow(array $row, array $indexFieldMap): array
    {
        $data = [];

        foreach ($indexFieldMap as $i => $field) {
            $raw   = isset($row[$i]) ? trim((string) $row[$i]) : '';
            $value = match ($field) {
                'whatsapp' => $raw !== '' ? $this->parseBoolean($raw) : null,
                default    => $raw !== '' ? $raw : null,
            };

            $data[$field] = $value;
        }

        return $data;
    }

    // ── Helpers de parsing ────────────────────────────────────────────────────

    private function normalizeKey(string $key): string
    {
        $key = mb_strtolower(trim($key), 'UTF-8');
        $key = str_replace([' ', '-'], '_', $key);
        $key = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $key) ?: $key;

        return preg_replace('/[^a-z0-9_]/', '', $key) ?? $key;
    }

    private function detectDelimiter(mixed $handle, int $bomOffset): string
    {
        $pos    = ftell($handle);
        $sample = fgets($handle);
        fseek($handle, $pos);

        if ($sample === false) {
            return ';';
        }

        $candidates = [';' => 0, ',' => 0, "\t" => 0];

        foreach (array_keys($candidates) as $delim) {
            $candidates[$delim] = substr_count($sample, $delim);
        }
        arsort($candidates);

        return array_key_first($candidates) ?? ';';
    }

    /** Avança além do BOM UTF-8 se presente. Retorna offset consumido (3 ou 0). */
    private function skipBom(mixed $handle): int
    {
        $bom = fread($handle, 3);

        if ($bom !== "\xEF\xBB\xBF") {
            fseek($handle, 0);

            return 0;
        }

        return 3;
    }

    private function parseBoolean(string $value): ?bool
    {
        return match (mb_strtolower(trim($value), 'UTF-8')) {
            '1', 'true', 'sim', 'yes', 'on' => true,
            '0', 'false', 'nao', 'não', 'no', 'off' => false,
            default => null,
        };
    }

    private function onlyNumbers(string $value): string
    {
        return preg_replace('/\D/', '', $value) ?? '';
    }

    // ── Progresso e erros ─────────────────────────────────────────────────────

    private function updateProgress(DoctorImport $import, int $processed, int $imported, int $skipped, int $errors): void
    {
        $import->update([
            'processed_rows' => $processed,
            'imported_rows'  => $imported,
            'skipped_rows'   => $skipped,
            'error_rows'     => $errors,
        ]);
    }

    private function errorRow(int $lineNum, string $reason, array $data): array
    {
        return array_merge(['_linha' => $lineNum, '_erro' => $reason], $data);
    }

    /**
     * Motivo gravado no CSV de erros (baixável pela clínica) e em abort_reason.
     *
     * Só a mensagem de negócio (RuntimeException lançada por este serviço)
     * vai como está. Erro de banco (PDOException/QueryException) traz o SQL
     * com os bindings — CPF, e-mail, telefone — e host/porta/banco da
     * conexão: vira texto traduzido. O log leva só classe, SQLSTATE e local
     * (nunca a mensagem, que tem dados pessoais).
     */
    private function safeFailureReason(Throwable $e, DoctorImport $import, ?int $rowNum, string $fallbackKey): string
    {
        if ($e::class === RuntimeException::class) {
            return $e->getMessage();
        }

        Log::error($rowNum === null ? 'doctor_import.failed' : 'doctor_import.row_failed', [
            'import_id' => (string) $import->id,
            'row'       => $rowNum,
            'exception' => $e::class,
            'sqlstate'  => $e instanceof PDOException ? (string) $e->getCode() : null,
            'at'        => basename($e->getFile()) . ':' . $e->getLine(),
        ]);

        return __($fallbackKey);
    }

    /** Salva CSV de erros no disco privado e registra o caminho no import. */
    private function saveErrorsFile(DoctorImport $import, array $errors): void
    {
        if (empty($errors)) {
            return;
        }

        $path   = "imports/doctors/{$import->entity_id}/errors_{$import->id}.csv";
        $stream = fopen('php://temp', 'r+');

        fwrite($stream, "\xEF\xBB\xBF");

        fputcsv($stream, array_keys(reset($errors)), ';');

        foreach ($errors as $error) {
            fputcsv($stream, array_map('strval', array_values($error)), ';');
        }

        rewind($stream);
        Storage::disk('private')->put($path, stream_get_contents($stream));
        fclose($stream);

        $import->update(['errors_file_path' => $path]);
    }
}
