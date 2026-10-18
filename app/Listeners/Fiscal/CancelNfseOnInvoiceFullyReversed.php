<?php

declare(strict_types=1);

namespace App\Listeners\Fiscal;

use App\Enums\Fiscal\NfseStatus;
use App\Events\Billing\InvoiceFullyReversed;
use App\Models\Fiscal\NfseDocument;
use App\Services\Fiscal\NfseCancellationService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Queue\InteractsWithQueue;
use Throwable;

/**
 * Fatura devolvida por inteiro (estorno total ou chargeback) → cancela a NFS-e que a ocupa.
 *
 * Roda na FILA e depois do commit: o webhook de cobrança não passa a depender da Focus (fora do ar, só esta tarefa
 * repete). Fatura sem nota é um no-op. Falha definitiva da Focus (município sem cancelamento, prazo, erro) deixa a nota
 * marcada `cancel_required` — a pendência aparece no Manager; falha de rede repete com backoff e, esgotadas as tentativas,
 * também vira pendência (`failed`). Estorno parcial nunca chega aqui.
 */
class CancelNfseOnInvoiceFullyReversed implements ShouldQueue
{
    use InteractsWithQueue;

    public int $tries = 5;

    public bool $afterCommit = true;

    /** @return list<int> */
    public function backoff(): array
    {
        return [60, 300, 1800, 3600];
    }

    public function viaQueue(): string
    {
        return (string) config('fiscal.queue', 'default');
    }

    public function handle(InvoiceFullyReversed $event): void
    {
        $service = app(NfseCancellationService::class);

        foreach ($this->documents($event) as $document) {
            $service->forReversal($document, $event->reason);
        }
    }

    public function failed(InvoiceFullyReversed $event, Throwable $exception): void
    {
        $service = app(NfseCancellationService::class);

        foreach ($this->documents($event) as $document) {
            $service->flag($document, $event->reason, 'unreachable');
        }
    }

    /** @return Collection<int, NfseDocument> */
    private function documents(InvoiceFullyReversed $event)
    {
        return NfseDocument::query()
            ->where('invoice_id', $event->invoiceId)
            ->whereIn('status', [...NfseStatus::occupyingValues(), NfseStatus::SendFailed->value])
            ->get();
    }
}
