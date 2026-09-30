<?php

declare(strict_types=1);

namespace App\Services\Financial\DoctorPayouts;

use App\Models\DoctorPayout;
use App\Services\Audit\AuditLogger;
use App\Support\Export\SpreadsheetWriter;
use Illuminate\Http\{Request, Response};
use RuntimeException;

/**
 * Planilhas do repasse (CSV/XLSX) com o mesmo comportamento das exportações
 * financeiras: SpreadsheetWriter (anti-injeção de fórmula, BOM, separador
 * decimal do idioma), XLSX com fallback para XLS, e auditoria da exportação.
 */
final class DoctorPayoutExporter
{
    public const FORMATS = ['csv', 'xlsx'];

    public function __construct(
        private readonly SpreadsheetWriter $spreadsheets,
        private readonly AuditLogger $auditLogger,
    ) {
    }

    public static function normalizeFormat(mixed $format): string
    {
        $format = is_string($format) ? mb_strtolower(trim($format)) : '';

        return in_array($format, self::FORMATS, true) ? $format : 'csv';
    }

    /** @param list<list<mixed>> $rows */
    public function download(string $format, array $rows, string $baseFilename, string $sheetName): Response
    {
        if ($format === 'xlsx' && SpreadsheetWriter::supportsXlsx()) {
            try {
                return $this->response(
                    $this->spreadsheets->xlsx($rows, $sheetName),
                    'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                    "{$baseFilename}.xlsx",
                );
            } catch (RuntimeException $e) {
                report($e);
            }
        }

        if ($format === 'xlsx') {
            return $this->response($this->spreadsheets->xls($rows, $sheetName), 'application/vnd.ms-excel; charset=UTF-8', "{$baseFilename}.xls");
        }

        return $this->response(
            $this->spreadsheets->csv($rows, (string) __('financial_reports.decimal_separator')),
            'text/csv; charset=UTF-8',
            "{$baseFilename}.csv",
        );
    }

    /** @param array<string, mixed> $context */
    public function audit(Request $request, string $entityId, string $report, string $format, array $context, int $rows): void
    {
        $this->auditLogger->recordAdminAction(
            event: 'financial.report.export',
            targetEntityId: $entityId,
            targetUserId: null,
            auditableType: 'entity',
            auditableId: $entityId,
            reason: 'Exportação de repasse médico.',
            newValues: ['report' => $report, 'format' => $format, 'rows' => $rows] + $context,
            request: $request,
        );
    }

    /**
     * Abertura do demonstrativo na tela (clínica ou "Meus repasses"): traz
     * nome e procedimento de pacientes — a leitura fica na trilha, como o PDF
     * e a planilha (sem dados do paciente no registro).
     */
    public function auditView(Request $request, string $entityId, DoctorPayout $payout, bool $forDoctor): void
    {
        $this->auditLogger->recordAdminAction(
            event: 'financial.report.view',
            targetEntityId: $entityId,
            targetUserId: null,
            auditableType: 'doctor_payout',
            auditableId: (string) $payout->id,
            reason: 'Visualização de demonstrativo de repasse médico.',
            newValues: [
                'report'      => 'doctor_payout_statement',
                'code'        => $payout->code,
                'items_count' => (int) $payout->items_count,
                'viewer'      => $forDoctor ? 'doctor' : 'clinic',
            ],
            request: $request,
        );
    }

    private function response(string $content, string $contentType, string $filename): Response
    {
        return response($content, 200, [
            'Content-Type'        => $contentType,
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
        ]);
    }
}
