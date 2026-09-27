<?php

declare(strict_types=1);

namespace App\Http\Controllers\Stock;

use App\Http\Controllers\Concerns\RedirectsToListing;
use App\Http\Controllers\Controller;
use App\Http\Requests\EntityIolLensRequest;
use App\Http\Resources\EntityIolLensResource;
use App\Models\EntityIolLens;
use App\Services\{IolLensCatalogService, IolLensStockBridgeService};
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\{JsonResponse, RedirectResponse, Request};
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Storage;
use Inertia\{Inertia, Response as InertiaResponse};

/**
 * CRUD do inventário de lentes IOL (catarata) DA CLÍNICA —
 * App\Models\EntityIolLens. Toda lente É um produto de estoque real (1:1
 * obrigatório com App\Models\EntityProduct) — criação/atualização/exclusão
 * do par delega pra App\Services\IolLensStockBridgeService; este controller
 * só resolve HTTP/upload/autocomplete do catálogo global.
 *
 * NÃO estende BaseSettingController: aquele controller genérico foi
 * desenhado pra catálogos simples (name/code/active) e serializa via
 * `serializeRecord()` dinâmico a partir de `getColumns()`/`crudFields` — não
 * comporta bem upload de imagem, faixa de dioptria e preço, campos que a
 * tabela genérica não tem. Segue o mesmo padrão manual de
 * ReportSettingsController (index Inertia + show/store/update/destroy com
 * checagem explícita de posse por entity_id, sem herdar do catálogo base).
 *
 * Isolamento OBRIGATÓRIO: entity_iol_lenses é dado DA CLÍNICA (diferente de
 * iol_lens_models, catálogo GLOBAL sem escopo, compartilhado entre todas as
 * clínicas) — toda query aqui filtra por entity_id da sessão, sem exceção;
 * update/destroy/show também re-checam posse no model resolvido pelo route
 * model binding (nunca confiar só no binding pra isolamento entre clínicas).
 *
 * MOVIDO pro namespace/menu/rotas de Estoque (era App\Http\Controllers\
 * Setting\IolLensesController, `panel.setting.iollenses.*`, fora do gate
 * `feature:has_inventory_module` de propósito) — decisão revertida a pedido
 * do usuário: cadastro de lente IOL agora É parte do módulo pago de
 * Estoque, sujeito ao mesmo gate duplo do resto do módulo
 * (`permission:stock.manage` + `feature:has_inventory_module`, ver
 * routes/web.php). Clínica sem o módulo contratado perde acesso ao cadastro
 * de lente — ver plano de migração (doc "Plano de Migração IOL → Estoque")
 * pra contexto completo dessa mudança e o redirect 301 da URL antiga.
 */
class IolLensesController extends Controller
{
    use RedirectsToListing;

    /**
     * Parâmetros da listagem preservados ao voltar de criar/editar/ativar/
     * excluir (RedirectsToListing) — busca, status, ordenação e página.
     */
    private const LISTING_PARAMS = ['search', 'status', 'sort', 'direction', 'page'];

    public function __construct(
        private readonly IolLensCatalogService $catalogService,
        private readonly IolLensStockBridgeService $bridge,
    ) {
    }

    /**
     * Colunas ordenáveis da listagem (whitelist) → coluna real no banco
     * (da lente ou do produto de estoque vinculado, via JOIN do index()).
     */
    private const SORTABLE = [
        'manufacturer' => 'entity_products.manufacturer',
        'model_name'   => 'entity_products.name',
        'category'     => 'entity_iol_lenses.category',
        'diopter_min'  => 'entity_iol_lenses.diopter_min',
        'price'        => 'entity_products.sale_price',
        'qty_on_hand'  => 'entity_products.qty_on_hand',
    ];

    /** Ordem padrão = a de sempre da tela (fabricante, depois modelo). */
    private const DEFAULT_SORT = 'manufacturer';

    private const DEFAULT_DIRECTION = 'asc';

    private const STATUSES = ['all', 'active', 'inactive'];

