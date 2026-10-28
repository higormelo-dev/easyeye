<?php

declare(strict_types=1);

namespace App\Services\Covenants;

use App\Enums\{CovenantSource, ImportStatus};
use App\Models\{Covenant, CovenantImport};
use App\Support\{BrazilianFormat, TenantContext};
use Illuminate\Support\{Carbon, Str};
use Illuminate\Support\Facades\{DB, Http, Log, Storage};
use Throwable;

/**
 * Sincroniza o catálogo GLOBAL de convênios (entity_id nulo) com o Cadastro
 * de Operadoras da ANS (CADOP — dados abertos, atualizados diariamente).
 *
 * - Lista de ativas: atualiza os dados oficiais das operadoras que já estão
 *   no catálogo (razão social, nome fantasia, CNPJ, modalidade, cidade/UF,
 *   data de registro) e cria as que faltam, nas modalidades escolhidas.
 * - Lista de canceladas: marca a operadora como cancelada na ANS e a
 *   DESATIVA só na primeira vez — se o admin reativar à mão, a próxima
 *   sincronização não desfaz.
 *
 * Nunca muda o nome de exibição de quem já existe (importações de pacientes
 * e agenda casam convênio pelo nome), nunca exclui (convênio tem FKs com
 * cascade em pacientes, agenda e faturamento) e não toca nos convênios
 * próprios das clínicas. Só guarda dado da empresa — nada de representante,
 * e-mail ou telefone (LGPD: minimização).
 *
 * Depois das operadoras, sincroniza os planos de cada convênio
 * (AnsPlanImportService).
 */
class AnsOperatorImportService
{
    public const PHASE_DOWNLOADING = 'downloading';

    public const PHASE_READING = 'reading';

    public const PHASE_PROCESSING = 'processing';

    public const PHASE_CANCELLATIONS = 'cancellations';

    private const ACTIVE_COLUMNS = [
        'REGISTRO_OPERADORA', 'CNPJ', 'RAZAO_SOCIAL', 'NOME_FANTASIA', 'MODALIDADE', 'CIDADE', 'UF', 'DATA_REGISTRO_ANS',
    ];

    private const CANCELLED_COLUMNS = ['REGISTRO_OPERADORA', 'DATA_DESCREDENCIAMENTO', 'MOTIVO_DO_DESCREDENCIAMENTO'];

    /** Campos oficiais comparados a cada sincronização. */
    private const OFFICIAL_FIELDS = [
        'company_name', 'trade_name', 'national_registry', 'ans_modality', 'city', 'uf', 'ans_registered_at',
        'ans_status', 'ans_cancelled_at', 'ans_cancellation_reason', 'source',
    ];

    /** Cor dos convênios novos — estável por registro (o seeder sorteava). */
    private const PALETTE = [
        '#2563EB', '#0EA5E9', '#14B8A6', '#22C55E', '#84CC16', '#EAB308',
        '#F97316', '#EF4444', '#EC4899', '#A855F7', '#6366F1', '#64748B',
    ];

    /** @var array<string, int> */
    private array $counts = [];

    private int $processed = 0;

    private float $lastReportAt = 0.0;

    public function __construct(
        private readonly TenantContext $tenant,
        private readonly AnsPlanImportService $plans,
    ) {
    }

