<?php

declare(strict_types=1);

namespace App\Http\Controllers\Setting;

use App\DTOs\ActionPolicy;
use App\Http\Controllers\Controller;
use App\Services\BaseSettingService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\{JsonResponse, RedirectResponse, Request};
use Illuminate\Support\Facades\DB;
use Inertia\{Inertia, Response as InertiaResponse};

/**
 * Base abstrata dos catálogos clínicos (tipos de pele, íris, lentes, convênios...).
 *
 * Todos os catálogos seguem o mesmo CRUD: list/cards/search/restore/delete +
 * form modal com campos variáveis. Em vez de cada um ter sua própria página Vue,
 * uma página GENÉRICA (`Panel/Settings/Catalog/Index.vue`) é renderizada com
 * `columns` (estrutura da tabela) e `fields` (estrutura do form) injetados via
 * Inertia props. Cada controller filho só precisa configurar o schema.
 *
 * Hardening:
 *  - `restore` exige justificativa via reason (TODO em uma próxima etapa).
 *  - Mass-assignment limitado aos `crudFields` declarados pelo controller filho.
 *  - Tenant isolation: query sempre filtra por entity_id ativo ou globais (null).
 */
abstract class BaseSettingController extends Controller
{
    protected BaseSettingService $service;

    protected string $resourceClass;

    /** Nome da rota base (ex.: `panel.setting.skintypes`). */
    protected string $routePrefix;

    /** Chave usada em traduções/UI para identificar o módulo (ex.: `skintypes`). */
    protected string $viewSlot = '';

    /** Campos editáveis (whitelist mass-assignment para o form). */
    protected array $crudFields = ['name' => '', 'active' => true];

    /**
     * Estrutura das COLUNAS da tabela. Sobrescrever no filho quando precisar
     * adicionar colunas extras (ex.: `abbreviation`, `category`, `color`).
     *
     * Cada coluna: ['key' => 'name', 'label' => 'Nome', 'type' => 'text']
     * Tipos suportados pelo Vue: text | code | color | abbrev | yesno | scale | numeric
     *
     * @return list<array{key: string, label: string, type?: string, sortable?: bool}>
     */
    protected function getColumns(): array
    {
        return [
            ['key' => 'code', 'label' => __('actions.code'), 'type' => 'code'],
            ['key' => 'name', 'label' => __('actions.name'), 'type' => 'text', 'sortable' => true],
        ];
    }

    /**
     * Estrutura dos CAMPOS do form modal. Sobrescrever para adicionar/customizar.
     * Tipos: text | select | color | checkbox | numeric.
     *
     * @return list<array{key: string, label: string, type: string, required?: bool, hint?: string, options?: array}>
     */
    protected function getFormFields(): array
    {
        return [
            ['key' => 'name', 'label' => __('actions.name'), 'type' => 'text', 'required' => true],
        ];
    }

    public function index(Request $request): InertiaResponse
    {
        return Inertia::render('Panel/Settings/Catalog/Index', [
            'meta' => [
                'title'      => $this->titleController,
                'total'      => $this->service->count(),
                'cardsUrl'   => route($this->routePrefix . '.cards'),
                'storageKey' => ($this->viewSlot ?: 'catalog') . '_view',
            ],
            'breadcrumbs' => [
                ['label' => __('actions.sidemenu.dashboard'), 'url' => route('panel.dashboard'), 'active' => false],
                ['label' => $this->titleController, 'url' => '#', 'active' => true],
            ],
            'columns'    => $this->getColumns(),
            'fields'     => $this->getFormFields(),
            'crudFields' => $this->crudFields,
            'routes'     => [
                'index' => route($this->routePrefix . '.index'),
                'store' => route($this->routePrefix . '.store'),
                'cards' => route($this->routePrefix . '.cards'),
            ],
            'urlTemplates' => [
                // Vue substitui {id} no client (evita 11 routes :id na hidratação).
                'show'    => route($this->routePrefix . '.show', ['placeholder' => '__ID__']),
                'update'  => route($this->routePrefix . '.update', ['placeholder' => '__ID__']),
                'destroy' => route($this->routePrefix . '.destroy', ['placeholder' => '__ID__']),
                'restore' => route($this->routePrefix . '.restore', ['placeholder' => '__ID__']),
            ],
            'items'   => $this->fetchTableRows($request),
            'filters' => [
                'search' => $request->string('search')->trim()->value(),
                'sort'   => $request->string('sort', 'name')->value(),
                'dir'    => $request->string('dir', 'asc')->value(),
            ],
            't' => trans('catalog_setting'),
        ]);
    }

