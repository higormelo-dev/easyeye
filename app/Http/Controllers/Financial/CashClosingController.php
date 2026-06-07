<?php

declare(strict_types=1);

namespace App\Http\Controllers\Financial;

use App\Enums\EntityGate;
use App\Exceptions\Financial\CashPeriodClosedException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Financial\CashCloseRequest;
use App\Models\{CashClose, Entity};
use App\Services\Financial\{CashClosingService, CashFlowService};
use Illuminate\Http\{RedirectResponse, Request};
use Illuminate\Support\Facades\{Auth, Gate};
use Inertia\{Inertia, Response as InertiaResponse};

/**
 * Fechamento de caixa por período (portado do lock/unlock do smart_oftal).
 */
class CashClosingController extends Controller
{
    public function __construct(
        private readonly CashClosingService $service,
        private readonly CashFlowService $cashFlow,
    ) {
        $this->titleController = 'Fechamento de caixa';
    }

    public function index(Request $request): InertiaResponse
    {
        $entity   = $this->authorizeFinancial();
        $entityId = (string) $entity->id;

        $closes = CashClose::query()
            ->where('entity_id', $entityId)
            ->orderByDesc('period_end')
            ->get()
            ->map(fn (CashClose $c) => [
                'id'            => $c->id,
                'period_start'  => $c->period_start?->format('Y-m-d'),
                'period_end'    => $c->period_end?->format('Y-m-d'),
                'total_income'  => (float) $c->total_income,
                'total_expense' => (float) $c->total_expense,
                'balance'       => (float) $c->balance,
                'closed_at'     => $c->closed_at?->format('Y-m-d H:i'),
                'notes'         => $c->notes,
            ]);

        $from    = (string) $request->input('from', now()->startOfMonth()->toDateString());
        $to      = (string) $request->input('to', now()->toDateString());
        $preview = $this->cashFlow->summary($entityId, $from, $to);

        return Inertia::render('Panel/Financial/CashClosing/Index', [
            'breadcrumbs' => [
                ['label' => __('actions.sidemenu.dashboard'), 'url' => route('panel.dashboard'), 'active' => false],
                ['label' => 'Financeiro', 'url' => route('panel.financial.cash-flow.index'), 'active' => false],
                ['label' => 'Fechamento de Caixa', 'url' => '#', 'active' => true],
            ],
            'closes'  => $closes,
            'preview' => $preview,
            'filters' => ['from' => $from, 'to' => $to],
            't'       => trans('financial'),
        ]);
    }

    public function store(CashCloseRequest $request): RedirectResponse
    {
        $entity = $this->authorizeFinancial();
        $data   = $request->validated();

        try {
            $this->service->closePeriod(
                (string) $entity->id,
                $data['period_start'],
                $data['period_end'],
                Auth::id(),
                $data['notes'] ?? null,
            );
        } catch (CashPeriodClosedException $e) {
            return back()->withErrors(['period_start' => $e->getMessage()]);
        }

        return back()->with('message', __('financial.cc_closed'));
    }

    public function destroy(CashClose $cashClose): RedirectResponse
    {
        $entity = $this->authorizeFinancial();
        abort_unless((string) $cashClose->entity_id === (string) $entity->id, 403);

        $this->service->reopen($cashClose);

        return back()->with('message', __('financial.cc_reopened'));
    }

    private function authorizeFinancial(): Entity
    {
        $entity = Entity::query()->findOrFail(session('selected_entity_id'));
        Gate::authorize(EntityGate::ViewFinancial->value, $entity);

        return $entity;
    }
}
