<?php

namespace App\Services;

use App\Concerns\OpensImportFile;
use App\Enums\{FeatureKey, ImportStatus};
use App\Models\{Covenant, CovenantPlan, Patient, PatientImport, People};
use Carbon\Carbon;
use Exception;
use Illuminate\Support\Facades\{DB, Log, Storage};
use PDOException;
use RuntimeException;
use Throwable;

/**
 * Processa importações de pacientes via CSV.
 *
 * Aceita separadores ; , \t (auto-detectado).
 * Aceita UTF-8 com ou sem BOM.
 * Colunas são mapeadas por cabeçalho normalizado (sem acento, minúsculo).
 *
 * Deduplicação:
 *   1. Se CPF presente → busca People por national_registry. Se ele for de
 *      OUTRA clínica (e não desta), a linha vira erro: CPF de planilha não
 *      prova posse, e o vínculo expunha o cadastro/portal de outra clínica.
 *   2. Se não → busca People por full_name + telefone, só entre cadastros
 *      desta clínica (nunca entre clínicas)
 *   3. Se paciente já existe nesta entidade → pula (não sobrescreve)
 *   4. Se pessoa existe mas sem paciente aqui → cria só o Patient
 *   5. Se pessoa não existe → cria People + Patient
 */
class PatientImportService
{
    use OpensImportFile;

    /** Mapa cabeçalho normalizado → campo do modelo (prefixo _ = campo de Patient). */
    private const COLUMN_MAP = [
        // people.full_name
        'nome'          => 'full_name',
        'nome_completo' => 'full_name',
        'paciente'      => 'full_name',
        'name'          => 'full_name',
        'full_name'     => 'full_name',
        // people.nickname
        'apelido'    => 'nickname',
        'nickname'   => 'nickname',
        'nome_curto' => 'nickname',
        // people.national_registry (CPF)
        'cpf'               => 'national_registry',
        'documento'         => 'national_registry',
        'national_registry' => 'national_registry',
        // people.state_registry (RG)
        'rg'             => 'state_registry',
        'state_registry' => 'state_registry',
        // people.cellphone
        'celular'          => 'cellphone',
        'telefone_celular' => 'cellphone',
        'cellphone'        => 'cellphone',
        'cel'              => 'cellphone',
        'tel_celular'      => 'cellphone',
        // people.telephone
        'telefone'      => 'telephone',
        'telefone_fixo' => 'telephone',
        'telephone'     => 'telephone',
        'fone'          => 'telephone',
        'tel_fixo'      => 'telephone',
        // people.email
        'email'  => 'email',
        'e_mail' => 'email',
        // people.birth_date
        'data_nascimento' => 'birth_date',
        'nascimento'      => 'birth_date',
        'dt_nascimento'   => 'birth_date',
        'birth_date'      => 'birth_date',
        'data_nasc'       => 'birth_date',
        'dt_nasc'         => 'birth_date',
        // people.gender  (0=F, 1=M)
        'sexo'   => 'gender',
        'genero' => 'gender',
        'gender' => 'gender',
        'sex'    => 'gender',
        // people.marital_status
        'estado_civil'   => 'marital_status',
        'marital_status' => 'marital_status',
        'est_civil'      => 'marital_status',
        // people.mother_name
        'nome_mae'    => 'mother_name',
        'mae'         => 'mother_name',
        'mother_name' => 'mother_name',
        // people.father_name
        'nome_pai'    => 'father_name',
        'pai'         => 'father_name',
        'father_name' => 'father_name',
        // people.address
        'endereco'   => 'address',
        'logradouro' => 'address',
        'address'    => 'address',
        'rua'        => 'address',
        // people.number
        'numero'       => 'number',
        'num'          => 'number',
        'number'       => 'number',
        'num_endereco' => 'number',
        // people.complement
        'complemento' => 'complement',
        'complement'  => 'complement',
        'comp'        => 'complement',
        // people.district
        'bairro'   => 'district',
        'district' => 'district',
        // people.city
        'cidade'    => 'city',
        'city'      => 'city',
        'municipio' => 'city',
        // people.state
        'estado' => 'state',
        'uf'     => 'state',
        'state'  => 'state',
        // people.zipcode
        'cep'     => 'zipcode',
        'zipcode' => 'zipcode',
        'zip'     => 'zipcode',
        // people.country
        'pais'    => 'country',
        'country' => 'country',
        // patients.covenant (lookup por nome). "plano" sozinho é o convênio
        // em planilhas antigas; com coluna de convênio, vira o plano (headerFields).
        'convenio'      => '_covenant',
        'convenio_nome' => '_covenant',
        'plano'         => '_covenant',
        'plano_saude'   => '_covenant',
        // patients.covenant_plan_id (registro do produto na ANS ou nome do plano)
        'nome_plano'         => '_plan',
        'plano_nome'         => '_plan',
        'nome_do_plano'      => '_plan',
        'produto'            => '_plan',
        'registro_plano'     => '_plan',
        'registro_ans_plano' => '_plan',
        'plano_registro_ans' => '_plan',
        'codigo_plano'       => '_plan',
        // patients.card_number
        'carteirinha'        => '_card_number',
        'num_carteira'       => '_card_number',
        'card_number'        => '_card_number',
        'numero_carteirinha' => '_card_number',
        // patients.import_code (código do sistema anterior)
        'codigo_importacao'       => '_import_code',
        'codigo_sistema_anterior' => '_import_code',
        'codigo_legado'           => '_import_code',
        'import_code'             => '_import_code',
    ];

