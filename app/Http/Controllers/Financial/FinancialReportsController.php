<?php

declare(strict_types=1);

namespace App\Http\Controllers\Financial;

use App\Enums\{EntityGate, FinancialEntryStatus, FinancialEntryType};
use App\Http\Controllers\Controller;
use App\Models\{Covenant, Entity, FinancialCashEntry, FinancialCategory};
use App\Services\Audit\AuditLogger;
use App\Services\Financial\{CashFlowService, CovenantReportService};
use App\Support\Export\SpreadsheetWriter;
use App\Support\ReportPeriod;
use BackedEnum;
use Barryvdh\Snappy\Facades\SnappyPdf;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\{Builder, Collection as EloquentCollection};
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\{JsonResponse, RedirectResponse, Request, Response};
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;
use Inertia\{Inertia, Response as InertiaResponse};
use RuntimeException;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;
use Throwable;

class FinancialReportsController extends Controller
{
    /**
     * Teto do período do relatório de fluxo de caixa — tela E exportações
     * (CSV/XLSX/PDF). Período maior é recortado no servidor mantendo a data
     * final (os lançamentos mais recentes): a tela avisa o recorte e a
     * exportação sai com o período recortado (nome do arquivo e auditoria
     * também). 366 = um ano inteiro, inclusive bissexto.
     */
    public const CASH_FLOW_MAX_PERIOD_DAYS = 366;

    /** Lançamentos por página na lista do relatório. */
    public const CASH_FLOW_PER_PAGE = 30;

    /** Parâmetro de página próprio da lista (não colide com outros paginadores). */
    public const CASH_FLOW_PAGE_NAME = 'entries_page';

    /** Ordenação aceita na lista (whitelist da query string) → coluna. */
    public const CASH_FLOW_SORTABLE = [
        'entry_date'  => 'financial_cash_entries.entry_date',
        'code'        => 'financial_cash_entries.code',
        'description' => 'financial_cash_entries.description',
        'type'        => 'financial_cash_entries.type',
        'status'      => 'financial_cash_entries.status',
        'amount'      => 'financial_cash_entries.amount',
    ];

    /** Status filtráveis na lista: o relatório nunca mostra cancelados. */
    private const CASH_FLOW_STATUSES = [FinancialEntryStatus::Paid->value, FinancialEntryStatus::Pending->value];

    /** Busca maior que isso é cortada (descrição tem 255; código, 32). */
    private const SEARCH_MAX_LENGTH = 100;

    public function __construct(
        private readonly CashFlowService $cashFlowService,
        private readonly CovenantReportService $covenantReports,
        private readonly AuditLogger $auditLogger,
        private readonly SpreadsheetWriter $spreadsheets,
    ) {
        $this->titleController = 'Relatórios Financeiros';
    }

    /**
     * Agregados (KPIs, por dia, por categoria) em SQL sobre o período INTEIRO;
     * a lista de lançamentos é paginada no servidor, com busca, filtros e
     * ordenação próprios (que não mudam os agregados nem a exportação).
     */
    public function cashFlow(Request $request): InertiaResponse
    {
        $entity                  = $this->authorizeFinancial();
        $entityId                = (string) $entity->id;
        [$from, $to, $requested] = $this->cashFlowPeriod($request);

        $byCategory = $this->cashFlowService->reportByCategory($entityId, $from, $to);
        $categories = $this->cashFlowCategoryOptions($entityId, $byCategory);
        $filters    = $this->cashFlowListFilters($request, $categories);

        return Inertia::render('Panel/Financial/Reports/CashFlow', [
            'breadcrumbs' => [
                ['label' => __('actions.sidemenu.dashboard'), 'url' => route('panel.dashboard'), 'active' => false],
                ['label' => __('financial.financial'), 'url' => route('panel.financial.bi.index'), 'active' => false],
                ['label' => __('financial_reports.cashflow.breadcrumb'), 'url' => '#', 'active' => true],
            ],
            'filters' => ['from' => $from, 'to' => $to, ...$filters],
            // Período pedido acima do teto (null = sem recorte): a tela avisa.
            'period_capped' => $requested,
            // "Hoje" no fuso da clínica: atalhos do PeriodFilter.
            'today' => now()->toDateString(),
            // summary() intocado (income/expense/balance/pending): PDF, snapshot
            // do fechamento e BI dependem dessas chaves. Closures: a troca de
            // filtro/ordem da lista (recarga parcial) não recalcula os agregados.
            'summary' => fn () => $this->cashFlowService->summary($entityId, $from, $to),
            // KPIs realizado × previsto: a MESMA definição da tela de Fluxo de caixa.
            'overview'   => fn () => $this->cashFlowService->overview($entityId, $from, $to),
            'byCategory' => $byCategory,
            'byDay'      => fn () => $this->cashFlowService->reportByDay($entityId, $from, $to),
            'categories' => $categories,
            'entries'    => fn () => $this->cashFlowEntriesPage($entityId, $from, $to, $filters),
            'routes'     => [
                'index'  => route('panel.financial.reports.cash-flow'),
                'export' => route('panel.financial.reports.cash-flow.export'),
            ],
            'export_formats' => ['csv', 'xlsx', 'pdf'],
            't'              => trans('financial_reports') + ['shared' => trans('financial_shared')],
        ]);
    }

