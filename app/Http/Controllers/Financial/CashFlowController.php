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
use Inertia\{Inertia, Response as InertiaResponse};

class CashFlowController extends Controller
{
    public function __construct(
        private readonly CashFlowService $cashFlowService
    ) {
        $this->titleController = 'Lançamento financeiro';
    }

    public function index(Request $request): InertiaResponse
    {
        $entity   = $this->authorizeFinancial();
        $entityId = (string) $entity->id;

        $from = (string) $request->input('from', now()->startOfMonth()->toDateString());
        $to   = (string) $request->input('to', now()->toDateString());

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
            ->withQueryString()
            ->through(fn (FinancialCashEntry $e) => [
                'id'             => $e->id,
                'entry_date'     => $e->entry_date?->format('Y-m-d'),
                'description'    => $e->description,
                'type'           => $e->type instanceof \BackedEnum ? $e->type->value : $e->type,
                'status'         => $e->status instanceof \BackedEnum ? $e->status->value : $e->status,
                'amount'         => (float) $e->amount,
                'category_name'  => $e->category?->name,
                'category_id'    => $e->category_id,
                'covenant_name'  => $e->covenant?->name,
                'covenant_id'    => $e->covenant_id,
                'has_claim'      => $e->billingClaim !== null,
                'notes'          => $e->notes,
            ]);

        $categories = FinancialCategory::query()
            ->availableForEntity($entityId)
            ->orderBy('type')
            ->orderBy('name')
            ->get(['id', 'name', 'type']);

        $summary = $this->cashFlowService->summary($entityId, $from, $to);

        return Inertia::render('Panel/Financial/CashFlow/Index', [
            'breadcrumbs' => [
                ['label' => __('actions.sidemenu.dashboard'), 'url' => route('panel.dashboard'),               'active' => false],
                ['label' => 'Financeiro',                     'url' => route('panel.financial.cash-flow.index'), 'active' => false],
                ['label' => 'Fluxo de Caixa',                 'url' => '#',                                     'active' => true],
            ],
            'entries'    => $entries,
            'categories' => $categories,
            'summary'    => $summary,
            'filters'    => [
                'from'        => $from,
                'to'          => $to,
                'type'        => $request->input('type'),
                'status'      => $request->input('status'),
                'category_id' => $request->input('category_id'),
            ],
            't' => trans('financial'),
        ]);
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
