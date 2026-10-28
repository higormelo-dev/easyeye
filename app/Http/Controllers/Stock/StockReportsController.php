<?php

declare(strict_types=1);

namespace App\Http\Controllers\Stock;

use App\Http\Controllers\Controller;
use App\Services\Stock\StockReportService;
use DateTimeImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\{Arr, Str};
use Inertia\{Inertia, Response as InertiaResponse};

/**
 * Relatórios de estoque (GAP fechado nesta revisão — ver docblock de
 * App\Services\Stock\StockReportService). Leitura pura, mesmo grupo de
 * rota `stock.` (permission:stock.manage + feature:has_inventory_module).
 */
class StockReportsController extends Controller
{
    /**
     * Ordenação aceita por relatório (whitelist): chave → campos da linha em
     * ordem de desempate (`-campo` = sentido inverso). A PRIMEIRA chave de
     * cada relatório é a ordenação padrão, a mesma que o StockReportService
     * já devolve — `-cumulative_pct` mantém a curva ABC em ordem quando dois
     * produtos têm o mesmo valor. O nome dos relatórios é o mesmo `report`
     * de exportCsv().
     */
    private const SORTABLE = [
        'inventory' => [
            'total_value'    => ['total_value', '-cumulative_pct'],
            'name'           => ['name', 'code'],
            'category_name'  => ['category_name', 'name'],
            'qty_on_hand'    => ['qty_on_hand'],
            'cost_avg'       => ['cost_avg'],
            'cumulative_pct' => ['cumulative_pct', '-total_value'],
        ],
        'turnover' => [
            'qty_out'        => ['qty_out'],
            'name'           => ['name', 'code'],
            'qty_on_hand'    => ['qty_on_hand'],
            'turnover_ratio' => ['turnover_ratio'],
        ],
        'consumption' => [
            'total_cost'     => ['total_cost', 'executed_at', 'procedure_name'],
            'procedure_name' => ['procedure_name', 'executed_at'],
            'doctor_name'    => ['doctor_name', 'executed_at'],
            'executed_at'    => ['executed_at', 'procedure_name'],
        ],
        'purchases' => [
            'total_spent'   => ['total_spent', 'supplier_name'],
            'supplier_name' => ['supplier_name'],
            'orders_count'  => ['orders_count', 'supplier_name'],
        ],
    ];

    /** Direção padrão de todos os relatórios (maior valor primeiro, como hoje). */
    private const DEFAULT_DIRECTION = 'desc';

    /**
     * Marcadores de "sem valor" nas linhas do serviço (o StockReportService
     * devolve '—' para procedimento/médico desconhecido) — na ordenação contam
     * como vazio e vão para o fim, igual a null.
     */
    private const BLANK_VALUES = ['', '—'];

    public function index(Request $request, StockReportService $reports): InertiaResponse
    {
        $entityId    = (string) session('selected_entity_id');
        [$from, $to] = $this->period($request);

        // `report` = aba ativa. Cada aba guarda a própria ordenação em
        // `sorts[<relatório>][sort|direction]` (a tela reenvia as que saíram do
        // padrão, pra não se perderem ao ordenar outra aba ou mudar o período);
        // `sort`/`direction` avulsos valem para o `report` e têm prioridade.
        // Tudo validado pela mesma whitelist; `sorts` volta normalizado.
        $report = $this->stringInput($request, 'report');
        $report = array_key_exists($report, self::SORTABLE) ? $report : array_key_first(self::SORTABLE);
        $sorts  = [];

        foreach (array_keys(self::SORTABLE) as $key) {
            $sorts[$key] = $this->requestedSort(
                $key,
                $this->stringInput($request, "sorts.{$key}.sort"),
                $this->stringInput($request, "sorts.{$key}.direction"),
            );
        }

        if ($request->hasAny(['sort', 'direction'])) {
            $sorts[$report] = $this->requestedSort(
                $report,
                $this->stringInput($request, 'sort'),
                $this->stringInput($request, 'direction'),
            );
        }

        $inventory          = $reports->valuedInventory($entityId);
        $inventory['items'] = $this->sortRows($inventory['items'], 'inventory', $sorts['inventory']);

        return Inertia::render('Panel/Stock/Reports/Index', [
            'breadcrumbs' => [
                ['label' => __('actions.sidemenu.dashboard'), 'url' => route('panel.dashboard'), 'active' => false],
                ['label' => __('actions.sidemenu.stock'), 'url' => '#', 'active' => false],
                ['label' => __('actions.sidemenu.stock_reports'), 'url' => '#', 'active' => true],
            ],
            // Normalizados: a tela mostra o período e a ordenação realmente aplicados.
            'filters' => [
                'from'      => $from,
                'to'        => $to,
                'report'    => $report,
                'sort'      => $sorts[$report]['sort'],
                'direction' => $sorts[$report]['direction'],
                'sorts'     => $sorts,
            ],
            'valuedInventory' => $inventory,
            'turnover'        => $this->sortRows($reports->turnoverByProduct($entityId, $from, $to), 'turnover', $sorts['turnover']),
            // executed_at já vem em ISO do serviço: a tela formata no idioma do usuário.
            'consumptionByProcedure' => $this->sortRows($reports->consumptionByProcedure($entityId, $from, $to), 'consumption', $sorts['consumption']),
            'purchasesBySupplier'    => $this->sortRows($reports->purchasesBySupplier($entityId, $from, $to), 'purchases', $sorts['purchases']),
            // Chaves ordenáveis por relatório — a tela só oferece o que a whitelist aceita.
            'sortable' => array_map(fn (array $keys) => array_keys($keys), self::SORTABLE),
            'routes'   => [
                'index'  => route('panel.stock.reports.index'),
                'export' => route('panel.stock.reports.export'),
            ],
            // Cabeçalhos do CSV (`csv`) só servem ao exportCsv — fora da prop.
            't' => Arr::except(trans('stock_reports'), ['csv']),
        ]);
    }

