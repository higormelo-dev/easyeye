<?php

declare(strict_types=1);

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\URL;

/**
 * Laudo de imagem pronto pro médico solicitante (Fase 5) — link assinado
 * (`URL::temporarySignedRoute`, HMAC com APP_KEY, sem conta/portal), mesmo
 * padrão de App\Notifications\PatientPortalInvitation. Diferente daquela
 * (texto fixo, inconsistência já conhecida): aqui todo texto vem de
 * lang/eye_images.php desde o início.
 *
 * Enviada via Notification::sendNow(Notification::route('mail', $email), ...)
 * — NUNCA ShouldQueue/->notify(): quem chama (EyeImageReportResendController,
 * o único caminho de envio — e só com o prontuário ASSINADO) só grava
 * requesting_doctor_sent_at depois do envio de verdade ter sucesso, pra nunca
 * confundir "enfileirado" com
 * "enviado" (uma fila parada/job falho marcava sent_at mesmo sem nenhum
 * e-mail ter saído — achado via revisão Codex). O médico solicitante é texto
 * livre, pode não ter cadastro algum no EasyEye.
 */
class RequestingDoctorReportReady extends Notification
{
    public function __construct(
        public readonly string $documentationId,
        public readonly string $requestingDoctorName,
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
        $url = URL::temporarySignedRoute(
            'external-reports.show',
            now()->addDays((int) config('eye_image_capture.external_doctor_link.expires_days')),
            ['documentation' => $this->documentationId],
        );

        $expiresDays = (int) config('eye_image_capture.external_doctor_link.expires_days');

        return (new MailMessage())
            ->subject(__('eye_images.requesting_doctor_mail_subject', ['clinic' => $this->clinicName]))
            ->greeting(__('eye_images.requesting_doctor_mail_greeting', ['doctor' => $this->requestingDoctorName]))
            ->line(__('eye_images.requesting_doctor_mail_line1', ['clinic' => $this->clinicName, 'patient' => $this->patientName]))
            ->action(__('eye_images.requesting_doctor_mail_action'), $url)
            ->line(__('eye_images.requesting_doctor_mail_expires', ['days' => $expiresDays]))
            ->line(__('eye_images.requesting_doctor_mail_disclaimer'))
            ->salutation(__('eye_images.requesting_doctor_mail_salutation', ['clinic' => $this->clinicName]));
    }

    public function toArray(object $notifiable): array
    {
        return [
            'documentation_id' => $this->documentationId,
        ];
    }
}
