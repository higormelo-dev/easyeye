<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Exceptions\Fiscal\NfseIssueRejectedException;
use App\Services\Fiscal\NfseIssueService;
use App\Support\Fiscal\{NfsePendingInvoices, NfseSettings};
use Illuminate\Console\Command;
use Throwable;

/**
 * Emissão em lote da NFS-e das faturas pagas que ficaram sem nota (listener que falhou, emissão automática ligada depois
 * do pagamento, erro de cadastro já corrigido). Agendada diariamente; também roda à mão.
 *
 * Cada fatura passa por NfseIssueService (as mesmas guardas e a mesma trava) com origem `batch`; quem for barrada fica
 * contada por motivo e segue na lista "Faturas pagas sem nota". Regras de elegibilidade (janela, teto de 3 notas por
 * fatura, espera de 6 h, nunca nota cancelada): ver NfsePendingInvoices.
 */
class ReissueNfseInvoicesCommand extends Command
{
    protected $signature = 'fiscal:nfse-reissue
        {--since= : Dias para trás a olhar (padrão: fiscal.reissue.window_days)}
        {--limit= : Máximo de faturas por execução (padrão: fiscal.reissue.limit)}
        {--dry-run : Só lista o que emitiria, sem criar nada}
        {--force : Roda mesmo com a emissão automática desligada}';

    protected $description = 'Emite a NFS-e das faturas pagas que ficaram sem nota (até 3 tentativas por fatura)';

    public function handle(NfseIssueService $issuer, NfseSettings $settings): int
    {
        $dryRun = (bool) $this->option('dry-run');

        if (! $settings->autoIssue() && ! $this->option('force')) {
            $this->info('Emissão automática desligada (Manager → Nota fiscal). Use --force para rodar mesmo assim.');

            return self::SUCCESS;
        }

        if (! $dryRun && ! $settings->isConfigured()) {
            $this->error('Prestador da NFS-e incompleto: faltam ' . implode(', ', $settings->missing()) . '. Nada foi emitido.');

            return self::FAILURE;
        }

        $days  = max(1, (int) ($this->option('since') ?: config('fiscal.reissue.window_days', 90)));
        $limit = max(1, (int) ($this->option('limit') ?: config('fiscal.reissue.limit', 50)));

        $invoices = NfsePendingInvoices::forBatch(now()->subDays($days))
            ->with('entity:id,name')
            ->orderBy('invoices.paid_at')
            ->limit($limit)
            ->get();

        if ($invoices->isEmpty()) {
            $this->info("Nenhuma fatura paga sem nota nos últimos {$days} dias.");

            return self::SUCCESS;
        }

        $this->info(($dryRun ? 'Emitiria' : 'Emitindo') . " {$invoices->count()} fatura(s) paga(s) sem nota (últimos {$days} dias, limite {$limit}):");

        $issued   = 0;
        $rejected = [];

        foreach ($invoices as $invoice) {
            $label = "{$invoice->reference} · " . ($invoice->entity?->name ?? $invoice->entity_id) . ' · ' . number_format((float) $invoice->amount, 2, ',', '.');

            if ($dryRun) {
                $this->line("  {$label}");

                continue;
            }

            try {
                $document = $issuer->issue($invoice, NfseIssueService::SOURCE_BATCH);

                $document->wasRecentlyCreated ? $issued++ : null;
                $this->line("  ✓ {$label}");
            } catch (NfseIssueRejectedException $e) {
                $rejected[$e->reason] = ($rejected[$e->reason] ?? 0) + 1;
                $this->line("  ✗ {$label} — {$e->getMessage()}");
            } catch (Throwable $e) {
                report($e);
                $rejected['erro'] = ($rejected['erro'] ?? 0) + 1;
                $this->line("  ✗ {$label} — erro inesperado (veja o log)");
            }
        }

        if (! $dryRun) {
            $this->info("Criadas: {$issued}. Barradas: " . array_sum($rejected) . ($rejected === [] ? '.' : ' (' . implode(', ', array_map(fn ($reason, $count) => "{$reason}: {$count}", array_keys($rejected), $rejected)) . ').'));
        }

        return self::SUCCESS;
    }
}
