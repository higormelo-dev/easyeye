<?php

declare(strict_types=1);

namespace App\Http\Controllers\Financial;

use App\Enums\EntityGate;
use App\Http\Controllers\Controller;
use App\Models\{BillingClaim, Entity, FinancialCashEntry};
use App\Services\Financial\CashFlowService;
use BackedEnum;
use Barryvdh\Snappy\Facades\SnappyPdf;
use Illuminate\Http\{Request, Response};
use Illuminate\Support\Facades\Gate;
use Inertia\{Inertia, Response as InertiaResponse};
use Throwable;
use ZipArchive;

class FinancialReportsController extends Controller
{
    public function __construct(
        private readonly CashFlowService $cashFlowService,
    ) {
        $this->titleController = 'Relatórios Financeiros';
    }

    public function cashFlow(Request $request)
    {
        $entity   = $this->authorizeFinancial();
        $entityId = (string) $entity->id;

        $from = (string) $request->input('from', now()->startOfMonth()->toDateString());
        $to   = (string) $request->input('to', now()->toDateString());

        $entries = FinancialCashEntry::query()
            ->with(['category', 'covenant'])
            ->where('entity_id', $entityId)
            ->whereBetween('entry_date', [$from, $to])
            ->where('status', '!=', 'cancelled')
            ->whereNull('deleted_at')
            ->orderBy('entry_date')
            ->get();

        $summary = $this->cashFlowService->summary($entityId, $from, $to);

        $byCategory = $entries
            ->groupBy(fn ($entry) => ($entry->category?->name ?? 'Sem categoria') . '|' . $entry->type->value)
            ->map(function ($group, $key) {
                [$category, $type] = explode('|', $key);

                return [
                    'category' => $category,
                    'type'     => $type,
                    'total'    => (float) $group->sum('amount'),
                ];
            })
            ->values()
            ->sortBy(['type', 'category'])
            ->values();

        $byDay = $entries
            ->groupBy(fn ($entry) => $entry->entry_date->format('Y-m-d'))
            ->map(fn ($group, $day) => [
                'day'     => $day,
                'income'  => (float) $group->where('type', 'income')->sum('amount'),
                'expense' => (float) $group->where('type', 'expense')->sum('amount'),
            ])
            ->values();

        return Inertia::render('Panel/Financial/Reports/CashFlow', [
            'breadcrumbs' => [
                ['label' => __('actions.sidemenu.dashboard'), 'url' => route('panel.dashboard'), 'active' => false],
                ['label' => __('financial.financial'), 'url' => route('panel.financial.bi.index'), 'active' => false],
                ['label' => __('financial.cashflow.breadcrumb'), 'url' => '#', 'active' => true],
            ],
            'filters'    => ['from' => $from, 'to' => $to],
            'summary'    => $summary,
            'byCategory' => $byCategory->values()->all(),
            'byDay'      => $byDay->values()->all(),
            'entries'    => $entries->map(fn ($e) => [
                'entry_date'    => $e->entry_date?->format('d/m/Y'),
                'description'   => $e->description,
                'category_name' => $e->category?->name,
                'covenant_name' => $e->covenant?->name,
                'type'          => $e->type instanceof BackedEnum ? $e->type->value : $e->type,
                'amount'        => (float) $e->amount,
            ]),
            'export_url' => route('panel.financial.reports.cash-flow.export'),
            't'          => trans('financial'),
        ]);
    }