    /**
     * Período (from/to) validado: data fora de Y-m-d (ou vazia) volta ao
     * padrão — antes ia crua pro whereBetween e virava erro 500 do
     * PostgreSQL — e período invertido é trocado.
     *
     * @return array{0: string, 1: string}
     */
    private function period(Request $request): array
    {
        $from = $this->validDate($request->input('from')) ?? now()->startOfMonth()->toDateString();
        $to   = $this->validDate($request->input('to')) ?? now()->toDateString();

        return $from <= $to ? [$from, $to] : [$to, $from];
    }

    /**
     * Y-m-d que existe no calendário e no PostgreSQL: '0000-01-01' passa no
     * round-trip do formato, mas o PostgreSQL não tem ano 0 (SQLSTATE 22008),
     * por isso o ano mínimo é 1.
     */
    private function validDate(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        return $date !== false && $date->format('Y-m-d') === $value && (int) $date->format('Y') >= 1 ? $value : null;
    }

    /**
     * OWASP CSV/Formula Injection: texto livre (nome/código de produto,
     * categoria, fornecedor, procedimento, médico) começando com =, +, -, @,
     * tab ou CR vira fórmula ao abrir no Excel. Prefixa com aspas simples —
     * mesma regra de App\Support\Export\SpreadsheetWriter::sanitizeCell(). Só
     * string: os números do serviço são float e não são tocados.
     */
    private function csvCell(mixed $cell): mixed
    {
        if (! is_string($cell) || $cell === '') {
            return $cell;
        }

        return preg_match('/^[=+\-@\t\r]/', $cell) === 1 ? "'" . $cell : $cell;
    }

    /**
     * Data ISO do serviço (Y-m-d) → d/m/Y, formato de sempre do CSV (planilha
     * BR). Vazio continua vazio.
     */
    private function csvDate(?string $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        return $date !== false ? $date->format('d/m/Y') : $value;
    }

    /**
     * Ordenação pedida para um relatório, validada pela whitelist dele (fora
     * dela → padrão).
     *
     * @return array{sort: string, direction: string}
     */
    private function requestedSort(string $report, string $sort, string $direction): array
    {
        return [
            'sort'      => array_key_exists($sort, self::SORTABLE[$report]) ? $sort : array_key_first(self::SORTABLE[$report]),
            'direction' => in_array($direction, ['asc', 'desc'], true) ? $direction : self::DEFAULT_DIRECTION,
        ];
    }

    /** Query string escalar (lista/objeto na URL vira vazio em vez de erro); aceita notação com ponto. */
    private function stringInput(Request $request, string $key): string
    {
        $value = $request->input($key);

        return is_string($value) ? $value : '';
    }

    /**
     * Ordena as linhas já calculadas pelo serviço (os relatórios são
     * agregados em memória, não há query paginada para ordenar no banco).
     * Vazio (null ou BLANK_VALUES, ex.: o '—' de procedimento desconhecido)
     * fica sempre no fim; texto compara sem acento/caixa; o `id` desempata
     * por último quando a linha tem um — sem ele, usort (estável no PHP 8)
     * mantém a ordem do serviço.
     *
     * @param list<array<string, mixed>>             $rows
     * @param array{sort: string, direction: string} $sort
     *
     * @return list<array<string, mixed>>
     */
    private function sortRows(array $rows, string $report, array $sort): array
    {
        $fields     = self::SORTABLE[$report][$sort['sort']];
        $descending = $sort['direction'] === 'desc';
        $normalized = [];
        $text       = function (string $value) use (&$normalized): string {
            return $normalized[$value] ??= Str::lower(Str::ascii($value));
        };
        $value = fn (array $row, string $key): mixed => in_array($row[$key] ?? null, self::BLANK_VALUES, true) ? null : ($row[$key] ?? null);

        usort($rows, function (array $a, array $b) use ($fields, $descending, $text, $value): int {
            foreach ($fields as $field) {
                $inverse = str_starts_with($field, '-');
                $key     = ltrim($field, '-');
                $x       = $value($a, $key);
                $y       = $value($b, $key);

                if ($x === $y) {
                    continue;
                }

                if ($x === null || $y === null) {
                    return $x === null ? 1 : -1;
                }

                $cmp = is_string($x) && is_string($y) ? strnatcmp($text($x), $text($y)) : $x <=> $y;

                if ($cmp !== 0) {
                    return $descending !== $inverse ? -$cmp : $cmp;
                }
            }

            return ($a['id'] ?? null) <=> ($b['id'] ?? null);
        });

        return $rows;
    }

