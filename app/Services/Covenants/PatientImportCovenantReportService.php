<?php

declare(strict_types=1);

namespace App\Services\Covenants;

use App\Concerns\OpensImportFile;
use App\Models\PatientImport;
use App\Services\PatientImportService;
use App\Support\ImportNameNormalizer;
use Illuminate\Support\Facades\{Log, Storage};
use Throwable;

/**
 * Relatório de convênios de uma importação de pacientes: quais nomes a planilha trouxe, quantas linhas cada um tem,
 * quantas falharam por convênio e a situação ATUAL de cada um (catálogo + de-para da clínica). NÃO contém dado de
 * paciente — só nomes de convênio, contagens e alvos do catálogo.
 *
 * Fontes, da mais confiável para a menos: (1) import novo — `covenant_error_lines` + `preview.covenant_summary` gravados
 * pelo job; (2) CSV de erros (import antigo, ou resumo truncado); (3) CSV original (nomes e linhas por varredura em
 * streaming; a contagem de falhas vira estimativa). Os arquivos respeitam a retenção: expirado/limpo = indisponível.
 */
class PatientImportCovenantReportService
{
    use OpensImportFile;

    /**
     * Mensagens de erro de convênio de imports ANTIGOS (anteriores a `covenant_error_lines`). O aviso de plano
     * ("Aviso: plano … não encontrado no convênio …") e os demais erros de linha não casam: todos os padrões ancoram no
     * início da mensagem.
     */
    private const LEGACY_COVENANT_ERRORS = [
        '/^Conv[eê]nio\s+".*"\s+não encontrado/iu',
        '/^O conv[eê]nio\s+".*"\s+está inativo/iu',
        '/^(The )?health plan\s+".*"\s+(not found|is inactive)/iu',
        '/^Conv[eê]nio não informado e nenhum convênio "Particular"/iu',
    ];

    /** Situações em que as linhas do nome falham por convênio até haver de-para. */
    public const PENDING_STATUSES = ['unmatched', 'inactive', 'invalid_mapping'];

    public function __construct(
        private readonly PatientImportService $imports,
        private readonly PatientImportCovenantResolver $resolver,
    ) {
    }

    /**
     * @return array{available: bool, source: string, truncated: bool, names_capped: bool, retry_line_count: int, items: list<array<string, mixed>>, totals: array{file_rows: int, failed_rows: int, failed_rows_exact: bool, unmatched_names: int, mapped_names: int}}
     */
    public function forImport(PatientImport $import): array
    {
        $analysis = $this->analysis($import);
        unset($analysis['retry_lines'], $analysis['names']);

        return $analysis;
    }

    /**
     * Linhas (do arquivo original) que falharam por convênio: as gravadas pelo job ou, em import antigo, as do CSV de
     * erros. Vazio quando não há como saber (CSV de erros indisponível).
     *
     * @return list<int>
     */
    public function retryLineNumbers(PatientImport $import): array
    {
        return $this->analysis($import)['retry_lines'];
    }

    /**
     * Todos os nomes de convênio (1ª grafia) do arquivo, sem repetir e sem o "sem convênio" — base para validar o PUT do
     * de-para e para o snapshot do confirm. Não usa o resumo truncado da prévia.
     *
     * @return list<string>
     */
    public function sourceNames(PatientImport $import): array
    {
        return $this->analysis($import)['names'];
    }

    /**
     * Pendentes primeiro (os que precisam de de-para), depois mais linhas, depois nome.
     *
     * @param list<array<string, mixed>> $items
     *
     * @return list<array<string, mixed>>
     */
    public static function sortItems(array $items): array
    {
        usort($items, fn (array $a, array $b): int => [(int) ! in_array($a['status'], self::PENDING_STATUSES, true), -$a['rows'], mb_strtolower($a['source_name'])]
            <=> [(int) ! in_array($b['status'], self::PENDING_STATUSES, true), -$b['rows'], mb_strtolower($b['source_name'])]);

        return $items;
    }