    public function covenants(Request $request): InertiaResponse
    {
        $entity      = $this->authorizeFinancial();
        $entityId    = (string) $entity->id;
        [$from, $to] = $this->period($request);

        // Consolidado agregado no banco pela fonte única do BI
        // (BillingReportService): sem rascunho/cancelada, "Recebido" só de
        // guia paga, convênio excluído na própria linha (`inactive`).
        // Nenhum dado de paciente aqui.
        $byCovenant = $this->covenantReports->byCovenant($entityId, $from, $to);

        return Inertia::render('Panel/Financial/Reports/Covenants', [
            'breadcrumbs' => [
                ['label' => __('actions.sidemenu.dashboard'), 'url' => route('panel.dashboard'), 'active' => false],
                ['label' => __('financial.financial'), 'url' => route('panel.financial.bi.index'), 'active' => false],
                ['label' => __('financial_reports.covenants.breadcrumb'), 'url' => '#', 'active' => true],
            ],
            'filters'    => ['from' => $from, 'to' => $to],
            'today'      => now()->toDateString(),
            'summary'    => $this->covenantReports->totals($byCovenant),
            'byCovenant' => $byCovenant,
            // % de glosa acima do qual a linha ganha o selo "Alta" (legenda da tela).
            'glosa_alert_threshold' => CovenantReportService::GLOSA_ALERT_THRESHOLD,
            'routes'                => [
                'index'  => route('panel.financial.reports.covenants'),
                'export' => route('panel.financial.reports.covenants.export'),
                'claims' => route('panel.financial.reports.covenants.claims'),
            ],
            'export_formats' => ['csv', 'xlsx'],
            't'              => trans('financial_reports') + ['shared' => trans('financial_shared')],
        ]);
    }

    /**
     * Guias de um convênio no período (linha expandida do relatório), em JSON
     * paginado. `covenant_id` vazio = linha "Sem convênio" (chave '' do BI);
     * senão precisa ser convênio da clínica ou global — de outra clínica ou
     * inválido → 422. Do paciente, só código + iniciais (LGPD).
     */
    public function covenantClaims(Request $request): JsonResponse
    {
        $entity   = $this->authorizeFinancial();
        $entityId = (string) $entity->id;

        // bail: UUID inválido para antes do exists (sem erro 22P02 → 500 no PostgreSQL).
        $validated = $request->validate([
            'covenant_id' => ['bail', 'present', 'nullable', 'string', 'uuid', $this->covenantExistsRule($entityId)],
        ]);

        [$from, $to] = $this->period($request);

        $claims = $this->covenantReports->claims($entityId, $from, $to, $validated['covenant_id'] ?? null);

        return response()->json([
            'data' => $claims->items(),
            'meta' => [
                'current_page' => $claims->currentPage(),
                'last_page'    => $claims->lastPage(),
                'per_page'     => $claims->perPage(),
                'total'        => $claims->total(),
                'from'         => $claims->firstItem(),
                'to'           => $claims->lastItem(),
            ],
            'filters' => ['from' => $from, 'to' => $to],
        ]);
    }