    public function process(CovenantImport $import): void
    {
        $import->update([
            'status'         => ImportStatus::Processing,
            'phase'          => $import->source === CovenantImport::SOURCE_UPLOAD ? self::PHASE_READING : self::PHASE_DOWNLOADING,
            'started_at'     => now(),
            'processed_rows' => 0,
            'error'          => null,
        ]);

        $this->counts = [
            'created_count'     => 0, 'updated_count' => 0, 'unchanged_count' => 0,
            'deactivated_count' => 0, 'skipped_modality' => 0, 'skipped_invalid' => 0,
        ];
        $this->processed    = 0;
        $this->lastReportAt = 0.0;

        $runAt     = now();
        $tempFiles = [];

        // Sem transação única de propósito (o progresso precisa aparecer na
        // tela): cada operadora é uma escrita idempotente pelo registro ANS e
        // as desativações só rodam depois de ler a lista de ativas inteira —
        // falha no meio deixa o que foi atualizado e nada desativado.
        try {
            if ($import->source !== CovenantImport::SOURCE_UPLOAD) {
                $this->download($import);
                $import->update(['phase' => self::PHASE_READING]);
            }

            $tempFiles[] = $activePath = $this->localCopy((string) $import->active_file_path);
            $active      = $this->readCsv($activePath, self::ACTIVE_COLUMNS);
            $cancelled   = [];

            if ($import->cancelled_file_path) {
                $tempFiles[] = $cancelledPath = $this->localCopy($import->cancelled_file_path);
                $cancelled   = $this->readCsv($cancelledPath, self::CANCELLED_COLUMNS);
            }

            $import->update(['total_rows' => count($active) + count($cancelled), 'phase' => self::PHASE_PROCESSING]);

            $modalities = array_flip(array_map(fn ($m) => $this->normalize((string) $m), (array) $import->modalities));
            $existing   = $this->globalOperators();
            $usedNames  = $this->globalNames();
            $seenActive = [];
            $syncedIds  = [];

            foreach ($active as $row) {
                $this->processed++;
                $record = $this->activeRecord($row);

                if ($record === null) {
                    $this->counts['skipped_invalid']++;
                } elseif (isset($existing[$record['ans_registry']])) {
                    $seenActive[$record['ans_registry']] = true;
                    $syncedIds[]                         = $this->apply($existing[$record['ans_registry']], $record, $import);
                } elseif (isset($modalities[$this->normalize((string) $record['ans_modality'])])) {
                    $seenActive[$record['ans_registry']] = true;
                    $syncedIds[]                         = $this->create($record, $usedNames);
                    $this->counts['created_count']++;
                } else {
                    $seenActive[$record['ans_registry']] = true;
                    $this->counts['skipped_modality']++;
                }

                $this->reportProgress($import);
            }

            // Arquivo errado (outra planilha, só cabeçalho): sem nenhuma
            // operadora válida não segue para as desativações.
            if ($seenActive === []) {
                throw new CovenantImportException(__('manager_covenants.import_no_valid_rows'));
            }

            $this->reportProgress($import, force: true, phase: self::PHASE_CANCELLATIONS);

            foreach ($cancelled as $row) {
                $this->processed++;
                $record = $this->cancelledRecord($row);

                if ($record === null) {
                    $this->counts['skipped_invalid']++;
                } elseif (! isset($seenActive[$record['ans_registry']]) && isset($existing[$record['ans_registry']])) {
                    // Ainda na lista de ativas (recadastrada) = vale a de ativas.
                    $syncedIds[] = $this->cancel($existing[$record['ans_registry']], $record, $import);
                }

                $this->reportProgress($import);
            }

            foreach (array_chunk(array_values(array_unique($syncedIds)), 500) as $chunk) {
                DB::table('covenants')->whereIn('id', $chunk)->update(['source_synced_at' => $runAt]);
            }

            $import->update([...$this->counts, 'total_rows' => $this->processed, 'processed_rows' => $this->processed]);

            $import->update([
                'status'      => ImportStatus::Done,
                'phase'       => null,
                'plans_error' => $this->syncPlans($import),
                'finished_at' => now(),
            ]);
        } catch (Throwable $e) {
            Log::error('Falha na sincronização de operadoras da ANS', [
                'import_id' => $import->id,
                'error'     => $e->getMessage(),
                'at'        => basename($e->getFile()) . ':' . $e->getLine(),
            ]);

            $import->update([
                ...$this->counts,
                'processed_rows' => $this->processed,
                'status'         => ImportStatus::Failed,
                'phase'          => null,
                'error'          => $e instanceof CovenantImportException ? $e->getMessage() : __('manager_covenants.import_failed_generic'),
                'finished_at'    => now(),
            ]);
        } finally {
            foreach ($tempFiles as $path) {
                @unlink($path);
            }
        }
    }

    /**
     * Etapa 2: planos dos convênios (arquivo de ~75 MB, só pelo download da
     * ANS — no envio manual de CSV fica para a próxima sincronização). Falha
     * aqui não desfaz as operadoras: volta como aviso no import.
     */
    private function syncPlans(CovenantImport $import): ?string
    {
        if ($import->source === CovenantImport::SOURCE_UPLOAD || ! config('covenants.ans.plans_enabled')) {
            return null;
        }

        try {
            $this->plans->sync($import, $this->processed);

            return null;
        } catch (Throwable $e) {
            Log::error('Falha na sincronização de planos da ANS', [
                'import_id' => $import->id,
                'error'     => $e->getMessage(),
                'at'        => basename($e->getFile()) . ':' . $e->getLine(),
            ]);

            return $e instanceof CovenantImportException ? $e->getMessage() : __('manager_covenants.plans_failed_generic');
        }
    }

