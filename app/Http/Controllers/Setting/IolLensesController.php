<?php

declare(strict_types=1);

namespace App\Http\Controllers\Setting;

use App\Http\Controllers\Controller;
use App\Http\Requests\EntityIolLensRequest;
use App\Http\Resources\EntityIolLensResource;
use App\Models\EntityIolLens;
use App\Services\IolLensCatalogService;
use Illuminate\Http\{JsonResponse, RedirectResponse, Request};
use Illuminate\Support\Facades\{DB, Storage};
use Inertia\{Inertia, Response as InertiaResponse};

/**
 * CRUD do inventário de lentes IOL (catarata) DA CLÍNICA —
 * App\Models\EntityIolLens.
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
 */
class IolLensesController extends Controller
{
    public function __construct(
        private readonly IolLensCatalogService $catalogService,
    ) {
    }

    public function index(Request $request): InertiaResponse
    {
        $entityId = (string) session('selected_entity_id');
        $search   = $request->string('search')->trim()->value();
        $status   = $request->string('status', 'all')->value(); // active|inactive|all

        $records = EntityIolLens::query()
            ->where('entity_id', $entityId)
            ->when($search !== '', function ($query) use ($search) {
                $lower = mb_strtolower($search, 'UTF-8');
                $query->where(function ($q) use ($lower) {
                    $q->whereRaw('LOWER(manufacturer) LIKE ?', ["%{$lower}%"])
                        ->orWhereRaw('LOWER(model_name) LIKE ?', ["%{$lower}%"]);
                });
            })
            ->when($status === 'active', fn ($query) => $query->where('active', true))
            ->when($status === 'inactive', fn ($query) => $query->where('active', false))
            ->orderBy('manufacturer')
            ->orderBy('model_name')
            ->paginate(12)
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

        return Inertia::render('Panel/Settings/IolLenses/Index', [
            'breadcrumbs' => [
                ['label' => __('actions.sidemenu.dashboard'), 'url' => route('panel.dashboard'), 'active' => false],
                ['label' => __('actions.sidemenu.settings'), 'url' => '#', 'active' => false],
                ['label' => __('actions.sidemenu.iol_lenses'), 'url' => '#', 'active' => true],
            ],
            'items'   => $records,
            'filters' => [
                'search' => $search,
                'status' => $status,
            ],
            'routes' => [
                'index'  => route('panel.setting.iollenses.index'),
                'store'  => route('panel.setting.iollenses.store'),
                'search' => route('panel.setting.iollenses.search'),
                // Vue substitui {id} no client (evita gerar 1 rota por linha
                // na hidratação) — mesma convenção de BaseSettingController::
                // index() / AccessControl\RolesController::index().
                'show'    => route('panel.setting.iollenses.show', ['__ID__']),
                'update'  => route('panel.setting.iollenses.update', ['__ID__']),
                'destroy' => route('panel.setting.iollenses.destroy', ['__ID__']),
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

        return response()->json(['data' => new EntityIolLensResource($entityIolLens)]);
    }

    public function store(EntityIolLensRequest $request): RedirectResponse
    {
        $entityId = (string) session('selected_entity_id');

        DB::transaction(function () use ($request, $entityId) {
            $data              = $request->safe()->except(['image']);
            $data['entity_id'] = $entityId;

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

            EntityIolLens::create($data);
        });

        return redirect()
            ->route('panel.setting.iollenses.index')
            ->with('message', __('catalog_setting.created'));
    }

    public function update(EntityIolLensRequest $request, EntityIolLens $entityIolLens): RedirectResponse
    {
        $this->assertOwnership($entityIolLens);

        // DECISÃO: findOrCreateModel() NÃO roda aqui (só em store()). Editar
        // um item de inventário existente é frequentemente ajuste de texto
        // local (snapshot) que pode divergir intencionalmente do catálogo
        // global — replicar a lógica de auto-registro no update poluiria o
        // catálogo global a cada edição de detalhe específico da clínica.
        DB::transaction(function () use ($request, $entityIolLens) {
            $data = $request->safe()->except(['image']);

            if ($request->hasFile('image')) {
                $oldPath = $entityIolLens->image_path;

                // Salva a NOVA imagem primeiro; só troca `image_path` (e só
                // apaga a antiga do disco) se o storeImage() não lançar —
                // nunca fica sem imagem numa falha de upload no meio do
                // caminho.
                $newPath = $this->catalogService->storeImage(
                    $request->file('image'),
                    "iol-lenses/{$entityIolLens->entity_id}",
                );
                $data['image_path'] = $newPath;

                if ($oldPath !== null && $oldPath !== $newPath) {
                    Storage::disk('public')->delete($oldPath);
                }
            }

            $entityIolLens->update($data);
        });

        return redirect()
            ->route('panel.setting.iollenses.index')
            ->with('message', __('catalog_setting.updated'));
    }

    public function destroy(EntityIolLens $entityIolLens): RedirectResponse
    {
        $this->assertOwnership($entityIolLens);

        $entityIolLens->delete();

        return redirect()
            ->route('panel.setting.iollenses.index')
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
        $term = $request->string('q')->trim()->value();

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

    private function assertOwnership(EntityIolLens $entityIolLens): void
    {
        abort_unless(
            (string) $entityIolLens->entity_id === (string) session('selected_entity_id'),
            404,
        );
    }
}
