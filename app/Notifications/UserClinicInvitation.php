<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\{DoctorInvitation, EntityUserInvitation, SystemProfile};
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\URL;

/**
 * Convite de clínica para usuário que já tem login no EasyEye — enviado ao
 * e-mail DO LOGIN. Leva só o nome da clínica e o link assinado; na fila, só
 * escalares.
 */
class UserClinicInvitation extends Notification implements ShouldQueue
{
    use Queueable;

    public readonly string $invitationId;

    public readonly string $clinicName;

    public readonly int $expiresAt;

    public readonly string $role;

    public function __construct(EntityUserInvitation $invitation)
    {
        $this->invitationId = (string) $invitation->id;
        $this->clinicName   = (string) ($invitation->entity?->name ?? config('app.name', 'EasyEye'));
        $this->expiresAt    = $invitation->expires_at->getTimestamp();
        $this->role         = (string) (SystemProfile::labelMap(SystemProfile::CONTEXT_CLIENT)[$invitation->rule] ?? $invitation->rule);
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $url = URL::temporarySignedRoute(
            'user-invitations.show',
            now()->setTimestamp($this->expiresAt),
            ['invitation' => $this->invitationId],
        );

        return (new MailMessage())
            ->subject(__('access_control.invitation.mail.subject', ['clinic' => $this->clinicName]))
            ->greeting(__('access_control.invitation.mail.greeting', ['name' => $notifiable->name ?? '']))
            ->line(__('access_control.invitation.mail.intro', ['clinic' => $this->clinicName]))
            ->line(__('access_control.invitation.mail.role', ['role' => $this->role]))
            ->line(__('access_control.invitation.mail.login_note'))
            ->action(__('access_control.invitation.mail.action'), $url)
            ->line(__('access_control.invitation.mail.expires', ['days' => DoctorInvitation::VALID_DAYS]));
    }
}
