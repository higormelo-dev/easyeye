<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\WhatsApp\WhatsAppMessage;
use App\Services\WhatsApp\WhatsAppAlerts;
use Illuminate\Console\Command;

/**
 * Mensagens presas em `sending`: o worker morreu (ou travou) no meio da
 * chamada à Gupshup e nada voltou para a linha. Como a mensagem PODE ter
 * saído, ela não é reenviada — vira failed com error_code unknown_delivery
 * (o mesmo estado que o job grava quando encontra a linha assim), com alerta
 * ao time para conferir. Vale para todos os tipos (confirmação, pesquisa,
 * código do cadastro, aviso do SaaS).
 *
 * Confirmação/pesquisa não vira `skipped` de propósito: o comando a
 * reenviaria e o paciente poderia receber em dobro.
 *
 * "Presa" = `sending` sem atualização há mais de whatsapp.sending_stuck_minutes
 * (padrão 15) — bem acima do $timeout dos jobs (150 s).
 */
class SweepStuckWhatsAppMessagesCommand extends Command
{
    protected $signature = 'whatsapp:sweep-stuck';

    protected $description = 'Marca como falha (entrega desconhecida) as mensagens de WhatsApp presas em envio, com alerta';

    public function handle(): int
    {
        $minutes = max(5, (int) config('whatsapp.sending_stuck_minutes', 15));

        $stuck = WhatsAppMessage::query()
            ->where('direction', 'out')
            ->where('status', WhatsAppMessage::STATUS_SENDING)
            ->where('updated_at', '<', now()->subMinutes($minutes));

        $byKind = (clone $stuck)->selectRaw('kind, count(*) as total')->groupBy('kind')->pluck('total', 'kind');

        if ($byKind->isEmpty()) {
            $this->info('Nenhuma mensagem presa em envio.');

            return self::SUCCESS;
        }

        $total = $stuck->update([
            'status'     => WhatsAppMessage::STATUS_FAILED,
            'failed_at'  => now(),
            'error_code' => 'unknown_delivery',
            'error'      => "Presa em envio há mais de {$minutes} min (worker interrompido no meio da chamada à Gupshup): pode ter saído — não reenviada (conferir).",
            'updated_at' => now(),
        ]);

        $context = ['total' => $total];

        foreach ($byKind as $kind => $count) {
            $context['kind_' . $kind] = (int) $count;
        }

        WhatsAppAlerts::critical('stuck_sending', $context);

        $this->warn("Mensagens presas em envio marcadas como falha (unknown_delivery): {$total}");

        return self::SUCCESS;
    }
}
