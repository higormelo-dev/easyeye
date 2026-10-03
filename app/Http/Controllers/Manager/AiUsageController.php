<?php

namespace App\Http\Controllers\Manager;

use App\Domains\AI\Services\AiUsageReportService;
use App\DTOs\AI\AiUsageFiltersData;
use App\Enums\EntityGate;
use App\Http\Controllers\Controller;
use App\Http\Requests\Manager\AiUsageRequest;
use App\Models\Entity;
use App\Support\Export\SpreadsheetWriter;
use Generator;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Inertia\{Inertia, Response};
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Manager → Uso de IA: quanto o EasyEye gasta com IA, em quê e por quem —
 * custo (US$/R$), execuções, falhas, créditos, receita estimada e margem por
 * período, ação, clínica, usuário e provedor/modelo, mais a lista das
 * execuções (com detalhe e exportação CSV).
 *
 * Só metadados de uso — nunca prompt/resposta (dado clínico). Acesso: dono ou
 * admin do SaaS (Gate SaasOwnerFinancial, o mesmo do P&L); leituras e
 * exportações ficam em audit_logs pelo middleware admin.audit do grupo.
 */
class AiUsageController extends Controller
{
    public function __construct(
        private readonly AiUsageReportService $report,
        private readonly SpreadsheetWriter $spreadsheets,
    ) {
    }

    public function index(AiUsageRequest $request): Response
    {
        $filters            = $request->filters();
        [$sort, $direction] = $request->sort();

        return Inertia::render('Panel/Manager/AiUsage/Index', [
            'filters' => [
                'preset'    => $request->preset(),
                'from'      => $filters->from->toDateString(),
                'to'        => $filters->to->toDateString(),
                'entity_id' => $filters->entityId,
                'user_id'   => $filters->userId,
                'user_name' => $filters->userId !== null ? $this->report->userName($filters->userId) : null,
                'workflow'  => $filters->workflow,
                'provider'  => $filters->provider,
                'status'    => $filters->status,
                'sort'      => $sort,
                'direction' => $direction,
            ],
            'presets'    => AiUsageRequest::PRESETS,
            'kpis'       => $this->report->kpis($filters),
            'series'     => $this->report->series($filters),
            'byWorkflow' => $this->report->byWorkflow($filters),
            'byEntity'   => $this->report->byEntity($filters),
            'byUser'     => $this->report->byUser($filters),
            'byProvider' => $this->report->byProvider($filters),
            'runs'       => $this->report->runsPage($filters, $sort, $direction),
            'options'    => $this->report->filterOptions(),
            'rate'       => $this->report->rate(),
            'topLimit'   => AiUsageReportService::TOP_LIMIT,
            't'          => trans('manager_ai_usage'),
        ]);
    }

    public function showRun(string $run): JsonResponse
    {
        $this->authorizeOwner();

        $detail = $this->report->runDetail($run);

        abort_if($detail === null, 404);

        return response()->json(['data' => $detail]);
    }

    public function export(AiUsageRequest $request): StreamedResponse
    {
        $filters  = $request->filters();
        $decimal  = (string) __('financial_reports.decimal_separator');
        $filename = __('manager_ai_usage.export_filename', [
            'from' => $filters->from->toDateString(),
            'to'   => $filters->to->toDateString(),
        ]) . '.csv';

        return response()->streamDownload(function () use ($filters, $decimal) {
            $output = fopen('php://output', 'w');

            $this->spreadsheets->writeCsv($output, $this->exportRows($filters, $decimal), $decimal);

            fclose($output);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /** @return Generator<int, array<int, mixed>> cabeçalho + uma linha por execução (já traduzidos) */
    private function exportRows(AiUsageFiltersData $filters, string $decimal): Generator
    {
        yield [
            __('manager_ai_usage.export.date'),
            __('manager_ai_usage.export.entity'),
            __('manager_ai_usage.export.entity_type'),
            __('manager_ai_usage.export.user'),
            __('manager_ai_usage.export.action'),
            __('manager_ai_usage.export.mode'),
            __('manager_ai_usage.export.status'),
            __('manager_ai_usage.export.calls'),
            __('manager_ai_usage.export.failed_calls'),
            __('manager_ai_usage.export.providers'),
            __('manager_ai_usage.export.credits'),
            __('manager_ai_usage.export.cost_usd'),
            __('manager_ai_usage.export.cost_brl'),
            __('manager_ai_usage.export.run_id'),
        ];

        foreach ($this->report->exportRuns($filters) as $row) {
            yield [
                Carbon::parse($row['created_at'])->format('Y-m-d H:i:s'),
                $row['entity_name'],
                $row['is_internal'] ? __('manager_ai_usage.internal') : __('manager_ai_usage.clinic'),
                $row['user_name'],
                $row['workflow_label'],
                $row['mode_label'],
                $row['status_label'],
                // Contagens e US$ (frações de centavo) como texto já formatado — o
                // escritor de CSV põe 2 casas em todo número; R$ fica com 2 casas.
                (string) $row['calls'],
                (string) $row['failed_calls'],
                implode(', ', $row['providers']),
                (string) $row['credits'],
                number_format((float) $row['cost_usd'], 6, $decimal, ''),
                (float) $row['cost_brl'],
                $row['id'],
            ];
        }
    }

    private function authorizeOwner(): void
    {
        Gate::authorize(EntityGate::SaasOwnerFinancial->value, Entity::query()->findOrFail(session('selected_entity_id')));
    }
}