    /**
     * Exporta o período (recortado pelo teto CASH_FLOW_MAX_PERIOD_DAYS)
     * INTEIRO: sem paginação e sem os filtros/busca da lista — os mesmos
     * números dos indicadores da tela.
     */
    public function exportCashFlowCsv(Request $request): SymfonyResponse
    {
        $entity      = $this->authorizeFinancial();
        $entityId    = (string) $entity->id;
        [$from, $to] = $this->cashFlowPeriod($request);

        $entries = $this->cashFlowEntries($entityId, $from, $to);
        $rows    = $this->cashFlowExportRows($entries);

        $format       = $this->normalizeExportFormat(ReportPeriod::text($request->query('format'), 'csv'));
        $baseFilename = "fluxo_caixa_{$from}_{$to}";

        $response = $format === 'pdf'
            ? $this->cashFlowPdfResponse(
                entity: $entity,
                entries: $entries,
                summary: $this->cashFlowService->summary($entityId, $from, $to),
                from: $from,
                to: $to,
                filename: "{$baseFilename}.pdf",
            )
            : $this->spreadsheetResponse($format, $rows, $baseFilename, __('financial_reports.cashflow.sheet_name'));

        if ($response->isSuccessful()) {
            $this->auditExport($request, $entityId, 'cash_flow', $format, $from, $to, count($rows) - 1);
        }

        return $response;
    }

    public function exportCovenantsCsv(Request $request): SymfonyResponse
    {
        $entity      = $this->authorizeFinancial();
        $entityId    = (string) $entity->id;
        [$from, $to] = $this->period($request);

        // Mesmas guias do relatório na tela (sem rascunho/cancelada). Do
        // paciente só código + iniciais (LGPD, decisão do usuário): o nome é
        // lido para as iniciais e nunca vai para o arquivo.
        $claims = $this->covenantReports->billedClaimsQuery($entityId, $from, $to)
            ->with([
                'covenant' => $this->covenantWithTrashed(...),
                ...$this->covenantReports->patientRelations($entityId),
            ])
            ->orderBy('billing_claims.attendance_date')
            ->orderBy('billing_claims.code')
            ->get([
                'billing_claims.id',
                'billing_claims.covenant_id',
                'billing_claims.patient_id',
                'billing_claims.code',
                'billing_claims.status',
                'billing_claims.attendance_date',
                'billing_claims.amount',
                'billing_claims.paid_amount',
                'billing_claims.glosa_amount',
            ]);

        $rows   = [];
        $rows[] = [
            __('financial_reports.covenants.col_attendance_date'),
            __('financial_reports.covenants.col_guide'),
            __('financial_reports.covenants.col_covenant'),
            __('financial_reports.covenants.col_patient'),
            __('financial_reports.covenants.col_status'),
            __('financial_reports.covenants.col_value'),
            __('financial_reports.covenants.col_glosa'),
            __('financial_reports.covenants.col_received'),
        ];

        foreach ($claims as $claim) {
            $rows[] = [
                $claim->attendance_date?->format(__('financial_reports.date_format')),
                $claim->code,
                $this->covenantLabel($claim->covenant),
                $this->covenantReports->patientReference($claim->patient),
                __('financial_reports.claim_status.' . $claim->status->value),
                (float) $claim->amount,
                (float) $claim->glosa_amount,
                // "Recebido" = regra do BI: só guia paga conta como recebida.
                $this->covenantReports->receivedAmount($claim),
            ];
        }

        // Convênios não tem modelo de PDF: pdf cai no CSV (allowlist do normalize).
        $format = $this->normalizeExportFormat(ReportPeriod::text($request->query('format'), 'csv'));
        $format = $format === 'pdf' ? 'csv' : $format;

        $response = $this->spreadsheetResponse(
            $format,
            $rows,
            "faturamento_convenios_{$from}_{$to}",
            __('financial_reports.covenants.sheet_name'),
        );

        $this->auditExport($request, $entityId, 'covenants', $format, $from, $to, count($rows) - 1);

        return $response;
    }

    private function authorizeFinancial(): Entity
    {
        $entity = Entity::query()->findOrFail(session('selected_entity_id'));
        Gate::authorize(EntityGate::ViewFinancial->value, $entity);

        return $entity;
    }

    /**
     * Período validado (App\Support\ReportPeriod): data inválida cai no mês
     * atual e período invertido é trocado — nunca chega ao PostgreSQL nem ao
     * nome do arquivo exportado.
     *
     * @return array{0: string, 1: string}
     */
    private function period(Request $request): array
    {
        return ReportPeriod::resolve($request->query('from'), $request->query('to'));
    }

