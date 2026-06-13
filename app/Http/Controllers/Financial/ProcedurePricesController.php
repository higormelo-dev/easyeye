<?php

declare(strict_types=1);

namespace App\Http\Controllers\Financial;

use App\Enums\EntityGate;
use App\Http\Controllers\Controller;
use App\Http\Requests\Financial\ProcedurePriceRequest;
use App\Models\{Covenant, Entity, Procedure};
use App\Services\Financial\ProcedurePriceService;
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
    ) {
        $this->titleController = 'Tabela de preços';
    }

    public function index(Request $request): InertiaResponse
    {
        $entity   = $this->authorizeFinancial();
        $entityId = (string) $entity->id;

        $covenants = Covenant::query()
            ->where(fn ($q) => $q->where('entity_id', $entityId)->orWhereNull('entity_id'))
            ->where('active', true)
            ->orderBy('name')
            ->get(['id', 'name']);

        $procedures = Procedure::query()
            ->where(fn ($q) => $q->where('entity_id', $entityId)->orWhereNull('entity_id'))
            ->where('active', true)
            ->orderBy('name')
            ->get(['id', 'code', 'name']);

        $covenantId = (string) $request->input('covenant_id', $covenants->first()?->id ?? '');
        $prices     = $covenantId !== '' ? $this->service->pricesForCovenant($entityId, $covenantId) : [];

        return Inertia::render('Panel/Financial/ProcedurePrices/Index', [
            'breadcrumbs' => [
                ['label' => __('actions.sidemenu.dashboard'), 'url' => route('panel.dashboard'), 'active' => false],
                ['label' => 'Financeiro', 'url' => route('panel.financial.cash-flow.index'), 'active' => false],
                ['label' => 'Tabela de Preços', 'url' => '#', 'active' => true],
            ],
            'covenants'          => $covenants,
            'procedures'         => $procedures,
            'selectedCovenantId' => $covenantId,
            'prices'             => $prices,
            't'                  => trans('financial'),
        ]);
    }

    public function store(ProcedurePriceRequest $request): RedirectResponse
    {
        $entity = $this->authorizeFinancial();

        $data = $request->validated();
        $this->service->syncForCovenant((string) $entity->id, $data['covenant_id'], $data['items']);

        return back()->with('message', __('financial.pp_saved'));
    }

    private function authorizeFinancial(): Entity
    {
        $entity = Entity::query()->findOrFail(session('selected_entity_id'));
        Gate::authorize(EntityGate::ViewFinancial->value, $entity);

        return $entity;
    }
}
