<?php

declare(strict_types=1);

namespace App\Http\Controllers\Financial;

use App\Enums\{ClientRule, EntityGate};
use App\Exceptions\Financial\CashPeriodClosedException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Financial\{CashCloseRequest, ReopenCashCloseRequest};
use App\Models\{CashClose, Entity};
use App\Services\Financial\CashClosingService;
use App\Support\ReportPeriod;
use Illuminate\Http\{RedirectResponse, Request};
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\{Auth, Gate};
use Inertia\{Inertia, Response as InertiaResponse};

/**
 * Fechamento de caixa por período (portado do lock/unlock do smart_oftal).
 */
class CashClosingController extends Controller
{
    public function __construct(
        private readonly CashClosingService $service,
    ) {
    }

    public function index(Request $request): InertiaResponse
    {
        $entity   = $this->authorizeFinancial();
        $entityId = (string) $entity->id;
        $today    = now()->toDateString();

        $lastCloseEnd = $this->lastCloseEnd($entityId);

        // Período sugerido: do dia seguinte ao último fechamento ativo (sem
        // buraco entre fechamentos) até hoje; sem fechamento, o mês atual.
        $defaultFrom = $lastCloseEnd !== null
            ? Carbon::parse($lastCloseEnd)->addDay()->toDateString()
            : now()->startOfMonth()->toDateString();
        $defaultFrom = min($defaultFrom, $today);

        // Datas inválidas na URL (from=abc) caíam cruas no whereBetween → 500.
        // Fechamento não aceita futuro: a prévia também não.
        [$from, $to] = ReportPeriod::resolve($request->query('from'), $request->query('to'), $defaultFrom, $today);
        $to          = min($to, $today);
        $from        = min($from, $to);

        return Inertia::render('Panel/Financial/CashClosing/Index', [
            'breadcrumbs' => [
                ['label' => __('actions.sidemenu.dashboard'), 'url' => route('panel.dashboard'), 'active' => false],
                ['label' => __('financial_cash_closing.breadcrumb_financial'), 'url' => route('panel.financial.bi.index'), 'active' => false],
                ['label' => __('financial_cash_closing.breadcrumb'), 'url' => '#', 'active' => true],
            ],
            // Closure: o reload parcial da prévia (only: preview/filters) não
            // refaz a paginação do histórico a cada troca de data.
            'closes' => fn () => CashClose::query()
                ->with('closedBy:id,name')
                ->where('entity_id', $entityId)
                ->orderByDesc('period_end')
                ->paginate(30)
                ->withQueryString()
                ->through(fn (CashClose $c) => [
                    'id'             => $c->id,
                    'period_start'   => $c->period_start?->format('Y-m-d'),
                    'period_end'     => $c->period_end?->format('Y-m-d'),
                    'total_income'   => (float) $c->total_income,
                    'total_expense'  => (float) $c->total_expense,
                    'balance'        => (float) $c->balance,
                    'closed_at'      => $c->closed_at?->toIso8601String(),
                    'closed_by_name' => $c->closedBy?->name,
                    'notes'          => $c->notes,
                ]),
            // Só leitura (sem lock); o snapshot é recalculado no servidor ao fechar.
            'preview'        => $this->service->preview($entityId, $from, $to),
            'filters'        => ['from' => $from, 'to' => $to],
            'last_close_end' => $lastCloseEnd,
            'today'          => $today,
            // A UI só mostra "Reabrir" para admin; quem garante é a rota (entity.role:admin).
            'can_reopen' => fn () => (bool) $request->user()?->hasAnyRoleInEntity($entity, [ClientRule::Admin]),
            't'          => fn () => trans('financial_cash_closing') + ['shared' => trans('financial_shared')],
            // Textos do ConfirmationWithReasonModal (lidos de `t_hardening`)
            // para a reabertura: mínimo de 10 caracteres e exemplo de caixa, em
            // vez do texto genérico do manager (mínimo 20, ticket de cliente).
            't_hardening' => fn () => array_merge(
                (array) trans('manager_hardening'),
                (array) trans('financial_cash_closing.reopen_reason_modal'),
            ),
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

        // Volta sem from/to: a tela já sugere o próximo período (dia seguinte
        // ao que acabou de ser fechado) em vez de mostrar o fechado como "sobreposto".
        return redirect()
            ->route('panel.financial.cash-closing.index')
            ->with('message', __('financial_cash_closing.closed'));
    }

    /**
     * Reabre o período. Só admin da clínica (entity.role:admin na rota) e com
     * motivo (ReopenCashCloseRequest); motivo, quem e quando ficam gravados no
     * fechamento e na auditoria.
     */
    public function destroy(ReopenCashCloseRequest $request, CashClose $cashClose): RedirectResponse
    {
        $entity = $this->authorizeFinancial();
        abort_unless((string) $cashClose->entity_id === (string) $entity->id, 403);

        $this->service->reopen($cashClose, (string) $request->validated('reason'), Auth::id());

        return back()->with('message', __('financial_cash_closing.reopened'));
    }

    /** Fim (Y-m-d) do fechamento ativo mais recente da clínica, ou null. */
    private function lastCloseEnd(string $entityId): ?string
    {
        $last = CashClose::query()
            ->where('entity_id', $entityId)
            ->whereNull('deleted_at')
            ->max('period_end');

        return $last !== null ? Carbon::parse((string) $last)->toDateString() : null;
    }

    private function authorizeFinancial(): Entity
    {
        $entity = Entity::query()->findOrFail(session('selected_entity_id'));
        Gate::authorize(EntityGate::ViewFinancial->value, $entity);

        return $entity;
    }
}
