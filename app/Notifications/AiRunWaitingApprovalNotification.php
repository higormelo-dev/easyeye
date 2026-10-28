<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Domains\AI\Models\AiRun;
use Carbon\CarbonInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Onda 4, C5 — Lembrete diário ao médico de runs IA aguardando decisão (aprovar
 * ou rejeitar) há mais de 24h. Crédito reservado fica preso enquanto o run não
 * é encerrado; lembrete força a decisão.
 *
 * Disparada pelo comando `ai:notify-waiting-approval` (schedule diário 07:00).
 */
class AiRunWaitingApprovalNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly AiRun $run,
    ) {
    }

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $run        = $this->run;
        $clinicName = $run->entity?->name ?? config('app.name');
        $patient    = $run->patient?->person?->full_name ?? $run->patient?->code ?? '—';
        $workflow   = __('ai.workflow_' . $run->workflow);
        $age        = $run->created_at?->diffForHumans(syntax: CarbonInterface::DIFF_ABSOLUTE) ?? '';
        $url        = route('panel.ai-runs.index', ['status' => 'waiting_approval']);

        return (new MailMessage())
            ->subject("[{$clinicName}] Análise de IA aguardando sua decisão")
            ->greeting('Olá, ' . ($notifiable->name ?? 'Doutor') . '!')
            ->line("Uma análise gerada pelo Assistente de IA está aguardando sua aprovação ou rejeição há **{$age}**.")
            ->line("**Paciente:** {$patient}")
            ->line("**Tipo:** {$workflow}")
            ->line('Créditos reservados ficam alocados até você decidir. Acesse o painel para aprovar, editar ou rejeitar.')
            ->action(__('ai.notifications.review_now', [], 'pt_BR') ?: 'Revisar agora', $url)
            ->salutation("— {$clinicName}");
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'ai_run_id' => (string) $this->run->id,
            'workflow'  => (string) $this->run->workflow,
        ];
    }
}
