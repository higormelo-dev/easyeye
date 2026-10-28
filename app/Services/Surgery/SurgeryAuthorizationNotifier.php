<?php

declare(strict_types=1);

namespace App\Services\Surgery;

use App\Enums\FeatureKey;
use App\Enums\Surgery\{AuthorizationEventType, SurgicalCaseStatus};
use App\Jobs\WhatsApp\SendWhatsAppMessageJob;
use App\Models\{Entity, Patient};
use App\Models\Surgery\{SurgeryAuthorizationNotice, SurgeryClinicSetting, SurgicalAuthorizationEvent, SurgicalCase};
use App\Models\WhatsApp\{WhatsAppMessage, WhatsAppSetting};
use App\Services\Billing\ClinicServiceGate;
use App\Services\FeatureGateService;
use App\Services\WhatsApp\{WhatsAppService, WhatsAppTemplates};
use App\Support\Database\UniqueViolation;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\{DB, Log};
use Illuminate\Support\Str;
use Throwable;

/**
 * Aviso NEUTRO ao paciente de que a operadora respondeu o pedido de
 * autorização (ADR 0003, D14).
 *
 * Regras:
 *  - texto fixo e neutro (primeiro nome + clínica + "entre em contato"): nunca
 *    procedimento, olho, motivo nem se foi autorizado ou negado;
 *  - só para os eventos authorized / partially_authorized / denied (dispensa,
 *    anotação, envio e vencimento são internos);
 *  - só com o kill-switch da clínica LIGADO (desligado de fábrica), o recurso
 *    has_surgery no plano, celular marcado como WhatsApp (opt-in do cadastro),
 *    paciente ativo, sem SAIR no número que envia e WhatsApp da clínica (ou o
 *    global) operante;
 *  - tudo é REVALIDADO aqui, no instante do envio — a fila pode ter atrasado
 *    minutos: o evento precisa continuar sendo o desfecho vigente do caso;
 *  - no máximo UM aviso por evento: o livro `surgery_authorization_notices`
 *    (chave única por evento) é gravado ANTES de qualquer envio. Reentrega do
 *    job ou do ouvinte cai na chave única e não faz nada. Se o processo morrer
 *    depois de gravar e antes de enviar, o aviso se perde (aceito: perdido é
 *    tolerável; duplicado ao paciente, não);
 *  - falha de envio NUNCA desfaz nem trava a autorização: este serviço roda
 *    depois do commit, fora da trava do caso, e engole a exceção do envio.
 */
class SurgeryAuthorizationNotifier
{
    public const KIND = 'surgery_notice';

    public const TEMPLATE_KEY = 'surgery_authorization_notice';

    /** @var list<AuthorizationEventType> */
    public const NOTIFIABLE = [
        AuthorizationEventType::Authorized,
        AuthorizationEventType::PartiallyAuthorized,
        AuthorizationEventType::Denied,
    ];

    public function __construct(
        private readonly FeatureGateService $features,
        private readonly ClinicServiceGate $gate,
        private readonly WhatsAppService $whatsapp,
        private readonly WhatsAppTemplates $templates,
    ) {
    }

    public static function isNotifiable(AuthorizationEventType $type): bool
    {
        return in_array($type, self::NOTIFIABLE, true);
    }

    /** Processa um evento do log. Devolve a linha do livro, ou null se já tinha sido tratado / não se aplica. */
    public function notify(string $eventId): ?SurgeryAuthorizationNotice
    {
        $event = SurgicalAuthorizationEvent::query()->withoutGlobalScopes()->find($eventId);

        if ($event === null || ! self::isNotifiable($event->type)) {
            return null;
        }

        $notice = $this->claim($event);

        if ($notice === null) {
            return null; // outro processo (ou uma entrega anterior) já tratou este evento
        }

        try {
            $reason = $this->block($event, $phone, $first, $clinic, $setting);

            if ($reason !== null) {
                return $this->finish($notice, SurgeryAuthorizationNotice::STATUS_SKIPPED, $reason);
            }

            $body    = $this->templates->render(self::TEMPLATE_KEY, ['first_name' => $first, 'clinic' => $clinic], 'pt_BR');
            $message = WhatsAppMessage::query()->create([
                'entity_id'           => $event->entity_id,
                'schedule_id'         => null,
                'whatsapp_setting_id' => null,
                'direction'           => 'out',
                'kind'                => self::KIND,
                'template'            => $this->templates->config(self::TEMPLATE_KEY)['name'],
                'phone'               => $phone,
                'body'                => $body,
                'status'              => WhatsAppMessage::STATUS_PENDING,
                'payload'             => ['template_key' => self::TEMPLATE_KEY, 'values' => ['first_name' => $first, 'clinic' => $clinic], 'locale' => 'pt_BR'],
            ]);

            $notice = $this->finish($notice, SurgeryAuthorizationNotice::STATUS_QUEUED, null, (string) $message->getKey());
        } catch (Throwable $e) {
            // Sem texto da exceção no log: pode carregar telefone/nome.
            Log::warning('surgery.authorization_notice.failed', ['notice_id' => $notice->getKey(), 'error' => $e::class]);

            return $this->finish($notice, SurgeryAuthorizationNotice::STATUS_SKIPPED, 'error');
        }

        // Envio inline: a revalidação acima e a chamada ao provedor ficam a
        // milissegundos uma da outra. O job cuida de supressão (SAIR),
        // acesso bloqueado, lease e do histórico do provedor.
        try {
            SendWhatsAppMessageJob::dispatchSync((string) $message->getKey());
        } catch (Throwable $e) {
            Log::warning('surgery.authorization_notice.send_failed', ['notice_id' => $notice->getKey(), 'error' => $e::class]);
        }

        return $notice;
    }

