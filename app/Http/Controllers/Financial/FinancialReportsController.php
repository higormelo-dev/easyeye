<?php

declare(strict_types = 1);

namespace App\Http\Controllers\Financial;

use App\Enums\EntityGate;
use App\Http\Controllers\Controller;
use App\Models\{BillingClaim, Entity, FinancialCashEntry};
use App\Services\Financial\CashFlowService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class FinancialReportsController extends Controller
{
    public function __construct(
        private readonly CashFlowService $cashFlowService
    ) {
        $this->titleController = 'Relatórios Financeiros';
    }

    public function index()
    {
        $this->authorizeFinancial();

        $meta = [
            'title' => 'Relatórios Financeiros',
            'action' => 'Financeiro',
            'breadcrumbs' => [
                ['label' => __('actions.sidemenu.dashboard'), 'url' => route('panel.dashboard'), 'active' => false],
                ['label' => 'Financeiro', 'url' => route('panel.financial.reports.index'), 'active' => false],
                ['label' => 'Relatórios Financeiros', 'url' => 'javascript:void(0)', 'active' => true],
            ],
        ];

        return view('system.financial.reports.index', compact('meta'));
    }

    public function cashFlow(Request $request)
    {
        $entity = $this->authorizeFinancial();
        $entityId = (string) $entity->id;

        $from = (string) $request->input('from', now()->startOfMonth()->toDateString());
        $to = (string) $request->input('to', now()->toDateString());

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
                    'type' => $type,
                    'total' => (float) $group->sum('amount'),
                ];
            })
            ->values()
            ->sortBy(['type', 'category'])
            ->values();

        $byDay = $entries
            ->groupBy(fn ($entry) => $entry->entry_date->format('Y-m-d'))
            ->map(fn ($group, $day) => [
                'day' => $day,
                'income' => (float) $group->where('type', 'income')->sum('amount'),
                'expense' => (float) $group->where('type', 'expense')->sum('amount'),
            ])
            ->values();

        $meta = [
            'title' => 'Relatório de Fluxo de Caixa',
            'action' => 'Financeiro',
            'breadcrumbs' => [
                ['label' => __('actions.sidemenu.dashboard'), 'url' => route('panel.dashboard'), 'active' => false],
                ['label' => 'Relatórios Financeiros', 'url' => route('panel.financial.reports.index'), 'active' => false],
                ['label' => 'Fluxo de Caixa', 'url' => 'javascript:void(0)', 'active' => true],
            ],
        ];

        return view('system.financial.reports.cashflow', compact(
            'meta',
            'entries',
            'summary',
            'byCategory',
            'byDay',
            'from',
            'to'
        ));
    }

    public function covenants(Request $request)
    {
        $entity = $this->authorizeFinancial();
        $entityId = (string) $entity->id;

        $from = (string) $request->input('from', now()->startOfMonth()->toDateString());
        $to = (string) $request->input('to', now()->toDateString());

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
            'total_paid' => (float) $claims
                ->filter(fn ($claim) => $claim->status->value === 'paid')
                ->sum('paid_amount'),
            'total_denied' => (float) $claims->sum('glosa_amount'),
        ];

        $byCovenant = $claims
            ->groupBy(fn ($claim) => $claim->covenant?->name ?? 'Sem convênio')
            ->map(fn ($group, $name) => [
                'covenant' => $name,
                'claims' => $group->count(),
                'amount' => (float) $group->sum('amount'),
                'paid' => (float) $group->sum('paid_amount'),
                'denied' => (float) $group->sum('glosa_amount'),
            ])
            ->values()
            ->sortByDesc('amount')
            ->values();

        $meta = [
            'title' => 'Relatório de Faturamento por Convênio',
            'action' => 'Financeiro',
            'breadcrumbs' => [
                ['label' => __('actions.sidemenu.dashboard'), 'url' => route('panel.dashboard'), 'active' => false],
                ['label' => 'Relatórios Financeiros', 'url' => route('panel.financial.reports.index'), 'active' => false],
                ['label' => 'Faturamento Convênios', 'url' => 'javascript:void(0)', 'active' => true],
            ],
        ];

        return view('system.financial.reports.covenants', compact(
            'meta',
            'claims',
            'summary',
            'byCovenant',
            'from',
            'to'
        ));
    }

    public function exportCashFlowCsv(Request $request)
    {
        $entity = $this->authorizeFinancial();
        $entityId = (string) $entity->id;

        $from = (string) $request->input('from', now()->startOfMonth()->toDateString());
        $to = (string) $request->input('to', now()->toDateString());

        $entries = FinancialCashEntry::query()
            ->with('category')
            ->where('entity_id', $entityId)
            ->whereBetween('entry_date', [$from, $to])
            ->whereNull('deleted_at')
            ->orderBy('entry_date')
            ->get();

        $rows = [];
        $rows[] = ['Data', 'Código', 'Descrição', 'Tipo', 'Status', 'Categoria', 'Valor'];

        foreach ($entries as $entry) {
            $rows[] = [
                $entry->entry_date?->format('d/m/Y'),
                $entry->code,
                $entry->description,
                $entry->type->label(),
                $entry->status->label(),
                $entry->category?->name ?? 'Sem categoria',
                number_format((float) $entry->amount, 2, '.', ''),
            ];
        }

        return $this->csvResponse($rows, "fluxo_caixa_{$from}_{$to}.csv");
    }

    public function exportCovenantsCsv(Request $request)
    {
        $entity = $this->authorizeFinancial();
        $entityId = (string) $entity->id;

        $from = (string) $request->input('from', now()->startOfMonth()->toDateString());
        $to = (string) $request->input('to', now()->toDateString());

        $claims = BillingClaim::query()
            ->with(['covenant', 'patient.person'])
            ->where('entity_id', $entityId)
            ->whereBetween('attendance_date', [$from, $to])
            ->whereNull('deleted_at')
            ->orderBy('attendance_date')
            ->get();

        $rows = [];
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
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
        ]);
    }
}
