<?php

declare(strict_types=1);

namespace App\Services\Medicines;

use App\Enums\{ImportStatus, MedicineSource};
use App\Models\{Medicine, MedicineImport};
use Generator;
use Illuminate\Support\Facades\{DB, Log, Storage};
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Reader\{IReadFilter, IReader};
use PhpOffice\PhpSpreadsheet\RichText\RichText;
use Throwable;

/**
 * Importa o catálogo GLOBAL de medicamentos (entity_id nulo) a partir da
 * lista de preços CMED/Anvisa (XLSX publicado mensalmente, ou CSV) — uma
 * linha por apresentação, chave estável = código GGREM.
 *
 * Opcionalmente cruza com DADOS_ABERTOS_MEDICAMENTOS.csv (Anvisa) pelos 9
 * primeiros dígitos do registro, descartando registro cancelado/vencido.
 * Apresentações de uso restrito a hospital ficam de fora (o receituário é
 * do paciente). Apresentação que saiu da lista é DESATIVADA, nunca apagada:
 * doctor_medication_presets aponta pra ela (FK com cascade).
 *
 * Posologia sugerida (dosage/frequency/duration/instructions) não vem da
 * CMED e nunca é sobrescrita aqui — é editada pelo admin no manager.
 */
class AnvisaMedicineImportService
{
    private const CHUNK_ROWS = 5000;

    private const UPSERT_BATCH = 500;

    /** Cabeçalho normalizado (ver normalizeHeader) → chave interna. */
    private const COLUMNS = [
        'SUBSTANCIA'           => 'substance',
        'LABORATORIO'          => 'laboratory',
        'CODIGO GGREM'         => 'ggrem',
        'REGISTRO'             => 'registration',
        'EAN 1'                => 'ean',
        'PRODUTO'              => 'product',
        'APRESENTACAO'         => 'presentation',
        'CLASSE TERAPEUTICA'   => 'therapeutic_class',
        'TIPO DE PRODUTO'      => 'category',
        'RESTRICAO HOSPITALAR' => 'hospital_only',
        'COMERCIALIZACAO'      => 'marketed',
    ];

    private const REQUIRED = ['substance', 'ggrem', 'registration', 'product', 'presentation'];

    /** Colunas atualizadas quando a apresentação já existe (posologia não). */
    private const UPDATE_COLUMNS = [
        'name', 'active_ingredient', 'concentration', 'pharmaceutical_form', 'presentation_detail',
        'laboratory', 'anvisa_registration', 'ean', 'regulatory_category', 'therapeutic_class',
        'is_ophthalmic', 'is_marketed', 'active', 'source_synced_at', 'search_text', 'updated_at', 'updated_by',
    ];

    public const PHASE_READING = 'reading';

    public const PHASE_PROCESSING = 'processing';

    public const PHASE_DEACTIVATING = 'deactivating';

    /** @var array<string, int> */
    private array $counts = [];

    private int $processed = 0;

    private float $lastReportAt = 0.0;

    /** @var array<string, true> GGREM já gravados nesta execução */
    private array $seen = [];

    public function __construct(
        private readonly CmedPresentationParser $parser,
    ) {
    }

