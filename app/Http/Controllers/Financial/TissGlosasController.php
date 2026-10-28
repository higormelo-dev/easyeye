<?php

declare(strict_types=1);

namespace App\Http\Controllers\Financial;

use App\Domains\Tiss\Actions\{OpenGlosaAppealAction, ResolveGlosaAppealAction, SubmitGlosaAppealAction};
use App\Domains\Tiss\Enums\TissAppealStatus;
use App\Domains\Tiss\Models\{TissGlosa, TissGlosaAppeal};
use App\Enums\EntityGate;
use App\Exceptions\Financial\{AppealNumberUnavailableException, GlosaNotAppealableException};
use App\Http\Controllers\Controller;
use App\Models\{Covenant, Entity};
use App\Services\Financial\GlosaQueueService;
use App\Support\ReportPeriod;
use Illuminate\Http\{RedirectResponse, Request};
use Illuminate\Support\{Arr, Number};
use Illuminate\Support\Facades\{DB, Gate};
use Illuminate\Validation\Rule;
use Inertia\{Inertia, Response as InertiaResponse};

/**
 * Conciliação de glosas como fila de trabalho.
 *
 *  - Aba "Pendentes" (padrão): glosas Abertas/Recorridas de QUALQUER data, pelo
 *    prazo da próxima ação (vencidas primeiro). "Resolvidas" e "Todas" são o
 *    histórico, pelo período (data de identificação).
 *  - Lista paginada (PER_PAGE); KPIs, contagem das abas e resumo por
 *    operadora são agregados SQL sobre a mesma base escopada (clínica +
 *    operadora [+ busca nas abas]) — nenhuma glosa fora da página é carregada.
 *  - KPIs da fila (em aberto, recorridas, vencidas, vencendo) independem do
 *    período; "Recuperado" é do período. Todos respeitam o convênio/operadora.
 *  - Detalhe (painel lateral) por recarga parcial: `?detail=<id>` com
 *    `only: ['glosaDetail']` — sem rota nova.
 *
 * Tudo escopado pela clínica da sessão (entity_id); parâmetros inválidos caem
 * no padrão em vez de virar erro 500.
 */
class TissGlosasController extends Controller
{
    /** Justificativa mínima do recurso (antes só validada no front). */
    private const APPEAL_REASON_MIN = 10;

    private const NOTES_MAX = 1000;

    /** Mesmo tamanho de página da fila (mantido aqui para quem já referencia). */
    public const PER_PAGE = GlosaQueueService::PER_PAGE;

    /** Textos do modal "Importar retorno TISS", reaproveitado do Faturamento. */
    private const IMPORT_TEXT_KEYS = [
        'import_return_title', 'import_return_hint', 'import_return_covenant', 'import_return_no_covenants',
        'import_return_file', 'import_return_btn', 'select', 'btn_cancel', 'processing',
    ];

    public function __construct(
        private readonly GlosaQueueService $queue,
        private readonly OpenGlosaAppealAction $openAppeal,
        private readonly SubmitGlosaAppealAction $submitGlosaAppeal,
        private readonly ResolveGlosaAppealAction $resolveGlosaAppeal,
    ) {
    }