    /**
     * Endpoint JSON para o modo "cards" (mesma listagem renderizada em grid).
     * Mantido com a mesma assinatura do legado para preservar o `cardsUrl`.
     */
    public function cards(Request $request): JsonResponse
    {
        $class    = $this->service->getModelClass();
        $search   = $request->string('search')->trim()->value();
        $perPage  = 12;
        $entityId = session('selected_entity_id');

        $records = $class::query()
            ->where(
                fn ($q) => $q
                    ->where('entity_id', $entityId)
                    ->orWhereNull('entity_id'),
            )
            ->when($search, fn ($q) => $q->whereRaw('LOWER(name) LIKE ?', ['%' . mb_strtolower($search, 'UTF-8') . '%']))
            ->orderBy('name')
            ->paginate($perPage);

        return response()->json([
            'data' => $records->map(fn ($r) => array_merge(
                $this->serializeRecord($r),
                ActionPolicy::from($r, $entityId)->toArray(),
            )),
            'meta' => [
                'total'        => $records->total(),
                'per_page'     => $records->perPage(),
                'current_page' => $records->currentPage(),
                'last_page'    => $records->lastPage(),
            ],
        ]);
    }

    /**
     * GET show — retorna JSON sempre (consumido pelo DetailDrawer Vue).
     */
    public function show(string $id): JsonResponse
    {
        $record = $this->service->findByIdOrCode($id);

        return response()->json(['data' => $this->serializeRecord($record)]);
    }

    /**
     * Dados para preencher o form modal de edição.
     * Retorna apenas os crudFields (whitelist).
     */
    public function edit(string $id): JsonResponse
    {
        $record = $this->service->findByIdOrCode($id);

        return response()->json([
            'data' => array_intersect_key(
                $record->only(array_keys($this->crudFields)) + ['id' => $record->id, 'code' => $record->code],
                array_flip(array_merge(array_keys($this->crudFields), ['id', 'code'])),
            ),
        ]);
    }

    public function destroy(Request $request, string $id): JsonResponse|RedirectResponse
    {
        $record = $this->service->findByIdOrCode($id);

        return DB::transaction(function () use ($request, $record) {
            $message    = $this->getDeleteMessage();
            $recordData = $record->toArray();
            $record->delete();

            if ($request->wantsJson() || $request->hasHeader('X-Inertia')) {
                return response()->json(['message' => $message, 'deleted' => $recordData]);
            }

            return redirect(route($this->routePrefix . '.index'))->with('message', $message);
        });
    }

    public function restore(Request $request, string $id): JsonResponse|RedirectResponse
    {
        $record = $this->service->findByIdOrCode($id);

        return DB::transaction(function () use ($request, $record) {
            $message    = $this->getRestoreMessage();
            $recordData = $record->toArray();
            $record->restore();

            if ($request->wantsJson() || $request->hasHeader('X-Inertia')) {
                return response()->json(['message' => $message, 'restored' => $recordData]);
            }

            return redirect(route($this->routePrefix . '.index'))->with('message', $message);
        });
    }

