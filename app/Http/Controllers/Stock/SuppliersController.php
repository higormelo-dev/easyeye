<?php

declare(strict_types=1);

namespace App\Http\Controllers\Stock;

use App\Http\Controllers\Concerns\RedirectsToListing;
use App\Http\Controllers\Controller;
use App\Http\Requests\SupplierRequest;
use App\Http\Resources\SupplierResource;
use App\Models\Supplier;
use App\Support\BrazilianFormat;
use Illuminate\Http\{JsonResponse, RedirectResponse, Request};
use Inertia\{Inertia, Response as InertiaResponse};

/**
 * CRUD de fornecedores DA CLÍNICA (Fase 4) — App\Models\Supplier. Mesmo
 * padrão manual (sem BaseSettingController) de ProductsController: campos
 * demais (documento, contato) pro catálogo genérico name/code/active
 * comportar.
 *
 * Isolamento OBRIGATÓRIO: toda query filtra por entity_id da sessão;
 * update/destroy re-checam posse — mesma regra de ProductsController.
 */
class SuppliersController extends Controller
{
    use RedirectsToListing;

    /** Parâmetros da listagem preservados ao voltar de uma ação (RedirectsToListing). */
    private const LISTING_PARAMS = ['search', 'status', 'sort', 'direction', 'page'];

    /** Colunas ordenáveis da listagem (whitelist) → coluna real no banco. */
    private const SORTABLE = [
        'name'         => 'suppliers.name',
        'code'         => 'suppliers.code',
        'document'     => 'suppliers.document',
        'contact_name' => 'suppliers.contact_name',
        'phone'        => 'suppliers.phone',
        'email'        => 'suppliers.email',
    ];

    private const STATUSES = ['all', 'active', 'inactive'];

    public function index(Request $request): InertiaResponse
    {
        $entityId = (string) session('selected_entity_id');
        // Leitura à prova de array: ?search[]=x chegava como array e
        // $request->string() estourava erro 500 — agora vira o padrão.
        $search  = self::queryString($request, 'search');
        $status  = self::queryString($request, 'status', 'all');
        $sortBy  = self::queryString($request, 'sort', 'name');
        $sortDir = self::queryString($request, 'direction', 'asc');

        // Normalizados: a UI mostra a ordenação/filtro realmente aplicados.
        // Padrão = ordem de sempre da tela (nome crescente).
        $status  = in_array($status, self::STATUSES, true) ? $status : 'all';
        $sortBy  = array_key_exists($sortBy, self::SORTABLE) ? $sortBy : 'name';
        $sortDir = in_array($sortDir, ['asc', 'desc'], true) ? $sortDir : 'asc';

        $searchDigits = self::documentSearchDigits($search);

        $records = Supplier::query()
            ->where('entity_id', $entityId)
            ->when($search !== '', function ($query) use ($search, $searchDigits) {
                $query->where(function ($q) use ($search, $searchDigits) {
                    $q->whereLikeUnaccent('name', $search)
                        ->orWhereLikeUnaccent('document', $search)
                        ->orWhereLikeUnaccent('code', $search)
                        ->when(
                            $searchDigits !== '',
                            fn ($q) => $q->orWhereLikeUnaccent('document', $searchDigits),
                        );
                });
            })
            ->when($status === 'active', fn ($query) => $query->where('active', true))
            ->when($status === 'inactive', fn ($query) => $query->where('active', false))
            // `id` desempata valores iguais (nome, contato...) — sem ele a
            // ordem entre páginas não é determinística no PostgreSQL.
            ->orderBy(self::SORTABLE[$sortBy], $sortDir)
            ->orderBy('suppliers.id')
            ->paginate(15)
            ->withQueryString()
            ->through(fn (Supplier $record) => [
                ...(new SupplierResource($record))->resolve(),
                ...self::displayFields($record),
            ]);

        return Inertia::render('Panel/Stock/Suppliers/Index', [
            'breadcrumbs' => [
                ['label' => __('actions.sidemenu.dashboard'), 'url' => route('panel.dashboard'), 'active' => false],
                ['label' => __('actions.sidemenu.stock'), 'url' => '#', 'active' => false],
                ['label' => __('actions.sidemenu.suppliers'), 'url' => '#', 'active' => true],
            ],
            'items'   => $records,
            'filters' => ['search' => $search, 'status' => $status, 'sort' => $sortBy, 'direction' => $sortDir],
            't'       => trans('stock_suppliers'),
            'routes'  => [
                'index'                 => route('panel.stock.suppliers.index'),
                'store'                 => route('panel.stock.suppliers.store'),
                'update'                => route('panel.stock.suppliers.update', ['__ID__']),
                'destroy'               => route('panel.stock.suppliers.destroy', ['__ID__']),
                'purchase_orders_index' => route('panel.stock.purchase-orders.index'),
            ],
        ]);
    }

    public function show(Supplier $supplier): JsonResponse
    {
        $this->assertOwnership($supplier);

        return response()->json(['data' => new SupplierResource($supplier)]);
    }

    public function store(SupplierRequest $request): RedirectResponse
    {
        $data              = $request->validated();
        $data['entity_id'] = (string) session('selected_entity_id');

        Supplier::create($data);

        return $this->redirectToListing('panel.stock.suppliers.index', self::LISTING_PARAMS)
            ->with('message', __('stock.supplier_created'));
    }

    public function update(SupplierRequest $request, Supplier $supplier): RedirectResponse
    {
        $this->assertOwnership($supplier);

        $supplier->update($request->validated());

        return $this->redirectToListing('panel.stock.suppliers.index', self::LISTING_PARAMS)
            ->with('message', __('stock.supplier_updated'));
    }

    public function destroy(Supplier $supplier): RedirectResponse
    {
        $this->assertOwnership($supplier);

        $supplier->delete();

        return $this->redirectToListing('panel.stock.suppliers.index', self::LISTING_PARAMS)
            ->with('message', __('stock.supplier_deleted'));
    }

    /** Parâmetro de query como texto aparado; array/ausente → padrão. */
    private static function queryString(Request $request, string $key, string $default = ''): string
    {
        $value = $request->query($key);

        return is_string($value) ? trim($value) : $default;
    }

    /**
     * Documento é gravado só com dígitos, mas a listagem o exibe formatado —
     * "12.345.678/0001-99" colado na busca também precisa encontrar o registro.
     * Só vale quando o termo é um documento pontuado (dígitos + . / - espaço):
     * "Alfa 3" não vira busca por documento contendo "3", e termo só com
     * dígitos já é coberto pela busca normal.
     */
    private static function documentSearchDigits(string $search): string
    {
        if (preg_match('/^[\d\s.\/-]+$/', $search) !== 1) {
            return '';
        }

        $digits = preg_replace('/\D/', '', $search) ?? '';

        return $digits === $search ? '' : $digits;
    }

    /**
     * Documento/telefone formatados só para a listagem (os crus seguem no
     * SupplierResource para o formulário). BrazilianFormat devolve intacto o
     * valor com quantidade de dígitos inesperada (telefone legado em texto
     * livre, 4004-0001) — mesma política dos leads e do PDF do pedido de compra.
     *
     * @return array{document_display: ?string, phone_display: ?string}
     */
    private static function displayFields(Supplier $record): array
    {
        return [
            'document_display' => BrazilianFormat::cpfCnpj($record->document),
            'phone_display'    => BrazilianFormat::phone($record->phone),
        ];
    }

    private function assertOwnership(Supplier $supplier): void
    {
        abort_unless((string) $supplier->entity_id === (string) session('selected_entity_id'), 404);
    }
}
