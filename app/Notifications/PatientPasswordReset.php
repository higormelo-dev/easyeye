<?php

declare(strict_types=1);

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use SensitiveParameter;

/**
 * Redefinição de senha do Portal do Paciente.
 *
 * NUNCA usar a notificação padrão Illuminate\Auth\Notifications\ResetPassword
 * aqui: ela monta o link via route('password.reset', ...), nome de rota que
 * já pertence à tela de reset do STAFF (routes/auth.php, guard "web", broker
 * "users"). A rota do portal só existe com o nome PREFIXADO
 * (patient-portal.password.reset) — a resolução de rota do Laravel bate na
 * do staff primeiro, então todo paciente recebia um link pra tela errada e
 * o reset falhava sempre (achado da revisão de segurança da área do
 * paciente — verificado com `route('password.reset', ...)` no tinker).
 */
class PatientPasswordReset extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        #[SensitiveParameter]
        public readonly string $token,
    ) {
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $url = route('patient-portal.password.reset', [
            'token' => $this->token,
            'email' => $notifiable->getEmailForPasswordReset(),
        ]);

        return (new MailMessage())
            ->subject('Redefinição de senha — Portal do Paciente')
            ->greeting('Olá!')
            ->line('Recebemos uma solicitação de redefinição de senha para sua conta no Portal do Paciente.')
            ->action('Redefinir senha', $url)
            ->line('Este link expira em 30 minutos e só pode ser usado uma vez.')
            ->line('Se você não solicitou isso, pode ignorar este e-mail com segurança — sua senha continua a mesma.');
    }
}
