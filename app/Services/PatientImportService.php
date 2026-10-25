<?php

namespace App\Services;

use App\Concerns\OpensImportFile;
use App\Enums\{FeatureKey, ImportStatus};
use App\Models\{Covenant, CovenantPlan, Patient, PatientImport, People};
use App\Services\ClinicalSafety\PatientClinicalSafetyService;
use App\Services\Covenants\{CovenantResolutionException, PatientImportCovenantReportService, PatientImportCovenantResolver};
use App\Support\{AuditContext, ClinicalSafety\TermNormalizer, ImportNameNormalizer};
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
 *
 * Alergias (Fase 2 / G4, colunas opcionais): `alergias` (substâncias
 * separadas por ";") viram alergias "informadas pelo paciente" — também no
 * paciente que já existia (só acrescenta; nunca repete a que ele já tem,
 * ativa ou descartada, sem acento/maiúsculas: reimportar a planilha não
 * duplica nem ressuscita o que o médico descartou); `nega_alergias`
 * (sim/não) só vale sem alergia na linha nem ativa no paciente. Registradas
 * em nome de quem enviou a planilha (AuditContext).
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
        // Alergias informadas pelo paciente (Fase 2 / G4)
        'alergias'         => '_allergies',
        'alergia'          => '_allergies',
        'allergies'        => '_allergies',
        'allergy'          => '_allergies',
        'nega_alergias'    => '_allergies_denied',
        'nega_alergia'     => '_allergies_denied',
        'sem_alergias'     => '_allergies_denied',
        'denies_allergies' => '_allergies_denied',
    ];

    /** Valores de "nega alergias" aceitos (minúsculo, sem acento). */
    private const YES_VALUES = ['sim', 's', 'yes', 'y', 'x', '1', 'true', 'verdadeiro', 'nega'];

    private const NO_VALUES = ['nao', 'n', 'no', '0', 'false', 'falso'];

    /** Teto de alergias por linha (mesmo limite do cadastro completo). */
    private const MAX_ALLERGIES_PER_ROW = 20;

    private const ALLERGY_MIN_LENGTH = 2;

    private const ALLERGY_MAX_LENGTH = 160;

    /** Quem enviou a planilha — autor das alergias registradas (job sem login). */
    private ?string $importUserId = null;

    private const DATE_FORMATS = ['d/m/Y', 'd-m-Y', 'Y-m-d', 'd/m/Y H:i:s', 'd-m-Y H:i:s'];

    /** Cabeçalhos que nomeiam o convênio de fato. */
    private const COVENANT_HEADERS = ['convenio', 'convenio_nome'];

    /** "Plano": convênio quando é a única coluna; plano quando há coluna de convênio. */
    private const AMBIGUOUS_PLAN_HEADERS = ['plano', 'plano_saude'];

    public function __construct(
        private readonly FeatureGateService $featureGate,
        private readonly PatientService $patientService,
        private readonly PatientDuplicateFinder $duplicateFinder,
        private readonly PatientImportCovenantResolver $covenants,
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
        '_allergies'        => 'Alergias',
        '_allergies_denied' => 'Nega alergias',
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

        $indexFieldMap  = $this->buildIndexFieldMap($rawHeaders);
        $covenantColumn = $this->covenantColumnIndex($rawHeaders);
        $covenantLimit  = $this->covenantNamesLimit();
        $covenantCounts = [];
        $namesCapped    = false;

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
            // Convênios únicos na MESMA passagem (só nome e contagem, nenhum dado de paciente).
            $this->tallyCovenantName($covenantCounts, $covenantColumn !== null ? ($row[$covenantColumn] ?? null) : null, $covenantLimit, $namesCapped);

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
            ...$this->covenantPreview($import, $covenantCounts, $totalRows, $namesCapped),
        ];
    }

    /**
     * Resumo dos convênios do arquivo para a prévia: situação de cada nome (catálogo + de-para da clínica) e a estimativa
     * de linhas que falharão por convênio. Se o catálogo não carregar a prévia NÃO quebra: itens como não encontrados e um
     * aviso (o processamento continua exigindo o convênio resolvido).
     *
     * @param array<string, array{source_name: string, rows: int}> $counts
     *
     * @return array<string, mixed>
     */
    private function covenantPreview(PatientImport $import, array $counts, int $fileRows, bool $namesCapped): array
    {
        $warning = null;

        try {
            $this->covenants->load((string) $import->entity_id);
        } catch (Throwable $e) {
            Log::warning('patient_import.covenant_preview_failed', ['import_id' => (string) $import->id, 'exception' => $e::class]);
            $warning = 'catalog_unavailable';
        }

        $items = [];

        foreach ($counts as $key => $count) {
            $item = $warning === null
                ? $this->covenants->forSource($count['source_name'])
                : ['source_name' => $count['source_name'], 'source_name_normalized' => $key, 'status' => $key === '' ? 'particular' : 'unmatched', 'covenant' => null, 'plan' => null, 'mapping' => null, 'retryable' => false];

            $failed  = in_array($item['status'], PatientImportCovenantReportService::PENDING_STATUSES, true) ? $count['rows'] : 0;
            $items[] = [...$item, 'rows' => $count['rows'], 'failed_rows' => $failed, 'retryable' => $failed > 0];
        }

        return array_filter([
            'covenant_summary' => PatientImportCovenantReportService::summaryPayload(PatientImportCovenantReportService::sortItems($items), $fileRows, $namesCapped),
            'covenant_warning' => $warning,
        ], fn ($value) => $value !== null);
    }

    /**
     * Convênios citados no arquivo, numa única passagem em streaming — só nomes e contagens, nenhum dado de paciente.
     * `names`: covenantSourceKey → [source_name (1ª grafia), rows]; a chave "" reúne as linhas sem convênio (entram como
     * Particular). Para de coletar NOVOS nomes no teto `patient_imports.max_covenant_names` (`capped`). Null quando o
     * arquivo não existe mais ou está vazio.
     *
     * @return array{file_rows: int, names: array<string, array{source_name: string, rows: int}>, capped: bool}|null
     */
    public function scanCovenantNames(PatientImport $import): ?array
    {
        $handle = $this->openImportFile((string) $import->file_path);

        if (! $handle) {
            return null;
        }

        $this->skipBom($handle);
        $delimiter  = $this->detectDelimiter($handle, 0);
        $rawHeaders = fgetcsv($handle, 0, $delimiter);

        if (! $rawHeaders) {
            fclose($handle);

            return null;
        }

        $column = $this->covenantColumnIndex($rawHeaders);
        $limit  = $this->covenantNamesLimit();
        $names  = [];
        $capped = false;
        $rows   = 0;

        while (($row = fgetcsv($handle, 0, $delimiter)) !== false) {
            $rows++;
            $this->tallyCovenantName($names, $column !== null ? ($row[$column] ?? null) : null, $limit, $capped);
        }

        fclose($handle);

        return ['file_rows' => $rows, 'names' => $names, 'capped' => $capped];
    }

    /**
     * Coluna que o processamento lê como convênio (a última, como o mapRow: a coluna seguinte sobrescreve a anterior).
     * Null = planilha sem coluna de convênio (todas as linhas entram como Particular).
     */
    private function covenantColumnIndex(array $rawHeaders): ?int
    {
        $index = null;

        foreach ($this->headerFields($rawHeaders) as $i => $field) {
            if ($field === '_covenant') {
                $index = $i;
            }
        }

        return $index;
    }

    /** Teto de nomes distintos por passagem (config patient_imports.max_covenant_names). */
    private function covenantNamesLimit(): int
    {
        return max(1, (int) config('patient_imports.max_covenant_names', 1000));
    }

    /**
     * Conta a linha no nome. No teto de nomes distintos NÃO coleta nome novo (marca `$capped`), mas segue contando as
     * linhas dos nomes já coletados — a memória fica limitada, a contagem dos que importam continua certa.
     *
     * @param array<string, array{source_name: string, rows: int}> $counts
     */
    private function tallyCovenantName(array &$counts, mixed $raw, int $limit, bool &$capped): void
    {
        $name = trim((string) $raw);
        $key  = ImportNameNormalizer::covenantSourceKey($name);

        if (! isset($counts[$key])) {
            if (count($counts) >= $limit) {
                $capped = true;

                return;
            }

            $counts[$key] = ['source_name' => $name, 'rows' => 0];
        }

        $counts[$key]['rows']++;
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

        $indexFieldMap      = $this->buildIndexFieldMap($rawHeaders);
        $this->importUserId = $import->user_id ? (string) $import->user_id : null;

        if (! in_array('full_name', $indexFieldMap, true)) {
            fclose($handle);

            throw new RuntimeException("Coluna obrigatória 'nome' não encontrada no CSV. Verifique o modelo.");
        }

        // Reprocessamento por convênio: só as linhas listadas (número no arquivo ORIGINAL), sem a passagem de contagem.
        $retrySet = $this->retrySet($import);

        if ($retrySet === null) {
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
        } else {
            $totalRows = count($retrySet);
            $import->update(['total_rows' => $totalRows]);
        }

        // Verifica limite de pacientes no plano
        $planStatus = $this->featureGate->status($import->entity_id, FeatureKey::MaxPatients);
        $planLimit  = $planStatus->isUnlimited ? PHP_INT_MAX : $planStatus->limit;

        // Pre-carrega catálogo + de-para da clínica UMA vez (nenhuma consulta de convênio por linha). O snapshot congela o
        // de-para do confirm/reprocessamento; import sem snapshot (antigo) lê a tabela.
        $this->covenants->load((string) $import->entity_id, $import->covenant_mapping_snapshot);

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
        // Convênios do arquivo: linhas por nome, falhas por nome e as linhas que falharam por convênio.
        $covenantCounts     = [];
        $covenantFailures   = [];
        $covenantErrorLines = [];
        $covenantLimit      = $this->covenantNamesLimit();
        $namesCapped        = false;
        $sourceLine         = 1; // linha física do arquivo (o cabeçalho é a 1)

        while (($row = fgetcsv($handle, 0, $delimiter)) !== false) {
            $sourceLine++;

            if ($retrySet !== null && ! isset($retrySet[$sourceLine])) {
                continue; // linha fora do reprocessamento: não conta nem processa
            }

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
                    ...$this->covenantFinish($import, $covenantCounts, $covenantFailures, $covenantErrorLines, $totalRows, $namesCapped),
                ]);

                return;
            }

            $processed++;
            $rowNum = $sourceLine; // no reprocessamento aponta para a linha do arquivo original

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
                    ...$this->covenantFinish($import, $covenantCounts, $covenantFailures, $covenantErrorLines, $totalRows, $namesCapped),
                ]);
                fclose($handle);
                $this->saveErrorsFile($import, $errors);

                return;
            }

            $data = $this->mapRow($row, $indexFieldMap);
            $this->tallyCovenantName($covenantCounts, $data['_covenant'] ?? null, $covenantLimit, $namesCapped);

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
                    function () use ($data, $import, &$usedImportCodes, &$plansCache, &$warning) {
                        return $this->importRow($data, $import->entity_id, $usedImportCodes, $plansCache, $warning);
                    },
                );

                $result === 'imported' ? $imported++ : $skipped++;

                // Importado, mas com ressalva (ex.: plano não encontrado): vai
                // para o mesmo CSV baixável, marcado como aviso.
                if ($warning !== null) {
                    $errors[] = $this->errorRow($rowNum, $warning, $data);
                    $warningCount++;
                }
            } catch (CovenantResolutionException $e) {
                // Convênio não resolvido: erro de negócio reprocessável depois de ajustar o de-para.
                $errors[]             = $this->errorRow($rowNum, $e->getMessage(), $data);
                $covenantErrorLines[] = $rowNum; // TODAS as linhas, mesmo as de nomes fora do teto: seguem reprocessáveis

                // A contagem por nome só existe para os nomes coletados (memória limitada).
                if (isset($covenantCounts[$e->sourceNameNormalized])) {
                    $covenantFailures[$e->sourceNameNormalized] = ($covenantFailures[$e->sourceNameNormalized] ?? 0) + 1;
                }

                $errorCount++;
            } catch (Throwable $e) {
                $errors[] = $this->errorRow($rowNum, $this->safeFailureReason($e, $import, $rowNum, 'shared_identity.import.row_failed'), $data);
                $errorCount++;
            }

            // Atualiza progresso a cada 25 linhas para não sobrecarregar o DB
            if ($processed % 25 === 0) {
                $this->updateProgress($import, $processed, $imported, $skipped, $errorCount);
            }

            if ($retrySet !== null && $processed >= $totalRows) {
                break; // todas as linhas do reprocessamento já foram tratadas: não lê o resto do arquivo
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
            ...$this->covenantFinish($import, $covenantCounts, $covenantFailures, $covenantErrorLines, $totalRows, $namesCapped),
        ]);
    }

    /**
     * Linhas do arquivo original a processar num import filho de reprocessamento (null = import normal). Filho sem
     * linhas processa NADA — nunca o arquivo todo.
     *
     * @return array<int, true>|null
     */
    private function retrySet(PatientImport $import): ?array
    {
        $lines = $import->reprocess_lines;

        if (! is_array($lines) && ! $import->isReprocess()) {
            return null;
        }

        return array_fill_keys(array_map('intval', (array) $lines), true);
    }

    /**
     * Fecha o que o processamento aprendeu sobre convênios: linhas que falharam por convênio (reprocessáveis), resumo com
     * a contagem exata por nome, snapshot do de-para usado (preserva o do confirm/reprocessamento) e prazo de retenção
     * dos arquivos renovado — o usuário ganha o prazo cheio para baixar o relatório.
     *
     * @param array<string, array{source_name: string, rows: int}> $counts
     * @param array<string, int>                                   $failures
     * @param list<int>                                            $lines
     *
     * @return array<string, mixed>
     */
    private function covenantFinish(PatientImport $import, array $counts, array $failures, array $lines, int $fileRows, bool $namesCapped): array
    {
        $items = [];

        foreach ($counts as $key => $count) {
            $failed  = (int) ($failures[$key] ?? 0);
            $items[] = [...$this->covenants->forSource($count['source_name']), 'rows' => $count['rows'], 'failed_rows' => $failed, 'retryable' => $failed > 0];
        }

        return [
            'covenant_error_lines'      => $lines,
            'covenant_mapping_snapshot' => $import->covenant_mapping_snapshot ?? $this->covenants->snapshot(array_column($counts, 'source_name')),
            'files_expire_at'           => now()->addDays((int) config('patient_imports.retention_days', 30)),
            'preview'                   => [
                ...(is_array($import->preview) ? $import->preview : []),
                'covenant_summary' => PatientImportCovenantReportService::summaryPayload(PatientImportCovenantReportService::sortItems($items), $fileRows, $namesCapped),
            ],
        ];
    }

    // ── Criação de People + Patient ───────────────────────────────────────────

    private function importRow(
        array $data,
        string $entityId,
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
                // Paciente já existe: nada é sobrescrito — só as alergias novas
                // da planilha são acrescentadas (sem repetir).
                $warning = $this->joinWarnings($warning, $this->registerAllergies($existingPatient, $data));

                return 'skipped';
            }

            if ($existingPatient && $existingPatient->trashed()) {
                $existingPatient->restore();
                $this->assignImportCode($existingPatient, $data['_import_code'] ?? null, $usedImportCodes);
                $warning = $this->joinWarnings($warning, $this->registerAllergies($existingPatient, $data));

                return 'imported';
            }

            // Pessoa existe, cria apenas o Patient
            $homonymWarning = $this->guardDuplicate($entityId, (string) ($person->full_name ?: $name), $person->birth_date?->toDateString(), $person->id);

            $patient = Patient::create([
                'entity_id' => $entityId,
                'person_id' => $person->id,
                ...$this->covenantData($data, $entityId, $plansCache, $warning),
                'active' => true,
            ]);
            $this->assignImportCode($patient, $data['_import_code'] ?? null, $usedImportCodes);
            $warning = $this->joinWarnings($homonymWarning, $warning, $this->registerAllergies($patient, $data));

            return 'imported';
        }

        // Paciente novo: duplicado real (mesmo nome + nascimento) vira erro da
        // linha; homônimo (nascimento diferente/vazio) importa como outra
        // pessoa — comportamento de sempre da importação — com aviso na linha.
        $homonymWarning = $this->guardDuplicate($entityId, $name, $data['birth_date'] ?? null);

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
            ...$this->covenantData($data, $entityId, $plansCache, $warning),
            'active' => true,
        ]);
        $this->assignImportCode($patient, $data['_import_code'] ?? null, $usedImportCodes);
        $warning = $this->joinWarnings($homonymWarning, $warning, $this->registerAllergies($patient, $data));

        return 'imported';
    }

    /**
     * Alergias da linha (coluna `alergias`) e "nega alergias" — sempre como
     * informadas pelo paciente, sem repetir (PatientClinicalSafetyService).
     * Devolve o aviso da linha (item inválido, "nega" ignorado), se houver.
     */
    private function registerAllergies(Patient $patient, array $data): ?string
    {
        $warnings   = [];
        $substances = [];

        foreach (PatientClinicalSafetyService::splitSubstances($data['_allergies'] ?? null) as $substance) {
            $length = mb_strlen($substance);

            if ($length < self::ALLERGY_MIN_LENGTH || $length > self::ALLERGY_MAX_LENGTH) {
                $warnings[] = __('imports.patients.allergy_item_invalid', [
                    'item' => mb_strimwidth($substance, 0, 40, '…'),
                    'min'  => self::ALLERGY_MIN_LENGTH,
                    'max'  => self::ALLERGY_MAX_LENGTH,
                ]);

                continue;
            }

            $substances[] = $substance;
        }

        if (count($substances) > self::MAX_ALLERGIES_PER_ROW) {
            $substances = array_slice($substances, 0, self::MAX_ALLERGIES_PER_ROW);
            $warnings[] = __('imports.patients.allergies_too_many', ['max' => self::MAX_ALLERGIES_PER_ROW]);
        }

        $denied = $data['_allergies_denied'] ?? null;

        if (is_string($denied)) {
            $warnings[] = __('imports.patients.allergies_denied_invalid', ['value' => mb_strimwidth($denied, 0, 20, '…')]);
            $denied     = null;
        }

        if ($denied === true && trim((string) ($data['_allergies'] ?? '')) !== '') {
            $warnings[] = __('imports.patients.allergies_denied_ignored');
        }

        if ($substances !== [] || $denied === true) {
            // Autor = quem enviou a planilha (o job roda sem usuário logado).
            $previous = AuditContext::userId();
            AuditContext::setUserId($this->importUserId);

            try {
                app(PatientClinicalSafetyService::class)->registerReportedSubstances(
                    $patient,
                    $substances,
                    $denied === true && trim((string) ($data['_allergies'] ?? '')) === '',
                );
            } finally {
                AuditContext::setUserId($previous);
            }
        }

        return $warnings === [] ? null : implode(' ', $warnings);
    }

    /**
     * Regra única de duplicidade (App\Services\PatientDuplicateFinder):
     * duplicado real lança (a linha vira erro, nada é gravado); homônimo
     * devolve o aviso da linha.
     */
    private function guardDuplicate(string $entityId, string $name, ?string $birthDate, ?string $ignorePersonId = null): ?string
    {
        $found = $this->duplicateFinder->find($entityId, $name, $birthDate, $ignorePersonId);

        if ($found['duplicates'] !== []) {
            throw new RuntimeException(__('validation.custom.patient.duplicate_import', ['codes' => implode(', ', $found['duplicates'])]));
        }

        return $found['homonyms'] !== []
            ? __('validation.custom.patient.homonym_import', ['codes' => implode(', ', $found['homonyms'])])
            : null;
    }

    private function joinWarnings(?string ...$warnings): ?string
    {
        $text = implode(' ', array_filter($warnings, fn ($w) => $w !== null && $w !== ''));

        return $text === '' ? null : $text;
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
        $keys        = array_map(fn ($header) => ImportNameNormalizer::key((string) $header), $headers);
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
                'birth_date'        => $this->parseDate($raw),
                'gender'            => $raw !== '' ? $this->parseGender($raw) : null,
                'marital_status'    => $raw !== '' ? $this->parseMaritalStatus($raw) : null,
                '_allergies_denied' => $this->parseYesNo($raw),
                default             => $raw !== '' ? $raw : null,
            };

            $data[$field] = $value;
        }

        return $data;
    }

    // ── Helpers de parsing ────────────────────────────────────────────────────

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

    /** sim → true; não/vazio → false; outro texto volta como está (vira aviso). */
    private function parseYesNo(string $value): bool|string
    {
        $plain = TermNormalizer::plain($value);

        return match (true) {
            $plain === '' || in_array($plain, self::NO_VALUES, true) => false,
            in_array($plain, self::YES_VALUES, true)                 => true,
            default                                                  => $value,
        };
    }

    private function parseGender(string $value): ?int
    {
        return match (mb_strtolower(trim($value), 'UTF-8')) {
            'm', 'masculino', 'male', '1', 'masc' => 1,
            'f', 'feminino', 'female', '0', 'fem' => 0,
            default                               => null,
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
     * Convênio, carteirinha e plano da linha. patients.covenant_id é NOT NULL: convênio vazio vira "PARTICULAR" e nome
     * não resolvido lança CovenantResolutionException (erro legível na linha, em vez de violação de NOT NULL com SQL cru).
     * A resolução (catálogo + de-para da clínica) é do PatientImportCovenantResolver. Particular não tem carteirinha nem
     * plano (mesma regra do cadastro manual, PatientService); o plano do de-para prevalece sobre a coluna da planilha.
     *
     * @return array{covenant_id: string, card_number: ?string, covenant_plan_id: ?string}
     */
    private function covenantData(array $data, string $entityId, array &$plansCache, ?string &$warning): array
    {
        $resolved     = $this->covenants->forRow($data['_covenant'] ?? null);
        $isParticular = $resolved['is_particular'];

        return [
            'covenant_id'      => $resolved['covenant_id'],
            'card_number'      => $isParticular ? null : ($data['_card_number'] ?? null),
            'covenant_plan_id' => $isParticular ? null : ($resolved['mapped_plan_id'] ?? $this->resolvePlanId($data['_plan'] ?? null, $resolved['covenant_id'], $entityId, $plansCache, $warning)),
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
            : ($plans['names'][ImportNameNormalizer::key($value)] ?? []);

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

                $index['names'][ImportNameNormalizer::key((string) $plan->name)][] = (string) $plan->id;
            });

        return $index;
    }

    private function onlyNumbers(string $value): string
    {
        return preg_replace('/\D/', '', $value) ?? '';
    }

    // ── Convênios ─────────────────────────────────────────────────────────────

    /**
     * Mapa covenantKey → id do catálogo da clínica (próprios + globais; o próprio vence o global e o ativo vence o
     * inativo). A fonte é o PatientImportCovenantResolver; o método segue aqui só porque o PatientCovenantTenantScopeTest
     * o exercita por reflexão (isolamento por entity_id) — o processamento NÃO o usa.
     *
     * @return array<string, string>
     */
    private function loadCovenantsMap(string $entityId): array
    {
        $this->covenants->load($entityId);

        return $this->covenants->catalogMap();
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
     * Só a mensagem de negócio (RuntimeException lançada por este serviço ou
     * CovenantResolutionException) vai como está. Erro de banco (PDOException/QueryException) traz o SQL
     * com os bindings — CPF, carteirinha, e-mail — e host/porta/banco da
     * conexão: vira texto traduzido. O log leva só classe, SQLSTATE e local
     * (nunca a mensagem, que tem PHI).
     */
    private function safeFailureReason(Throwable $e, PatientImport $import, ?int $rowNum, string $fallbackKey): string
    {
        // CovenantResolutionException também é erro de negócio (convênio inexistente/inativo, de-para indisponível).
        if ($e::class === RuntimeException::class || $e instanceof CovenantResolutionException) {
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
