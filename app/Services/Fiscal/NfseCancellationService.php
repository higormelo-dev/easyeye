<?php

declare(strict_types=1);

namespace App\Services\Fiscal;

use App\Enums\Fiscal\NfseStatus;
use App\Events\Billing\InvoiceFullyReversed;
use App\Exceptions\Fiscal\{FocusNfeException, NfseIssueRejectedException as Rejected};
use App\Models\Billing\Invoice;
use App\Models\Fiscal\NfseDocument;
use App\Models\User;
use App\Services\Billing\BillingLogService;
use App\Support\Fiscal\{NfseLogText, NfsePayloadSanitizer};
use Illuminate\Support\Facades\{DB, Log};
use Throwable;

/**
 * Cancelamento da NFS-e do SaaS — definitivo e só de nota AUTORIZADA.
 *
 *  - `cancel`: pedido do Manager. Justificativa 15–255, chamada SÍNCRONA à Focus (fora de qualquer trava), `erro_cancelamento`
 *    devolvido à tela sem mudar o status. Município que não cancela por webservice (`possui_cancelamento_nfse = false`) é
 *    barrado antes, com a orientação de cancelar na prefeitura e `markCancelledExternally`;
 *  - `markCancelledExternally`: a prefeitura cancelou fora do sistema; só registra, com motivo, e fica na auditoria;
 *  - `forReversal`: estorno total/chargeback da fatura (evento InvoiceFullyReversed). Nunca automático fora disso. Se a
 *    Focus não puder cancelar, a nota fica marcada `cancel_required` (pendência visível no Manager). Nota que ainda não
 *    foi autorizada não se cancela na Focus: a que nem foi enviada é anulada aqui mesmo, a que está em voo fica marcada e é
 *    cancelada assim que a Focus a autorizar (NfseSyncService).
 */
class NfseCancellationService
{
    public function __construct(
        private readonly NfseCredentialResolver $credentials,
        private readonly NfseMunicipalityService $municipalities,
        private readonly NfseSyncService $sync,
        private readonly BillingLogService $billingLog,
    ) {
    }

    /**
     * @return array{cancelled: bool, errors: list<array{codigo: ?string, mensagem: string, correcao: ?string}>}
     *
     * @throws Rejected          justificativa fora de 15–255, nota que não está autorizada ou município sem cancelamento
     * @throws FocusNfeException falha da chamada (token, rede, limite) — a nota não mudou
     */
    public function cancel(NfseDocument $document, string $justification, ?User $by = null): array
    {
        $justification = trim($justification);

        if (mb_strlen($justification) < 15 || mb_strlen($justification) > 255) {
            throw Rejected::because(Rejected::INVALID_JUSTIFICATION);
        }

        $document = $document->fresh() ?? $document;

        if ($document->status !== NfseStatus::Authorized) {
            throw Rejected::because(Rejected::NOT_AUTHORIZED);
        }

        if ($this->municipalityForbidsCancellation($document)) {
            throw Rejected::because(Rejected::NO_CANCELLATION);
        }

        try {
            $result = $this->credentials->client($document->environment, $document->correlation_id)->cancel($document->standard, $document->ref, $justification);
        } catch (FocusNfeException $e) {
            if ($e->errorCode === FocusNfeException::NOT_AUTHORIZED) {
                throw Rejected::because(Rejected::NOT_AUTHORIZED);
            }

            throw $e;
        }

        if (($result['status'] ?? null) !== 'cancelado') {
            return ['cancelled' => false, 'errors' => FocusNfeException::errorsFrom($result)];
        }

        $this->markCancelled($document->id, fn (NfseDocument $locked) => $locked->forceFill([
            'status'                 => NfseStatus::Cancelled->value,
            'cancelled_at'           => now(),
            'cancelled_externally'   => false,
            'cancel_required'        => false,
            'cancel_required_reason' => null,
            'justification'          => $justification,
        ])->save());

        $this->pullCancellationDocuments($document->fresh());

        return ['cancelled' => true, 'errors' => []];
    }

    /**
     * Registra que a nota foi cancelada FORA do EasyEye (na prefeitura). Nenhuma chamada à Focus.
     *
     * @throws Rejected motivo fora de 20–255 (mais exigente que a justificativa do cancelamento na Focus, 15–255) ou nota que não está autorizada
     */
    public function markCancelledExternally(NfseDocument $document, string $reason, ?User $by = null): NfseDocument
    {
        $reason = trim($reason);

        if (mb_strlen($reason) < 20 || mb_strlen($reason) > 255) {
            throw Rejected::because(Rejected::INVALID_JUSTIFICATION);
        }

        $changed = $this->markCancelled($document->id, fn (NfseDocument $locked) => $locked->forceFill([
            'status'                 => NfseStatus::Cancelled->value,
            'cancelled_at'           => now(),
            'cancelled_externally'   => true,
            'cancel_required'        => false,
            'cancel_required_reason' => null,
            'justification'          => $reason,
        ])->save());

        if (! $changed) {
            throw Rejected::because(Rejected::NOT_AUTHORIZED);
        }

        return $document->fresh();
    }