    private const DATE_FORMATS = ['d/m/Y', 'd-m-Y', 'Y-m-d', 'd/m/Y H:i:s', 'd-m-Y H:i:s'];

    /** Chave normalizada (normalizeKey) do convênio usado quando a coluna vem vazia. */
    private const PARTICULAR_KEY = 'particular';

    /** Cabeçalhos que nomeiam o convênio de fato. */
    private const COVENANT_HEADERS = ['convenio', 'convenio_nome'];

    /** "Plano": convênio quando é a única coluna; plano quando há coluna de convênio. */
    private const AMBIGUOUS_PLAN_HEADERS = ['plano', 'plano_saude'];

    public function __construct(
        private readonly FeatureGateService $featureGate,
        private readonly PatientService $patientService,
    ) {
    }

    /** Rótulos legíveis para cada campo do sistema (usados no preview). */
    private const FIELD_LABELS = [
        'full_name'         => 'Nome completo',
        'nickname'          => 'Apelido',
        'national_registry' => 'CPF',
        'state_registry'    => 'RG',
        'cellphone'         => 'Celular',
        'telephone'         => 'Telefone',
        'email'             => 'E-mail',
        'birth_date'        => 'Data de nascimento',
        'gender'            => 'Sexo',
        'marital_status'    => 'Estado civil',
        'mother_name'       => 'Nome da mãe',
        'father_name'       => 'Nome do pai',
        'address'           => 'Endereço',
        'number'            => 'Número',
        'complement'        => 'Complemento',
        'district'          => 'Bairro',
        'city'              => 'Cidade',
        'state'             => 'Estado (UF)',
        'zipcode'           => 'CEP',
        'country'           => 'País',
        '_covenant'         => 'Convênio',
        '_plan'             => 'Plano',
        '_card_number'      => 'Carteirinha',
        '_import_code'      => 'Código de importação',
    ];

    private const REQUIRED_FIELDS = ['full_name', 'cellphone'];

    // ── Preview (leitura rápida das primeiras linhas) ─────────────────────────

