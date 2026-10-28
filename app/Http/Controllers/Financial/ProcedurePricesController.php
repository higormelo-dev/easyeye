<?php

declare(strict_types=1);

namespace App\Http\Controllers\Financial;

use App\Domains\Tiss\Actions\ResolveTissOperatorForCovenantAction;
use App\Enums\{EntityGate, Permission};
use App\Http\Controllers\Controller;
use App\Http\Requests\Financial\ProcedurePriceRequest;
use App\Models\{Covenant, Entity, Procedure};
use App\Services\Financial\ProcedurePriceService;
use App\Support\ReportPeriod;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\{RedirectResponse, Request};
use Illuminate\Support\Facades\Gate;
use Inertia\{Inertia, Response as InertiaResponse};

/**
 * Gestão da tabela de preço por procedimento × convênio (portada do smart_oftal).
 * Tela dedicada: seletor de convênio + grade de procedimentos com preço editável.
 */
class ProcedurePricesController extends Controller
{
    public function __construct(
        private readonly ProcedurePriceService $service,
        private readonly ResolveTissOperatorForCovenantAction $tissOperator,
    ) {
        $this->titleController = 'Tabela de preços';
    }

    public function index(Request $request): InertiaResponse
    {
        $entity   = $this->authorizeFinancial();
        $entityId = (string) $entity->id;

        // `tiss`: convênio com operadora TISS (registro ANS) — só nele o
        // "Cobrar do convênio (guia TISS)" pode ser ligado (o registro ANS em si
        // não vai para a tela).
        $covenants = Covenant::query()
            ->where(fn ($q) => $q->where('entity_id', $entityId)->orWhereNull('entity_id'))
            ->where('active', true)
            ->orderBy('name')
            ->get(['id', 'name', 'ans_registry'])
            ->map(fn (Covenant $covenant) => [
                'id'   => (string) $covenant->id,
                'name' => $covenant->name,
                'tiss' => $this->tissOperator->isEligible($covenant),
            ])
            ->values();

        $procedures = Procedure::query()
            ->where(fn ($q) => $q->where('entity_id', $entityId)->orWhereNull('entity_id'))
            ->where('active', true)
            ->orderBy('name')
            ->get(['id', 'code', 'name']);

        $covenantIds = $covenants->pluck('id')->all();
        $covenantId  = $this->selectedCovenantId($request, $covenantIds);

        return Inertia::render('Panel/Financial/ProcedurePrices/Index', [
            'breadcrumbs' => [
                ['label' => __('actions.sidemenu.dashboard'), 'url' => route('panel.dashboard'), 'active' => false],
                ['label' => __('financial_procedure_prices.breadcrumb_financial'), 'url' => route('panel.financial.cash-flow.index'), 'active' => false],
                ['label' => __('financial_procedure_prices.title'), 'url' => '#', 'active' => true],
            ],
            'covenants'          => $covenants,
            'procedures'         => $procedures,
            'selectedCovenantId' => $covenantId,
            // Closures: a recarga parcial do "Copiar de outro convênio" não os recalcula.
            'prices' => fn () => $covenantId !== '' ? $this->service->pricesForCovenant($entityId, $covenantId) : [],
            // Preço padrão do sistema (linha global) — placeholder quando a clínica não tem preço próprio.
            'inheritedPrices' => fn () => $covenantId !== '' ? $this->service->inheritedPricesForCovenant($covenantId) : [],
            // "Copiar de outro convênio": só na recarga parcial (only: ['sourcePrices'],
            // ?source_covenant_id=) — sem rota nova; nunca na carga normal da página.
            'sourcePrices' => Inertia::optional(fn () => $this->sourcePrices($request, $entityId, $covenantIds, $procedures)),
            // Teto de linhas por salvamento: a tela avisa antes de enviar.
            'limits' => ['max_items' => ProcedurePriceRequest::MAX_ITEMS],
            // Estado vazio: link para cadastrar convênio só para quem pode abrir a tela.
            'links' => [
                'covenants' => $request->user()?->hasPermissionInEntity($entity, Permission::SettingsManage)
                    ? route('panel.setting.covenants.index')
                    : null,
            ],
            't' => trans('financial_procedure_prices'),
        ]);
    }

    public function store(ProcedurePriceRequest $request): RedirectResponse
    {
        $entity = $this->authorizeFinancial();

        $data = $request->validated();
        $this->service->syncForCovenant((string) $entity->id, $data['covenant_id'], $data['items']);

        // 'success' é o flash exibido pelo AppLayout (o 'message' não aparecia).
        return back()->with('success', __('financial_procedure_prices.saved'));
    }

    /**
     * Preços do convênio de origem do "Copiar de outro convênio": o que a grade
     * dele mostra (preço da clínica ou, sem ele, o padrão do sistema), só dos
     * procedimentos da grade. Convênio fora da lista da clínica (outra
     * clínica, inativo, excluído) ou id inválido → null, sem dado algum.
     *
     * @param list<string>               $covenantIds convênios da tela (da clínica ou globais, ativos)
     * @param Collection<int, Procedure> $procedures  procedimentos da grade
     *
     * @return array{covenant_id: string, prices: array<string, float>}|null
     */
    private function sourcePrices(Request $request, string $entityId, array $covenantIds, Collection $procedures): ?array
    {
        $sourceId = ReportPeriod::uuidOrNull($request->query('source_covenant_id'));

        if ($sourceId === null || ! in_array($sourceId, $covenantIds, true)) {
            return null;
        }

        $gridIds = $procedures->mapWithKeys(fn (Procedure $procedure) => [(string) $procedure->id => true])->all();

        return [
            'covenant_id' => $sourceId,
            'prices'      => array_filter(
                $this->service->effectivePricesForCovenant($entityId, $sourceId),
                fn (string $procedureId) => isset($gridIds[$procedureId]),
                ARRAY_FILTER_USE_KEY,
            ),
        ];
    }

    /**
     * covenant_id da query só vale se for UUID de um convênio listado (da clínica
     * ou global, ativo); senão cai no primeiro — antes `?covenant_id=abc` chegava
     * cru ao PostgreSQL (erro 500) e um id de outra clínica era ecoado na tela.
     *
     * @param list<string> $allowedIds
     */
    private function selectedCovenantId(Request $request, array $allowedIds): string
    {
        $requested = ReportPeriod::uuidOrNull($request->query('covenant_id'));

        if ($requested !== null && in_array($requested, $allowedIds, true)) {
            return $requested;
        }

        return $allowedIds[0] ?? '';
    }

    private function authorizeFinancial(): Entity
    {
        $entity = Entity::query()->findOrFail(session('selected_entity_id'));
        Gate::authorize(EntityGate::ViewFinancial->value, $entity);

        return $entity;
    }
}
