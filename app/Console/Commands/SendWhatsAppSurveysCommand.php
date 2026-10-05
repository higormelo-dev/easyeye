<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\ScheduleSituation;
use App\Jobs\WhatsApp\SendWhatsAppMessageJob;
use App\Models\Schedule;
use App\Models\WhatsApp\{WhatsAppMessage, WhatsAppSetting};
use App\Services\Billing\ClinicServiceGate;
use App\Services\WhatsApp\WhatsAppService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Enfileira pesquisas de satisfação via WhatsApp (Z-API) para atendimentos
 * concluídos (Attended) há mais de survey_delay_hours.
 *
 * Comando (não hook no controller de agenda) de propósito: cobre também o
 * caminho de atualização em lote (bulkUpdateSituation, que não dispara
 * nenhum evento) e permite o delay pós-atendimento sem job com delay longo.
 * max_age_days evita spam retroativo ao ligar a feature numa clínica antiga.
 */
class SendWhatsAppSurveysCommand extends Command
{
    protected $signature = 'whatsapp:send-surveys {--dry-run : Lista sem enfileirar}';

    protected $description = 'Envia pesquisas de satisfação via WhatsApp (Z-API) após atendimentos concluídos';

    public function handle(WhatsAppService $service, ClinicServiceGate $gate): int
    {
        $maxAgeDays = (int) config('whatsapp.survey.max_age_days', 3);

        // Clínica envia com credenciais próprias OU pela instância global do
        // SaaS — a linha global (entity_id null) não é uma clínica, sai daqui.
        $globalOk = WhatsAppSetting::globalSetting()?->isOperational() ?? false;

        $settings = WhatsAppSetting::query()
            ->whereNotNull('entity_id')
            ->where('active', true)
            ->where('survey_enabled', true)
            ->get()
            ->filter(fn (WhatsAppSetting $s) => $s->hasCredentials() || $globalOk);

        $queued  = 0;
        $skipped = 0;

        foreach ($settings as $setting) {
            // Acesso da clínica bloqueado (assinatura): nada automático ao
            // paciente — pulado com o motivo; volta sozinho com o acesso.
            if (! $gate->allowsAutomation((string) $setting->entity_id)) {
                $skipped++;
                $this->line("[pulado] clínica {$setting->entity_id}: acesso bloqueado (assinatura)");
                Log::info('WhatsApp automático pulado: acesso da clínica bloqueado.', [
                    'entity_id' => $setting->entity_id,
                    'kind'      => 'survey',
                    'reason'    => ClinicServiceGate::REASON_ACCESS_BLOCKED,
                ]);

                continue;
            }

            $delay = max(0, (int) $setting->survey_delay_hours);

            $schedules = Schedule::query()
                ->withoutGlobalScopes()
                ->where('entity_id', $setting->entity_id)
                ->where('situation', ScheduleSituation::Attended->value)
                ->where('active', true)
                ->whereNull('deleted_at')
                // Consulta aconteceu: já passou do delay, mas não é velha demais.
                ->where('date_time', '<=', now()->subHours($delay))
                ->where('date_time', '>=', now()->subDays($maxAgeDays))
                // Pulada antes (acesso bloqueado) volta a ser enviada.
                ->whereDoesntHave('whatsappMessages', fn ($q) => $q
                    ->where('direction', 'out')
                    ->where('kind', 'survey')
                    ->where('status', '!=', WhatsAppMessage::STATUS_SKIPPED))
                ->with(['entity:id,name', 'patient.person:id,cellphone'])
                ->get();

            foreach ($schedules as $schedule) {
                $phone = WhatsAppService::resolveSchedulePhone($schedule);

                if ($phone === null) {
                    continue;
                }

                if ($this->option('dry-run')) {
                    $this->line("[dry-run] {$schedule->code} → {$phone}");
                    $queued++;

                    continue;
                }

                $message = $service->queueSurvey($setting, $schedule);

                if ($message !== null) {
                    SendWhatsAppMessageJob::dispatch((string) $message->id);
                    $queued++;
                }
            }
        }

        $this->info("Pesquisas enfileiradas: {$queued}" . ($skipped ? " · clínicas puladas (acesso bloqueado): {$skipped}" : ''));

        return self::SUCCESS;
    }
}
