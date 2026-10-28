<?php

declare(strict_types=1);

namespace App\Http\Controllers\Manager;

use App\Enums\EntityGate;
use App\Exceptions\Fiscal\{FocusNfeException, NfseIssueRejectedException};
use App\Http\Controllers\Controller;
use App\Models\Billing\Invoice;
use App\Models\Entity;
use App\Models\Fiscal\NfseDocument;
use App\Services\Audit\AuditLogger;
use App\Services\Fiscal\{NfseCancellationService, NfseCredentialResolver, NfseDocumentStorage, NfseIssueService, NfseSyncService};
use App\Support\Fiscal\NfseManagerPresenter;
use Illuminate\Http\{JsonResponse, Request};
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Manager → ações sobre as notas: emitir (por fatura paga), cancelar, marcar como cancelada na prefeitura, reenviar o e-mail,
 * consultar agora e baixar o XML. Emitir, reenviar, consultar e baixar são do financeiro (`saas.financial`); cancelar e
 * marcar cancelamento externo são do admin do SaaS (`saas.admin-panel`) — cancelar é definitivo. Respostas JSON:
 * `{message, document}` no sucesso; `{message, errors}` na recusa (422 de guarda, erros da própria Focus campo a campo).
 * Cada ação grava em audit_logs com o motivo/justificativa.
 */
class NfseDocumentsController extends Controller
{
    public function __construct(
        private readonly NfseIssueService $issuer,
        private readonly NfseCancellationService $cancellation,
        private readonly NfseSyncService $sync,
        private readonly NfseCredentialResolver $credentials,
        private readonly NfseDocumentStorage $storage,
        private readonly NfseManagerPresenter $presenter,
        private readonly AuditLogger $audit,
    ) {
    }

    public function issue(Request $request, Invoice $invoice): JsonResponse
    {
        $this->authorizeFinancial();

        try {
            $document = $this->issuer->issue($invoice, NfseIssueService::SOURCE_MANUAL, $request->user());
        } catch (NfseIssueRejectedException $e) {
            return $this->rejected($e);
        }

        $this->record($request, 'manager.nfse.issue', $document, 'Emissão manual de NFS-e', ['invoice_id' => $invoice->id, 'ref' => $document->ref]);

        return response()->json(['message' => __('fiscal.issue_queued'), 'document' => $this->presenter->row($document)], 201);
    }

    public function cancel(Request $request, NfseDocument $document): JsonResponse
    {
        $this->authorizeAdmin();

        $data = $request->validate(['justification' => ['required', 'string', 'min:15', 'max:255']]);

        try {
            $result = $this->cancellation->cancel($document, $data['justification'], $request->user());
        } catch (NfseIssueRejectedException $e) {
            return $this->rejected($e);
        } catch (FocusNfeException $e) {
            return $this->focusError($e);
        }

        if (! $result['cancelled']) {
            return response()->json(['message' => __('fiscal.errors.cancel_failed'), 'errors' => $result['errors'], 'document' => $this->presenter->row($document->fresh())], 422);
        }

        $this->record($request, 'manager.nfse.cancel', $document, trim($data['justification']), ['ref' => $document->ref]);

        return response()->json(['message' => __('fiscal.cancelled'), 'document' => $this->presenter->row($document->fresh())]);
    }

    public function markCancelledExternally(Request $request, NfseDocument $document): JsonResponse
    {
        $this->authorizeAdmin();

        // Mais exigente que a justificativa do cancelamento na Focus (15–255): aqui é registro de ato feito FORA do sistema.
        $data = $request->validate(['reason' => ['required', 'string', 'min:20', 'max:255']]);

        try {
            $fresh = $this->cancellation->markCancelledExternally($document, $data['reason'], $request->user());
        } catch (NfseIssueRejectedException $e) {
            return $this->rejected($e);
        }

        $this->record($request, 'manager.nfse.mark-cancelled-externally', $document, trim($data['reason']), ['ref' => $document->ref]);

        return response()->json(['message' => __('fiscal.marked_cancelled_externally'), 'document' => $this->presenter->row($fresh)]);
    }

    public function resendEmail(Request $request, NfseDocument $document): JsonResponse
    {
        $this->authorizeFinancial();

        $data = $request->validate(['emails' => ['required', 'array', 'min:1', 'max:10'], 'emails.*' => ['required', 'email', 'max:80']]);

        if (! $document->isAuthorized()) {
            return $this->rejected(NfseIssueRejectedException::because(NfseIssueRejectedException::NOT_AUTHORIZED));
        }

        try {
            $this->credentials->client($document->environment, $document->correlation_id)->email($document->standard, $document->ref, array_values($data['emails']));
        } catch (FocusNfeException $e) {
            return $this->focusError($e);
        }

        $this->record($request, 'manager.nfse.resend-email', $document, 'Reenvio da NFS-e por e-mail', ['ref' => $document->ref, 'recipients' => count($data['emails'])]);

        return response()->json(['message' => __('fiscal.email_resent'), 'document' => $this->presenter->row($document)]);
    }

    public function sync(Request $request, NfseDocument $document): JsonResponse
    {
        $this->authorizeFinancial();

        try {
            $fresh = $this->sync->syncDocument($document);
        } catch (FocusNfeException $e) {
            return $this->focusError($e);
        }

        $this->record($request, 'manager.nfse.sync', $document, 'Consulta da NFS-e na Focus', ['ref' => $document->ref, 'status' => $fresh->status->value]);

        return response()->json(['message' => __('fiscal.synced'), 'document' => $this->presenter->row($fresh->fresh())]);
    }

    public function xml(NfseDocument $document): StreamedResponse
    {
        return $this->download($document, NfseDocumentStorage::KIND_AUTHORIZED);
    }

    public function cancelXml(NfseDocument $document): StreamedResponse
    {
        return $this->download($document, NfseDocumentStorage::KIND_CANCEL);
    }

    private function download(NfseDocument $document, string $kind): StreamedResponse
    {
        $this->authorizeFinancial();

        $contents = $this->storage->ensure($document, $kind);

        abort_if($contents === null, 404);

        return response()->streamDownload(fn () => print($contents), $this->storage->downloadName($document, $kind), [
            'Content-Type'  => 'application/xml',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    /** @param array<string, mixed> $new */
    private function record(Request $request, string $event, NfseDocument $document, string $reason, array $new): void
    {
        $this->audit->recordAdminAction(
            event: $event,
            targetEntityId: (string) $document->entity_id,
            targetUserId: null,
            auditableType: 'nfse_document',
            auditableId: (string) $document->id,
            reason: $reason,
            newValues: $new,
            request: $request,
        );
    }

    private function rejected(NfseIssueRejectedException $e): JsonResponse
    {
        return response()->json(['message' => $e->getMessage(), 'errors' => ['nfse' => [$e->getMessage()]], 'reason' => $e->reason], 422);
    }

    private function focusError(FocusNfeException $e): JsonResponse
    {
        $status = match ($e->errorCode) {
            FocusNfeException::RATE_LIMITED => 429,
            FocusNfeException::UNAVAILABLE  => 502,
            default                         => 422,
        };

        return response()->json(['message' => $e->userMessage(), 'errors' => $e->errors], $status);
    }

    private function authorizeAdmin(): void
    {
        Gate::authorize(EntityGate::SaasAdminPanel->value, Entity::findOrFail(session('selected_entity_id')));
    }

    private function authorizeFinancial(): void
    {
        Gate::authorize(EntityGate::SaasFinancial->value, Entity::findOrFail(session('selected_entity_id')));
    }
}