    public function index(Request $request): InertiaResponse
    {
        $entityId = (string) session('selected_entity_id');
        $search   = $this->queryText($request, 'search');
        $status   = $this->queryText($request, 'status', 'all');
        $sortBy   = $this->queryText($request, 'sort', self::DEFAULT_SORT);
        $sortDir  = $this->queryText($request, 'direction', self::DEFAULT_DIRECTION);

        // Normalizados: valor fora da whitelist cai no padrão (a UI mostra o
        // que foi realmente aplicado).
        $status  = in_array($status, self::STATUSES, true) ? $status : 'all';
        $sortBy  = array_key_exists($sortBy, self::SORTABLE) ? $sortBy : self::DEFAULT_SORT;
        $sortDir = in_array($sortDir, ['asc', 'desc'], true) ? $sortDir : self::DEFAULT_DIRECTION;

        // JOIN (não whereHas) pra poder ORDENAR por manufacturer/name do
        // produto vinculado — Eloquent não ordena por coluna de relação sem
        // join explícito. `select('entity_iol_lenses.*')` evita ambiguidade
        // de colunas homônimas (id/created_at/etc.) entre as duas tabelas.
        $query = EntityIolLens::query()
            ->join('entity_products', 'entity_products.id', '=', 'entity_iol_lenses.entity_product_id')
            ->where('entity_iol_lenses.entity_id', $entityId)
            ->with('entityProduct:id,name,manufacturer,code,unit,qty_on_hand,sale_price,image_path,active')
            ->when($search !== '', function ($query) use ($search) {
                $query->where(function ($q) use ($search) {
                    $q->whereLikeUnaccent('entity_products.manufacturer', $search)
                        ->orWhereLikeUnaccent('entity_products.name', $search);
                });
            })
            ->when($status === 'active', fn ($query) => $query->where('entity_products.active', true))
            ->when($status === 'inactive', fn ($query) => $query->where('entity_products.active', false))
            // NULLS LAST: fabricante/tipo/dioptria/valor são anuláveis e o
            // PostgreSQL põe NULL primeiro em DESC. Em ASC é o padrão do
            // banco (ordem de sempre inalterada). Coluna e direção vêm só da
            // whitelist acima, nunca crus do request.
            ->orderByRaw(self::SORTABLE[$sortBy] . ' ' . $sortDir . ' NULLS LAST')
            // Padrão de sempre: dentro do mesmo fabricante, modelo A→Z.
            ->when($sortBy === 'manufacturer', fn ($query) => $query->orderBy('entity_products.name'))
            // `id` desempata valores iguais — sem ele a ordem entre páginas
            // não é determinística no PostgreSQL.
            ->orderBy('entity_iol_lenses.id')
            ->select('entity_iol_lenses.*');

        $records = $this->paginateClamped($query, 12)
            ->withQueryString()
            // `->through()` preserva o paginator (current_page/last_page/
            // total/links) e só troca os itens — mesmo idioma usado em
            // PatientsController/CashFlowController/EntitiesController nesta
            // base. Passar `EntityIolLensResource::collection($records)`
            // direto como prop Inertia NÃO funciona: como não é a resposta
            // HTTP top-level, o PaginatedResourceResponse (que injeta
            // meta/links) nunca é acionado — só o toArray() plano rodaria,
            // perdendo a paginação no Vue.
            ->through(fn (EntityIolLens $record) => (new EntityIolLensResource($record))->resolve());

        return Inertia::render('Panel/Stock/IolLenses/Index', [
            'breadcrumbs' => [
                ['label' => __('actions.sidemenu.dashboard'), 'url' => route('panel.dashboard'), 'active' => false],
                ['label' => __('actions.sidemenu.stock'), 'url' => '#', 'active' => false],
                ['label' => __('actions.sidemenu.iol_lenses'), 'url' => '#', 'active' => true],
            ],
            'items'   => $records,
            'filters' => [
                'search'    => $search,
                'status'    => $status,
                'sort'      => $sortBy,
                'direction' => $sortDir,
            ],
            't'      => trans('stock_iollenses'),
            'routes' => [
                'index'  => route('panel.stock.iollenses.index'),
                'store'  => route('panel.stock.iollenses.store'),
                'search' => route('panel.stock.iollenses.search'),
                // Vue substitui {id} no client (evita gerar 1 rota por linha
                // na hidratação) — mesma convenção de BaseSettingController::
                // index() / AccessControl\RolesController::index().
                'show'    => route('panel.stock.iollenses.show', ['__ID__']),
                'update'  => route('panel.stock.iollenses.update', ['__ID__']),
                'destroy' => route('panel.stock.iollenses.destroy', ['__ID__']),
                // Atalho "Movimentações da lente" (filtra pelo produto vinculado).
                'movements_index' => route('panel.stock.movements.index'),
            ],
        ]);
    }

    /**
     * JSON pro modal de detalhe/edição aberto ao clicar num card — ver
     * decisão sobre manter `show` no resource no cabeçalho do arquivo de
     * rotas (routes/web.php, bloco iollenses).
     */
    public function show(EntityIolLens $entityIolLens): JsonResponse
    {
        $this->assertOwnership($entityIolLens);

        return response()->json(['data' => new EntityIolLensResource($entityIolLens->load('entityProduct:id,name,manufacturer,code,unit,qty_on_hand,sale_price,image_path,active'))]);
    }

