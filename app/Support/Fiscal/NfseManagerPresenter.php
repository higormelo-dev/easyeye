<?php

declare(strict_types=1);

namespace App\Support\Fiscal;

use App\Enums\Billing\InvoiceStatus;
use App\Enums\EntityGate;
use App\Enums\Fiscal\NfseStatus;
use App\Models\Billing\Invoice;
use App\Models\Entity;
use App\Models\Fiscal\NfseDocument;
use App\Services\Billing\SubscriptionCycleService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;

/**
 * Formato das notas para o Manager (página Nota fiscal e drawers de fatura) e para a clínica (Minha assinatura). Contrato
 * com o front: docs/internal/planos/nfse-focusnfe-plano.md §13.3. `actions` só traz a URL do que o status e o Gate de quem
 * está logado permitem; a clínica nunca vê ref, erros, snapshot nem ação.
 */
final class NfseManagerPresenter
{
    /**
     * Linha de nota do Manager.
     *
     * @return array<string, mixed>
     */
    public function row(NfseDocument $document): array
    {
        $document->loadMissing(['invoice:id,reference', 'entity:id,name']);

        return [
            'id'                     => $document->id,
            'ref'                    => $document->ref,
            'invoice_id'             => $document->invoice_id,
            'invoice_reference'      => $document->invoice?->reference,
            'entity'                 => ['id' => $document->entity_id, 'name' => $document->entity?->name],
            'environment'            => $document->environment->value,
            'standard'               => $document->standard->value,
            'status'                 => $document->status->value,
            'status_label'           => $document->status->label(),
            'status_badge'           => $document->status->badge(),
            'numero'                 => $document->numero,
            'codigo_verificacao'     => $document->codigo_verificacao,
            'amount'                 => (float) $document->amount,
            'currency'               => $document->currency,
            'issued_at'              => $document->issued_at?->toIso8601String(),
            'cancelled_at'           => $document->cancelled_at?->toIso8601String(),
            'cancelled_externally'   => (bool) $document->cancelled_externally,
            'cancel_required'        => (bool) $document->cancel_required,
            'cancel_required_reason' => $document->cancel_required_reason,
            'source'                 => $document->source,
            'errors'                 => $document->errors,
            'urls'                   => [
                'xml'        => filled($document->xml_path) || $document->isAuthorized() || $document->status === NfseStatus::Cancelled ? route('manager.nfse.documents.xml', $document) : null,
                'cancel_xml' => $document->status === NfseStatus::Cancelled && ! $document->cancelled_externally ? route('manager.nfse.documents.cancel-xml', $document) : null,
                'pdf'        => NfseUrlGuard::safeUrl($document->pdf_url),
                'mirror'     => NfseUrlGuard::safeUrl($document->url),
            ],
            'actions' => $this->actions($document),
        ];
    }

    /**
     * Bloco `nfse` das faturas dos drawers (contrato 13.3 + seção 17 do plano):
     *  - fatura com nota OCUPANDO-a (a caminho ou valendo): a linha dessa nota + `partial_refund_warning`;
     *  - fatura PAGA sem nota ocupando: `{status: null, status_label, actions: {issue}}` — o drawer mostra "Emitir NFS-e"
     *    (`actions` vazio sem o Gate de emissão) e `last_attempt` leva a última tentativa (erro, cancelada) para o motivo
     *    continuar visível;
     *  - fatura não paga, estornada por inteiro ou "pagamento não aplicado": null (não há o que emitir).
     *
     * @param Collection<int, Invoice> $invoices
     *
     * @return array<string, array<string, mixed>|null> id da fatura → bloco
     */
    public function forInvoices(Collection $invoices): array
    {
        if ($invoices->isEmpty()) {
            return [];
        }

        $documents = NfseDocument::query()
            ->whereIn('invoice_id', $invoices->pluck('id'))
            ->orderByDesc('created_at')
            ->get()
            ->groupBy('invoice_id');

        $blocks = [];

        foreach ($invoices as $invoice) {
            /** @var Collection<int, NfseDocument> $docs */
            $docs      = $documents->get($invoice->id, collect());
            $occupying = $docs->first(fn (NfseDocument $d) => $d->status->isOccupying());

            if ($occupying !== null) {
                $blocks[(string) $invoice->id] = $this->row($occupying) + [
                    // Estorno parcial depois da emissão: a nota segue válida; decidir se corrige é humano.
                    'partial_refund_warning' => $occupying->isAuthorized()
                        && (float) $invoice->refunded_amount > 0
                        && (float) $invoice->refunded_amount < (float) $invoice->amount,
                ];

                continue;
            }

            $blocks[(string) $invoice->id] = $this->isIssuable($invoice) ? [
                'status'                 => null,
                'status_label'           => __('fiscal.status.none'),
                'actions'                => Gate::allows(EntityGate::SaasFinancial->value, $this->saas()) ? ['issue' => route('manager.nfse.invoices.issue', $invoice)] : [],
                'partial_refund_warning' => false,
                'last_attempt'           => ($last = $docs->first()) ? $this->row($last) : null,
            ] : null;
        }

        return $blocks;
    }