    /**
     * Lê apenas o cabeçalho e as primeiras linhas do CSV para gerar o preview.
     * Não processa nem persiste pacientes — apenas analisa a estrutura.
     *
     * @return array<string, mixed>
     */
    public function generatePreview(PatientImport $import): array
    {
        $handle = $this->openImportFile($import->file_path);

        if (! $handle) {
            return ['error' => 'Arquivo não encontrado.'];
        }
        $this->skipBom($handle);
        $delimiter = $this->detectDelimiter($handle, 0);

        $rawHeaders = fgetcsv($handle, 0, $delimiter);

        if (! $rawHeaders) {
            fclose($handle);

            return ['error' => 'CSV sem cabeçalho ou vazio.'];
        }

        $indexFieldMap = $this->buildIndexFieldMap($rawHeaders);

        // Monta coluna-a-coluna para o preview
        $mappedColumns   = [];
        $unmappedColumns = [];

        $headerFields = $this->headerFields($rawHeaders);

        foreach ($rawHeaders as $i => $header) {
            $field = $headerFields[$i] ?? null;

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

        // Lê até 5 linhas de amostra
        $sampleRows   = [];
        $totalRows    = 0;
        $mappedFields = array_values($indexFieldMap);

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

        $hasName  = in_array('full_name', $indexFieldMap, true);
        $hasPhone = in_array('cellphone', $indexFieldMap, true)
                 || in_array('telephone', $indexFieldMap, true);

        $missingRequired = [];

        if (! $hasName) {
            $missingRequired[] = 'nome (obrigatório)';
        }

        if (! $hasPhone) {
            $missingRequired[] = 'celular ou telefone (obrigatório)';
        }

        return [
            'delimiter'        => $delimiter === "\t" ? 'tab' : $delimiter,
            'total_rows'       => $totalRows,
            'mapped_columns'   => $mappedColumns,
            'unmapped_columns' => $unmappedColumns,
            'sample_rows'      => $sampleRows,
            'has_name'         => $hasName,
            'has_phone'        => $hasPhone,
            'missing_required' => $missingRequired,
            'can_proceed'      => empty($missingRequired),
        ];
    }

    // ── Ponto de entrada ──────────────────────────────────────────────────────

    public function process(PatientImport $import): void
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

    private function doProcess(PatientImport $import): void
    {
        $handle = $this->openImportFile($import->file_path);

        if (! $handle) {
            throw new RuntimeException('Arquivo de importação não encontrado no disco.');
        }

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

        // Conta linhas para exibição de progresso
        $totalRows = 0;

        while (fgetcsv($handle, 0, $delimiter) !== false) {
            $totalRows++;
        }

        $import->update(['total_rows' => $totalRows]);

        // Reinicia a leitura após a contagem
        rewind($handle);
        $this->skipBom($handle);
        fgetcsv($handle, 0, $delimiter); // pula cabeçalho

        // Verifica limite de pacientes no plano
        $planStatus = $this->featureGate->status($import->entity_id, FeatureKey::MaxPatients);
        $planLimit  = $planStatus->isUnlimited ? PHP_INT_MAX : $planStatus->limit;

        // Pre-carrega convênios (nome → id) para evitar N+1
        $covenantsMap = $this->loadCovenantsMap($import->entity_id);

        // Códigos de importação já usados nesta entidade (evita duplicidade
        // entre linhas do arquivo e contra pacientes já importados antes).
        $usedImportCodes = $this->loadUsedImportCodes($import->entity_id);

        $errors       = [];
        $imported     = 0;
        $skipped      = 0;
        $errorCount   = 0;
        $warningCount = 0;
        $processed    = 0;
        // Planos por convênio, carregados sob demanda (a maior operadora tem ~4,5 mil).
        $plansCache = [];

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
                    'warning_rows'   => $warningCount,
                    'finished_at'    => now(),
                ]);

