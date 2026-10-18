<?php

declare(strict_types=1);

namespace App\Services\Fiscal;

use App\DTOs\Fiscal\NfsePayloadContext;
use App\Enums\Fiscal\NfseStatus;
use App\Exceptions\Fiscal\FocusNfeException;
use App\Models\Billing\Invoice;
use App\Models\Fiscal\NfseDocument;
use App\Services\Billing\BillingLogService;
use App\Services\Fiscal\Payloads\NfsePayloadFactory;
use App\Support\Fiscal\{NfseDiscriminacao, NfseLogText, NfsePayloadSanitizer, NfseSettings};
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Support\Facades\{DB, Log};
use Throwable;

/**
 * Envia à Focus a nota criada por NfseIssueService (`pending_send` → `processando_autorizacao`). É o miolo do SendNfseJob;
 * não segura trava de fatura nem de banco durante o HTTP.
 *
 *  - O corpo é montado UMA vez e guardado (`request_payload`, saneado): os reenvios depois de queda reaproveitam o mesmo
 *    JSON, com a mesma `ref`, a mesma série/número de DPS e a mesma data de emissão (a da criação da nota);
 *  - 201/202 → `processando_autorizacao`; o desfecho vem por webhook ou pela varredura;
 *  - 400/422 → `erro_autorizacao` com os erros da própria Focus (sem retentativa: repetir dá o mesmo erro);
 *  - 401/403, credencial ausente ou 404 → `send_failed` (sem retentativa até alguém corrigir o token/configuração);
 *  - 429, 5xx e queda de conexão → exceção, para o job tentar de novo com backoff (a nota segue `pending_send`);
 *  - reenvio (já houve tentativa): consulta a ref ANTES de repetir o POST e adota o que a Focus já souber;
 *  - 422 `nfe_autorizada` → a Focus já tem a ref (resposta perdida no envio anterior): vira `processando_autorizacao` e a
 *    consulta imediata traz a nota;
 *  - toda mudança de estado é condicional ao estado atual (travada na linha): um webhook que chegue antes não é
 *    "desfeito" por uma resposta que demorou.
 */
final class NfseSendService
{
    /** Estados em que o envio ainda pode agir; a partir de `processando_autorizacao` a nota é da Focus. */
    private const SENDABLE = [NfseStatus::PendingSend, NfseStatus::SendFailed];

    public function __construct(
        private readonly NfseSettings $settings,
        private readonly NfseCredentialResolver $credentials,
        private readonly NfsePayloadFactory $payloads,
        private readonly BillingLogService $billingLog,
        private readonly NfseSyncService $sync,
    ) {
    }

    /**
     * @throws FocusNfeException 429 / 5xx / conexão: repetir depois
     */
    public function send(string $documentId): void
    {
        $document = NfseDocument::query()->find($documentId);

        if ($document === null || ! in_array($document->status, self::SENDABLE, true)) {
            return;
        }

        try {
            $client  = $this->credentials->client($document->environment, $document->correlation_id);
            $retry   = $document->request_payload !== null;
            $payload = $document->request_payload ?: $this->buildAndStorePayload($document);

            // Já houve uma tentativa: a Focus pode ter recebido o pedido e só a resposta se perdeu (queda, timeout).
            // Confere pela ref ANTES de repetir o POST e adota o que ela souber.
            if ($retry && ($remote = $client->show($document->standard, $document->ref)) !== null) {
                $adopted = $this->sync->apply($document->ref, $document->environment, $remote, 'send');

                if ($adopted !== null && ! in_array($adopted->status, self::SENDABLE, true)) {
                    return;
                }
            }

            $response = $client->issue($document->standard, $document->ref, $payload);
        } catch (FocusNfeException $e) {
            $this->fail($document, $e);

            return;
        }

        $this->transition($document->id, fn (NfseDocument $locked) => $locked->forceFill([
            'status'           => NfseStatus::Processing->value,
            'response_payload' => NfsePayloadSanitizer::clean($response),
            'errors'           => null,
        ])->save());
    }

    /** Esgotou as tentativas (ou erro inesperado): a nota vira `send_failed` e o time é avisado. */
    public function markSendFailed(string $documentId, Throwable $reason): void
    {
        $this->transition($documentId, function (NfseDocument $locked) use ($reason): void {
            $message = $reason instanceof FocusNfeException ? $reason->userMessage() : __('fiscal.errors.focus_unavailable');

            $locked->forceFill([
                'status' => NfseStatus::SendFailed->value,
                'errors' => [['codigo' => 'send_failed', 'mensagem' => $message, 'correcao' => null]],
            ])->save();

            $this->alert($locked, 'error', 'NFS-e não enviada à Focus depois de todas as tentativas.', [
                'codigo' => NfseLogText::code($reason instanceof FocusNfeException ? $reason->errorCode : 'inesperado'),
            ]);
        });
    }

