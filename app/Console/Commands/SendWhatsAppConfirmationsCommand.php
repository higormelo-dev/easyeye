<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\ScheduleSituation;
use App\Jobs\WhatsApp\SendWhatsAppMessageJob;
use App\Models\Schedule;
use App\Models\WhatsApp\WhatsAppSetting;
use App\Services\WhatsApp\WhatsAppService;
use Illuminate\Console\Command;

/**
 * Enfileira confirmações de consulta via WhatsApp (Z-API) para todas as
 * clínicas com a feature ativa.
 *
 * Seleção: consultas Scheduled cujo horário cai dentro da janela
 * [agora, agora + confirmation_hours_before] da clínica. A idempotência é
 * do banco (unique parcial 1 confirmação por consulta) — rodar o comando
 * várias vezes por dia nunca duplica mensagem.
 */
class SendWhatsAppConfirmationsCommand extends Command
{
    protected $signature = 'whatsapp:send-confirmations {--dry-run : Lista sem enfileirar}';

    protected $description = 'Envia confirmações de consulta via WhatsApp (Z-API) para as clínicas com a integração ativa';

    public function handle(WhatsAppService $service): int
    {
        // Clínica envia com credenciais próprias OU pela instância global do
        // SaaS — a linha global (entity_id null) não é uma clínica, sai daqui.
        $globalOk = WhatsAppSetting::globalSetting()?->isOperational() ?? false;

        $settings = WhatsAppSetting::query()
            ->whereNotNull('entity_id')
            ->where('active', true)
            ->where('confirmation_enabled', true)
            ->get()
            ->filter(fn (WhatsAppSetting $s) => $s->hasCredentials() || $globalOk);

        $queued = 0;

        foreach ($settings as $setting) {
            $hours = max(1, (int) $setting->confirmation_hours_before);

            $schedules = Schedule::query()
                ->withoutGlobalScopes() // worker/CLI: sem tenant em sessão
                ->where('entity_id', $setting->entity_id)
                ->where('situation', ScheduleSituation::Scheduled->value)
                ->where('active', true)
                ->whereNull('deleted_at')
                ->whereBetween('date_time', [now(), now()->addHours($hours)])
                ->whereDoesntHave('whatsappMessages', fn ($q) => $q
                    ->where('direction', 'out')
                    ->where('kind', 'confirmation'))
                ->with(['entity:id,name', 'doctor.person:id,full_name', 'patient.person:id,cellphone'])
                ->get();

            foreach ($schedules as $schedule) {
                $phone = WhatsAppService::resolveSchedulePhone($schedule);

                if ($phone === null) {
                    continue;
                }

                if ($this->option('dry-run')) {
                    $this->line("[dry-run] {$schedule->code} → {$phone} ({$schedule->date_time})");
                    $queued++;

                    continue;
                }

                $message = $service->queueConfirmation($setting, $schedule);

                if ($message !== null) {
                    SendWhatsAppMessageJob::dispatch((string) $message->id);
                    $queued++;
                }
            }
        }

        $this->info("Confirmações enfileiradas: {$queued}");

        return self::SUCCESS;
    }
}
