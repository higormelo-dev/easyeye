<?php

declare(strict_types=1);

namespace App\Http\Controllers\Surgery;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Surgery\Concerns\AuthorizesSurgery;
use App\Http\Requests\Surgery\{SurgeryDocumentIssueRequest, SurgeryDocumentPreviewRequest, SurgeryDocumentSignedCopyRequest};
use App\Models\Surgery\{SurgeryDocumentIssue, SurgicalCase};
use App\Services\Surgery\Documents\SurgeryDocumentService;
use Illuminate\Http\{JsonResponse, RedirectResponse, Response};
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * TCLE e orientações pré-operatórias do caso: prévia, emissão, PDF, cópia
 * digitalizada assinada. Perfis: ManageSurgery (admin, médico, secretária); o
 * médico só no caso em que participa. Documento emitido é imutável — não há
 * edição nem exclusão, só reemissão.
 */
class SurgeryDocumentsController extends Controller
{
    use AuthorizesSurgery;

    public function __construct(private readonly SurgeryDocumentService $documents)
    {
    }

    public function preview(SurgeryDocumentPreviewRequest $request, SurgicalCase $surgicalCase): JsonResponse
    {
        $this->authorizeCase($surgicalCase);

        return response()->json($this->documents->preview(
            $surgicalCase,
            $request->documentType(),
            $request->filled('template_id') ? (string) $request->input('template_id') : null,
        ));
    }

    /**
     * Emite o documento. JSON (a tela de emissão usa axios para manter o texto
     * editado se a regra recusar) devolve o id; Inertia volta à tela do caso.
     */
    public function store(SurgeryDocumentIssueRequest $request, SurgicalCase $surgicalCase): RedirectResponse|JsonResponse
    {
        $this->authorizeCase($surgicalCase);

        $issue = $this->documents->issue(
            $surgicalCase,
            $request->documentType(),
            (string) $request->input('title'),
            (string) $request->input('content'),
            $request->filled('template_id') ? (string) $request->input('template_id') : null,
            $request->filled('basis_hash') ? (string) $request->input('basis_hash') : null,
        );

        if ($request->expectsJson()) {
            return response()->json([
                'id'      => (string) $issue->getKey(),
                'message' => __('surgery_documents.messages.issued'),
                'pdf'     => route('panel.surgery.documents.pdf', ['surgicalCase' => $surgicalCase->getKey(), 'document' => $issue->getKey()]),
            ], 201);
        }

        return back()->with('success', __('surgery_documents.messages.issued'));
    }

    public function pdf(SurgicalCase $surgicalCase, SurgeryDocumentIssue $document): Response
    {
        $this->authorizeDocument($surgicalCase, $document);

        return $this->documents->pdf($document);
    }

    public function storeSignedCopy(SurgeryDocumentSignedCopyRequest $request, SurgicalCase $surgicalCase, SurgeryDocumentIssue $document): RedirectResponse
    {
        $this->authorizeDocument($surgicalCase, $document);

        $this->documents->attachSignedCopy($document, $request->file('file'));

        return back()->with('success', __('surgery_documents.messages.signed_copy_attached'));
    }

    public function showSignedCopy(SurgicalCase $surgicalCase, SurgeryDocumentIssue $document): StreamedResponse
    {
        $this->authorizeDocument($surgicalCase, $document);

        return $this->documents->signedCopy($document);
    }

    // ── Autorização ─────────────────────────────────────────────────────────

    private function authorizeCase(SurgicalCase $case): void
    {
        $this->authorizeManage();
        $this->authorizeCaseAccess($case);
    }

    /** O documento é DESTE caso (e desta clínica — o binding já filtra a clínica); outro = 404. */
    private function authorizeDocument(SurgicalCase $case, SurgeryDocumentIssue $document): void
    {
        $this->authorizeCase($case);

        abort_unless((string) $document->surgical_case_id === (string) $case->getKey(), 404);
    }
}