    /**
     * Estorno total / chargeback da fatura: cancela a nota que a ocupa. Falha definitiva da Focus vira pendência
     * (`cancel_required`); falha de rede/limite relança, para a fila repetir.
     *
     * @param string $reason refund | chargeback
     *
     * @throws FocusNfeException rede/limite
     */
    public function forReversal(NfseDocument $document, string $reason): void
    {
        $document = $document->fresh() ?? $document;

        switch ($document->status) {
            case NfseStatus::Authorized:
                $this->cancelForReversal($document, $reason);

                break;
            case NfseStatus::PendingSend:
            case NfseStatus::SendFailed:
                // Nunca chegou à Focus: anula aqui. Se já houve tentativa de envio, pode estar lá — vale como "em voo".
                if ($document->request_payload === null) {
                    $this->voidUnsent($document, $reason);

                    break;
                }

                // no break
            case NfseStatus::Processing:
                $this->flag($document, $reason, 'in_flight');

                break;
            default:
                // erro_autorizacao e cancelado: nada a cancelar.
        }
    }

    /**
     * Marca a pendência (nota que precisa ser cancelada por um humano ou ao ser autorizada). O log de cobrança recebe um motivo
     * FIXO e traduzível (`fiscal.cancel_pending.{cause}`) e, no máximo, um código curto permitido — NUNCA o texto livre da Focus
     * (pode trazer e-mail, CPF ou o que a prefeitura quiser): esse texto só existe, saneado, em `nfse_documents.errors`.
     */
    public function flag(NfseDocument $document, string $reason, string $cause, ?string $code = null): void
    {
        if ($document->cancel_required && $document->cancel_required_reason === $reason) {
            return;
        }

        $document->forceFill(['cancel_required' => true, 'cancel_required_reason' => $reason])->save();

        $code = NfseLogText::code((string) $code);

        $this->billingLog->log(
            level: 'warning',
            message: 'NFS-e pendente de cancelamento: ' . trans("fiscal.cancel_pending.{$cause}", [], 'pt_BR'),
            context: array_filter(['nfse_document_id' => $document->id, 'ref' => $document->ref, 'reason' => $reason, 'status' => $document->status->value, 'cause' => $cause, 'code' => $code], fn ($value) => $value !== ''),
            entityId: (string) $document->entity_id,
            invoice: Invoice::query()->find($document->invoice_id),
            correlationId: $document->correlation_id,
        );
    }

    private function cancelForReversal(NfseDocument $document, string $reason): void
    {
        if ($this->municipalityForbidsCancellation($document)) {
            $this->flag($document, $reason, 'no_webservice');

            return;
        }

        $justification = $this->reversalJustification($document, $reason);

        try {
            $outcome = $this->cancel($document, $justification);
        } catch (Rejected $e) {
            $this->flag($document, $reason, 'blocked', $e->reason);

            return;
        } catch (FocusNfeException $e) {
            if ($e->retryable()) {
                throw $e;
            }

            $this->flag($document, $reason, 'focus_refused', $e->errorCode);

            return;
        }

        if (! $outcome['cancelled']) {
            $this->flag($document->fresh(), $reason, 'focus_error', (string) ($outcome['errors'][0]['codigo'] ?? ''));
        }
    }

    /** Nota que nunca saiu: cancelada localmente, sem Focus. */
    private function voidUnsent(NfseDocument $document, string $reason): void
    {
        $this->markCancelled($document->id, fn (NfseDocument $locked) => $locked->forceFill([
            'status'                 => NfseStatus::Cancelled->value,
            'cancelled_at'           => now(),
            'cancelled_externally'   => false,
            'cancel_required'        => false,
            'cancel_required_reason' => null,
            'justification'          => $this->reversalJustification($document, $reason),
        ])->save(), allowed: [NfseStatus::PendingSend, NfseStatus::SendFailed]);
    }

    /**
     * Aplica com a linha travada e só se ainda está num estado que aceita o cancelamento.
     *
     * @param callable(NfseDocument): mixed $apply
     * @param list<NfseStatus>              $allowed
     */
    private function markCancelled(string $documentId, callable $apply, array $allowed = [NfseStatus::Authorized]): bool
    {
        return DB::transaction(function () use ($documentId, $apply, $allowed): bool {
            $locked = NfseDocument::query()->whereKey($documentId)->lockForUpdate()->first();

            if ($locked === null || ! in_array($locked->status, $allowed, true)) {
                return false;
            }

            $apply($locked);

            return true;
        });
    }

    /** Traz da Focus o XML do cancelamento (fora de trava; falha só vai ao log, a nota já está cancelada). */
    private function pullCancellationDocuments(NfseDocument $document): void
    {
        try {
            $this->sync->syncDocument($document);
        } catch (Throwable $e) {
            Log::info('nfse.cancel.sync_failed', ['ref' => $document->ref, 'erro' => $e::class]);
        }
    }

    private function municipalityForbidsCancellation(NfseDocument $document): bool
    {
        $ibge = (string) data_get($document->request_payload, 'prestador.codigo_municipio', data_get($document->request_payload, 'codigo_municipio_emissora', ''));

        if (preg_match('/^\d{7}$/', $ibge) !== 1) {
            return false;
        }

        return $this->municipalities->cached($ibge, $document->environment)?->allowsCancellation === false;
    }

    /** Texto da justificativa do cancelamento por estorno/chargeback (15–255; documento fiscal, sempre em português). */
    private function reversalJustification(NfseDocument $document, string $reason): string
    {
        $reference = (string) Invoice::query()->whereKey($document->invoice_id)->value('reference');
        $key       = "fiscal.cancel.{$reason}_justification";

        $text = (string) trans($key, ['referencia' => $reference], 'pt_BR');

        if ($text === $key || mb_strlen($text) < 15) {
            $text = ($reason === InvoiceFullyReversed::CHARGEBACK ? 'Chargeback da fatura ' : 'Estorno total da fatura ') . $reference;
        }

        return mb_substr(NfsePayloadSanitizer::clean(['t' => $text])['t'], 0, 255);
    }
}