    private function buildAndStorePayload(NfseDocument $document): array
    {
        $invoice = Invoice::query()->with(['plan', 'subscription.plan', 'entity'])->findOrFail($document->invoice_id);
        $when    = CarbonImmutable::instance($document->created_at);

        $payload = NfsePayloadSanitizer::clean($this->payloads->for($document->standard)->build(new NfsePayloadContext(
            issuedAt: $when,
            competence: CarbonImmutable::instance($invoice->period_start ?? $invoice->paid_at ?? $when)->startOfDay(),
            amount: (float) $document->amount,
            description: NfseDiscriminacao::forInvoice($invoice, $this->settings),
            prestador: $this->settings->prestador(),
            payer: (array) $document->payer_snapshot,
            dpsSeries: $document->dps_series,
            dpsNumber: $document->dps_number,
            descriptionLimit: max(1, (int) config('fiscal.limits.discriminacao', 2000)),
        )));

        $document->forceFill(['request_payload' => $payload])->save();

        return $payload;
    }

    /** @throws FocusNfeException quando vale tentar de novo */
    private function fail(NfseDocument $document, FocusNfeException $e): void
    {
        if ($e->retryable()) {
            throw $e;
        }

        // A Focus já conhece esta ref como autorizada: o envio anterior chegou e só a resposta se perdeu. Fica processando e
        // a consulta traz a nota na hora (se falhar, o webhook ou a varredura trazem).
        if ($e->errorCode === FocusNfeException::ALREADY_AUTHORIZED) {
            $this->transition($document->id, fn (NfseDocument $locked) => $locked->forceFill([
                'status' => NfseStatus::Processing->value,
                'errors' => null,
            ])->save());

            try {
                $this->sync->syncDocument($document->fresh());
            } catch (Throwable $syncError) {
                Log::info('nfse.send.sync_after_authorized_failed', ['ref' => $document->ref, 'erro' => $syncError::class]);
            }

            return;
        }

        // 400/422: a Focus recusou o conteúdo (erro_autorizacao, mostra os erros). O resto (token, credencial, 404):
        // a nota nem foi avaliada (send_failed) e só destrava quando alguém corrigir a configuração.
        $rejected = in_array($e->errorCode, [FocusNfeException::INVALID_REQUEST, FocusNfeException::VALIDATION], true);
        $errors   = $rejected && $e->errors !== []
            ? $e->errors
            : [['codigo' => $e->errorCode, 'mensagem' => $e->userMessage(), 'correcao' => null]];

        $this->transition($document->id, function (NfseDocument $locked) use ($rejected, $errors, $e): void {
            $locked->forceFill([
                'status' => ($rejected ? NfseStatus::AuthorizationError : NfseStatus::SendFailed)->value,
                'errors' => $errors,
            ])->save();

            $this->alert($locked, 'warning', $rejected ? 'NFS-e recusada pela Focus no envio.' : 'NFS-e não enviada: configuração da Focus.', [
                'codigo'  => NfseLogText::code($e->errorCode),
                'codigos' => array_values(array_filter(array_map(fn ($code) => NfseLogText::code((string) $code), array_column($errors, 'codigo')))),
            ]);
        });
    }

    /**
     * Aplica a mudança com a linha travada e SÓ se ela ainda está esperando o envio: um webhook (ou a varredura) que já a
     * tenha adiantado vence uma resposta que demorou.
     *
     * @param Closure(NfseDocument): mixed $apply
     */
    private function transition(string $documentId, Closure $apply): void
    {
        DB::transaction(function () use ($documentId, $apply): void {
            $locked = NfseDocument::query()->whereKey($documentId)->lockForUpdate()->first();

            if ($locked === null || ! in_array($locked->status, self::SENDABLE, true)) {
                return;
            }

            $apply($locked);
        });
    }

    /** @param array<string, mixed> $context */
    private function alert(NfseDocument $document, string $level, string $message, array $context): void
    {
        $context += ['nfse_document_id' => $document->id, 'ref' => $document->ref, 'status' => $document->status->value];

        Log::{$level}('nfse.send', $context);

        $this->billingLog->log(
            level: $level,
            message: $message,
            context: $context,
            entityId: (string) $document->entity_id,
            invoice: Invoice::query()->find($document->invoice_id),
            correlationId: $document->correlation_id,
        );
    }
}