    /**
     * Resumo gravável em `preview.covenant_summary` a partir dos itens do relatório: limitado a
     * `patient_imports.max_covenant_summary_items` (500) itens — o relatório completo sai do arquivo sob demanda. Puro
     * (sem dependências): o PatientImportService o usa sem instanciar o serviço.
     *
     * `truncated` = há mais nomes do que cabem no resumo (corte de N itens) OU a coleta parou no teto de nomes distintos;
     * `names_capped` = só o segundo caso (o arquivo tem nomes que nem foram coletados).
     *
     * @param list<array<string, mixed>> $items
     *
     * @return array{items: list<array<string, mixed>>, totals: array{file_rows: int, unmatched_names: int, mapped_names: int}, truncated: bool, names_capped: bool}
     */
    public static function summaryPayload(array $items, int $fileRows, bool $namesCapped = false): array
    {
        $limit = max(1, (int) config('patient_imports.max_covenant_summary_items', 500));

        return [
            'items'  => array_slice($items, 0, $limit),
            'totals' => [
                'file_rows'       => $fileRows,
                'unmatched_names' => count(array_filter($items, fn (array $item): bool => in_array($item['status'], self::PENDING_STATUSES, true))),
                'mapped_names'    => count(array_filter($items, fn (array $item): bool => $item['mapping'] !== null)),
            ],
            'truncated'    => $namesCapped || count($items) > $limit,
            'names_capped' => $namesCapped,
        ];
    }

    /** CSV original ainda disponível (existe no disco e dentro da retenção). */
    public function originalAvailable(PatientImport $import): bool
    {
        return $this->filesWithinRetention($import)
            && filled($import->file_path)
            && Storage::disk()->exists((string) $import->file_path);
    }

    /** CSV de erros ainda disponível (existe no disco e dentro da retenção). */
    public function errorsFileAvailable(PatientImport $import): bool
    {
        return $this->filesWithinRetention($import)
            && filled($import->errors_file_path)
            && Storage::disk()->exists((string) $import->errors_file_path);
    }

    /** Retenção vencida: o prazo passou ou a limpeza já apagou os arquivos. */
    public function filesExpired(PatientImport $import): bool
    {
        return ! $this->filesWithinRetention($import);
    }

    private function filesWithinRetention(PatientImport $import): bool
    {
        return ! $import->filesExpired();
    }

    /**
     * Análise completa numa única leitura dos arquivos (itens, totais, nomes e linhas reprocessáveis) — para quem precisa
     * de mais de um dado (o reprocessamento). forImport/retryLineNumbers/sourceNames são recortes dela.
     *
     * @return array{available: bool, source: string, truncated: bool, names_capped: bool, retry_line_count: int, retry_lines: list<int>, names: list<string>, items: list<array<string, mixed>>, totals: array<string, mixed>}
     */
    public function analysis(PatientImport $import): array
    {
        $processed = $import->covenant_error_lines !== null;
        $lines     = $processed ? $this->sortedLines($import->covenant_error_lines) : null;
        $summary   = $this->summary($import);
        $complete  = $summary !== null && ! $summary['truncated'];

        $scan   = $complete ? null : $this->scan($import);
        $errors = $this->errorsAnalysis($import, $lines);

        [$rows, $fileRows, $source] = $this->rowsByName($summary, $scan, $errors, $complete);

        if ($rows === null) {
            return $this->unavailable($lines ?? ($errors['lines'] ?? []));
        }

        $failed = $this->failedByName($summary, $errors, $processed, $complete);
        $retry  = $lines ?? ($errors['lines'] ?? []);
        $this->resolver->load((string) $import->entity_id);

        $items = [];

        foreach ($rows as $key => $row) {
            $item  = $this->resolver->forSource($row['source_name']);
            $exact = $failed !== null;
            $count = $exact ? (int) ($failed[$key] ?? 0) : (in_array($item['status'], self::PENDING_STATUSES, true) ? $row['rows'] : 0);

            $items[] = [...$item, 'rows' => $row['rows'], 'failed_rows' => $count, 'retryable' => $count > 0];
        }

        $items = self::sortItems($items);

        return [
            'available'    => true,
            'source'       => $source,
            'truncated'    => $summary !== null && $summary['truncated'] && $source === 'summary',
            'names_capped' => match ($source) {
                'scan'    => (bool) ($scan['capped'] ?? false),
                'summary' => $summary['names_capped'],
                default   => false,
            },
            'retry_line_count' => count($retry),
            'retry_lines'      => $retry,
            'names'            => array_values(array_map(fn (array $row): string => $row['source_name'], array_filter($rows, fn (array $row, string $key): bool => $key !== '', ARRAY_FILTER_USE_BOTH))),
            'items'            => $items,
            'totals'           => [
                'file_rows'         => $fileRows ?? array_sum(array_column($rows, 'rows')),
                'failed_rows'       => array_sum(array_column($items, 'failed_rows')),
                'failed_rows_exact' => $failed !== null,
                'unmatched_names'   => count(array_filter($items, fn (array $item): bool => in_array($item['status'], self::PENDING_STATUSES, true))),
                'mapped_names'      => count(array_filter($items, fn (array $item): bool => $item['mapping'] !== null)),
            ],
        ];
    }