    /**
     * GAP fechado (revisão pós-Fase 4): Financeiro já tinha exportação de
     * relatório (FinancialReportsController::exportCashFlowCsv()), Estoque
     * não tinha nenhuma — cada relatório era só leitura na tela, sem forma
     * de levar pra planilha. CSV puro (não o CSV/XLS/XLSX/PDF completo do
     * Financeiro — volume de dado de estoque é bem menor, não justifica a
     * mesma máquina); mesmo padrão de streaming de
     * ComplianceController::exportAuditLogs().
     */
    public function exportCsv(Request $request, StockReportService $reports)
    {
        $entityId = (string) session('selected_entity_id');
        // Mesmo período validado da tela: data inválida não chega ao PostgreSQL
        // (antes: erro 500) nem ao nome do arquivo no Content-Disposition.
        [$from, $to] = $this->period($request);
        $report      = is_string($request->input('report')) ? $request->input('report') : 'inventory';
        $col         = fn (string $key): string => __("stock_reports.csv.{$key}");

        [$header, $rows, $filename] = match ($report) {
            'turnover' => [
                [$col('product'), $col('code'), $col('qty_out'), $col('current_qty'), $col('turnover_ratio')],
                collect($reports->turnoverByProduct($entityId, $from, $to))->map(fn ($r) => [
                    $r['name'], $r['code'], $r['qty_out'], $r['qty_on_hand'], $r['turnover_ratio'] ?? '',
                ]),
                "estoque_giro_{$from}_{$to}.csv",
            ],
            'consumption' => [
                [$col('procedure'), $col('doctor'), $col('executed_at'), $col('product'), $col('quantity'), $col('unit_cost'), $col('total_cost')],
                collect($reports->consumptionByProcedure($entityId, $from, $to))
                    ->flatMap(fn ($group) => collect($group['items'])->map(fn ($item) => [
                        $group['procedure_name'], $group['doctor_name'], $this->csvDate($group['executed_at']),
                        $item['product_name'], $item['quantity'], $item['unit_cost'], $item['total_cost'],
                    ])),
                "estoque_consumo_{$from}_{$to}.csv",
            ],
            'purchases' => [
                [$col('supplier'), $col('orders_count'), $col('total_spent')],
                collect($reports->purchasesBySupplier($entityId, $from, $to))->map(fn ($r) => [
                    $r['supplier_name'], $r['orders_count'], $r['total_spent'],
                ]),
                "estoque_compras_{$from}_{$to}.csv",
            ],
            default => [
                [
                    $col('product'), $col('code'), $col('category'), $col('qty_on_hand'),
                    $col('cost_avg'), $col('total_value'), $col('cumulative_pct'), $col('abc_class'),
                ],
                collect($reports->valuedInventory($entityId)['items'])->map(fn ($r) => [
                    $r['name'], $r['code'], $r['category_name'] ?? '', $r['qty_on_hand'],
                    $r['cost_avg'], $r['total_value'], $r['cumulative_pct'], $r['abc_class'],
                ]),
                'estoque_posicao_valorizada_' . now()->format('Y-m-d') . '.csv',
            ],
        };

        $stream = fopen('php://temp', 'r+');
        fwrite($stream, "\xEF\xBB\xBF"); // BOM UTF-8 — Excel BR abre acentuação certa sem isso
        // escape '' = RFC 4180 (aspas dobradas), o que o Excel entende; sem
        // passar explícito o PHP 8.4 emite deprecation (igual ao SpreadsheetWriter).
        fputcsv($stream, $header, ';', '"', '');

        foreach ($rows as $row) {
            fputcsv($stream, array_map($this->csvCell(...), $row), ';', '"', '');
        }
        rewind($stream);
        $content = stream_get_contents($stream) ?: '';
        fclose($stream);

        return response($content, 200, [
            'Content-Type'        => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
        ]);
    }
}