    // ── Escrita ─────────────────────────────────────────────────────────────

    /** Atualiza os campos oficiais que mudaram (sem auditoria por linha: o import é a trilha). */
    private function apply(object $current, array $record, CovenantImport $import): string
    {
        $changes = $this->changes($current, $record);

        // Registro legado sem os zeros à esquerda: grava no formato oficial.
        if ((string) $current->ans_registry !== $record['ans_registry']) {
            $changes['ans_registry'] = $record['ans_registry'];
        }

        if ($changes === []) {
            $this->counts['unchanged_count']++;
        } else {
            DB::table('covenants')->where('id', $current->id)->update([
                ...$changes,
                'updated_at' => now(),
                'updated_by' => $import->user_id,
            ]);
            $this->counts['updated_count']++;
        }

        return (string) $current->id;
    }

    private function cancel(object $current, array $record, CovenantImport $import): string
    {
        $values = [
            'ans_status'              => 'cancelled',
            'ans_cancelled_at'        => $record['ans_cancelled_at'],
            'ans_cancellation_reason' => $record['ans_cancellation_reason'],
            'source'                  => CovenantSource::Ans->value,
        ];

        $changes = $this->changes($current, $values);

        // Desativa só na primeira detecção do cancelamento.
        if ($current->ans_cancelled_at === null && $current->active) {
            $changes['active'] = false;
            $this->counts['deactivated_count']++;
        }

        if ($changes !== []) {
            DB::table('covenants')->where('id', $current->id)->update([
                ...$changes,
                'updated_at' => now(),
                'updated_by' => $import->user_id,
            ]);
        }

        return (string) $current->id;
    }

    /**
     * Operadora nova: Eloquent (gera o código CVP-…, grava auditoria de
     * criação) fora do escopo de tenant — senão o hook de criação poria a
     * clínica da sessão no entity_id.
     *
     * @param array<string, true> $usedNames
     */
    private function create(array $record, array &$usedNames): string
    {
        $name = mb_strtoupper((string) ($record['trade_name'] ?? $record['company_name']), 'UTF-8');

        // Nome repetido quebraria quem casa convênio pelo nome — leva o registro junto.
        if (isset($usedNames[$name])) {
            $name .= ' (' . $record['ans_registry'] . ')';
        }

        $usedNames[$name] = true;

        return $this->tenant->withoutScope(fn () => DB::transaction(function () use ($record, $name) {
            $covenant = new Covenant();
            $covenant->forceFill([
                ...$record,
                'entity_id' => null,
                'name'      => $name,
                'color'     => self::PALETTE[crc32($record['ans_registry']) % count(self::PALETTE)],
                'table'     => true,
                'active'    => true,
            ]);
            $covenant->save();

            return (string) $covenant->id;
        }));
    }

    /**
     * @param array<string, mixed> $values
     *
     * @return array<string, mixed> só o que mudou
     */
    private function changes(object $current, array $values): array
    {
        $changes = [];

        foreach ($values as $field => $value) {
            if (! in_array($field, self::OFFICIAL_FIELDS, true)) {
                continue;
            }

            $old = $current->{$field} ?? null;
            $old = $old === '' ? null : $old;
            $new = $value === '' ? null : $value;

            if ($old !== null && in_array($field, ['ans_registered_at', 'ans_cancelled_at'], true)) {
                $old = substr((string) $old, 0, 10);
            }

            if ((string) $old !== (string) $new || ($old === null) !== ($new === null)) {
                $changes[$field] = $new;
            }
        }

        return $changes;
    }

    // ── Leitura ─────────────────────────────────────────────────────────────

    /** @return array<string, object> operadoras globais por registro ANS (6 dígitos) */
    private function globalOperators(): array
    {
        $operators = [];

        DB::table('covenants')
            ->whereNull('entity_id')
            ->whereNull('deleted_at')
            ->whereNotNull('ans_registry')
            ->orderBy('created_at')
            ->get(['id', 'active', 'ans_registry', ...self::OFFICIAL_FIELDS])
            ->each(function (object $row) use (&$operators) {
                $registry = $this->registry((string) $row->ans_registry);

                // Duplicata antiga: vale a mais antiga.
                if ($registry !== null && ! isset($operators[$registry])) {
                    $operators[$registry] = $row;
                }
            });

        return $operators;
    }