    public function process(MedicineImport $import): void
    {
        $import->update([
            'status'         => ImportStatus::Processing,
            'phase'          => self::PHASE_READING,
            'started_at'     => now(),
            'processed_rows' => 0,
            'error'          => null,
        ]);

        $this->counts = [
            'created_count'    => 0, 'updated_count' => 0, 'deactivated_count' => 0,
            'skipped_hospital' => 0, 'skipped_inactive_registration' => 0, 'skipped_invalid' => 0,
        ];
        $this->seen         = [];
        $this->processed    = 0;
        $this->lastReportAt = 0.0;

        // Carimbo desta execução com microssegundos (coluna timestamp(6)) —
        // string, porque o query builder formataria Carbon sem fração.
        $runAt     = now()->format('Y-m-d H:i:s.u');
        $tempFiles = [];

        // Sem transação única de propósito: o progresso gravado no import tem
        // que ser visível pra tela durante o processamento. Seguro porque cada
        // lote é um upsert atômico e idempotente (chave GGREM) e a varredura
        // de desativação só roda depois de TODAS as linhas — falha no meio
        // deixa itens válidos atualizados e nada desativado; reenviar corrige.
        try {
            $registrations = null;

            if ($import->open_data_file_path) {
                $tempFiles[]   = $openDataPath = $this->localCopy($import->open_data_file_path);
                $registrations = $this->readRegistrationStatus($openDataPath);
            }

            $tempFiles[]       = $cmedPath = $this->localCopy($import->cmed_file_path);
            [$expected, $rows] = $this->openCmed($cmedPath);

            $import->update(['total_rows' => $expected, 'phase' => self::PHASE_PROCESSING]);

            $batch = [];

            foreach ($rows as $row) {
                $this->processed++;

                $record = $this->toRecord($row, $registrations, $import, $runAt);

                if ($record !== null) {
                    $batch[] = $record;
                }

                if (count($batch) >= self::UPSERT_BATCH) {
                    $this->flush($batch);
                    $batch = [];
                }

                $this->reportProgress($import);
            }

            $this->flush($batch);

            // Arquivo errado (outra planilha, aba trocada) não pode
            // desativar o catálogo inteiro na varredura abaixo.
            if ($this->seen === []) {
                throw new MedicineImportException(__('manager_medicines.import_no_valid_rows'));
            }

            $this->reportProgress($import, force: true, phase: self::PHASE_DEACTIVATING);

            $this->counts['deactivated_count'] = DB::table('medicines')
                ->whereNull('entity_id')
                ->where('source', MedicineSource::Cmed->value)
                ->where('active', true)
                ->where(fn ($q) => $q->whereNull('source_synced_at')->orWhere('source_synced_at', '<', $runAt))
                ->update(['active' => false, 'updated_at' => now(), 'updated_by' => $import->user_id]);

            $import->update([
                ...$this->counts,
                'total_rows'     => $this->processed,
                'processed_rows' => $this->processed,
                'status'         => ImportStatus::Done,
                'phase'          => null,
                'finished_at'    => now(),
            ]);
        } catch (Throwable $e) {
            Log::error('Falha na importação do catálogo de medicamentos', [
                'import_id' => $import->id,
                'error'     => $e->getMessage(),
                'at'        => basename($e->getFile()) . ':' . $e->getLine(),
            ]);

            // Contadores refletem o que de fato foi gravado antes da falha.
            $import->update([
                ...$this->counts,
                'processed_rows' => $this->processed,
                'status'         => ImportStatus::Failed,
                'phase'          => null,
                'error'          => $e instanceof MedicineImportException ? $e->getMessage() : __('manager_medicines.import_failed_generic'),
                'finished_at'    => now(),
            ]);
        } finally {
            foreach ($tempFiles as $path) {
                @unlink($path);
            }
        }
    }

    /**
     * Grava processed_rows + contadores no import (a tela consulta a cada
     * 2 s). No máximo 1 escrita/segundo — a lista completa tem ~26 mil linhas.
     */
    private function reportProgress(MedicineImport $import, bool $force = false, ?string $phase = null): void
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

