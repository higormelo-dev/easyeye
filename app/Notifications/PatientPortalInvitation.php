<?php

declare(strict_types=1);

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\URL;

class PatientPortalInvitation extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly string $personId,
        public readonly string $patientName,
        public readonly string $clinicName,
    ) {
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        // Convite não reutilizável: InvitationController::accept()/store()
        // rejeitam o link (redirect para login) se já existir PatientAccount
        // para este person_id — inclusive em caso de reenvio do convite.
        $url = URL::temporarySignedRoute(
            'patient-portal.invitation.accept',
            now()->addDays(3),
            ['person_id' => $this->personId],
        );

        return (new MailMessage())
            ->subject("[{$this->clinicName}] Convite para o Portal do Paciente")
            ->greeting("Olá, {$this->patientName}!")
            ->line("A clínica **{$this->clinicName}** convidou você para acessar o **Portal do Paciente** do EasyEye.")
            ->line('Com um único login, você poderá acompanhar todas as clínicas onde já foi atendido.')
            ->action('Criar minha senha', $url)
            ->line('Este link expira em 3 dias e só pode ser usado uma vez.')
            ->line('Se você não esperava este e-mail, pode ignorá-lo com segurança.')
            ->salutation("Atenciosamente — {$this->clinicName}");
    }

    public function toArray(object $notifiable): array
    {
        return [
            'person_id' => $this->personId,
        ];
    }
}