    protected function genericStore(FormRequest $request): JsonResponse|RedirectResponse
    {
        $record  = $this->service->create($request);
        $message = $this->getCreateMessage();

        if ($request->expectsJson() || $request->wantsJson() || $request->hasHeader('X-Inertia')) {
            return response()->json(['message' => $message, 'data' => new $this->resourceClass($record)]);
        }

        return redirect(route($this->routePrefix . '.index'))->with('message', $message);
    }

    protected function genericUpdate(FormRequest $request, string $id): JsonResponse|RedirectResponse
    {
        $record  = $this->service->findByIdOrCode($id);
        $updated = $this->service->update($record, $request);
        $message = $this->getUpdateMessage(request());

        if ($request->expectsJson() || $request->wantsJson() || $request->hasHeader('X-Inertia')) {
            return response()->json(['message' => $message, 'data' => new $this->resourceClass($updated)]);
        }

        return redirect(route($this->routePrefix . '.index'))->with('message', $message);
    }

    /**
     * Linha da tabela index — composta dinamicamente pelas colunas declaradas
     * pelo controller filho + ações canônicas (urls + policy).
     *
     * Estratégia: o controller só precisa declarar `getColumns()` com as keys
     * que importam; nós montamos o payload a partir delas. Isso evita escrever
     * `toTableRow` em cada catálogo (11 implementações idênticas).
     */
    protected function fetchTableRows(Request $request): array
    {
        $class    = $this->service->getModelClass();
        $entityId = session('selected_entity_id');
        $search   = $request->string('search')->trim()->value();
        $sort     = $request->string('sort', 'name')->value();
        $dir      = $request->string('dir', 'asc')->value() === 'desc' ? 'desc' : 'asc';

        $sortable = collect($this->getColumns())
            ->filter(fn ($c) => ($c['sortable'] ?? false) === true)
            ->pluck('key')
            ->push('name', 'code', 'created_at')
            ->unique()
            ->all();

        if (! \in_array($sort, $sortable, true)) {
            $sort = 'name';
        }

        $query = $class::query()
            ->withTrashed()
            ->where(
                fn ($q) => $q
                    ->where('entity_id', $entityId)
                    ->orWhereNull('entity_id'),
            );

        if ($search !== '') {
            $lower = mb_strtolower($search, 'UTF-8');
            $query->where(function ($q) use ($lower): void {
                $q->whereRaw('LOWER(name) LIKE ?', ["%{$lower}%"])
                    ->orWhereRaw('LOWER(code) LIKE ?', ["%{$lower}%"]);
            });
        }

        return $query->orderBy($sort, $dir)
            ->get()
            ->map(fn ($r) => array_merge(
                $this->serializeRecord($r),
                ActionPolicy::from($r, $entityId)->toArray(),
            ))
            ->all();
    }

    /**
     * Serializa um record para o payload da listagem/show.
     * Inclui as keys das colunas declaradas + os crudFields + metadata.
     */
    protected function serializeRecord(Model $record): array
    {
        $columnKeys = collect($this->getColumns())->pluck('key')->all();
        $formKeys   = array_keys($this->crudFields);
        $keys       = array_unique(array_merge($columnKeys, $formKeys, ['id', 'code', 'name', 'active']));

        $data = [];

        foreach ($keys as $key) {
            if ($key === 'active') {
                $data[$key] = (bool) $record->{$key};

                continue;
            }
            $data[$key] = $record->{$key};
        }

        $data['deleted']    = $record->deleted_at !== null;
        $data['created_at'] = $record->created_at?->format('d/m/Y H:i');
        $data['is_global']  = $record->entity_id === null;

        return $data;
    }

    // ── Helpers de mensagem — sobrescrever via traits ou métodos no filho ──

    protected function getCreateMessage(): string
    {
        return __('catalog_setting.created');
    }

    protected function getUpdateMessage(Request $request): string
    {
        return __('catalog_setting.updated');
    }

    protected function getDeleteMessage(): string
    {
        return __('catalog_setting.deleted');
    }

    protected function getRestoreMessage(): string
    {
        return __('catalog_setting.restored');
    }
}