    public function covenants(Request $request): InertiaResponse
    {
        $entity   = $this->authorizeFinancial();
        $entityId = (string) $entity->id;

        $from = (string) $request->input('from', now()->startOfMonth()->toDateString());
        $to   = (string) $request->input('to', now()->toDateString());

        $claims = BillingClaim::query()
            ->with(['covenant', 'batch', 'patient.person'])
            ->where('entity_id', $entityId)
            ->whereBetween('attendance_date', [$from, $to])
            ->whereNull('deleted_at')
            ->orderByDesc('attendance_date')
            ->get();

        $summary = [
            'total_claims' => $claims->count(),
            'total_amount' => (float) $claims->sum('amount'),
            'total_paid'   => (float) $claims
                ->filter(fn ($claim) => $claim->status->value === 'paid')
                ->sum('paid_amount'),
            'total_denied' => (float) $claims->sum('glosa_amount'),
        ];

        $byCovenant = $claims
            ->groupBy(fn ($claim) => $claim->covenant?->name ?? 'Sem convênio')
            ->map(fn ($group, $name) => [
                'covenant' => $name,
                'claims'   => $group->count(),
                'amount'   => (float) $group->sum('amount'),
                'paid'     => (float) $group->sum('paid_amount'),
                'denied'   => (float) $group->sum('glosa_amount'),
            ])
            ->values()
            ->sortByDesc('amount')
            ->values();

        return Inertia::render('Panel/Financial/Reports/Covenants', [
            'breadcrumbs' => [
                ['label' => __('actions.sidemenu.dashboard'), 'url' => route('panel.dashboard'), 'active' => false],
                ['label' => __('financial.financial'), 'url' => route('panel.financial.bi.index'), 'active' => false],
                ['label' => __('financial.covenants.breadcrumb'), 'url' => '#', 'active' => true],
            ],
            'filters'    => ['from' => $from, 'to' => $to],
            'summary'    => $summary,
            'byCovenant' => $byCovenant->values()->all(),
            'export_url' => route('panel.financial.reports.covenants.export'),
            't'          => trans('financial'),
        ]);
    }

    public function exportCashFlowCsv(Request $request)
    {
        $entity   = $this->authorizeFinancial();
        $entityId = (string) $entity->id;

        $from = (string) $request->input('from', now()->startOfMonth()->toDateString());
        $to   = (string) $request->input('to', now()->toDateString());

        $entries = FinancialCashEntry::query()
            ->with(['category', 'covenant'])
            ->where('entity_id', $entityId)
            ->whereBetween('entry_date', [$from, $to])
            ->where('status', '!=', 'cancelled')
            ->whereNull('deleted_at')
            ->orderBy('entry_date')
            ->get();

        $summary = $this->cashFlowService->summary($entityId, $from, $to);
        $rows    = $this->cashFlowExportRows($entries);

        $format       = $this->normalizeExportFormat((string) $request->input('format', 'csv'));
        $baseFilename = "fluxo_caixa_{$from}_{$to}";

        return match ($format) {
            'pdf' => $this->cashFlowPdfResponse(
                entity: $entity,
                entries: $entries,
                summary: $summary,
                from: $from,
                to: $to,
                filename: "{$baseFilename}.pdf",
            ),
            'xls'   => $this->xlsResponse($rows, "{$baseFilename}.xls"),
            'xlsx'  => $this->xlsxResponse($rows, "{$baseFilename}.xlsx"),
            default => $this->csvResponse($rows, "{$baseFilename}.csv"),
        };
    }

    public function exportCovenantsCsv(Request $request)
    {
        $entity   = $this->authorizeFinancial();
        $entityId = (string) $entity->id;

        $from = (string) $request->input('from', now()->startOfMonth()->toDateString());
        $to   = (string) $request->input('to', now()->toDateString());

        $claims = BillingClaim::query()
            ->with(['covenant', 'patient.person'])
            ->where('entity_id', $entityId)
            ->whereBetween('attendance_date', [$from, $to])
            ->whereNull('deleted_at')
            ->orderBy('attendance_date')
            ->get();

        $rows   = [];
        $rows[] = ['Data atendimento', 'Guia', 'Convênio', 'Paciente', 'Status', 'Valor', 'Glosa', 'Pago'];

        foreach ($claims as $claim) {
            $rows[] = [
                $claim->attendance_date?->format('d/m/Y'),
                $claim->code,
                $claim->covenant?->name ?? 'Sem convênio',
                $claim->patient?->person?->full_name ?? 'Sem paciente',
                $claim->status->label(),
                number_format((float) $claim->amount, 2, '.', ''),
                number_format((float) $claim->glosa_amount, 2, '.', ''),
                number_format((float) $claim->paid_amount, 2, '.', ''),
            ];
        }

        return $this->csvResponse($rows, "faturamento_convenios_{$from}_{$to}.csv");
    }

    private function authorizeFinancial(): Entity
    {
        $entity = Entity::query()->findOrFail(session('selected_entity_id'));
        Gate::authorize(EntityGate::ViewFinancial->value, $entity);

        return $entity;
    }

    private function csvResponse(array $rows, string $filename)
    {
        $stream = fopen('php://temp', 'r+');

        foreach ($rows as $row) {
            fputcsv($stream, $row, ';');
        }

        rewind($stream);
        $content = stream_get_contents($stream) ?: '';
        fclose($stream);

        return response($content, 200, [
            'Content-Type'        => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
        ]);
    }