    /** @param list<int> $retryLines */
    private function unavailable(array $retryLines): array
    {
        return [
            'available'        => false,
            'source'           => 'none',
            'truncated'        => false,
            'names_capped'     => false,
            'retry_line_count' => count($retryLines),
            'retry_lines'      => $retryLines,
            'names'            => [],
            'items'            => [],
            'totals'           => ['file_rows' => 0, 'failed_rows' => 0, 'failed_rows_exact' => false, 'unmatched_names' => 0, 'mapped_names' => 0],
        ];
    }

    /**
     * Resumo gravado no import (prévia ou fim do processamento): itens por nome com as contagens.
     *
     * @return array{items: list<array<string, mixed>>, file_rows: ?int, truncated: bool, names_capped: bool}|null
     */
    private function summary(PatientImport $import): ?array
    {
        $summary = is_array($import->preview) ? ($import->preview['covenant_summary'] ?? null) : null;

        if (! is_array($summary) || ! is_array($summary['items'] ?? null)) {
            return null;
        }

        return [
            'items'        => array_values(array_filter($summary['items'], 'is_array')),
            'file_rows'    => isset($summary['totals']['file_rows']) ? (int) $summary['totals']['file_rows'] : null,
            'truncated'    => (bool) ($summary['truncated'] ?? false),
            'names_capped' => (bool) ($summary['names_capped'] ?? false),
        ];
    }

    /** @return array{file_rows: int, names: array<string, array{source_name: string, rows: int}>, capped: bool}|null */
    private function scan(PatientImport $import): ?array
    {
        if (! $this->originalAvailable($import)) {
            return null;
        }

        try {
            return $this->imports->scanCovenantNames($import);
        } catch (Throwable $e) {
            Log::warning('patient_import.covenant_report_scan_failed', ['import_id' => (string) $import->id, 'exception' => $e::class]);

            return null;
        }
    }

    /**
     * Contagem de falhas por nome e linhas de erro de convênio lidas do CSV de erros. Import novo: só as linhas de
     * `covenant_error_lines` (independe do texto da mensagem); antigo: as linhas cuja mensagem é de convênio.
     *
     * @param list<int>|null $onlyLines
     *
     * @return array{failed: array<string, array{source_name: string, failed: int}>, lines: list<int>}|null
     */
    private function errorsAnalysis(PatientImport $import, ?array $onlyLines): ?array
    {
        if (! $this->errorsFileAvailable($import)) {
            return null;
        }

        try {
            $handle = $this->openImportFile((string) $import->errors_file_path);
        } catch (Throwable $e) {
            Log::warning('patient_import.covenant_report_errors_failed', ['import_id' => (string) $import->id, 'exception' => $e::class]);

            return null;
        }

        if (! $handle) {
            return null;
        }

        if (fread($handle, 3) !== "\xEF\xBB\xBF") {
            rewind($handle);
        }

        $header = fgetcsv($handle, 0, ';');
        $line   = is_array($header) ? array_search('_linha', $header, true) : false;
        $reason = is_array($header) ? array_search('_erro', $header, true) : false;

        if ($line === false || $reason === false) {
            fclose($handle);

            return null;
        }

        $name   = array_search('_covenant', $header, true);
        $only   = $onlyLines !== null ? array_fill_keys($onlyLines, true) : null;
        $failed = [];
        $lines  = [];

        while (($row = fgetcsv($handle, 0, ';')) !== false) {
            $number = (int) ($row[$line] ?? 0);

            if ($number <= 0 || ! ($only !== null ? isset($only[$number]) : $this->isLegacyCovenantError((string) ($row[$reason] ?? '')))) {
                continue;
            }

            $source = $name === false ? '' : trim((string) ($row[$name] ?? ''));
            $key    = ImportNameNormalizer::covenantSourceKey($source);

            $failed[$key] ??= ['source_name' => $source, 'failed' => 0];
            $failed[$key]['failed']++;
            $lines[] = $number;
        }

        fclose($handle);
        sort($lines);

        return ['failed' => $failed, 'lines' => array_values(array_unique($lines))];
    }