    /**
     * Período do fluxo de caixa com teto (CASH_FLOW_MAX_PERIOD_DAYS): acima
     * dele o início é trazido para perto do fim — nunca erro 500 nem consulta
     * sem limite. O 3º item descreve o recorte para o aviso da tela.
     *
     * @return array{0: string, 1: string, 2: ?array{requested_from: string, requested_to: string, max_days: int}}
     */
    private function cashFlowPeriod(Request $request): array
    {
        [$from, $to] = $this->period($request);

        $earliest = CarbonImmutable::parse($to)->subDays(self::CASH_FLOW_MAX_PERIOD_DAYS - 1)->toDateString();

        if ($from >= $earliest) {
            return [$from, $to, null];
        }

        return [$earliest, $to, [
            'requested_from' => $from,
            'requested_to'   => $to,
            'max_days'       => self::CASH_FLOW_MAX_PERIOD_DAYS,
        ]];
    }

    /**
     * Filtros da lista normalizados — valor inválido vira null/padrão (nunca
     * 500): tipo/status por enum (cancelado não é opção), categoria só entre
     * as opções do período (da própria clínica), busca aparada e limitada,
     * ordenação pela whitelist (padrão: data, crescente).
     *
     * @param list<array{id: string, name: string, type: ?string}> $categories
     *
     * @return array{type: ?string, status: ?string, category_id: ?string, search: string, sort: string, direction: string}
     */
    private function cashFlowListFilters(Request $request, array $categories): array
    {
        $status     = ReportPeriod::text($request->query('status'));
        $categoryId = ReportPeriod::uuidOrNull($request->query('category_id'));
        $sort       = ReportPeriod::text($request->query('sort'));

        return [
            'type'        => FinancialEntryType::tryFrom(ReportPeriod::text($request->query('type')))?->value,
            'status'      => in_array($status, self::CASH_FLOW_STATUSES, true) ? $status : null,
            'category_id' => in_array($categoryId, array_column($categories, 'id'), true) ? $categoryId : null,
            'search'      => mb_substr(ReportPeriod::text($request->query('search')), 0, self::SEARCH_MAX_LENGTH),
            'sort'        => array_key_exists($sort, self::CASH_FLOW_SORTABLE) ? $sort : 'entry_date',
            'direction'   => ReportPeriod::text($request->query('direction')) === 'desc' ? 'desc' : 'asc',
        ];
    }

    /**
     * Opções do filtro de categoria: as categorias com lançamento no período
     * (ids vindos do agregado, já escopado pela clínica), da clínica ou
     * globais e não excluídas, em ordem de nome.
     *
     * @param list<array{category_id: ?string}> $byCategory
     *
     * @return list<array{id: string, name: string, type: ?string}>
     */
    private function cashFlowCategoryOptions(string $entityId, array $byCategory): array
    {
        $ids = array_values(array_unique(array_filter(array_column($byCategory, 'category_id'))));

        if ($ids === []) {
            return [];
        }

        return FinancialCategory::query()
            ->whereIn('id', $ids)
            ->where(fn (Builder $query) => $query->where('entity_id', $entityId)->orWhereNull('entity_id'))
            ->orderBy('name')
            ->orderBy('id')
            ->get(['id', 'name', 'type'])
            ->map(fn (FinancialCategory $category): array => [
                'id'   => (string) $category->id,
                'name' => (string) $category->name,
                'type' => $category->type instanceof BackedEnum ? $category->type->value : $category->type,
            ])
            ->values()
            ->all();
    }

    /**
     * Página da lista (paginação no servidor com página própria; a query
     * string segue nos links). Cancelados sempre fora. Cada linha leva o
     * atalho para a tela de Fluxo de caixa no mesmo período buscando pelo
     * código FLC — sem código (legado), o dia do lançamento.
     *
     * @param array{type: ?string, status: ?string, category_id: ?string, search: string, sort: string, direction: string} $filters
     */
    private function cashFlowEntriesPage(string $entityId, string $from, string $to, array $filters): LengthAwarePaginator
    {
        $direction = $filters['direction'];

        return $this->cashFlowService->entriesQuery($entityId, $from, $to, $filters)
            ->where('financial_cash_entries.status', '!=', FinancialEntryStatus::Cancelled->value)
            ->with(['category:id,name', 'covenant:id,name'])
            ->orderBy(self::CASH_FLOW_SORTABLE[$filters['sort']], $direction)
            ->orderBy('financial_cash_entries.created_at', $direction)
            ->orderBy('financial_cash_entries.id', $direction)
            ->paginate(self::CASH_FLOW_PER_PAGE, ['financial_cash_entries.*'], self::CASH_FLOW_PAGE_NAME)
            ->withQueryString()
            ->through(fn (FinancialCashEntry $entry): array => $this->cashFlowEntryRow($entry, $from, $to));
    }

