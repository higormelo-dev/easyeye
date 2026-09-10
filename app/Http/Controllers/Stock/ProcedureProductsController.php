<?php

declare(strict_types=1);

namespace App\Http\Controllers\Stock;

use App\Http\Controllers\Controller;
use App\Http\Requests\ProcedureProductRequest;
use App\Models\{Procedure, ProcedureProduct};
use Illuminate\Http\JsonResponse;

/**
 * BOM de estoque por procedimento (App\Models\ProcedureProduct) — admin
 * configura "cirurgia de catarata consome: 1x lente IOL, 2x seringa...".
 * Puro JSON (mesmo padrão de ProductLotsController), consumido por uma
 * tela leve de gestão em Panel/Stock.
 *
 * Isolamento OBRIGATÓRIO: index filtra por entity_id + procedure_id; store/
 * destroy re-checam posse do procedimento/registro resolvido pelo route
 * model binding.
 */
class ProcedureProductsController extends Controller
{
    public function index(Procedure $procedure): JsonResponse
    {
        $this->assertOwnsProcedure($procedure);

        // `productBom()` (Procedure→ProcedureProduct) NÃO filtra por
        // entity_id sozinho — pra um Procedure GLOBAL (entity_id null,
        // compartilhado entre clínicas), a relação traria a BOM configurada
        // por QUALQUER clínica que já tenha usado esse mesmo procedimento
        // global. `where('entity_id', ...)` aqui é o que garante isolamento
        // real, não a relação em si.
        $items = $procedure->productBom()
            ->where('entity_id', session('selected_entity_id'))
            ->with('product:id,name,code,unit')
            ->get()
            ->map(fn (ProcedureProduct $bom) => $this->serialize($bom));

        return response()->json(['data' => $items->values()]);
    }

    public function store(ProcedureProductRequest $request, Procedure $procedure): JsonResponse
    {
        $this->assertOwnsProcedure($procedure);

        $entityId = (string) session('selected_entity_id');

        // updateOrCreate pela constraint unique(procedure_id, entity_product_id)
        // — reenviar o mesmo produto só ATUALIZA a quantidade, não duplica.
        // entity_id vem da SESSÃO, não de $procedure->entity_id: Procedure
        // pode ser GLOBAL (entity_id null), mas procedure_products.entity_id
        // é NOT NULL — é a BOM DESTA clínica pra esse procedimento,
        // independente do procedimento ser catálogo próprio ou global.
        $bom = ProcedureProduct::query()->updateOrCreate(
            [
                'procedure_id'      => $procedure->id,
                'entity_product_id' => $request->validated('entity_product_id'),
                'entity_id'         => $entityId,
            ],
            [
                'quantity' => $request->validated('quantity'),
                'notes'    => $request->validated('notes'),
            ],
        );

        $bom->load('product:id,name,code,unit');

        return response()->json(['data' => $this->serialize($bom)], 201);
    }

    public function destroy(ProcedureProduct $procedureProduct): JsonResponse
    {
        abort_unless(
            (string) $procedureProduct->entity_id === (string) session('selected_entity_id'),
            404,
        );

        $procedureProduct->delete();

        return response()->json(['message' => __('stock.product_deleted')]);
    }

    /**
     * @return array<string, mixed>
     */
    private function serialize(ProcedureProduct $bom): array
    {
        return [
            'id'                => $bom->id,
            'entity_product_id' => $bom->entity_product_id,
            'product_name'      => $bom->product?->name,
            'product_code'      => $bom->product?->code,
            'unit_label'        => $bom->product?->unit?->label(),
            'quantity'          => (float) $bom->quantity,
            'notes'             => $bom->notes,
        ];
    }

    /**
     * Procedure pode ser DA CLÍNICA (entity_id = sessão) OU GLOBAL
     * (entity_id null, catálogo compartilhado — ver ProcedureSearchController)
     * — só bloqueia procedimento de OUTRA clínica.
     */
    private function assertOwnsProcedure(Procedure $procedure): void
    {
        abort_unless(
            $procedure->entity_id === null || (string) $procedure->entity_id === (string) session('selected_entity_id'),
            404,
        );
    }
}
