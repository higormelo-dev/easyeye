<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\WhatsApp\SendWhatsAppMessageJob;
use App\Models\WhatsApp\WhatsAppMessage;
use Illuminate\Console\Command;

/**
 * Rede de segurança do convite de retorno: a onda é gravada numa transação e o job é enfileirado DEPOIS do commit; se o
 * processo morre no meio, fica uma mensagem `pending` sem job. Esta rotina reenfileira a que passou de
 * `recall.invites.requeue_after_minutes` (o job é único por mensagem — reenfileirar duas vezes é inofensivo) e cancela
 * a que não saiu em `recall.invites.expire_pending_days` (fila parada, fora da janela de horário): convite velho não
 * faz mais sentido, e a rodada seguinte cria uma onda nova se o retorno ainda estiver em aberto.
 */
class RequeueRecallInvitesCommand extends Command
{
    protected $signature = 'recall:requeue-pending';

    protected $description = 'Reenfileira convites de retorno parados em pending e cancela os que passaram do prazo';

    public function handle(): int
    {
        $requeueAfter = now()->subMinutes(max(1, (int) config('recall.invites.requeue_after_minutes', 5)));
        $expireBefore = now()->subDays(max(1, (int) config('recall.invites.expire_pending_days', 3)));

        $pending = fn () => WhatsAppMessage::query()
            ->where('direction', 'out')
            ->where('kind', WhatsAppMessage::KIND_RECALL)
            ->where('status', WhatsAppMessage::STATUS_PENDING);

        $expired = $pending()->where('created_at', '<', $expireBefore)->update([
            'status'     => WhatsAppMessage::STATUS_SUPPRESSED,
            'error_code' => 'recall_expired',
            'error'      => 'Convite de retorno cancelado: não saiu no prazo.',
            'updated_at' => now(),
        ]);

        $requeued = 0;

        $pending()->where('created_at', '<', $requeueAfter)->where('created_at', '>=', $expireBefore)
            ->orderBy('created_at')
            ->pluck('id')
            ->each(function (string $id) use (&$requeued): void {
                SendWhatsAppMessageJob::dispatch($id);
                $requeued++;
            });

        $this->info("Reenfileirados: {$requeued} · cancelados por prazo: {$expired}");

        return self::SUCCESS;
    }
}
