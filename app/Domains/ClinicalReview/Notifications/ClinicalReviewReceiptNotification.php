<?php

declare(strict_types=1);

namespace App\Domains\ClinicalReview\Notifications;

use Carbon\CarbonInterface;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Recibo do parecer: o Revisor guarda o código de verificação (hash do envio), as decisões
 * e a hora. Sem texto de parecer nem dado de paciente. Síncrono, como o convite.
 */
class ClinicalReviewReceiptNotification extends Notification
{
    /**
     * @param array<int, array{title: string, version: int, decision: string}> $decisions
     */
    public function __construct(
        public readonly string $reviewerName,
        public readonly CarbonInterface $submittedAt,
        public readonly string $submissionHash,
        public readonly array $decisions,
    ) {
        $this->locale('pt_BR');
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage())
            ->subject(__('clinical_review.receipt.subject'))
            ->greeting(__('clinical_review.mail.greeting', ['name' => $this->reviewerName]))
            ->line(__('clinical_review.receipt.intro', ['when' => $this->submittedAt->copy()->timezone(config('app.timezone'))->format('d/m/Y H:i')]));

        foreach ($this->decisions as $row) {
            $mail->line('- ' . __('clinical_review.receipt.decision_line', [
                'protocol' => $row['title'],
                'version'  => $row['version'],
                'decision' => $row['decision'],
            ]));
        }

        return $mail
            ->line(__('clinical_review.receipt.code', ['code' => $this->submissionHash]))
            ->line(__('clinical_review.receipt.next'))
            ->line(__('clinical_review.receipt.unrecognized'))
            ->salutation(__('clinical_review.mail.salutation'));
    }

    public function toArray(object $notifiable): array
    {
        return ['submission_hash' => $this->submissionHash];
    }
}
