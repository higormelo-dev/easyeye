<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\DoctorInvitation;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\URL;

/**
 * Convite de clínica para médico que já tem login no EasyEye — enviado ao
 * e-mail DO LOGIN (nunca ao e-mail digitado pela clínica). Só leva o nome da
 * clínica e o link assinado; nenhum dado do cadastro. Na fila, só escalares
 * (nada do payload criptografado do convite é serializado).
 */
class DoctorClinicInvitation extends Notification implements ShouldQueue
{
    use Queueable;

    public readonly string $invitationId;

    public readonly string $clinicName;

    public readonly int $expiresAt;

    public function __construct(DoctorInvitation $invitation)
    {
        $this->invitationId = (string) $invitation->id;
        $this->clinicName   = (string) ($invitation->entity?->name ?? config('app.name', 'EasyEye'));
        $this->expiresAt    = $invitation->expires_at->getTimestamp();
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $url = URL::temporarySignedRoute(
            'doctor-invitations.show',
            now()->setTimestamp($this->expiresAt),
            ['invitation' => $this->invitationId],
        );

        return (new MailMessage())
            ->subject(__('doctors.invitation.mail.subject', ['clinic' => $this->clinicName]))
            ->greeting(__('doctors.invitation.mail.greeting', ['name' => $notifiable->name ?? '']))
            ->line(__('doctors.invitation.mail.intro', ['clinic' => $this->clinicName]))
            ->line(__('doctors.invitation.mail.login_note'))
            ->action(__('doctors.invitation.mail.action'), $url)
            ->line(__('doctors.invitation.mail.expires', ['days' => DoctorInvitation::VALID_DAYS]));
    }
}