    /**
     * Linha da lista (data em ISO: a tela formata no idioma do usuário).
     *
     * @return array<string, mixed>
     */
    private function cashFlowEntryRow(FinancialCashEntry $entry, string $from, string $to): array
    {
        $date = $entry->entry_date?->toDateString();
        $code = trim((string) $entry->code);

        return [
            'id'                   => (string) $entry->id,
            'code'                 => $entry->code,
            'entry_date'           => $date,
            'description'          => $entry->description,
            'category_name'        => $entry->category?->name,
            'covenant_name'        => $entry->covenant?->name,
            'payment_method_label' => $entry->payment_method?->label(),
            'type'                 => $entry->type instanceof BackedEnum ? $entry->type->value : $entry->type,
            'status'               => $entry->status instanceof BackedEnum ? $entry->status->value : $entry->status,
            'amount'               => (float) $entry->amount,
            'cash_flow_url'        => route('panel.financial.cash-flow.index', $code !== ''
                ? ['from' => $from, 'to' => $to, 'search' => $code]
                : ['from' => $date ?? $from, 'to' => $date ?? $to]),
        ];
    }

    /**
     * Lançamentos da exportação de fluxo de caixa (sem cancelados, período
     * inteiro, ordem cronológica): mesma base da tela de Fluxo de caixa
     * (CashFlowService::entriesQuery).
     */
    private function cashFlowEntries(string $entityId, string $from, string $to): EloquentCollection
    {
        return $this->cashFlowService->entriesQuery($entityId, $from, $to)
            ->with(['category', 'covenant'])
            ->where('financial_cash_entries.status', '!=', FinancialEntryStatus::Cancelled->value)
            ->orderBy('financial_cash_entries.entry_date')
            ->orderBy('financial_cash_entries.created_at')
            ->orderBy('financial_cash_entries.id')
            ->get();
    }

    /**
     * Convênio aceito no detalhe: da clínica ou global. Inclui excluído (soft
     * delete) porque convênio inativo continua com linha no relatório.
     */
    private function covenantExistsRule(string $entityId): Exists
    {
        return Rule::exists('covenants', 'id')->where(
            fn ($query) => $query->where(
                fn ($scope) => $scope->where('entity_id', $entityId)->orWhereNull('entity_id'),
            ),
        );
    }

    /**
     * Carrega o convênio da guia mesmo excluído (soft delete): o faturamento
     * histórico continua atribuído a ele. Sem vazamento entre clínicas — é o
     * convênio gravado na própria guia, já filtrada por entity_id.
     */
    private function covenantWithTrashed(Relation $query): void
    {
        $query->withTrashed()->select('id', 'name', 'deleted_at');
    }

    /** Nome do convênio para a exportação; excluído ganha "(inativo)". */
    private function covenantLabel(?Covenant $covenant): string
    {
        if ($covenant === null) {
            return __('financial_reports.no_covenant');
        }

        return $covenant->trashed()
            ? __('financial_reports.covenant_inactive', ['name' => $covenant->name])
            : (string) $covenant->name;
    }

    /**
     * LGPD (accountability): um evento por exportação com quem, clínica,
     * período, formato e nº de linhas — sem nomes de paciente nem valores.
     */
    private function auditExport(
        Request $request,
        string $entityId,
        string $report,
        string $format,
        string $from,
        string $to,
        int $rows,
    ): void {
        $this->auditLogger->recordAdminAction(
            event: 'financial.report.export',
            targetEntityId: $entityId,
            targetUserId: null,
            auditableType: 'entity',
            auditableId: $entityId,
            reason: 'Exportação de relatório financeiro.',
            newValues: [
                'report' => $report,
                'format' => $format,
                'from'   => $from,
                'to'     => $to,
                'rows'   => $rows,
            ],
            request: $request,
        );
    }

