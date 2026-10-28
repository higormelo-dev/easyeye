<?php

declare(strict_types=1);

namespace App\Support\Fiscal;

use App\Enums\Billing\InvoiceStatus;
use App\Enums\Fiscal\NfseStatus;
use App\Models\Billing\Invoice;
use App\Services\Billing\SubscriptionCycleService;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * Faturas pagas que ainda não têm NFS-e valendo ou a caminho — a mesma regra para a lista "Faturas pagas sem nota" do
 * Manager e para o comando `fiscal:nfse-reissue`.
 *
 * Elegível = paga, de clínica cliente, com valor, sem estorno total, que não é "pagamento não aplicado" e SEM nota
 * ocupando-a (a caminho ou autorizada). Fatura estornada por inteiro não é paga (`refunded`), então já fica de fora.
 *
 * O comando em lote é mais conservador que a lista (que deixa o humano reemitir qualquer uma):
 *  - só a janela recente (`fiscal.reissue.window_days`), para a emissão automática valer da entrada em produção em diante;
 *  - no máximo 3 notas por fatura e a última com mais de 6 h: um erro persistente (cadastro incompleto, token errado) não
 *    vira uma nota nova a cada execução;
 *  - nunca fatura com nota CANCELADA: cancelamento é decisão humana (ou estorno) e não se desfaz sozinho.
 */
final class NfsePendingInvoices
{
    /** Teto de tentativas por fatura na emissão em lote. */
    public const BATCH_MAX_DOCUMENTS = 3;

    /** Espera mínima depois da última tentativa antes de o lote tentar de novo. */
    public const BATCH_RETRY_AFTER_HOURS = 6;

    /** @return Builder<Invoice> */
    public static function forList(): Builder
    {
        return Invoice::query()
            ->where('invoices.status', InvoiceStatus::Paid->value)
            ->where('invoices.amount', '>', 0)
            ->whereColumn('invoices.refunded_amount', '<', 'invoices.amount')
            ->where(fn (Builder $q) => $q->whereNull('invoices.billing_reason')->orWhere('invoices.billing_reason', '!=', SubscriptionCycleService::BILLING_REASON_UNAPPLIED))
            ->whereExists(fn (QueryBuilder $q) => $q->selectRaw('1')->from('entities')
                ->whereColumn('entities.id', 'invoices.entity_id')
                ->where('entities.is_client', true))
            ->whereNotExists(fn (QueryBuilder $q) => $q->selectRaw('1')->from('nfse_documents')
                ->whereColumn('nfse_documents.invoice_id', 'invoices.id')
                ->whereIn('nfse_documents.status', NfseStatus::occupyingValues()));
    }

    /** @return Builder<Invoice> */
    public static function forBatch(CarbonInterface $since): Builder
    {
        return self::forList()
            ->where('invoices.paid_at', '>=', $since)
            ->whereNotExists(fn (QueryBuilder $q) => $q->selectRaw('1')->from('nfse_documents')
                ->whereColumn('nfse_documents.invoice_id', 'invoices.id')
                ->where('nfse_documents.status', NfseStatus::Cancelled->value))
            ->whereRaw('(select count(*) from nfse_documents where nfse_documents.invoice_id = invoices.id) < ?', [self::BATCH_MAX_DOCUMENTS])
            ->whereNotExists(fn (QueryBuilder $q) => $q->selectRaw('1')->from('nfse_documents')
                ->whereColumn('nfse_documents.invoice_id', 'invoices.id')
                ->where('nfse_documents.created_at', '>', now()->subHours(self::BATCH_RETRY_AFTER_HOURS)));
    }
}
