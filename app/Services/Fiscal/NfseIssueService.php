<?php

declare(strict_types=1);

namespace App\Services\Fiscal;

use App\Enums\Billing\InvoiceStatus;
use App\Enums\Fiscal\{NfseEnvironment, NfseStandard, NfseStatus};
use App\Exceptions\Fiscal\NfseIssueRejectedException as Rejected;
use App\Jobs\Fiscal\SendNfseJob;
use App\Models\Billing\Invoice;
use App\Models\{Entity, User};
use App\Models\Fiscal\NfseDocument;
use App\Services\Billing\{CorrelationIdService, SubscriptionCycleService};
use App\Support\Fiscal\{NfseSettings, NfseTomadorSnapshot};
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Único caminho de criação de NFS-e do SaaS. Três chamadores: o listener do `InvoicePaid` (`auto`), a ação manual do
 * Manager (`manual`) e o comando de reemissão em lote (`batch`).
 *
 * Só CRIA a linha (`pending_send`) e enfileira o envio — nunca chama a Focus aqui:
 *  - a fatura é travada (`FOR UPDATE`) numa transação curta, SEM HTTP dentro; duas emissões simultâneas da mesma fatura
 *    serializam nessa trava, e o índice único parcial `nfse_documents_invoice_occupying_unique` é o backstop;
 *  - a linha congela o valor pago e o retrato do tomador (`payer_snapshot`); no padrão nacional também reserva a série e o
 *    número da DPS sob trava (o número volta junto se a transação desfizer);
 *  - ambiente e padrão ficam gravados na linha: mudar a configuração depois não troca o destino de uma nota em curso;
 *  - o `ref` nasce com a linha e nunca muda (envio, consulta, cancelamento e e-mail usam o mesmo).
 *
 * Fatura com nota CANCELADA: `auto` e `batch` devolvem a cancelada sem criar outra; só a emissão `manual` reemite.
 *
 * Valor da nota = o pago (`invoice.amount`). Estorno parcial posterior não a reescreve (regra fiscal).
 */
class NfseIssueService
{
    public const SOURCE_AUTO = 'auto';

    public const SOURCE_MANUAL = 'manual';

    public const SOURCE_BATCH = 'batch';

    public function __construct(
        private readonly NfseSettings $settings,
        private readonly NfseCredentialResolver $credentials,
        private readonly NfseMunicipalityService $municipalities,
        private readonly CorrelationIdService $correlation,
    ) {
    }

    /**
     * @param string $source auto | manual | batch
     *
     * @return NfseDocument a nota criada (`wasRecentlyCreated`) ou, na emissão automática/em lote, a que já ocupava a fatura
     *
     * @throws Rejected uma guarda barrou (nada foi criado); na emissão manual, também quando a fatura já tem nota
     */
    public function issue(Invoice $invoice, string $source = self::SOURCE_MANUAL, ?User $by = null): NfseDocument
    {
        try {
            $document = DB::transaction(fn () => $this->create($invoice, $source, $by));
        } catch (UniqueConstraintViolationException $e) {
            // Corrida que a trava da fatura não pegou (outra conexão chegou ao INSERT antes): vale a linha vencedora.
            $document = $this->occupying($invoice->getKey()) ?? throw $e;

            if ($source === self::SOURCE_MANUAL) {
                throw Rejected::because(Rejected::DOCUMENT_EXISTS);
            }
        }

        if ($document->wasRecentlyCreated) {
            SendNfseJob::dispatch((string) $document->id)->afterCommit();
        }

        return $document;
    }

    /** Cria a linha com a fatura travada. `protected` só para o teste simular a corrida que escapa da trava. */
    protected function create(Invoice $invoice, string $source, ?User $by): NfseDocument
    {
        // Trava a fatura: nenhuma outra emissão dela passa daqui até este commit. Sem HTTP dentro.
        $locked = Invoice::query()->whereKey($invoice->getKey())->lockForUpdate()->firstOrFail();

        if (($existing = $this->occupying($locked->id)) !== null) {
            if ($source === self::SOURCE_MANUAL) {
                throw Rejected::because(Rejected::DOCUMENT_EXISTS);
            }

            return $existing;
        }

        // Nota cancelada é decisão humana (ou estorno): emissão automática e em lote NUNCA a refazem sozinhas. Só quem
        // emite à mão, no Manager, pode reemitir depois de cancelar.
        if ($source !== self::SOURCE_MANUAL && ($cancelled = $this->cancelled($locked->id)) !== null) {
            return $cancelled;
        }

        $standard    = $this->settings->standard();
        $environment = $this->settings->environment();
        $entity      = Entity::withTrashed()->findOrFail($locked->entity_id);

        $this->guard($locked, $entity, $standard, $environment);

        $document = new NfseDocument([
            'invoice_id'     => $locked->id,
            'entity_id'      => $locked->entity_id,
            'environment'    => $environment->value,
            'standard'       => $standard->value,
            'status'         => NfseStatus::PendingSend->value,
            'amount'         => $locked->amount,
            'currency'       => $locked->currency ?: 'BRL',
            'payer_snapshot' => NfseTomadorSnapshot::from($entity),
            'correlation_id' => $this->correlation->resolve(),
            'source'         => $source,
        ]);

        if ($standard === NfseStandard::National) {
            ['series' => $series, 'number' => $number] = $this->settings->reserveNextDpsNumber();

            $document->dps_series = $series;
            $document->dps_number = $number;
        }

        if ($by !== null) {
            $document->created_by = $by->id;
        }

        $document->save();

        return $document;
    }