    /**
     * CSV (padrão), XLS ou XLSX gerados pelo SpreadsheetWriter — neutralização
     * de fórmula (OWASP CSV injection), BOM UTF-8 e separador decimal do idioma
     * de quem exporta.
     *
     * @param list<array<int, mixed>> $rows
     */
    private function spreadsheetResponse(string $format, array $rows, string $baseFilename, string $sheetName): Response
    {
        return match ($format) {
            'xls'   => $this->xlsResponse($rows, "{$baseFilename}.xls", $sheetName),
            'xlsx'  => $this->xlsxResponse($rows, "{$baseFilename}.xlsx", $sheetName),
            default => $this->download(
                $this->spreadsheets->csv($rows, (string) __('financial_reports.decimal_separator')),
                'text/csv; charset=UTF-8',
                "{$baseFilename}.csv",
            ),
        };
    }

    /** @param list<array<int, mixed>> $rows */
    private function xlsResponse(array $rows, string $filename, string $sheetName): Response
    {
        return $this->download($this->spreadsheets->xls($rows, $sheetName), 'application/vnd.ms-excel; charset=UTF-8', $filename);
    }

    /**
     * Sem ZipArchive — ou falha ao montar o ZIP, antes um abort(500) — cai no
     * .xls (o mesmo conteúdo, formato antigo do Excel).
     *
     * @param list<array<int, mixed>> $rows
     */
    private function xlsxResponse(array $rows, string $filename, string $sheetName): Response
    {
        if (SpreadsheetWriter::supportsXlsx()) {
            try {
                return $this->download(
                    $this->spreadsheets->xlsx($rows, $sheetName),
                    'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                    $filename,
                );
            } catch (RuntimeException $e) {
                report($e);
            }
        }

        return $this->xlsResponse($rows, str_replace('.xlsx', '.xls', $filename), $sheetName);
    }

    private function download(string $content, string $contentType, string $filename): Response
    {
        return response($content, 200, [
            'Content-Type'        => $contentType,
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
        ]);
    }

    /**
     * Falha do wkhtmltopdf: antes abort(500) com texto fixo — o link de
     * exportação tirava o usuário do painel para uma tela de erro. Agora volta
     * ao relatório (mesmo período) com o aviso traduzido; o erro vai ao
     * report() sem dados do relatório.
     */
    private function cashFlowPdfResponse(
        Entity $entity,
        $entries,
        array $summary,
        string $from,
        string $to,
        string $filename,
    ): SymfonyResponse {
        try {
            return SnappyPdf::loadView('pdf.financial_cashflow', [
                'entity'      => $entity,
                'entries'     => $entries,
                'summary'     => $summary,
                'from'        => $from,
                'to'          => $to,
                'generatedAt' => now(),
            ])->setPaper('a4')->setOrientation('landscape')->download($filename);
        } catch (Throwable $e) {
            report($e);

            return $this->pdfFailedRedirect($from, $to);
        }
    }

    private function pdfFailedRedirect(string $from, string $to): RedirectResponse
    {
        return redirect()
            ->route('panel.financial.reports.cash-flow', ['from' => $from, 'to' => $to])
            ->with('error', __('financial_reports.export_pdf_failed'));
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

    /** Linhas da exportação com cabeçalhos/rótulos no idioma de quem exporta. */
    private function cashFlowExportRows($entries): array
    {
        $rows   = [];
        $rows[] = [
            __('financial_reports.cashflow.col_date'),
            __('financial_reports.cashflow.col_code'),
            __('financial_reports.cashflow.col_description'),
            __('financial_reports.cashflow.col_type'),
            __('financial_reports.cashflow.col_status'),
            __('financial_reports.cashflow.col_category'),
            __('financial_reports.cashflow.col_covenant'),
            __('financial_reports.cashflow.col_value'),
        ];

        foreach ($entries as $entry) {
            $rows[] = [
                $entry->entry_date?->format(__('financial_reports.date_format')),
                $entry->code,
                $entry->description,
                __('financial_reports.entry_type.' . $entry->type->value),
                __('financial_reports.entry_status.' . $entry->status->value),
                $entry->category?->name ?? __('financial_reports.no_category'),
                $entry->covenant?->name ?? __('financial_reports.no_covenant'),
                (float) $entry->amount,
            ];
        }

        return $rows;
    }
}