    /** @return array<string, true> nomes já usados no catálogo global (maiúsculas) */
    private function globalNames(): array
    {
        return DB::table('covenants')
            ->whereNull('entity_id')
            ->whereNull('deleted_at')
            ->pluck('name')
            ->mapWithKeys(fn ($name) => [mb_strtoupper((string) $name, 'UTF-8') => true])
            ->all();
    }

    /** @return array<string, mixed>|null */
    private function activeRecord(array $row): ?array
    {
        $registry = $this->registry($row['REGISTRO_OPERADORA'] ?? '');
        $company  = $this->title($row['RAZAO_SOCIAL'] ?? '');

        if ($registry === null || $company === null) {
            return null;
        }

        $cnpj = (string) BrazilianFormat::documentChars($row['CNPJ'] ?? '');
        $uf   = mb_strtoupper(trim((string) ($row['UF'] ?? '')));

        return [
            'ans_registry' => $registry,
            'company_name' => $company,
            'trade_name'   => $this->tradeName($row['NOME_FANTASIA'] ?? ''),
            // CNPJ alfanumérico (IN RFB 2.229/2024): 12 posições + 2 DVs numéricos.
            'national_registry'       => preg_match('/^[A-Z0-9]{12}\d{2}$/', $cnpj) === 1 ? $cnpj : null,
            'ans_modality'            => $this->clean($row['MODALIDADE'] ?? ''),
            'city'                    => $this->clean($row['CIDADE'] ?? ''),
            'uf'                      => preg_match('/^[A-Z]{2}$/', $uf) === 1 ? $uf : null,
            'ans_registered_at'       => $this->date($row['DATA_REGISTRO_ANS'] ?? ''),
            'ans_status'              => 'active',
            'ans_cancelled_at'        => null,
            'ans_cancellation_reason' => null,
            'source'                  => CovenantSource::Ans->value,
        ];
    }

    /** @return array<string, mixed>|null */
    private function cancelledRecord(array $row): ?array
    {
        $registry = $this->registry($row['REGISTRO_OPERADORA'] ?? '');

        if ($registry === null) {
            return null;
        }

        return [
            'ans_registry'            => $registry,
            'ans_cancelled_at'        => $this->date($row['DATA_DESCREDENCIAMENTO'] ?? ''),
            'ans_cancellation_reason' => ($reason = $this->clean($row['MOTIVO_DO_DESCREDENCIAMENTO'] ?? '')) !== null
                ? mb_substr($reason, 0, 255)
                : null,
        ];
    }

    /**
     * CSV da ANS (separador `;`, UTF-8; Latin-1 convertido) → linhas com as
     * colunas pelo nome do cabeçalho.
     *
     * @param list<string> $required
     *
     * @return list<array<string, string>>
     */
    private function readCsv(string $path, array $required): array
    {
        $handle = fopen($path, 'r');

        if ($handle === false) {
            throw new CovenantImportException(__('manager_covenants.import_file_missing'));
        }

        try {
            $header = fgetcsv($handle, 0, ';', '"', '');

            if ($header === false) {
                throw new CovenantImportException(__('manager_covenants.import_header_not_found'));
            }

            $header = array_map(fn ($h) => $this->normalize($this->utf8((string) $h)), $header);

            foreach ($required as $column) {
                if (! in_array($this->normalize($column), $header, true)) {
                    throw new CovenantImportException(__('manager_covenants.import_header_not_found'));
                }
            }

            $rows = [];

            while (($raw = fgetcsv($handle, 0, ';', '"', '')) !== false) {
                if ($raw === [null] || $raw === []) {
                    continue;
                }

                $row = [];

                foreach ($header as $index => $name) {
                    $row[$name] = $this->utf8((string) ($raw[$index] ?? ''));
                }

                $rows[] = $row;
            }

            return $rows;
        } finally {
            fclose($handle);
        }
    }