    /** @throws Rejected */
    private function guard(Invoice $invoice, Entity $entity, NfseStandard $standard, NfseEnvironment $environment): void
    {
        // Fatura de "pagamento não aplicado" é registro de dinheiro recebido sem fatura (vai para estorno), nunca receita.
        if ($invoice->status === InvoiceStatus::Refunded) {
            throw Rejected::because(Rejected::INVOICE_REFUNDED);
        }

        if ($invoice->status !== InvoiceStatus::Paid || $invoice->billing_reason === SubscriptionCycleService::BILLING_REASON_UNAPPLIED) {
            throw Rejected::because(Rejected::INVOICE_NOT_PAID);
        }

        if ((float) $invoice->refunded_amount >= (float) $invoice->amount && (float) $invoice->amount > 0) {
            throw Rejected::because(Rejected::INVOICE_REFUNDED);
        }

        if ((float) $invoice->amount <= 0) {
            throw Rejected::because(Rejected::ZERO_AMOUNT);
        }

        if (! NfseTomadorSnapshot::hasValidDocument($entity)) {
            throw Rejected::because(Rejected::INVALID_DOCUMENT);
        }

        $missing = $this->settings->missing($standard);

        if ($missing !== []) {
            throw Rejected::because(Rejected::ISSUER_INCOMPLETE, ['fields' => $missing]);
        }

        if (! $this->credentials->hasToken($environment)) {
            throw Rejected::because(Rejected::CREDENTIAL_MISSING);
        }

        $this->guardMunicipality($entity, $environment);
    }

    /**
     * O que o município do PRESTADOR exige (cache da Focus; sem cópia guardada não bloqueia — a validação síncrona da
     * Focus responde). `null` = a Focus não informa: tratado como "não sei", nunca como "exige".
     *
     * @throws Rejected
     */
    private function guardMunicipality(Entity $entity, NfseEnvironment $environment): void
    {
        $prestador    = $this->settings->prestador();
        $municipality = $this->municipalities->cached((string) $prestador['codigo_municipio'], $environment);

        if ($municipality === null) {
            return;
        }

        $missing = [];

        if ($municipality->cnaeRequired === true && blank($prestador['cnae'])) {
            $missing[] = 'prestador.cnae';
        }

        if ($municipality->municipalTaxCodeRequired === true && blank($prestador['codigo_tributario_municipio'])) {
            $missing[] = 'prestador.codigo_tributario_municipio';
        }

        if ($missing !== []) {
            throw Rejected::because(Rejected::ISSUER_INCOMPLETE, ['fields' => $missing]);
        }

        if ($municipality->addressRequired === true) {
            $gaps = NfseTomadorSnapshot::missingAddressFields($entity);

            if ($gaps === ['codigo_municipio']) {
                throw Rejected::because(Rejected::IBGE_MISSING);
            }

            if ($gaps !== []) {
                throw Rejected::because(Rejected::ADDRESS_REQUIRED, ['fields' => $gaps]);
            }
        }
    }

    /** A nota cancelada mais recente da fatura, se houver. */
    private function cancelled(mixed $invoiceId): ?NfseDocument
    {
        return NfseDocument::query()
            ->where('invoice_id', $invoiceId)
            ->where('status', NfseStatus::Cancelled->value)
            ->latest('created_at')
            ->first();
    }

    /** A nota que está ocupando a fatura (a caminho ou valendo), se houver. */
    private function occupying(mixed $invoiceId): ?NfseDocument
    {
        return NfseDocument::query()
            ->where('invoice_id', $invoiceId)
            ->whereIn('status', NfseStatus::occupyingValues())
            ->first();
    }
}
