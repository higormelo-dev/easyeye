<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Enums\ClientRule;
use App\Models\{EntityUser, User};
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\{ShouldQueue, ShouldQueueAfterCommit};
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Fluxo técnico → médico do laudo de imagem (Fase 5): avisa o médico que há um
 * rascunho esperando liberação e o técnico que o médico devolveu o rascunho.
 *
 * O e-mail NUNCA leva nome do paciente, exame, conteúdo do laudo nem o motivo
 * da devolução — só diz que há algo a fazer e abre o Gerenciador de Imagens,
 * onde o acesso já é autenticado e registrado. Enfileirada depois do commit:
 * a ação que a dispara (enviar/devolver) pode dar rollback e o aviso não
 * pode sair de uma transação desfeita.
 *
 * O destinatário é revalidado DUAS vezes — ao escolher quem avisar e, de novo, na
 * hora de sair (shouldSend, que roda no worker, depois de a notificação esperar
 * na fila): o e-mail leva o nome da clínica, então quem perdeu o vínculo
 * (desativado, excluído, perfil sem permissão de preparar laudo) não recebe.
 */
class ImageReportDraftNotification extends Notification implements ShouldQueue, ShouldQueueAfterCommit
{
    use Queueable;

    public const SUBMITTED = 'submitted';

    public const RETURNED = 'returned';

    /** Mesmo allowlist do Gate DraftImageReport (preparar laudo de imagem). */
    private const RECIPIENT_RULES = [ClientRule::Admin, ClientRule::Doctor, ClientRule::Secretary];

    public function __construct(
        public readonly string $event,
        public readonly string $clinicName,
        public readonly string $url,
        public readonly string $entityId,
    ) {
    }

    /**
     * Usuário ativo COM vínculo ativo e não excluído nesta clínica, num perfil
     * que prepara laudo. Consulta direta (sem o cache de perfil em memória do
     * User): o que vale é o estado do banco agora.
     */
    public static function recipientIsActive(User $user, string $entityId): bool
    {
        return User::query()->whereKey($user->getKey())->where('active', true)->exists()
            && EntityUser::query()
                ->where('user_id', $user->getKey())
                ->where('entity_id', $entityId)
                ->where('active', true)
                ->whereNull('deleted_at')
                ->whereIn('rule', array_map(static fn (ClientRule $rule) => $rule->value, self::RECIPIENT_RULES))
                ->exists();
    }

    /** Roda no momento do envio (inclusive no worker, depois da fila). */
    public function shouldSend(object $notifiable, string $channel): bool
    {
        return $notifiable instanceof User && self::recipientIsActive($notifiable, $this->entityId);
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
        return (new MailMessage())
            ->subject(__("eye_images.draft_mail_{$this->event}_subject", ['clinic' => $this->clinicName]))
            ->greeting(__('eye_images.draft_mail_greeting', ['name' => $notifiable->name ?? '']))
            ->line(__("eye_images.draft_mail_{$this->event}_line"))
            ->action(__('eye_images.draft_mail_action'), $this->url)
            ->salutation(__('eye_images.draft_mail_salutation', ['clinic' => $this->clinicName]));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return ['event' => $this->event];
    }
}
