<?php

declare(strict_types = 1);

namespace App\Http\Controllers\Financial;

use App\Enums\EntityGate;
use App\Http\Controllers\Controller;
use App\Http\Requests\Financial\CashEntryRequest;
use App\Models\{Entity, FinancialCashEntry, FinancialCategory};
use App\Services\Financial\CashFlowService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class CashFlowController extends Controller
{
    public function __construct(
        private readonly CashFlowService $cashFlowService
    ) {
        $this->titleController = 'Lançamento financeiro';
    }

    public function index(Request $request)
    {
        $entity = $this->authorizeFinancial();
        $entityId = (string) $entity->id;

        $from = (string) $request->input('from', now()->startOfMonth()->toDateString());
        $to = (string) $request->input('to', now()->toDateString());

        $query = FinancialCashEntry::query()
            ->with(['category', 'covenant', 'billingClaim'])
            ->where('entity_id', $entityId)
            ->whereBetween('entry_date', [$from, $to])
            ->whereNull('deleted_at');

        if ($request->filled('type')) {
            $query->where('type', $request->input('type'));
        }

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        if ($request->filled('category_id')) {
            $query->where('category_id', $request->input('category_id'));
        }

        $entries = $query
            ->orderByDesc('entry_date')
            ->orderByDesc('created_at')
            ->paginate(30)
            ->withQueryString();

        $categories = FinancialCategory::query()
            ->availableForEntity($entityId)
            ->orderBy('type')
            ->orderBy('name')
            ->get();

        $summary = $this->cashFlowService->summary($entityId, $from, $to);

        $meta = [
            'title' => 'Fluxo de Caixa',
            'action' => 'Financeiro',
            'breadcrumbs' => [
                ['label' => __('actions.sidemenu.dashboard'), 'url' => route('panel.dashboard'), 'active' => false],
                ['label' => 'Financeiro', 'url' => route('panel.financial.cash-flow.index'), 'active' => false],
                ['label' => 'Fluxo de Caixa', 'url' => 'javascript:void(0)', 'active' => true],
            ],
        ];

        return view('system.financial.cashflow.index', compact(
            'meta',
            'entries',
            'categories',
            'summary',
            'from',
            'to'
        ));
    }

    public function store(CashEntryRequest $request): RedirectResponse|JsonResponse
    {
        $this->authorizeFinancial();

        $entry = $this->cashFlowService->create($request->validated());

        if ($request->expectsJson()) {
            return response()->json([
                'message' => $this->getCreateMessage(),
                'data' => $entry->fresh(['category', 'covenant']),
            ]);
        }

        return back()->with('message', $this->getCreateMessage());
    }

    public function update(CashEntryRequest $request, FinancialCashEntry $entry): RedirectResponse|JsonResponse
    {
        $this->authorizeFinancial();

        $entry = $this->cashFlowService->update($entry, $request->validated());

        if ($request->expectsJson()) {
            return response()->json([
                'message' => $this->getUpdateMessage($request),
                'data' => $entry->fresh(['category', 'covenant']),
            ]);
        }

        return back()->with('message', $this->getUpdateMessage($request));
    }

    public function destroy(FinancialCashEntry $entry): RedirectResponse
    {
        $this->authorizeFinancial();

        $entry->delete();

        return back()->with('message', $this->getDeleteMessage());
    }

    private function authorizeFinancial(): Entity
    {
        $entity = Entity::query()->findOrFail(session('selected_entity_id'));
        Gate::authorize(EntityGate::ViewFinancial->value, $entity);

        return $entity;
    }
}