    /**
     * @param array<string, string>      $row
     * @param array<string, string>|null $registrations
     *
     * @return array<string, mixed>|null
     */
    private function toRecord(array $row, ?array $registrations, MedicineImport $import, string $runAt): ?array
    {
        $ggrem        = preg_replace('/\D/', '', $row['ggrem'] ?? '');
        $product      = $this->clean($row['product'] ?? '');
        $presentation = $this->clean($row['presentation'] ?? '');

        if ($ggrem === '' || $product === null || $presentation === null) {
            $this->counts['skipped_invalid']++;

            return null;
        }

        if ($this->yes($row['hospital_only'] ?? '')) {
            $this->counts['skipped_hospital']++;

            return null;
        }

        $registration = preg_replace('/\D/', '', $row['registration'] ?? '');

        // Registro conhecido nos dados abertos e não "Ativo" → fora. Registro
        // ausente dos dados abertos fica (benefício da dúvida).
        if ($registrations !== null && $registration !== '') {
            $status = $registrations[substr(str_pad($registration, 9, '0', STR_PAD_LEFT), 0, 9)] ?? null;

            if ($status !== null && $status !== 'ativo') {
                $this->counts['skipped_inactive_registration']++;

                return null;
            }
        }

        if (isset($this->seen[$ggrem])) {
            return null; // duplicata no mesmo arquivo: vale a primeira
        }

        $this->seen[$ggrem] = true;

        $parsed     = $this->parser->parse($presentation);
        $ingredient = $this->clean(str_replace(';', ' + ', mb_strtolower($row['substance'] ?? '', 'UTF-8')));
        $marketed   = $row['marketed'] ?? null;

        $attributes = [
            'name'                => mb_strtoupper($product, 'UTF-8'),
            'active_ingredient'   => $ingredient ? mb_substr($ingredient, 0, 1000) : null,
            'concentration'       => $parsed['concentration'] ? mb_substr($parsed['concentration'], 0, 255) : null,
            'pharmaceutical_form' => $parsed['form'],
            'presentation_detail' => mb_substr($presentation, 0, 500),
            'laboratory'          => $this->limit($row['laboratory'] ?? '', 255),
            'anvisa_registration' => $registration !== '' ? substr($registration, 0, 20) : null,
            'ean'                 => ($ean = preg_replace('/\D/', '', $row['ean'] ?? '')) !== '' ? substr($ean, 0, 20) : null,
            'regulatory_category' => $this->limit($row['category'] ?? '', 60),
            'therapeutic_class'   => $this->limit($row['therapeutic_class'] ?? '', 255),
            'is_ophthalmic'       => $parsed['is_ophthalmic'],
            'is_marketed'         => $marketed === null || $marketed === '' ? true : $this->yes($marketed),
        ];

        return [
            ...$attributes,
            'id'               => (string) Str::uuid7(),
            'entity_id'        => null,
            'source'           => MedicineSource::Cmed->value,
            'source_code'      => $ggrem,
            'source_synced_at' => $runAt,
            'active'           => true,
            'search_text'      => Medicine::searchTextFor($attributes),
            'created_at'       => $runAt,
            'updated_at'       => $runAt,
            'created_by'       => $import->user_id,
            'updated_by'       => $import->user_id,
        ];
    }

    /** @param list<array<string, mixed>> $batch */
    private function flush(array $batch): void
    {
        if ($batch === []) {
            return;
        }

        $codes    = array_column($batch, 'source_code');
        $existing = DB::table('medicines')
            ->where('source', MedicineSource::Cmed->value)
            ->whereIn('source_code', $codes)
            ->count();

        DB::table('medicines')->upsert($batch, ['source', 'source_code'], self::UPDATE_COLUMNS);

        $this->counts['updated_count'] += $existing;
        $this->counts['created_count'] += count($batch) - $existing;
    }

    // ── Leitura dos arquivos ────────────────────────────────────────────────

    /**
     * Linhas da lista CMED como [chave interna => valor], a partir da linha
     * de cabeçalho (achada pelo nome das colunas — a Anvisa já mudou a
     * posição delas e a quantidade de linhas de preâmbulo entre versões),
     * mais o total estimado de linhas de dados pra barra de progresso (o
     * total real é gravado no fim).
     *
     * @return array{0: int, 1: Generator<int, array<string, string>>}
     */
    private function openCmed(string $path): array
    {
        if (in_array(mb_strtolower(pathinfo($path, PATHINFO_EXTENSION)), ['csv', 'txt'], true)) {
            $lines  = 0;
            $handle = fopen($path, 'r');

            while (fgets($handle) !== false) {
                $lines++;
            }

            fclose($handle);

            return [max(0, $lines - 1), $this->cmedCsvRows($path)];
        }

        $reader = IOFactory::createReaderForFile($path);
        $reader->setReadDataOnly(true);
        $reader->setReadEmptyCells(false);

        [$sheet, $headerRow, $columns, $totalRows] = $this->locateXlsxHeader($reader, $path);

        return [max(0, $totalRows - $headerRow), $this->xlsxRows($reader, $path, $sheet, $headerRow, $columns, $totalRows)];
    }