    /**
     * Primeira razão pela qual NÃO se deve avisar agora, ou null se pode.
     * Preenche os dados do envio por referência.
     */
    private function block(SurgicalAuthorizationEvent $event, ?string &$phone, ?string &$first, ?string &$clinic, ?WhatsAppSetting &$setting): ?string
    {
        $entityId = (string) $event->entity_id;

        if (! $this->features->can($entityId, FeatureKey::HasSurgery)) {
            return 'feature_off';
        }

        if (! SurgeryClinicSetting::authorizationNoticeEnabled($entityId)) {
            return 'disabled';
        }

        $case = SurgicalCase::query()->withoutGlobalScopes()->with('patient.person')->find($event->surgical_case_id);

        if ($case === null || (string) $case->entity_id !== $entityId) {
            return 'case_missing';
        }

        if ($case->status === SurgicalCaseStatus::Cancelled) {
            return 'case_cancelled';
        }

        if (! $this->isCurrent($case, $event)) {
            return 'event_superseded';
        }

        /** @var Patient|null $patient */
        $patient = $case->patient;
        $person  = $patient?->person;

        if ($patient === null || ! $patient->active || (string) $patient->entity_id !== $entityId || $patient->trashed()) {
            return 'patient_inactive';
        }

        // Opt-in do canal: celular do cadastro marcado como WhatsApp.
        $phone = $person?->whatsapp ? WhatsAppService::normalizePhone($person->cellphone) : null;

        if ($phone === null) {
            return 'no_whatsapp';
        }

        $setting = WhatsAppSetting::query()->where('entity_id', $entityId)->first();
        $sender  = $setting?->active ? $setting->sendingSetting() : null;

        if ($sender === null) {
            return 'whatsapp_unavailable';
        }

        if ($this->whatsapp->isSuppressed($sender, $phone)) {
            return 'opted_out';
        }

        if (! $this->gate->allowsAutomation($entityId)) {
            return 'access_blocked';
        }

        $first  = Str::title(Str::lower(Str::before(trim((string) $person->full_name), ' ')));
        $clinic = (string) Entity::query()->whereKey($entityId)->value('name');

        return null;
    }

    /**
     * O evento ainda é o desfecho vigente do caso? O estado atual da
     * autorização bate com o do evento e nenhum evento posterior mudou o
     * estado (ex.: "autorizada" seguida de uma correção para "negada").
     */
    private function isCurrent(SurgicalCase $case, SurgicalAuthorizationEvent $event): bool
    {
        if ($case->authorization_status !== $event->type->resultingStatus()) {
            return false;
        }

        $stateChanging = array_values(array_filter(
            AuthorizationEventType::cases(),
            fn (AuthorizationEventType $t) => $t->resultingStatus() !== null,
        ));

        $latest = SurgicalAuthorizationEvent::query()
            ->withoutGlobalScopes()
            ->where('surgical_case_id', $case->getKey())
            ->whereIn('type', array_map(fn (AuthorizationEventType $t) => $t->value, $stateChanging))
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->first();

        return $latest !== null && (string) $latest->getKey() === (string) $event->getKey();
    }

    /** Grava o livro (status processing). null = o evento já tem linha. */
    private function claim(SurgicalAuthorizationEvent $event): ?SurgeryAuthorizationNotice
    {
        try {
            return DB::transaction(fn () => SurgeryAuthorizationNotice::query()->withoutGlobalScopes()->create([
                'entity_id'                       => $event->entity_id,
                'surgical_case_id'                => $event->surgical_case_id,
                'surgical_authorization_event_id' => $event->getKey(),
                'status'                          => SurgeryAuthorizationNotice::STATUS_PROCESSING,
            ]));
        } catch (QueryException $e) {
            if (UniqueViolation::isUniqueViolation($e)) {
                return null;
            }

            throw $e;
        }
    }

    private function finish(SurgeryAuthorizationNotice $notice, string $status, ?string $reason, ?string $messageId = null): SurgeryAuthorizationNotice
    {
        $notice->forceFill([
            'status'              => $status,
            'skip_reason'         => $reason,
            'whatsapp_message_id' => $messageId,
        ])->save();

        return $notice;
    }
}