    private function isLegacyCovenantError(string $message): bool
    {
        foreach (self::LEGACY_COVENANT_ERRORS as $pattern) {
            if (preg_match($pattern, trim($message)) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * Linhas do arquivo por nome: resumo completo → varredura do original → resumo truncado → só as falhas do CSV de erros.
     *
     * @param array<string, mixed>|null $summary
     * @param array<string, mixed>|null $scan
     * @param array<string, mixed>|null $errors
     *
     * @return array{0: array<string, array{source_name: string, rows: int}>|null, 1: ?int, 2: string}
     */
    private function rowsByName(?array $summary, ?array $scan, ?array $errors, bool $complete): array
    {
        if ($complete) {
            return [$this->summaryRows($summary), $summary['file_rows'], 'summary'];
        }

        if ($scan !== null) {
            return [$scan['names'], $scan['file_rows'], 'scan'];
        }

        if ($summary !== null) {
            return [$this->summaryRows($summary), $summary['file_rows'], 'summary'];
        }

        if ($errors !== null && $errors['failed'] !== []) {
            return [array_map(fn (array $row): array => ['source_name' => $row['source_name'], 'rows' => $row['failed']], $errors['failed']), null, 'errors_csv'];
        }

        return [null, null, 'none'];
    }

    /**
     * @param array<string, mixed> $summary
     *
     * @return array<string, array{source_name: string, rows: int}>
     */
    private function summaryRows(array $summary): array
    {
        $rows = [];

        foreach ($summary['items'] as $item) {
            $name = trim((string) ($item['source_name'] ?? ''));
            $key  = ImportNameNormalizer::covenantSourceKey($name);

            $rows[$key] = ['source_name' => $name, 'rows' => (int) ($item['rows'] ?? 0)];
        }

        return $rows;
    }

    /**
     * Falhas por covenantSourceKey quando a contagem é exata (resumo do import processado ou CSV de erros); null quando só
     * dá para estimar.
     *
     * @param array<string, mixed>|null $summary
     * @param array<string, mixed>|null $errors
     *
     * @return array<string, int>|null
     */
    private function failedByName(?array $summary, ?array $errors, bool $processed, bool $complete): ?array
    {
        if ($processed && $complete && $summary !== null && $this->summaryHasFailures($summary)) {
            $failed = [];

            foreach ($summary['items'] as $item) {
                $failed[ImportNameNormalizer::covenantSourceKey((string) ($item['source_name'] ?? ''))] = (int) ($item['failed_rows'] ?? 0);
            }

            return $failed;
        }

        if ($errors !== null) {
            return array_map(fn (array $row): int => $row['failed'], $errors['failed']);
        }

        return null;
    }

    /** @param array<string, mixed> $summary */
    private function summaryHasFailures(array $summary): bool
    {
        foreach ($summary['items'] as $item) {
            if (array_key_exists('failed_rows', $item)) {
                return true;
            }
        }

        return $summary['items'] === [];
    }

    /**
     * @param array<int, mixed> $lines
     *
     * @return list<int>
     */
    private function sortedLines(array $lines): array
    {
        $lines = array_values(array_unique(array_map('intval', $lines)));
        sort($lines);

        return $lines;
    }
}
