<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\Fiscal\NfseStatus;
use App\Exceptions\Fiscal\FocusNfeException;
use App\Jobs\Fiscal\SendNfseJob;
use App\Models\Fiscal\NfseDocument;
use App\Services\Fiscal\NfseSyncService;
use Illuminate\Console\Command;

/**
 * Rede de segurança do webhook da Focus. A Focus retenta o aviso por até 48 h e depois só a consulta direta resolve; este
 * comando (a cada 10 min) faz essa consulta:
 *
 *  - nota `processando_autorizacao` há mais de `fiscal.sync.after_minutes` → GET na Focus e aplica o que ela disser
 *    (autorizada, com erro, cancelada) — a mesma gravação idempotente do webhook;
 *  - nota `pending_send` há mais de `fiscal.sync.stale_pending_minutes` (job perdido: fila limpa, worker morto) → reenvia
 *    o `SendNfseJob`, com a mesma ref — o job é idempotente e confere na Focus antes de reenviar.
 *
 * Teto de `fiscal.sync.limit` notas por execução. Token recusado/ausente de um ambiente interrompe as consultas DELE (não
 * adianta repetir 100 vezes o mesmo 401); rede e limite seguem para a próxima nota.
 */
class SyncNfseDocumentsCommand extends Command
{
    protected $signature = 'fiscal:nfse-sync {--dry-run : Só lista o que consultaria/reenviaria}';

    protected $description = 'Consulta na Focus as NFS-e paradas em processamento e reenvia as que nunca saíram';

    public function handle(NfseSyncService $sync): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $limit  = max(1, (int) config('fiscal.sync.limit', 100));

        $processing = NfseDocument::query()
            ->where('status', NfseStatus::Processing->value)
            ->where('updated_at', '<', now()->subMinutes(max(1, (int) config('fiscal.sync.after_minutes', 10))))
            ->orderBy('updated_at')
            ->limit($limit)
            ->get();

        $pending = NfseDocument::query()
            ->where('status', NfseStatus::PendingSend->value)
            ->where('updated_at', '<', now()->subMinutes(max(1, (int) config('fiscal.sync.stale_pending_minutes', 15))))
            ->orderBy('updated_at')
            ->limit($limit)
            ->get();

        $this->info(sprintf('%d nota(s) em processamento para consultar, %d pending_send parada(s) para reenviar.', $processing->count(), $pending->count()));

        $synced      = 0;
        $failed      = 0;
        $blockedEnvs = [];

        foreach ($processing as $document) {
            if ($dryRun) {
                $this->line("  consultaria {$document->ref} ({$document->environment->value})");

                continue;
            }

            if (isset($blockedEnvs[$document->environment->value])) {
                continue;
            }

            try {
                $sync->syncDocument($document);
                $synced++;
            } catch (FocusNfeException $e) {
                $failed++;

                if (in_array($e->errorCode, [FocusNfeException::AUTH, FocusNfeException::NOT_CONFIGURED], true)) {
                    $blockedEnvs[$document->environment->value] = true;
                    $this->warn("  token do ambiente {$document->environment->value} recusado/ausente: consultas desse ambiente interrompidas.");
                }
            }
        }

        foreach ($pending as $document) {
            if ($dryRun) {
                $this->line("  reenviaria {$document->ref}");

                continue;
            }

            SendNfseJob::dispatch((string) $document->id);
        }

        if (! $dryRun) {
            $this->info("Consultadas: {$synced}. Falhas na consulta: {$failed}. Reenvios enfileirados: {$pending->count()}.");
        }

        return self::SUCCESS;
    }
}