    private function xlsResponse(array $rows, string $filename): Response
    {
        $content = $this->renderXlsXml($rows);

        return response($content, 200, [
            'Content-Type'        => 'application/vnd.ms-excel; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
        ]);
    }

    private function xlsxResponse(array $rows, string $filename): Response
    {
        if (! class_exists(ZipArchive::class)) {
            return $this->xlsResponse($rows, str_replace('.xlsx', '.xls', $filename));
        }

        $content = $this->renderXlsx($rows);

        return response($content, 200, [
            'Content-Type'        => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
        ]);
    }

    private function cashFlowPdfResponse(
        Entity $entity,
        $entries,
        array $summary,
        string $from,
        string $to,
        string $filename,
    ) {
        try {
            return SnappyPdf::loadView('pdf.financial_cashflow', [
                'entity'      => $entity,
                'entries'     => $entries,
                'summary'     => $summary,
                'from'        => $from,
                'to'          => $to,
                'generatedAt' => now(),
            ])->setPaper('a4')->setOrientation('landscape')->download($filename);
        } catch (Throwable) {
            abort(500, 'Falha ao gerar PDF. Verifique a configuração do wkhtmltopdf.');
        }
    }

    private function normalizeExportFormat(string $format): string
    {
        $format = mb_strtolower(trim($format));

        return match ($format) {
            'xsl'   => 'xls', // compatibilidade com typo comum
            'excel' => 'xlsx',
            default => in_array($format, ['csv', 'xls', 'xlsx', 'pdf'], true) ? $format : 'csv',
        };
    }

    private function cashFlowExportRows($entries): array
    {
        $rows   = [];
        $rows[] = ['Data', 'Código', 'Descrição', 'Tipo', 'Status', 'Categoria', 'Convênio', 'Valor'];

        foreach ($entries as $entry) {
            $rows[] = [
                $entry->entry_date?->format('d/m/Y'),
                $entry->code,
                $entry->description,
                $entry->type->label(),
                $entry->status->label(),
                $entry->category?->name ?? 'Sem categoria',
                $entry->covenant?->name ?? 'Sem convênio',
                (float) $entry->amount,
            ];
        }

        return $rows;
    }

    private function renderXlsXml(array $rows): string
    {
        $xml   = [];
        $xml[] = '<?xml version="1.0"?>';
        $xml[] = '<?mso-application progid="Excel.Sheet"?>';
        $xml[] = '<Workbook xmlns="urn:schemas-microsoft-com:office:spreadsheet"';
        $xml[] = ' xmlns:o="urn:schemas-microsoft-com:office:office"';
        $xml[] = ' xmlns:x="urn:schemas-microsoft-com:office:excel"';
        $xml[] = ' xmlns:ss="urn:schemas-microsoft-com:office:spreadsheet">';
        $xml[] = '<Worksheet ss:Name="Fluxo de Caixa">';
        $xml[] = '<Table>';

        foreach ($rows as $row) {
            $xml[] = '<Row>';

            foreach ($row as $cell) {
                $isNumeric = is_int($cell) || is_float($cell);
                $type      = $isNumeric ? 'Number' : 'String';
                $value     = $isNumeric
                    ? (string) $cell
                    : htmlspecialchars((string) $cell, ENT_QUOTES | ENT_XML1, 'UTF-8');

                $xml[] = sprintf(
                    '<Cell><Data ss:Type="%s">%s</Data></Cell>',
                    $type,
                    $value,
                );
            }

            $xml[] = '</Row>';
        }

        $xml[] = '</Table>';
        $xml[] = '</Worksheet>';
        $xml[] = '</Workbook>';

        return implode('', $xml);
    }

    private function renderXlsx(array $rows): string
    {
        $tmpFile = tempnam(sys_get_temp_dir(), 'cashflow_xlsx_');

        if ($tmpFile === false) {
            abort(500, 'Não foi possível preparar o arquivo XLSX.');
        }

        $zip = new ZipArchive();

        if ($zip->open($tmpFile, ZipArchive::OVERWRITE) !== true) {
            @unlink($tmpFile);
            abort(500, 'Não foi possível gerar o arquivo XLSX.');
        }

        $zip->addFromString('[Content_Types].xml', $this->xlsxContentTypesXml());
        $zip->addFromString('_rels/.rels', $this->xlsxRootRelsXml());
        $zip->addFromString('docProps/app.xml', $this->xlsxAppXml());
        $zip->addFromString('docProps/core.xml', $this->xlsxCoreXml());
        $zip->addFromString('xl/workbook.xml', $this->xlsxWorkbookXml());
        $zip->addFromString('xl/_rels/workbook.xml.rels', $this->xlsxWorkbookRelsXml());
        $zip->addFromString('xl/styles.xml', $this->xlsxStylesXml());
        $zip->addFromString('xl/worksheets/sheet1.xml', $this->xlsxSheetXml($rows));
        $zip->close();

        $binary = file_get_contents($tmpFile) ?: '';
        @unlink($tmpFile);

        return $binary;
    }