    public function index(Request $request): InertiaResponse
    {
        $entityId = (string) $this->authorizeFinancial()->id;

        // Data inválida/ausente cai no mês atual e período invertido é trocado
        // (antes `?from=abc` virava erro 500 no Carbon).
        [$from, $to] = ReportPeriod::resolve(
            $request->query('from'),
            $request->query('to'),
            now()->startOfMonth()->toDateString(),
            now()->endOfMonth()->toDateString(),
        );

        $tab = ReportPeriod::text($request->query('tab'));
        $tab = in_array($tab, GlosaQueueService::TABS, true) ? $tab : GlosaQueueService::TAB_PENDING;

        // Operadora só vale se a clínica tiver glosa dela (nada de id "solto").
        $operators  = $this->queue->operatorOptions($entityId);
        $operatorId = ReportPeriod::uuidOrNull($request->query('operator_id'));
        $operatorId = $operatorId !== null && $operators->contains('id', $operatorId) ? $operatorId : null;

        $due = ReportPeriod::text($request->query('due'));

        $filters = [
            'tab'         => $tab,
            'from'        => $from,
            'to'          => $to,
            'status'      => $this->queue->statusFilter(ReportPeriod::text($request->query('status')), $tab),
            'operator_id' => $operatorId,
            'due'         => $tab === GlosaQueueService::TAB_PENDING && in_array($due, [GlosaQueueService::DUE_OVERDUE, GlosaQueueService::DUE_SOON], true) ? $due : null,
            'search'      => mb_substr(ReportPeriod::text($request->query('search')), 0, GlosaQueueService::SEARCH_MAX),
        ];

        $detailId = ReportPeriod::uuidOrNull($request->query('detail'));

        // Props em closure: a recarga parcial do detalhe (only: ['glosaDetail'])
        // não refaz lista, KPIs nem contagens.
        return Inertia::render('Panel/Financial/Tiss/GlosasIndex', [
            'breadcrumbs' => [
                ['label' => __('actions.sidemenu.dashboard'), 'url' => route('panel.dashboard'), 'active' => false],
                ['label' => __('financial_glosas.breadcrumb_financial'), 'url' => route('panel.financial.billing.index'), 'active' => false],
                ['label' => __('financial_glosas.title'), 'url' => '#', 'active' => true],
            ],
            'filters' => $filters,
            // "Hoje" do servidor (fuso da clínica) para o front calcular "vence em N dias".
            'today'     => now()->toDateString(),
            'summary'   => fn () => $this->queue->summary($entityId, $operatorId, $from, $to),
            'tabCounts' => fn () => $this->queue->tabCounts($entityId, $filters),
            // Paginator do Laravel (data, links, total...) para o TablePagination.
            'glosas'        => fn () => $this->queue->glosaPage($entityId, $filters),
            'byOperator'    => fn () => $this->queue->byOperator($entityId, $operatorId, $from, $to),
            'operators'     => $operators->values()->all(),
            'statusOptions' => $this->queue->statusOptions(),
            'glosaDetail'   => fn () => $detailId === null ? null : $this->queue->glosaDetail($entityId, $detailId),
            // Modal "Importar retorno TISS" (mesma rota do Faturamento).
            'covenants'       => fn () => $this->importCovenants($entityId),
            'importReturnUrl' => route('panel.financial.billing.import-return'),
            't'               => trans('financial_glosas') + [
                'shared' => trans('financial_shared'),
                'import' => Arr::only(trans('financial_billing'), self::IMPORT_TEXT_KEYS),
            ],
        ]);
    }

    public function appeal(Request $request, TissGlosa $glosa): RedirectResponse
    {
        $this->authorizeFinancial();

        abort_if((string) $glosa->entity_id !== session('selected_entity_id'), 403);
        abort_if(! $glosa->status->isActionable(), 409, __('financial_glosas.cannot_appeal'));

        $validated = $request->validate([
            'reason' => ['required', 'string', 'min:' . self::APPEAL_REASON_MIN, 'max:' . self::NOTES_MAX],
        ], [
            'reason.required' => __('financial_glosas.reason_required'),
            'reason.min'      => __('financial_glosas.reason_min', ['min' => self::APPEAL_REASON_MIN]),
            'reason.max'      => __('financial_glosas.reason_max', ['max' => self::NOTES_MAX]),
        ]);

        try {
            // A action relê a glosa com lock (duas abas/usuários não abrem dois
            // recursos para a mesma glosa), serializa a numeração da clínica e
            // usa o valor glosado relido como requested_amount.
            $appeal = ($this->openAppeal)($glosa, ['reason' => $validated['reason']]);
        } catch (GlosaNotAppealableException) {
            abort(409, __('financial_glosas.cannot_appeal'));
        } catch (AppealNumberUnavailableException) {
            // Colisão residual de número esgotou as tentativas: nada foi gravado.
            abort(409, __('financial_glosas.appeal_number_unavailable'));
        }

        return $this->backToList()
            ->with('success', __('financial_glosas.appeal_success', ['number' => $appeal->appeal_number]));
    }

    public function submitAppeal(Request $request, TissGlosaAppeal $appeal): RedirectResponse
    {
        $this->authorizeFinancial();

        abort_if((string) $appeal->entity_id !== session('selected_entity_id'), 403);
        abort_if(! $appeal->status->canBeSubmitted(), 409, __('financial_glosas.cannot_submit_appeal'));

        DB::transaction(function () use ($appeal): void {
            $locked = TissGlosaAppeal::query()->whereKey($appeal->id)->lockForUpdate()->firstOrFail();
            abort_if(! $locked->status->canBeSubmitted(), 409, __('financial_glosas.cannot_submit_appeal'));

            ($this->submitGlosaAppeal)($locked);
        });

        return $this->backToList()
            ->with('success', __('financial_glosas.appeal_submitted', ['number' => $appeal->appeal_number]));
    }