    /**
     * @param array<string, string> $columns chave interna → letra da coluna
     *
     * @return Generator<int, array<string, string>>
     */
    private function xlsxRows(IReader $reader, string $path, string $sheet, int $headerRow, array $columns, int $totalRows): Generator
    {
        $reader->setLoadSheetsOnly([$sheet]);

        for ($start = $headerRow + 1; $start <= $totalRows; $start += self::CHUNK_ROWS) {
            $end = min($start + self::CHUNK_ROWS - 1, $totalRows);
            $reader->setReadFilter($this->filter(array_values($columns), $start, $end));

            $spreadsheet = $reader->load($path);
            $worksheet   = $spreadsheet->getSheetByName($sheet) ?? $spreadsheet->getActiveSheet();

            for ($r = $start; $r <= $end; $r++) {
                $row = [];

                foreach ($columns as $key => $letter) {
                    $row[$key] = $this->cellString($worksheet->getCell($letter . $r)->getValue());
                }

                if (implode('', $row) !== '') {
                    yield $row;
                }
            }

            $spreadsheet->disconnectWorksheets();
            unset($spreadsheet, $worksheet);
        }
    }

    /**
     * @return array{0: string, 1: int, 2: array<string, string>, 3: int}
     */
    private function locateXlsxHeader(IReader $reader, string $path): array
    {
        foreach ($reader->listWorksheetInfo($path) as $info) {
            $reader->setLoadSheetsOnly([$info['worksheetName']]);
            $reader->setReadFilter($this->filter(null, 1, 80));
            $spreadsheet = $reader->load($path);
            $worksheet   = $spreadsheet->getActiveSheet();

            foreach ($worksheet->getRowIterator(1, min(80, (int) $info['totalRows'])) as $rowObj) {
                $cells = [];

                foreach ($rowObj->getCellIterator() as $cell) {
                    $cells[$cell->getColumn()] = $this->cellString($cell->getValue());
                }

                $columns = $this->mapHeader($cells);

                if ($columns !== null) {
                    $spreadsheet->disconnectWorksheets();

                    return [$info['worksheetName'], $rowObj->getRowIndex(), $columns, (int) $info['totalRows']];
                }
            }

            $spreadsheet->disconnectWorksheets();
        }

        throw new MedicineImportException(__('manager_medicines.import_header_not_found'));
    }

    /** @return Generator<int, array<string, string>> */
    private function cmedCsvRows(string $path): Generator
    {
        $handle = fopen($path, 'r');
        $first  = (string) fgets($handle);
        rewind($handle);
        $delimiter = substr_count($first, ';') >= substr_count($first, ',') ? ';' : ',';
        $columns   = null;
        $line      = 0;

        while (($raw = fgetcsv($handle, 0, $delimiter, '"', '')) !== false) {
            $line++;
            $raw = array_map(fn ($v) => $this->utf8((string) $v), $raw);

            if ($columns === null) {
                $columns = $line <= 80 ? $this->mapHeader($raw) : null;

                if ($columns === null && $line > 80) {
                    break;
                }

                continue;
            }

            $row = [];

            foreach ($columns as $key => $index) {
                $row[$key] = trim($raw[$index] ?? '');
            }

            if (implode('', $row) !== '') {
                yield $row;
            }
        }

        fclose($handle);

        if ($columns === null) {
            throw new MedicineImportException(__('manager_medicines.import_header_not_found'));
        }
    }

    /**
     * @param array<int|string, string> $cells índice/letra da coluna → texto
     *
     * @return array<string, int|string>|null chave interna → índice/letra
     */
    private function mapHeader(array $cells): ?array
    {
        $map = [];

        foreach ($cells as $position => $text) {
            $header = $this->normalizeHeader($text);

            foreach (self::COLUMNS as $name => $key) {
                // "TIPO DE PRODUTO (STATUS DO PRODUTO)", "COMERCIALIZAÇÃO 2025"
                // → prefixo; demais → igualdade exata ("REGISTRO" ≠ "CÓDIGO GGREM").
                $matches = in_array($key, ['category', 'marketed'], true)
                    ? str_starts_with($header, $name)
                    : $header === $name;

                if ($matches && ! isset($map[$key])) {
                    $map[$key] = $position;
                }
            }
        }

        foreach (self::REQUIRED as $key) {
            if (! isset($map[$key])) {
                return null;
            }
        }

        return $map;
    }