    /** Baixa as duas listas da ANS e guarda no disco padrão (trilha do que foi importado). */
    private function download(CovenantImport $import): void
    {
        $folder = 'imports/covenants/' . $import->id;
        $files  = [
            ['url' => (string) config('covenants.ans.active_url'), 'name' => 'operadoras_ativas.csv', 'column' => 'active'],
            ['url' => (string) config('covenants.ans.cancelled_url'), 'name' => 'operadoras_canceladas.csv', 'column' => 'cancelled'],
        ];

        $paths = [];

        foreach ($files as $file) {
            try {
                $response = Http::timeout((int) config('covenants.ans.timeout_seconds', 60))
                    ->retry(2, 2000, throw: false)
                    ->get($file['url']);
            } catch (Throwable) {
                throw new CovenantImportException(__('manager_covenants.import_download_failed'));
            }

            $body = (string) $response->body();

            if (! $response->successful() || $body === '' || strlen($body) > (int) config('covenants.ans.max_bytes')) {
                throw new CovenantImportException(__('manager_covenants.import_download_failed'));
            }

            Storage::disk()->put($folder . '/' . $file['name'], $body);

            $paths[$file['column'] . '_file_path']     = $folder . '/' . $file['name'];
            $paths[$file['column'] . '_original_name'] = basename(parse_url($file['url'], PHP_URL_PATH) ?: $file['name']);
        }

        $import->update($paths);
    }

    /** Arquivo do disco padrão (S3 ou local) → arquivo temporário local. */
    private function localCopy(string $diskPath): string
    {
        $source = $diskPath !== '' ? Storage::disk()->readStream($diskPath) : null;

        if (! $source) {
            throw new CovenantImportException(__('manager_covenants.import_file_missing'));
        }

        $temp = tempnam(sys_get_temp_dir(), 'ansimport_');
        $out  = fopen($temp, 'w');
        stream_copy_to_stream($source, $out);
        fclose($out);
        fclose($source);

        return $temp;
    }

    /**
     * Progresso no import (a tela recebe por WebSocket). No máximo 1
     * escrita/segundo.
     */
    private function reportProgress(CovenantImport $import, bool $force = false, ?string $phase = null): void
    {
        $now = microtime(true);

        if (! $force && $now - $this->lastReportAt < 1.0) {
            return;
        }

        $this->lastReportAt = $now;

        $import->update(array_filter([
            ...$this->counts,
            'processed_rows' => $this->processed,
            'phase'          => $phase,
        ], fn ($value) => $value !== null));
    }

    // ── Normalização ────────────────────────────────────────────────────────

    /** Registro ANS com 6 dígitos (zeros à esquerda) ou null. */
    private function registry(string $value): ?string
    {
        $digits = preg_replace('/\D/', '', $value) ?? '';

        if ($digits === '' || strlen($digits) > 6 || (int) $digits === 0) {
            return null;
        }

        return str_pad($digits, 6, '0', STR_PAD_LEFT);
    }

    /** Razão social no mesmo formato do CovenantsSeeder (comparação estável). */
    private function title(string $value): ?string
    {
        $value = trim(preg_replace('/\s+/u', ' ', $value) ?? '');

        return $value === '' ? null : mb_convert_case($value, MB_CASE_TITLE, 'UTF-8');
    }

    /** Nome fantasia: a ANS usa "******" para "sem nome fantasia". */
    private function tradeName(string $value): ?string
    {
        $value = trim($value);

        return ($value === '' || preg_match('/^\*+$/', $value) === 1) ? null : $this->title($value);
    }

    private function clean(string $value): ?string
    {
        $value = trim(preg_replace('/\s+/u', ' ', $value) ?? '');

        return $value === '' ? null : $value;
    }

    private function date(string $value): ?string
    {
        $value = trim($value);

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            return null;
        }

        try {
            return Carbon::createFromFormat('Y-m-d', $value)?->toDateString();
        } catch (Throwable) {
            return null;
        }
    }

    /** Maiúsculas sem acento — casamento de cabeçalho e de modalidade. */
    private function normalize(string $value): string
    {
        return mb_strtoupper(preg_replace('/[^A-Za-z0-9_ ]/', '', Str::ascii(trim($value))) ?? '', 'UTF-8');
    }

    private function utf8(string $value): string
    {
        $value = preg_replace('/^\xEF\xBB\xBF/', '', $value) ?? $value;

        return mb_check_encoding($value, 'UTF-8') ? $value : mb_convert_encoding($value, 'UTF-8', 'ISO-8859-1');
    }
}