    private function xlsxSheetXml(array $rows): string
    {
        $lines   = [];
        $lines[] = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>';
        $lines[] = '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">';
        $lines[] = '<sheetData>';

        foreach ($rows as $rowIndex => $row) {
            $excelRow = $rowIndex + 1;
            $lines[]  = sprintf('<row r="%d">', $excelRow);

            foreach (array_values($row) as $columnIndex => $cell) {
                $column  = $this->excelColumnLetter($columnIndex + 1);
                $cellRef = $column . $excelRow;

                if (is_int($cell) || is_float($cell)) {
                    $lines[] = sprintf('<c r="%s"><v>%s</v></c>', $cellRef, $cell);

                    continue;
                }

                $value   = htmlspecialchars((string) $cell, ENT_QUOTES | ENT_XML1, 'UTF-8');
                $lines[] = sprintf('<c r="%s" t="inlineStr"><is><t>%s</t></is></c>', $cellRef, $value);
            }

            $lines[] = '</row>';
        }

        $lines[] = '</sheetData>';
        $lines[] = '</worksheet>';

        return implode('', $lines);
    }

    private function excelColumnLetter(int $number): string
    {
        $letter = '';

        while ($number > 0) {
            $mod    = ($number - 1) % 26;
            $letter = chr(65 + $mod) . $letter;
            $number = intdiv($number - 1, 26);
        }

        return $letter;
    }

    private function xlsxContentTypesXml(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
            . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
            . '<Override PartName="/docProps/core.xml" ContentType="application/vnd.openxmlformats-package.core-properties+xml"/>'
            . '<Override PartName="/docProps/app.xml" ContentType="application/vnd.openxmlformats-officedocument.extended-properties+xml"/>'
            . '</Types>';
    }

    private function xlsxRootRelsXml(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/package/2006/relationships/metadata/core-properties" Target="docProps/core.xml"/>'
            . '<Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/extended-properties" Target="docProps/app.xml"/>'
            . '</Relationships>';
    }

    private function xlsxAppXml(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Properties xmlns="http://schemas.openxmlformats.org/officeDocument/2006/extended-properties" '
            . 'xmlns:vt="http://schemas.openxmlformats.org/officeDocument/2006/docPropsVTypes">'
            . '<Application>EasyEye</Application>'
            . '</Properties>';
    }

    private function xlsxCoreXml(): string
    {
        $generatedAt = now()->toAtomString();

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties" '
            . 'xmlns:dc="http://purl.org/dc/elements/1.1/" '
            . 'xmlns:dcterms="http://purl.org/dc/terms/" '
            . 'xmlns:dcmitype="http://purl.org/dc/dcmitype/" '
            . 'xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">'
            . '<dc:creator>EasyEye</dc:creator>'
            . '<cp:lastModifiedBy>EasyEye</cp:lastModifiedBy>'
            . '<dcterms:created xsi:type="dcterms:W3CDTF">' . $generatedAt . '</dcterms:created>'
            . '<dcterms:modified xsi:type="dcterms:W3CDTF">' . $generatedAt . '</dcterms:modified>'
            . '</cp:coreProperties>';
    }

    private function xlsxWorkbookXml(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" '
            . 'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . '<sheets><sheet name="Fluxo de Caixa" sheetId="1" r:id="rId1"/></sheets>'
            . '</workbook>';
    }

    private function xlsxWorkbookRelsXml(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
            . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
            . '</Relationships>';
    }

    private function xlsxStylesXml(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<fonts count="1"><font><sz val="11"/><name val="Calibri"/></font></fonts>'
            . '<fills count="1"><fill><patternFill patternType="none"/></fill></fills>'
            . '<borders count="1"><border/></borders>'
            . '<cellStyleXfs count="1"><xf/></cellStyleXfs>'
            . '<cellXfs count="1"><xf xfId="0"/></cellXfs>'
            . '</styleSheet>';
    }
}