    /**
     * Situação do registro nos dados abertos da Anvisa, por registro de 9
     * dígitos: 'ativo' | 'inativo'.
     *
     * @return array<string, string>
     */
    private function readRegistrationStatus(string $path): array
    {
        $handle = fopen($path, 'r');
        $header = fgetcsv($handle, 0, ';', '"', '');

        if ($header === false) {
            throw new MedicineImportException(__('manager_medicines.import_open_data_invalid'));
        }

        $header = array_map(fn ($h) => $this->normalizeHeader($this->utf8((string) $h)), $header);
        $regIdx = array_search('NUMERO_REGISTRO_PRODUTO', $header, true);
        $sitIdx = array_search('SITUACAO_REGISTRO', $header, true);

        if ($regIdx === false || $sitIdx === false) {
            fclose($handle);

            throw new MedicineImportException(__('manager_medicines.import_open_data_invalid'));
        }

        $map = [];

        while (($raw = fgetcsv($handle, 0, ';', '"', '')) !== false) {
            $registration = preg_replace('/\D/', '', (string) ($raw[$regIdx] ?? ''));

            if ($registration === '') {
                continue;
            }

            $key = substr(str_pad($registration, 9, '0', STR_PAD_LEFT), 0, 9);
            // Mesmo registro pode aparecer mais de uma vez: basta um "Ativo".
            $active    = mb_strtolower(trim($this->utf8((string) ($raw[$sitIdx] ?? ''))), 'UTF-8') === 'ativo';
            $map[$key] = ($map[$key] ?? null) === 'ativo' || $active ? 'ativo' : 'inativo';
        }

        fclose($handle);

        return $map;
    }

    // ── Helpers ─────────────────────────────────────────────────────────────

    /** Arquivo do disco padrão (S3 ou local) → arquivo temporário local. */
    private function localCopy(string $diskPath): string
    {
        $source = Storage::disk()->readStream($diskPath);

        if (! $source) {
            throw new MedicineImportException(__('manager_medicines.import_file_missing'));
        }

        $temp = tempnam(sys_get_temp_dir(), 'medimport_') . '.' . pathinfo($diskPath, PATHINFO_EXTENSION);
        $out  = fopen($temp, 'w');
        stream_copy_to_stream($source, $out);
        fclose($out);
        fclose($source);

        return $temp;
    }

    /** @param list<string>|null $columns null = todas */
    private function filter(?array $columns, int $startRow, int $endRow): IReadFilter
    {
        return new class($columns, $startRow, $endRow) implements IReadFilter {
            public function __construct(private ?array $columns, private int $start, private int $end)
            {
            }

            public function readCell($columnAddress, $row, $worksheetName = ''): bool
            {
                return $row >= $this->start && $row <= $this->end
                    && ($this->columns === null || in_array($columnAddress, $this->columns, true));
            }
        };
    }

    private function cellString(mixed $value): string
    {
        if ($value instanceof RichText) {
            $value = $value->getPlainText();
        }

        if (is_float($value) && floor($value) === $value) {
            return number_format($value, 0, '', '');
        }

        return trim(str_replace("\u{00A0}", ' ', (string) $value));
    }

    private function normalizeHeader(string $text): string
    {
        $text = str_replace("\u{00A0}", ' ', $text);

        return trim((string) preg_replace('/\s+/', ' ', mb_strtoupper(Str::ascii($text), 'UTF-8')));
    }

    private function utf8(string $value): string
    {
        return mb_check_encoding($value, 'UTF-8') ? $value : mb_convert_encoding($value, 'UTF-8', 'Windows-1252');
    }

    private function clean(string $value): ?string
    {
        $value = trim((string) preg_replace('/\s+/u', ' ', $value));

        return $value === '' || trim($value, '- ') === '' ? null : $value;
    }

    private function limit(string $value, int $max): ?string
    {
        $value = $this->clean($value);

        return $value === null ? null : mb_substr($value, 0, $max);
    }

    private function yes(string $value): bool
    {
        return in_array(mb_strtolower(Str::ascii(trim($value)), 'UTF-8'), ['sim', 's', 'yes', 'true', '1'], true);
    }
}