    public function store(EntityIolLensRequest $request): RedirectResponse
    {
        $entityId = (string) session('selected_entity_id');
        $data     = $request->safe()->except(['image']);

        if ($request->hasFile('image')) {
            $data['image_path'] = $this->catalogService->storeImage(
                $request->file('image'),
                "iol-lenses/{$entityId}",
            );
        }

        // DECISÃO: findOrCreateModel() só roda quando o usuário está
        // cadastrando um modelo que NÃO veio do autocomplete
        // (iol_lens_model_id vazio) — se ele já escolheu um model_id
        // existente no picker, o catálogo global já tem esse modelo,
        // não há nada a registrar. Isso alimenta o catálogo global
        // organicamente (sem duplicar, graças ao normalized_key único
        // em IolLensCatalogService::findOrCreateModel()) só quando é
        // genuinamente informação nova: da próxima vez que qualquer
        // clínica digitar o mesmo fabricante+modelo, o autocomplete já
        // encontra. O novo item de inventário também é vinculado
        // (iol_lens_model_id) ao registro global recém-criado/reaproveitado.
        if (blank($data['iol_lens_model_id'] ?? null)) {
            $model = $this->catalogService->findOrCreateModel(
                $data['manufacturer'],
                $data['model_name'],
                $data['category'] ?? null,
                $entityId,
            );

            $data['iol_lens_model_id'] = $model->id;
        }

        $this->bridge->create($entityId, $data);

        return $this->redirectToListing('panel.stock.iollenses.index', self::LISTING_PARAMS)
            ->with('message', __('catalog_setting.created'));
    }

    public function update(EntityIolLensRequest $request, EntityIolLens $entityIolLens): RedirectResponse
    {
        $this->assertOwnership($entityIolLens);

        // DECISÃO: findOrCreateModel() NÃO roda aqui (só em store()). Editar
        // um item de inventário existente é frequentemente ajuste de texto
        // local que pode divergir intencionalmente do catálogo global —
        // replicar a lógica de auto-registro no update poluiria o catálogo
        // global a cada edição de detalhe específico da clínica.
        $data = $request->safe()->except(['image']);

        if ($request->hasFile('image')) {
            $oldPath = $entityIolLens->entityProduct->image_path;

            // Salva a NOVA imagem primeiro; só troca `image_path` (e só
            // apaga a antiga do disco) se o storeImage() não lançar — nunca
            // fica sem imagem numa falha de upload no meio do caminho.
            $newPath = $this->catalogService->storeImage(
                $request->file('image'),
                "iol-lenses/{$entityIolLens->entity_id}",
            );
            $data['image_path'] = $newPath;

            if ($oldPath !== null && $oldPath !== $newPath) {
                Storage::disk('public')->delete($oldPath);
            }
        }

        $this->bridge->update($entityIolLens, $data);

        return $this->redirectToListing('panel.stock.iollenses.index', self::LISTING_PARAMS)
            ->with('message', __('catalog_setting.updated'));
    }

    public function destroy(EntityIolLens $entityIolLens): RedirectResponse
    {
        $this->assertOwnership($entityIolLens);

        $this->bridge->delete($entityIolLens);

        return $this->redirectToListing('panel.stock.iollenses.index', self::LISTING_PARAMS)
            ->with('message', __('catalog_setting.deleted'));
    }

    /**
     * Autocomplete do catálogo GLOBAL (App\Models\IolLensModel) — popula o
     * picker de fabricante+modelo no form. Sem escopo por entity_id
     * (intencional: catálogo compartilhado). Mesmo padrão de resposta e
     * limite mínimo de 2 caracteres de Cid10SearchController.
     */
    public function search(Request $request): JsonResponse
    {
        $term = $this->queryText($request, 'q');

        if (mb_strlen($term, 'UTF-8') < 2) {
            return response()->json(['data' => []]);
        }

        $results = $this->catalogService->search($term);

        return response()->json([
            'data' => $results->map(fn ($model) => [
                'id'           => $model->id,
                'manufacturer' => $model->manufacturer,
                'model_name'   => $model->model_name,
                'category'     => $model->category,
                'image_url'    => $model->image_url,
                'label'        => trim($model->manufacturer . ' ' . $model->model_name),
            ])->values(),
        ]);
    }

    /**
     * Pagina e, se a página pedida passou da última (ex.: excluiu a única
     * lente da última página e o redirect manteve ?page=N), usa a última
     * válida — mesmo padrão de DoctorsController::paginateClamped(). O clone
     * leva junto o JOIN e o select('entity_iol_lenses.*').
     */
    private function paginateClamped(Builder $query, int $perPage): LengthAwarePaginator
    {
        $paginator = (clone $query)->paginate($perPage);

        if ($paginator->isEmpty() && $paginator->currentPage() > 1 && $paginator->lastPage() >= 1) {
            $paginator = $query->paginate($perPage, ['*'], 'page', $paginator->lastPage());
        }

        return $paginator;
    }

    /**
     * Parâmetro de query como texto (trim). Valor não textual (ex.:
     * `?sort[]=x`) vira o padrão em vez de estourar "Array to string
     * conversion" (500) em `$request->string()`.
     */
    private function queryText(Request $request, string $key, string $default = ''): string
    {
        $value = $request->query($key, $default);

        return is_string($value) ? trim($value) : $default;
    }

    private function assertOwnership(EntityIolLens $entityIolLens): void
    {
        abort_unless(
            (string) $entityIolLens->entity_id === (string) session('selected_entity_id'),
            404,
        );
    }
}
