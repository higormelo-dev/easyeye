<?php

declare(strict_types=1);

namespace App\Domains\ClinicalReview\Notifications;

use Carbon\CarbonInterface;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * E-mail do convite de revisão clínica (ADR 0002). Enviado com `Notification::sendNow`
 * para um endereço avulso — NUNCA `ShouldQueue`/`notify()`: quem chama só grava `sent_at`
 * depois do envio de verdade, para nunca confundir "enfileirado" com "enviado".
 *
 * O e-mail não carrega dado de paciente (a página também não) e não escreve o PIN: ele vai
 * por OUTRO canal. O link é pessoal; abri-lo (inclusive por um verificador de e-mail) não
 * gasta o convite.
 */
class ClinicalReviewInvitationNotification extends Notification
{
    /**
     * @param array<int, array{title: string, version: int}> $versions
     */
    public function __construct(
        public readonly string $reviewerName,
        public readonly string $url,
        public readonly CarbonInterface $expiresAt,
        public readonly array $versions,
        public readonly bool $pinRequired,
    ) {
        // Os Revisores são médicos brasileiros: o e-mail sai em português, qualquer que seja o idioma de quem convidou.
        $this->locale('pt_BR');
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage())
            ->subject(__('clinical_review.mail.subject'))
            ->greeting(__('clinical_review.mail.greeting', ['name' => $this->reviewerName]))
            ->line(__('clinical_review.mail.intro'))
            ->line(__('clinical_review.mail.versions_title'));

        foreach ($this->versions as $item) {
            $mail->line('- ' . __('clinical_review.mail.version_line', ['protocol' => $item['title'], 'version' => $item['version']]));
        }

        $mail->action(__('clinical_review.mail.action'), $this->url)
            ->line(__('clinical_review.mail.expires', ['date' => $this->expiresAt->copy()->timezone(config('app.timezone'))->format('d/m/Y')]));

        if ($this->pinRequired) {
            $mail->line(__('clinical_review.mail.pin'));
        }

        return $mail
            ->line(__('clinical_review.mail.privacy'))
            ->line(__('clinical_review.mail.unsolicited'))
            ->salutation(__('clinical_review.mail.salutation'));
    }

    public function toArray(object $notifiable): array
    {
        return ['versions' => $this->versions];
    }
}