    /**
     * Bloco `nfse` da clínica (Minha assinatura): só nota AUTORIZADA ou CANCELADA — pendente, em processamento ou com erro
     * não existe para ela. Nada de ref, erros, snapshot nem ação. As URLs de XML são as rotas autenticadas da clínica;
     * o PDF é o link público da Focus.
     *
     * @param Collection<int, Invoice> $invoices faturas de UMA clínica
     *
     * @return array<string, array<string, mixed>|null> id da fatura → bloco
     */
    public function clinicBlocks(Collection $invoices): array
    {
        if ($invoices->isEmpty()) {
            return [];
        }

        $documents = NfseDocument::query()
            ->whereIn('invoice_id', $invoices->pluck('id'))
            ->whereIn('entity_id', $invoices->pluck('entity_id')->unique())
            ->whereIn('status', [NfseStatus::Authorized->value, NfseStatus::Cancelled->value])
            ->orderByDesc('created_at')
            ->get()
            ->groupBy('invoice_id')
            ->map(fn (Collection $docs) => $docs->first(fn (NfseDocument $d) => $d->isAuthorized()) ?? $docs->first());

        $blocks = [];

        foreach ($invoices as $invoice) {
            /** @var NfseDocument|null $document */
            $document = $documents->get($invoice->id);

            $cancelled = $document?->status === NfseStatus::Cancelled;

            $blocks[(string) $invoice->id] = $document === null ? null : [
                'status'         => $document->status->value,
                'number'         => $document->numero,
                'issued_at'      => $document->issued_at?->toIso8601String(),
                'pdf_url'        => NfseUrlGuard::safeUrl($document->pdf_url),
                'xml_url'        => route('panel.my-subscription.nfse.xml', $document),
                'cancel_xml_url' => $cancelled && ! $document->cancelled_externally && (filled($document->cancel_xml_path) || filled(data_get($document->response_payload, 'caminho_xml_cancelamento')))
                    ? route('panel.my-subscription.nfse.cancel-xml', $document)
                    : null,
                'mirror_url' => NfseUrlGuard::safeUrl($document->url),
                'cancelled'  => $cancelled,
            ];
        }

        return $blocks;
    }

    /** Fatura que pode (e deve) ganhar nota: paga, com valor, sem estorno total e que não é "pagamento não aplicado". */
    private function isIssuable(Invoice $invoice): bool
    {
        return $invoice->status === InvoiceStatus::Paid
            && (float) $invoice->amount > 0
            && (float) $invoice->refunded_amount < (float) $invoice->amount
            && $invoice->billing_reason !== SubscriptionCycleService::BILLING_REASON_UNAPPLIED;
    }

    /** @return array<string, string> */
    private function actions(NfseDocument $document): array
    {
        $canIssue  = Gate::allows(EntityGate::SaasFinancial->value, $this->saas());
        $canCancel = Gate::allows(EntityGate::SaasAdminPanel->value, $this->saas());
        $actions   = [];

        if ($canIssue && ! $document->status->isOccupying()) {
            $actions['issue'] = route('manager.nfse.invoices.issue', $document->invoice_id);
        }

        if ($canCancel && $document->isAuthorized()) {
            $actions['cancel']                    = route('manager.nfse.documents.cancel', $document);
            $actions['mark_cancelled_externally'] = route('manager.nfse.documents.mark-cancelled-externally', $document);
        }

        if ($canIssue && $document->isAuthorized()) {
            $actions['resend_email'] = route('manager.nfse.documents.resend-email', $document);
        }

        if ($canIssue && in_array($document->status, [NfseStatus::Processing, NfseStatus::Authorized], true)) {
            $actions['sync'] = route('manager.nfse.documents.sync', $document);
        }

        return $actions;
    }

    private function saas(): Entity
    {
        return Entity::findOrFail(session('selected_entity_id'));
    }
}