    public function resolveAppeal(Request $request, TissGlosaAppeal $appeal): RedirectResponse
    {
        $this->authorizeFinancial();

        abort_if((string) $appeal->entity_id !== session('selected_entity_id'), 403);
        abort_if(! $appeal->status->canBeResolved(), 409, __('financial_glosas.cannot_resolve_appeal'));

        $validated = $request->validate(
            $this->resolveRules($appeal),
            $this->resolveMessages($appeal),
        );

        DB::transaction(function () use ($appeal, $validated): void {
            $locked = TissGlosaAppeal::query()->whereKey($appeal->id)->lockForUpdate()->firstOrFail();
            abort_if(! $locked->status->canBeResolved(), 409, __('financial_glosas.cannot_resolve_appeal'));

            ($this->resolveGlosaAppeal)($locked, TissAppealStatus::from($validated['decision']), $validated);
        });

        return $this->backToList()
            ->with('success', __('financial_glosas.appeal_resolved', ['number' => $appeal->appeal_number]));
    }

    /**
     * "Aceito" exige valor > 0 e até o valor glosado; em "Rejeitado" o valor é
     * descartado (exclude_if) — um número que sobrou no formulário não é gravado.
     *
     * @return array<string, mixed>
     */
    private function resolveRules(TissGlosaAppeal $appeal): array
    {
        return [
            'decision'        => ['required', Rule::in([TissAppealStatus::Accepted->value, TissAppealStatus::Rejected->value])],
            'accepted_amount' => [
                'exclude_if:decision,' . TissAppealStatus::Rejected->value,
                'required_if:decision,' . TissAppealStatus::Accepted->value,
                'nullable',
                'numeric',
                // Centavos: a coluna é numeric(14,2) — 0.004/199.995 gravariam um valor
                // diferente do que decidiu o status da glosa.
                'decimal:0,2',
                'gt:0',
                'lte:' . number_format($this->glosaCeiling($appeal), 2, '.', ''),
            ],
            'result_notes' => ['nullable', 'string', 'max:' . self::NOTES_MAX],
        ];
    }

    /** @return array<string, string> */
    private function resolveMessages(TissGlosaAppeal $appeal): array
    {
        return [
            'decision.required'           => __('financial_glosas.decision_required'),
            'decision.in'                 => __('financial_glosas.decision_required'),
            'accepted_amount.required_if' => __('financial_glosas.accepted_amount_required'),
            'accepted_amount.numeric'     => __('financial_glosas.accepted_amount_numeric'),
            'accepted_amount.decimal'     => __('financial_glosas.accepted_amount_decimals'),
            'accepted_amount.gt'          => __('financial_glosas.accepted_amount_min'),
            'accepted_amount.lte'         => __('financial_glosas.accepted_amount_max', [
                'max' => Number::currency($this->glosaCeiling($appeal), 'BRL', app()->getLocale()),
            ]),
        ];
    }

    /** Teto do valor aceito: o valor glosado (fallback: valor solicitado no recurso). */
    private function glosaCeiling(TissGlosaAppeal $appeal): float
    {
        return (float) ($appeal->glosa?->amount ?? $appeal->requested_amount);
    }

    /**
     * Convênios da clínica (ou globais) com operadora TISS: opções do modal
     * "Importar retorno TISS".
     *
     * @return list<array{id: string, name: string, has_tiss_operator: bool}>
     */
    private function importCovenants(string $entityId): array
    {
        return Covenant::query()
            ->where(fn ($q) => $q->where('entity_id', $entityId)->orWhereNull('entity_id'))
            ->where('active', true)
            ->whereNotNull('tiss_operator_id')
            ->whereNull('deleted_at')
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (Covenant $c) => ['id' => (string) $c->id, 'name' => (string) $c->name, 'has_tiss_operator' => true])
            ->all();
    }

    /**
     * Volta para a lista na MESMA URL (com o período filtrado na query string);
     * antes redirecionava para o index sem from/to e a lista voltava ao mês
     * corrente enquanto os inputs ainda mostravam o período escolhido.
     */
    private function backToList(): RedirectResponse
    {
        return redirect()->back(302, [], route('panel.financial.tiss.glosas.index'));
    }

    private function authorizeFinancial(): Entity
    {
        $entity = Entity::query()->findOrFail(session('selected_entity_id'));
        Gate::authorize(EntityGate::ViewFinancial->value, $entity);

        return $entity;
    }
}
