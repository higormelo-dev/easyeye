<?php

namespace App\Services;

use App\Enums\{FeatureKey, ImportStatus};
use App\Models\{Covenant, Patient, PatientImport, People};
use Carbon\Carbon;
use Exception;
use Illuminate\Support\Facades\{DB, Log, Storage};
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
 *   1. Se CPF presente → busca People por national_registry
 *   2. Se não → busca People por full_name + telefone
 *   3. Se paciente já existe nesta entidade → pula (não sobrescreve)
 *   4. Se pessoa existe mas sem paciente aqui → cria só o Patient
 *   5. Se pessoa não existe → cria People + Patient
 */
class PatientImportService
{
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
        // patients.covenant (lookup por nome)
        'convenio'      => '_covenant',
        'convenio_nome' => '_covenant',
        'plano'         => '_covenant',
        'plano_saude'   => '_covenant',
        // patients.card_number
        'carteirinha'        => '_card_number',
        'num_carteira'       => '_card_number',
        'card_number'        => '_card_number',
        'numero_carteirinha' => '_card_number',
    ];

    private const DATE_FORMATS = ['d/m/Y', 'd-m-Y', 'Y-m-d', 'd/m/Y H:i:s', 'd-m-Y H:i:s'];

    public function __construct(
        private readonly FeatureGateService $featureGate,
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
        '_card_number'      => 'Carteirinha',
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

        // Monta coluna-a-coluna para o preview
        $mappedColumns   = [];
        $unmappedColumns = [];

        foreach ($rawHeaders as $i => $header) {
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
        $import->update(['status' => ImportStatus::Processing, 'started_at' => now()]);

        try {
            $this->doProcess($import);
        } catch (Throwable $e) {
            Log::error('PatientImport falhou', ['import_id' => $import->id, 'error' => $e->getMessage()]);

            $import->update([
                'status'       => ImportStatus::Failed,
                'abort_reason' => $e->getMessage(),
                'finished_at'  => now(),
            ]);
        }
    }

    // ── Processamento principal ───────────────────────────────────────────────

    private function doProcess(PatientImport $import): void
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
        $covenantsMap = $this->loadCovenantsMap();

        $errors     = [];
        $imported   = 0;
        $skipped    = 0;
        $errorCount = 0;
        $processed  = 0;

        while (($row = fgetcsv($handle, 0, $delimiter)) !== false) {
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
                $result = DB::transaction(
                    fn () => $this->importRow($data, $import->entity_id, $covenantsMap),
                );

                $result === 'imported' ? $imported++ : $skipped++;
            } catch (Throwable $e) {
                $errors[] = $this->errorRow($rowNum, $e->getMessage(), $data);
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
            'finished_at'    => now(),
        ]);
    }

    // ── Criação de People + Patient ───────────────────────────────────────────

    private function importRow(array $data, string $entityId, array $covenantsMap): string
    {
        $cpf  = $this->onlyNumbers((string) ($data['national_registry'] ?? ''));
        $name = mb_strtoupper(trim((string) ($data['full_name'] ?? '')), 'UTF-8');

        $cellphone = $this->onlyNumbers((string) ($data['cellphone'] ?? ''))
                  ?: $this->onlyNumbers((string) ($data['telephone'] ?? ''));
        $telephone = $this->onlyNumbers((string) ($data['telephone'] ?? ''));

        // 1. Tenta encontrar pessoa existente por CPF
        $person = null;

        if ($cpf !== '') {
            $person = People::withTrashed()->where('national_registry', $cpf)->first();
        }

        // 2. Fallback: nome + telefone
        if (! $person && $cellphone !== '') {
            $person = People::withTrashed()
                ->where('full_name', $name)
                ->where(function ($q) use ($cellphone, $telephone) {
                    $q->where('cellphone', $cellphone)
                        ->orWhere('telephone', $cellphone)
                        ->orWhen($telephone !== '', fn ($q2) => $q2->orWhere('cellphone', $telephone));
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

                return 'imported';
            }

            // Pessoa existe, cria apenas o Patient
            Patient::create([
                'entity_id'   => $entityId,
                'person_id'   => $person->id,
                'covenant_id' => $this->resolveCovenantId($data['_covenant'] ?? null, $covenantsMap),
                'card_number' => $data['_card_number'] ?? null,
                'active'      => true,
            ]);

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

        Patient::create([
            'entity_id'   => $entityId,
            'person_id'   => $person->id,
            'covenant_id' => $this->resolveCovenantId($data['_covenant'] ?? null, $covenantsMap),
            'card_number' => $data['_card_number'] ?? null,
            'active'      => true,
        ]);

        return 'imported';
    }

    // ── Mapeamento de colunas ─────────────────────────────────────────────────

    /** Constrói índice → campo a partir dos cabeçalhos do CSV. */
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

    private function resolveCovenantId(?string $name, array $covenantsMap): ?string
    {
        if (empty($name)) {
            return null;
        }

        return $covenantsMap[mb_strtolower(trim($name), 'UTF-8')] ?? null;
    }

    private function onlyNumbers(string $value): string
    {
        return preg_replace('/\D/', '', $value) ?? '';
    }

    // ── Convênios ─────────────────────────────────────────────────────────────

    /** Retorna mapa nome-em-minúsculo → UUID para lookup eficiente. */
    private function loadCovenantsMap(): array
    {
        return Covenant::pluck('id', 'name')
            ->mapWithKeys(fn ($id, $name) => [mb_strtolower($name, 'UTF-8') => (string) $id])
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
        Storage::disk('private')->put($path, stream_get_contents($stream));
        fclose($stream);

        $import->update(['errors_file_path' => $path]);
    }
}