                return;
            }

            $processed++;
            $rowNum = $processed + 1; // +1 pela linha do cabeçalho

            // Verifica limite antes de cada criação
            $currentTotal = Patient::where('entity_id', $import->entity_id)->count();

            if ($currentTotal + $imported >= $planLimit) {
                $import->update([
                    'status'         => ImportStatus::Done,
                    'processed_rows' => $processed,
                    'imported_rows'  => $imported,
                    'skipped_rows'   => $skipped,
                    'error_rows'     => $errorCount,
                    'warning_rows'   => $warningCount,
                    'abort_reason'   => "Limite do plano atingido ({$planLimit} pacientes). Importe interrompido na linha {$rowNum}.",
                    'finished_at'    => now(),
                ]);
                fclose($handle);
                $this->saveErrorsFile($import, $errors);

                return;
            }

            $data = $this->mapRow($row, $indexFieldMap);

            // Validações mínimas
            if (empty(trim((string) ($data['full_name'] ?? '')))) {
                $errors[] = $this->errorRow($rowNum, 'Nome obrigatório', $data);
                $errorCount++;
                $this->updateProgress($import, $processed, $imported, $skipped, $errorCount);

                continue;
            }

            $phone = trim($this->onlyNumbers((string) ($data['cellphone'] ?? '')))
                   ?: trim($this->onlyNumbers((string) ($data['telephone'] ?? '')));

            if ($phone === '') {
                $errors[] = $this->errorRow($rowNum, 'Celular ou telefone obrigatório', $data);
                $errorCount++;
                $this->updateProgress($import, $processed, $imported, $skipped, $errorCount);

                continue;
            }

            try {
                $warning = null;
                $result  = DB::transaction(
                    function () use ($data, $import, $covenantsMap, &$usedImportCodes, &$plansCache, &$warning) {
                        return $this->importRow($data, $import->entity_id, $covenantsMap, $usedImportCodes, $plansCache, $warning);
                    },
                );

                $result === 'imported' ? $imported++ : $skipped++;

                // Importado, mas com ressalva (ex.: plano não encontrado): vai
                // para o mesmo CSV baixável, marcado como aviso.
                if ($warning !== null) {
                    $errors[] = $this->errorRow($rowNum, $warning, $data);
                    $warningCount++;
                }
            } catch (Throwable $e) {
                $errors[] = $this->errorRow($rowNum, $this->safeFailureReason($e, $import, $rowNum, 'shared_identity.import.row_failed'), $data);
                $errorCount++;
            }

            // Atualiza progresso a cada 25 linhas para não sobrecarregar o DB
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
            'warning_rows'   => $warningCount,
            'finished_at'    => now(),
        ]);
    }

    // ── Criação de People + Patient ───────────────────────────────────────────

    private function importRow(
        array $data,
        string $entityId,
        array $covenantsMap,
        array &$usedImportCodes,
        array &$plansCache = [],
        ?string &$warning = null,
    ): string {
        $cpf  = $this->onlyNumbers((string) ($data['national_registry'] ?? ''));
        $name = mb_strtoupper(trim((string) ($data['full_name'] ?? '')), 'UTF-8');

        $cellphone = $this->onlyNumbers((string) ($data['cellphone'] ?? ''))
                  ?: $this->onlyNumbers((string) ($data['telephone'] ?? ''));
        $telephone = $this->onlyNumbers((string) ($data['telephone'] ?? ''));

        // 1. Tenta encontrar pessoa existente por CPF — só entre os cadastros
        //    DESTA clínica. O mesmo paciente pode estar em outras clínicas, cada
        //    uma com o seu People: CPF de outra clínica cria um cadastro novo
        //    aqui (passo 3), sem ler nem tocar no dela.
        $person = null;

        if ($cpf !== '') {
            $person = $this->patientService->whereLinkedToEntity(People::withTrashed(), $entityId)
                ->where('national_registry', $cpf)
                ->orderBy('created_at')
                ->first();
        }

        // 2. Fallback: nome + telefone — só entre cadastros DESTA clínica
        //    (nome e telefone não identificam ninguém entre clínicas).
        if (! $person && $cellphone !== '') {
            $person = $this->patientService->whereLinkedToEntity(People::withTrashed(), $entityId)
                ->where('full_name', $name)
                ->where(function ($q) use ($cellphone, $telephone) {
                    $q->where('cellphone', $cellphone)
                        ->orWhere('telephone', $cellphone)
                        ->when($telephone !== '', fn ($q2) => $q2->orWhere('cellphone', $telephone));
                })
                ->first();
        }

        if ($person) {
            if ($person->trashed()) {
                $person->restore();
            }

            // Verifica se já é paciente nesta entidade
            $existingPatient = Patient::withTrashed()
                ->where('entity_id', $entityId)
                ->where('person_id', $person->id)
                ->first();

            if ($existingPatient && ! $existingPatient->trashed()) {
                return 'skipped';
            }

            if ($existingPatient && $existingPatient->trashed()) {
                $existingPatient->restore();
                $this->assignImportCode($existingPatient, $data['_import_code'] ?? null, $usedImportCodes);

                return 'imported';
            }

            // Pessoa existe, cria apenas o Patient
            $patient = Patient::create([
                'entity_id' => $entityId,
                'person_id' => $person->id,
                ...$this->covenantData($data, $entityId, $covenantsMap, $plansCache, $warning),
                'active' => true,
            ]);
            $this->assignImportCode($patient, $data['_import_code'] ?? null, $usedImportCodes);

            return 'imported';
        }

        // 3. Cria People + Patient
        $personData = array_filter([
            'full_name'         => $name,
            'nickname'          => $data['nickname'] ?? null,
            'birth_date'        => $data['birth_date'] ?? null,
            'gender'            => $data['gender'] ?? null,
            'marital_status'    => $data['marital_status'] ?? null,
            'email'             => $data['email'] ?? null,
            'mother_name'       => $data['mother_name'] ?? null,
            'father_name'       => $data['father_name'] ?? null,
            'national_registry' => $cpf !== '' ? $cpf : null,
            'state_registry'    => $data['state_registry'] ?? null,
            'cellphone'         => $cellphone,
            'telephone'         => $telephone !== '' ? $telephone : null,
            'zipcode'           => $this->onlyNumbers((string) ($data['zipcode'] ?? '')) ?: null,
            'address'           => $data['address'] ?? null,
            'number'            => $data['number'] ?? null,
            'complement'        => $data['complement'] ?? null,
            'district'          => $data['district'] ?? null,
            'city'              => $data['city'] ?? null,
            'state'             => $data['state'] ?? null,
            'country'           => $data['country'] ?? null,
        ], fn ($v) => $v !== null && $v !== '');

        // cellphone é NOT NULL no schema; garante valor
        $personData['cellphone'] = $cellphone;

        $person = People::create($personData);

        $patient = Patient::create([
            'entity_id' => $entityId,
            'person_id' => $person->id,
            ...$this->covenantData($data, $entityId, $covenantsMap, $plansCache, $warning),
            'active' => true,
        ]);
        $this->assignImportCode($patient, $data['_import_code'] ?? null, $usedImportCodes);

        return 'imported';
    }

    /**
     * Grava o código de importação no paciente recém-criado/restaurado,
     * garantindo unicidade por entidade (contra o banco e contra outras
     * linhas do mesmo arquivo). Nunca é preenchido por outro caminho —
     * telas de cadastro/edição não expõem nem validam este campo.
     */
    private function assignImportCode(Patient $patient, ?string $importCode, array &$usedImportCodes): void
    {
        $importCode = trim((string) $importCode);

        if ($importCode === '') {
            return;
        }

        $key = mb_strtolower($importCode, 'UTF-8');

        if (isset($usedImportCodes[$key])) {
            throw new RuntimeException("Código de importação '{$importCode}' já utilizado por outro paciente.");
        }

        $patient->forceFill(['import_code' => $importCode])->save();
        $usedImportCodes[$key] = true;
    }

    /** Carrega os import_code já em uso nesta entidade (minúsculo → true). */
    private function loadUsedImportCodes(string $entityId): array
    {
        return Patient::where('entity_id', $entityId)
            ->whereNotNull('import_code')
            ->pluck('import_code')
            ->mapWithKeys(fn ($code) => [mb_strtolower((string) $code, 'UTF-8') => true])
            ->toArray();
    }

    // ── Mapeamento de colunas ─────────────────────────────────────────────────

    /** Constrói índice → campo a partir dos cabeçalhos do CSV. */
    private function buildIndexFieldMap(array $headers): array
    {
        return array_filter($this->headerFields($headers), fn (?string $field) => $field !== null);
    }

    /**
     * Campo de cada cabeçalho (null = coluna ignorada). Planilha com coluna
     * de convênio E coluna "plano": "plano" é o plano do convênio; sem coluna
     * de convênio, "plano" continua sendo o convênio (planilhas antigas).
     *
     * @return array<int, ?string>
     */
    private function headerFields(array $headers): array
    {
        $keys        = array_map(fn ($header) => $this->normalizeKey((string) $header), $headers);
        $hasCovenant = array_intersect($keys, self::COVENANT_HEADERS) !== [];

        return array_map(
            fn (string $key) => $hasCovenant && in_array($key, self::AMBIGUOUS_PLAN_HEADERS, true)
                ? '_plan'
                : (self::COLUMN_MAP[$key] ?? null),
            $keys,
        );
    }

    /** Converte uma linha do CSV em array campo → valor com parsing de tipos. */
    private function mapRow(array $row, array $indexFieldMap): array
    {
        $data = [];

        foreach ($indexFieldMap as $i => $field) {
            $raw   = isset($row[$i]) ? trim((string) $row[$i]) : '';
            $value = match ($field) {
                'birth_date'     => $this->parseDate($raw),
                'gender'         => $raw !== '' ? $this->parseGender($raw) : null,
                'marital_status' => $raw !== '' ? $this->parseMaritalStatus($raw) : null,
                default          => $raw !== '' ? $raw : null,
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
        // Remove acentos via transliteração ASCII
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

    private function parseDate(string $value): ?string
    {
        if ($value === '') {
            return null;
        }

        foreach (self::DATE_FORMATS as $format) {
            try {
                $date = Carbon::createFromFormat($format, $value);

                if ($date && $date->year > 1900 && $date->year <= now()->year) {
                    return $date->format('Y-m-d');
                }
            } catch (Exception) {
                // tenta próximo formato
            }
        }

        return null;
    }

    private function parseGender(string $value): ?int
    {
        return match (mb_strtolower(trim($value), 'UTF-8')) {
            'm', 'masculino', 'male', '1', 'masc' => 1,
            'f', 'feminino', 'female', '0', 'fem' => 0,
            default => null,
        };
    }

    private function parseMaritalStatus(string $value): ?int
    {
        if (is_numeric($value)) {
            $int = (int) $value;

            return array_key_exists($int, People::$maritalStatuses) ? $int : null;
        }

        $lower = mb_strtolower(trim($value), 'UTF-8');

        foreach (People::$maritalStatuses as $key => $label) {
            if (str_contains(mb_strtolower($label, 'UTF-8'), $lower)) {
                return $key;
            }
        }

        return null;
    }

    /**
     * patients.covenant_id é NOT NULL: convênio vazio vira "PARTICULAR"
     * (mesma semântica do sistema de origem), e nome não encontrado vira erro
     * legível na linha em vez de violação de NOT NULL com SQL cru no CSV.
     */
    private function resolveCovenantId(?string $name, array $covenantsMap): string
    {
        $key = $this->normalizeKey((string) $name);

        if ($key === '') {
            return $covenantsMap[self::PARTICULAR_KEY]
                ?? throw new RuntimeException('Convênio não informado e nenhum convênio "Particular" cadastrado.');
        }

        return $covenantsMap[$key]
            ?? throw new RuntimeException("Convênio \"{$name}\" não encontrado. Cadastre-o em Configurações › Convênios ou corrija a planilha.");
    }

    /**
     * Convênio, carteirinha e plano da linha. Particular não tem carteirinha
     * nem plano (mesma regra do cadastro manual, PatientService).
     *
     * @return array{covenant_id: string, card_number: ?string, covenant_plan_id: ?string}
     */
    private function covenantData(array $data, string $entityId, array $covenantsMap, array &$plansCache, ?string &$warning): array
    {
        $covenantId   = $this->resolveCovenantId($data['_covenant'] ?? null, $covenantsMap);
        $isParticular = $covenantId === ($covenantsMap[self::PARTICULAR_KEY] ?? null);

        return [
            'covenant_id'      => $covenantId,
            'card_number'      => $isParticular ? null : ($data['_card_number'] ?? null),
            'covenant_plan_id' => $isParticular ? null : $this->resolvePlanId($data['_plan'] ?? null, $covenantId, $entityId, $plansCache, $warning),
        ];
    }

    /**
     * Plano da linha dentro do convênio já resolvido: registro do produto na
     * ANS (só dígitos) ou nome (sem acento/caixa). Não encontrado ou ambíguo
     * (a ANS tem planos homônimos na mesma operadora): o paciente entra sem
     * plano e a linha volta como aviso — nunca derruba o cadastro.
     */
    private function resolvePlanId(?string $value, string $covenantId, string $entityId, array &$plansCache, ?string &$warning): ?string
    {
        $value = trim((string) $value);

        if ($value === '') {
            return null;
        }

        $plans = $plansCache[$covenantId] ??= $this->loadPlansIndex($covenantId, $entityId);
        $code  = $this->onlyNumbers($value);
        $ids   = $code !== '' && $code === preg_replace('/[\s.\-\/]/', '', $value)
            ? ($plans['codes'][$code] ?? [])
            : ($plans['names'][$this->normalizeKey($value)] ?? []);

        if (count($ids) === 1) {
            return $ids[0];
        }

        $warning = __($ids === [] ? 'imports.patients.plan_not_found' : 'imports.patients.plan_ambiguous', ['plan' => $value]);

        return null;
    }

    /**
     * Planos escolhíveis de um convênio (globais + da clínica) indexados por
     * registro e por nome normalizado.
     *
     * @return array{codes: array<string, list<string>>, names: array<string, list<string>>}
     */
    private function loadPlansIndex(string $covenantId, string $entityId): array
    {
        $index = ['codes' => [], 'names' => []];

        CovenantPlan::query()
            ->selectableFor($covenantId, $entityId)
            ->get(['covenant_plans.id', 'covenant_plans.name', 'covenant_plans.ans_code'])
            ->each(function (CovenantPlan $plan) use (&$index) {
                // Só registro numérico (plano antigo tem código com texto, ex.: "04 - juridico").
                if (ctype_digit((string) $plan->ans_code)) {
                    $index['codes'][(string) $plan->ans_code][] = (string) $plan->id;
                }

                $index['names'][$this->normalizeKey((string) $plan->name)][] = (string) $plan->id;
            });

        return $index;
    }

    private function onlyNumbers(string $value): string
    {
        return preg_replace('/\D/', '', $value) ?? '';
    }

    // ── Convênios ─────────────────────────────────────────────────────────────

    /** Retorna mapa nome-em-minúsculo → UUID para lookup eficiente, escopado à entidade. */
    private function loadCovenantsMap(string $entityId): array
    {
        // Globais primeiro, da clínica por último: se a clínica cadastrou o
        // próprio "Particular"/"Unimed", ele sobrescreve o global no mapa.
        return Covenant::where(function ($query) use ($entityId) {
            $query->where('entity_id', $entityId)
                ->orWhere('entity_id', null);
        })
            ->whereNull('deleted_at')
            ->orderByRaw('entity_id IS NOT NULL')
            ->get(['id', 'name'])
            ->mapWithKeys(fn (Covenant $c) => [$this->normalizeKey((string) $c->name) => (string) $c->id])
            ->toArray();
    }

    // ── Progresso e erros ─────────────────────────────────────────────────────

    private function updateProgress(PatientImport $import, int $processed, int $imported, int $skipped, int $errors): void
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
     * com os bindings — CPF, carteirinha, e-mail — e host/porta/banco da
     * conexão: vira texto traduzido. O log leva só classe, SQLSTATE e local
     * (nunca a mensagem, que tem PHI).
     */
    private function safeFailureReason(Throwable $e, PatientImport $import, ?int $rowNum, string $fallbackKey): string
    {
        if ($e::class === RuntimeException::class) {
            return $e->getMessage();
        }

        Log::error($rowNum === null ? 'patient_import.failed' : 'patient_import.row_failed', [
            'import_id' => (string) $import->id,
            'row'       => $rowNum,
            'exception' => $e::class,
            'sqlstate'  => $e instanceof PDOException ? (string) $e->getCode() : null,
            'at'        => basename($e->getFile()) . ':' . $e->getLine(),
        ]);

        return __($fallbackKey);
    }

    /** Salva CSV de erros no disco privado e registra o caminho no import. */
    private function saveErrorsFile(PatientImport $import, array $errors): void
    {
        if (empty($errors)) {
            return;
        }

        $path   = "imports/patients/{$import->entity_id}/errors_{$import->id}.csv";
        $stream = fopen('php://temp', 'r+');

        // BOM para Excel abrir em UTF-8 corretamente
        fwrite($stream, "\xEF\xBB\xBF");

        fputcsv($stream, array_keys(reset($errors)), ';');

        foreach ($errors as $error) {
            fputcsv($stream, array_map('strval', array_values($error)), ';');
        }

        rewind($stream);
        Storage::disk()->put($path, stream_get_contents($stream));
        fclose($stream);

        $import->update(['errors_file_path' => $path]);
    }
}
