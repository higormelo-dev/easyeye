<?php

declare(strict_types=1);

namespace App\Http\Controllers\Surgery;

use App\Enums\Surgery\ImplantKind;
use App\Exceptions\Surgery\SurgeryRuleException;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Surgery\Concerns\AuthorizesSurgery;
use App\Http\Requests\Surgery\{OperationAddendumRequest, OperationDescriptionRequest, OperationImplantRequest, OperationPerformRequest, OperationVoidRequest};
use App\Models\Doctor;
use App\Models\{EntityProduct, StockLot};
use App\Models\Surgery\{SurgicalCase, SurgicalImplant};
use App\Services\Surgery\{SurgeryDescriptionService, SurgeryImplantDocumentService, SurgeryImplantService, SurgeryPerformService};
use App\Support\CurrentDoctor;
use Illuminate\Http\{JsonResponse, RedirectResponse, Request, Response};
use Illuminate\Support\Str;

/**
 * Cirurgia realizada (M4): implantes (RDC 556/2021), descrição cirúrgica e o
 * ato "registrar como realizada". Ato médico (PerformSurgery + médico da
 * equipe do caso); impressão de etiquetas/cartão é ManageSurgery. As telas
 * chamam por fetch (JSON) para ler o motivo da recusa; sem JSON, volta com flash.
 */
class SurgeryOperationController extends Controller
{
    use AuthorizesSurgery;

    public function __construct(
        private readonly SurgeryImplantService $implants,
        private readonly SurgeryImplantDocumentService $implantDocuments,
        private readonly SurgeryDescriptionService $descriptions,
        private readonly SurgeryPerformService $performer,
    ) {
    }

    // ── Produtos de estoque para o implante ─────────────────────────────────

    /** Busca de produto implantável com os lotes em ordem FEFO (vence primeiro, com saldo, não vencido). */
    public function products(Request $request, SurgicalCase $surgicalCase): JsonResponse
    {
        $this->authorizePerform();
        $this->authorizeCaseAccess($surgicalCase);

        $term = trim((string) $request->query('q', ''));

        if (mb_strlen($term) < 2) {
            return response()->json([]);
        }

        $today = now()->toDateString();

        $products = EntityProduct::query()
            ->where('entity_id', $surgicalCase->entity_id)
            ->where('active', true)
            ->where(fn ($q) => $q->where('is_opm', true)->orWhere('requires_lot', true)->orWhere('requires_serial', true)->orWhereHas('iolLens'))
            ->where(fn ($q) => $q->whereLikeUnaccent('name', $term)->orWhereLikeUnaccent('code', $term)->orWhereLikeUnaccent('sku', $term)->orWhereLikeUnaccent('manufacturer', $term))
            ->with(['iolLens', 'lots' => fn ($q) => $q->where('active', true)->where('qty_on_hand', '>', 0)
                ->where(fn ($e) => $e->whereNull('expiry_date')->orWhere('expiry_date', '>=', $today))
                ->orderByRaw('expiry_date asc nulls last')->orderBy('lot_number')])
            ->orderBy('name')
            ->limit(15)
            ->get();

        $materials = $surgicalCase->materials()->whereNotNull('entity_product_id')->get()->keyBy('entity_product_id');

        return response()->json($products->map(function (EntityProduct $p) use ($materials) {
            $iol = $p->iolLens !== null;

            return [
                'id'               => (string) $p->getKey(),
                'name'             => $p->name,
                'code'             => $p->code,
                'manufacturer'     => $p->manufacturer,
                'sku'              => $p->sku,
                'kind'             => ($iol ? ImplantKind::Iol : ImplantKind::Other)->value,
                'requires_serial'  => $iol || (bool) $p->requires_serial,
                'requires_lot'     => $iol || (bool) $p->requires_lot || (bool) $p->is_opm,
                'qty_on_hand'      => (float) $p->qty_on_hand,
                'suggested_anvisa' => $materials[(string) $p->getKey()]->anvisa_registration ?? null,
                'lots'             => $p->lots->values()->map(fn (StockLot $l, int $i) => [
                    'id'          => (string) $l->getKey(),
                    'lot_number'  => $l->lot_number,
                    'expiry_date' => $l->expiry_date?->toDateString(),
                    'qty_on_hand' => (float) $l->qty_on_hand,
                    'suggested'   => $i === 0 && $l->expiry_date !== null,
                ])->all(),
            ];
        })->values());
    }

    // ── Implantes ───────────────────────────────────────────────────────────

    public function storeImplant(OperationImplantRequest $request, SurgicalCase $surgicalCase): JsonResponse|RedirectResponse
    {
        $this->authorizePerform();
        $this->authorizeCaseAccess($surgicalCase);

        $implant = $this->implants->register($surgicalCase, $request->validated(), $this->doctor());

        return $this->respond($request, 'surgery_operation.messages.implant_registered', ['implant_id' => (string) $implant->getKey()], 201);
    }

    public function voidImplant(OperationVoidRequest $request, SurgicalCase $surgicalCase, SurgicalImplant $implant): JsonResponse|RedirectResponse
    {
        $this->authorizePerform();
        $this->authorizeCaseAccess($surgicalCase);
        $this->assertOwns($surgicalCase, $implant);

        $this->implants->void($implant, (string) $request->input('reason'), $request->boolean('return_to_stock'), $this->doctor());

        return $this->respond($request, 'surgery_operation.messages.implant_voided');
    }

