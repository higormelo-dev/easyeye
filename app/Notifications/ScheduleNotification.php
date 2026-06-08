<?php

namespace App\Notifications;

use App\Models\Schedule;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ScheduleNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public const EVENT_CREATED = 'created';

    public const EVENT_CONFIRMED = 'confirmed';

    public const EVENT_CANCELLED = 'cancelled';

    public const EVENT_RESCHEDULED = 'rescheduled';

    public const EVENT_REMINDER = 'reminder';

    public function __construct(
        public readonly Schedule $schedule,
        public readonly string $event,
        public readonly ?string $extraInfo = null,
    ) {
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $clinicName = $this->schedule->entity?->name ?? config('app.name');
        $doctorName = $this->schedule->doctor?->abbreviation
            ?? $this->schedule->doctor?->user_name
            ?? 'Médico';
        $dateTime = $this->schedule->date_time->format('d/m/Y \à\s H:i');

        return match ($this->event) {
            self::EVENT_CREATED => (new MailMessage())
                ->subject("[{$clinicName}] Agendamento confirmado — {$dateTime}")
                ->greeting("Olá, {$this->schedule->full_name}!")
                ->line('Seu agendamento foi **confirmado**:')
                ->line("📅 **Data/Hora:** {$dateTime}")
                ->line("👨‍⚕️ **Médico:** {$doctorName}")
                ->line("📋 **Código:** {$this->schedule->code}")
                ->line('Lembre-se de chegar com 15 minutos de antecedência.')
                ->salutation("Até breve — {$clinicName}"),

            self::EVENT_CONFIRMED => (new MailMessage())
                ->subject("[{$clinicName}] Presença confirmada — {$dateTime}")
                ->greeting("Olá, {$this->schedule->full_name}!")
                ->line('Sua **presença foi confirmada**. Esperamos por você!')
                ->line("📅 **Data/Hora:** {$dateTime}")
                ->line("👨‍⚕️ **Médico:** {$doctorName}")
                ->salutation("Até breve — {$clinicName}"),

            self::EVENT_CANCELLED => (new MailMessage())
                ->subject("[{$clinicName}] Agendamento cancelado")
                ->greeting("Olá, {$this->schedule->full_name}.")
                ->line('Informamos que seu agendamento foi **cancelado**.')
                ->line("📅 **Era para:** {$dateTime}")
                ->when($this->extraInfo, fn ($mail) => $mail->line("**Motivo:** {$this->extraInfo}"))
                ->line('Entre em contato para reagendar.')
                ->salutation("Atenciosamente — {$clinicName}"),

            self::EVENT_RESCHEDULED => (new MailMessage())
                ->subject("[{$clinicName}] Agendamento remarcado — {$dateTime}")
                ->greeting("Olá, {$this->schedule->full_name}!")
                ->line('Seu agendamento foi **remarcado**:')
                ->line("📅 **Nova data/hora:** {$dateTime}")
                ->line("👨‍⚕️ **Médico:** {$doctorName}")
                ->when($this->extraInfo, fn ($mail) => $mail->line("**Observação:** {$this->extraInfo}"))
                ->salutation("Atenciosamente — {$clinicName}"),

            self::EVENT_REMINDER => (new MailMessage())
                ->subject("[{$clinicName}] Lembrete de consulta — {$dateTime}")
                ->greeting("Olá, {$this->schedule->full_name}!")
                ->line('Este é um **lembrete** do seu agendamento:')
                ->line("📅 **Data/Hora:** {$dateTime}")
                ->line("👨‍⚕️ **Médico:** {$doctorName}")
                ->line('Lembre-se de trazer seus documentos e exames anteriores.')
                ->salutation("Até logo — {$clinicName}"),

            default => (new MailMessage())
                ->subject("[{$clinicName}] Atualização no seu agendamento")
                ->line("Houve uma atualização no agendamento do dia {$dateTime}."),
        };
    }

    public function toArray(object $notifiable): array
    {
        return [
            'schedule_id' => $this->schedule->id,
            'event'       => $this->event,
        ];
    }
}