    public function issueRecord(Request $request, SurgicalCase $surgicalCase, SurgicalImplant $implant): JsonResponse|RedirectResponse
    {
        $this->authorizePerform();
        $this->authorizeCaseAccess($surgicalCase);
        $this->assertOwns($surgicalCase, $implant);

        $issue = $this->implantDocuments->issueRecord($implant);

        return $this->respond($request, 'surgery_operation.messages.record_issued', ['document_id' => (string) $issue->getKey()]);
    }

    public function recordPdf(SurgicalCase $surgicalCase, SurgicalImplant $implant): Response
    {
        $this->authorizeManage();
        $this->authorizeCaseAccess($surgicalCase);
        $this->assertOwns($surgicalCase, $implant);

        $this->logSurgeryRead($implant, (string) $surgicalCase->patient_id);

        return $this->implantDocuments->recordPdf($implant);
    }

    public function labels(Request $request, SurgicalCase $surgicalCase, SurgicalImplant $implant): Response
    {
        $this->authorizeManage();
        $this->authorizeCaseAccess($surgicalCase);
        $this->assertOwns($surgicalCase, $implant);

        $this->logSurgeryRead($implant, (string) $surgicalCase->patient_id);

        return $this->implantDocuments->labelsPdf($implant, (int) $request->query('count', 6));
    }

    public function card(Request $request, SurgicalCase $surgicalCase): Response
    {
        $this->authorizeManage();
        $this->authorizeCaseAccess($surgicalCase);

        $only = null;

        if ($request->filled('implant')) {
            $only = SurgicalImplant::query()
                ->where('surgical_case_id', $surgicalCase->getKey())
                ->whereKey(Str::isUuid((string) $request->query('implant')) ? (string) $request->query('implant') : abort(404))
                ->firstOrFail();
        }

        $this->logSurgeryRead($surgicalCase, (string) $surgicalCase->patient_id);

        return $this->implantDocuments->cardPdf($surgicalCase, $only);
    }

    // ── Descrição cirúrgica ─────────────────────────────────────────────────

    public function saveDescription(OperationDescriptionRequest $request, SurgicalCase $surgicalCase): JsonResponse|RedirectResponse
    {
        $this->authorizePerform();
        $this->authorizeCaseAccess($surgicalCase);

        $description = $this->descriptions->saveDraft($surgicalCase, $request->validated(), $this->doctor(), $request->expectedRevision());

        return $this->respond($request, 'surgery_operation.messages.description_saved', ['revision' => (int) $description->revision]);
    }

    public function finalizeDescription(Request $request, SurgicalCase $surgicalCase): JsonResponse|RedirectResponse
    {
        $this->authorizePerform();
        $this->authorizeCaseAccess($surgicalCase);

        $revision    = $request->filled('expected_revision') ? (int) $request->input('expected_revision') : null;
        $description = $this->descriptions->finalize($surgicalCase, $this->doctor(), $revision);

        return $this->respond($request, 'surgery_operation.messages.description_finalized', ['hash' => $description->content_hash]);
    }

    public function addAddendum(OperationAddendumRequest $request, SurgicalCase $surgicalCase): JsonResponse|RedirectResponse
    {
        $this->authorizePerform();
        $this->authorizeCaseAccess($surgicalCase);

        $this->descriptions->addAddendum($surgicalCase, (string) $request->input('text'), (string) $request->input('reason'), $this->doctor());

        return $this->respond($request, 'surgery_operation.messages.addendum_added', status: 201);
    }

    public function descriptionPdf(SurgicalCase $surgicalCase): Response
    {
        $this->authorizeManage();
        $this->authorizeCaseAccess($surgicalCase);

        $this->logSurgeryRead($surgicalCase, (string) $surgicalCase->patient_id);

        return $this->descriptions->pdf($surgicalCase);
    }

    // ── Cirurgia realizada ──────────────────────────────────────────────────

    public function perform(OperationPerformRequest $request, SurgicalCase $surgicalCase): JsonResponse|RedirectResponse
    {
        $this->authorizePerform();
        $this->authorizeCaseAccess($surgicalCase);

        $case = $this->performer->perform($surgicalCase, $request->validated(), $this->doctor(), $request->expectedRevision());

        return $this->respond($request, 'surgery_operation.messages.performed', ['revision' => (int) $case->revision]);
    }

    // ── Internos ────────────────────────────────────────────────────────────

    /** Médico logado com cadastro ativo (o Gate PerformSurgery já garantiu). */
    private function doctor(): Doctor
    {
        return CurrentDoctor::issuer() ?? throw new SurgeryRuleException((string) __('schedules.doctor_missing'), 403);
    }

    private function assertOwns(SurgicalCase $case, SurgicalImplant $implant): void
    {
        abort_unless((string) $implant->surgical_case_id === (string) $case->getKey(), 404);
    }

    /** @param array<string, mixed> $payload */
    private function respond(Request $request, string $messageKey, array $payload = [], int $status = 200): JsonResponse|RedirectResponse
    {
        $message = (string) __($messageKey);

        if ($request->expectsJson()) {
            return response()->json([...$payload, 'message' => $message], $status);
        }

        return back()->with('success', $message);
    }
}
