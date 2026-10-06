<?php

declare(strict_types=1);

namespace App\Services\WhatsApp;

use App\Enums\ScheduleSituation;
use App\Jobs\NotifyScheduleChangeJob;
use App\Models\Schedule;
use App\Models\Scopes\EntityScope;
use App\Models\WhatsApp\{WhatsAppMessage, WhatsAppOptOut, WhatsAppSetting};
use App\Notifications\ScheduleNotification;
use App\Services\ScheduleService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Regra de negócio do WhatsApp oficial (Gupshup/Meta): confirmação de
 * consulta + pesquisa de satisfação (templates aprovados, com botões),
 * leitura das respostas do paciente e descadastro (SAIR/VOLTAR).
 *
 * Fluxos:
 *  - Confirmação: whatsapp:send-confirmations cria a mensagem (pending) e o
 *    SendWhatsAppMessageJob envia o template. O paciente toca em
 *    Confirmar/Cancelar (o payload do botão identifica a mensagem) ou
 *    responde em texto (só respostas exatas — 1/2, sim/não... — e só com UMA
 *    mensagem aguardando resposta naquele número); a transição passa pelo
 *    ScheduleService::changeSituation (log, timestamps, sala de espera) e o
 *    e-mail de mudança sai como no painel.
 *  - Pesquisa: após Attended + delay; nota pelos botões 5..1 ou em texto.
 *  - Envio só para celular marcado como WhatsApp (agendamento ou cadastro)
 *    e que não respondeu SAIR para o número que envia.
 */
class WhatsAppService
{
    /** Formato de payload.schedule_at (date_time da consulta no envio). */
    public const SCHEDULE_AT_FORMAT = 'Y-m-d H:i:s';

    public function __construct(
        private readonly WhatsAppTemplates $templates,
        private readonly ScheduleService $schedules,
    ) {
    }

    // ──────────────────────────────────────────────────────────────────────
    // Telefone
    // ──────────────────────────────────────────────────────────────────────

    /**
     * Telefone no formato do envio (só dígitos, com DDI). Brasil: o banco
     * guarda dígitos sem DDI (ex.: 61999999999); celular antigo sem o 9º
     * dígito ganha o 9 (a Meta pode devolver o wa_id sem ele — assim os
     * dois lados casam). Número estrangeiro precisa vir com "+".
     */
    public static function normalizePhone(?string $raw): ?string
    {
        $raw           = trim((string) $raw);
        $international = str_starts_with($raw, '+') || str_starts_with($raw, '00');
        $digits        = preg_replace('/\D/', '', $raw) ?? '';

        if (str_starts_with($raw, '00')) {
            $digits = substr($digits, 2);
        }

        if ($international && ! str_starts_with($digits, '55')) {
            return strlen($digits) >= 8 && strlen($digits) <= 15 ? $digits : null;
        }

        // Remove DDI 55 se já veio (evita 5555...).
        if (str_starts_with($digits, '55') && strlen($digits) >= 12 && strlen($digits) <= 13) {
            $digits = substr($digits, 2);
        }

        // Celular (começa com 6-9) sem o 9º dígito → com o 9.
        if (strlen($digits) === 10 && in_array($digits[2], ['6', '7', '8', '9'], true)) {
            $digits = substr($digits, 0, 2) . '9' . substr($digits, 2);
        }

        if (strlen($digits) !== 10 && strlen($digits) !== 11) {
            return null;
        }

        return '55' . $digits;
    }

    /**
     * Celular da consulta que pode receber WhatsApp: o do agendamento, se
     * marcado como WhatsApp; senão o do cadastro do paciente, se marcado.
     * Sem a marcação, nada sai (opt-in do canal).
     */
    public static function resolveSchedulePhone(Schedule $schedule): ?string
    {
        if ($schedule->cellphone_whatsapp && ($phone = self::normalizePhone($schedule->cellphone)) !== null) {
            return $phone;
        }

        $person = $schedule->patient?->person;

        if ($person?->whatsapp && ($phone = self::normalizePhone($person->cellphone)) !== null) {
            return $phone;
        }

        return null;
    }

    // ──────────────────────────────────────────────────────────────────────
    // Descadastro (SAIR / VOLTAR)
    // ──────────────────────────────────────────────────────────────────────

    public function isSuppressed(WhatsAppSetting $sender, string $phone): bool
    {
        return WhatsAppOptOut::isSuppressed($sender, $phone);
    }

    public function optOut(WhatsAppSetting $sender, string $phone, string $source = WhatsAppOptOut::SOURCE_KEYWORD): void
    {
        WhatsAppOptOut::query()->firstOrCreate(
            ['whatsapp_setting_id' => $sender->id, 'phone' => $phone],
            ['source' => $source, 'opted_out_at' => now()],
        );
    }

    public function optIn(WhatsAppSetting $sender, string $phone): bool
    {
        return WhatsAppOptOut::query()
            ->where('whatsapp_setting_id', $sender->id)
            ->where('phone', $phone)
            ->delete() > 0;
    }

    /**
     * O celular respondeu SAIR ao número pelo qual a clínica envia (o
     * próprio dela, senão o do EasyEye)? Usado só para indicar no painel.
     */
    public static function optedOutFor(?string $entityId, ?string $rawPhone): bool
    {
        $phone = self::normalizePhone($rawPhone);

        if ($entityId === null || $phone === null) {
            return false;
        }

        $sender = WhatsAppSetting::query()->where('entity_id', $entityId)->first()?->sendingSetting()
            ?? WhatsAppSetting::globalSetting();

        return $sender !== null && WhatsAppOptOut::isSuppressed($sender, $phone);
    }

    /** 'out' (SAIR/PARAR/STOP), 'in' (VOLTAR/ATIVAR) ou null. */
    public static function keyword(string $text): ?string
    {
        $t = Str::lower(Str::ascii(trim($text)));
        $t = trim((string) preg_replace('/[^a-z ]/', '', $t));

        return match (true) {
            in_array($t, (array) config('whatsapp.opt_out_keywords', []), true) => 'out',
            in_array($t, (array) config('whatsapp.opt_in_keywords', []), true)  => 'in',
            default                                                             => null,
        };
    }

    // ──────────────────────────────────────────────────────────────────────
    // Criação das mensagens outbound (idempotente via unique parcial)
    // ──────────────────────────────────────────────────────────────────────

    /**
     * Cria a linha pending da confirmação (uma por consulta — unique parcial
     * whatsapp_messages_outbound_once absorve corrida entre comandos).
     * Retorna null quando já existe, não há celular com WhatsApp ou o
     * paciente se descadastrou do número que envia.
     */
    public function queueConfirmation(WhatsAppSetting $setting, Schedule $schedule): ?WhatsAppMessage
    {
        return $this->queue($setting, $schedule, WhatsAppMessage::KIND_CONFIRMATION, 'appointment_confirmation', [
            'first_name' => $this->firstName($schedule),
            'clinic'     => $schedule->entity?->name,
            'when'       => $schedule->date_time?->copy()->locale('pt_BR')->isoFormat((string) __('whatsapp.date_time_format', [], 'pt_BR')),
            'doctor'     => $schedule->doctor?->person?->full_name,
        ], ['schedule_at' => $schedule->date_time?->format(self::SCHEDULE_AT_FORMAT)]);
    }

    public function queueSurvey(WhatsAppSetting $setting, Schedule $schedule): ?WhatsAppMessage
    {
        return $this->queue($setting, $schedule, WhatsAppMessage::KIND_SURVEY, 'satisfaction_survey', [
            'first_name' => $this->firstName($schedule),
            'clinic'     => $schedule->entity?->name,
        ]);
    }

    /**
     * @param array<string, ?string> $values
     * @param array<string, ?string> $extra  gravado no payload (ex.: schedule_at)
     */
    private function queue(WhatsAppSetting $setting, Schedule $schedule, string $kind, string $templateKey, array $values, array $extra = []): ?WhatsAppMessage
    {
        $phone = self::resolveSchedulePhone($schedule);

        if ($phone === null) {
            return null;
        }

        $sender = $setting->sendingSetting();

        if ($sender !== null && $this->isSuppressed($sender, $phone)) {
            return null;
        }

        // Pacientes: pt_BR (clínicas no Brasil; template "en" só para
        // avisos do SaaS a quem usa o sistema em inglês).
        $body    = $this->templates->render($templateKey, $values, 'pt_BR');
        $payload = ['template_key' => $templateKey, 'values' => $values, 'locale' => 'pt_BR', ...$extra];

        try {
            // Savepoint: o unique parcial (1 confirmação/pesquisa por consulta)
            // pode disparar em corrida — rollback só do savepoint, sem
            // envenenar transação externa.
            return DB::transaction(fn () => WhatsAppMessage::create([
                'entity_id'   => $setting->entity_id,
                'schedule_id' => $schedule->id,
                'direction'   => 'out',
                'kind'        => $kind,
                'template'    => $this->templates->config($templateKey)['name'],
                'phone'       => $phone,
                'body'        => $body,
                'status'      => WhatsAppMessage::STATUS_PENDING,
                'payload'     => $payload,
            ]));
        } catch (QueryException $e) {
            if ($this->isUniqueViolation($e)) {
                // Pulada antes (acesso da clínica bloqueado): a mesma linha
                // volta para a fila. Senão já foi enviada/enfileirada.
                $revived = WhatsAppMessage::query()
                    ->where('schedule_id', $schedule->id)
                    ->where('direction', 'out')
                    ->where('kind', $kind)
                    ->where('status', WhatsAppMessage::STATUS_SKIPPED)
                    ->update([
                        'status'     => WhatsAppMessage::STATUS_PENDING,
                        'phone'      => $phone,
                        'body'       => $body,
                        'template'   => $this->templates->config($templateKey)['name'],
                        'payload'    => json_encode($payload),
                        'error'      => null,
                        'error_code' => null,
                        // Tentativa anterior (ex.: saldo da carteira, consulta
                        // remarcada) não conta.
                        'provider_message_id' => null,
                        'sent_at'             => null,
                        'answered_at'         => null,
                        'wa_message_id'       => null,
                        'failed_at'           => null,
                        'delivered_at'        => null,
                        'read_at'             => null,
                    ]);

                return $revived > 0
                    ? WhatsAppMessage::query()->where('schedule_id', $schedule->id)->where('direction', 'out')->where('kind', $kind)->first()
                    : null;
            }

            throw $e;
        }
    }

    private function firstName(Schedule $schedule): string
    {
        return Str::title(Str::lower(Str::before(trim((string) $schedule->full_name), ' ')));
    }

    // ──────────────────────────────────────────────────────────────────────
    // Inbound — resposta do paciente
    // ──────────────────────────────────────────────────────────────────────

    /**
     * Processa uma mensagem recebida pelo número de $receiver (app global ou
     * próprio da clínica) e devolve o texto da resposta automática (ou null).
     *
     * Ordem: SAIR/VOLTAR → payload do botão (id da mensagem enviada) →
     * context.id (mensagem respondida) → texto livre. Sempre restrito às
     * mensagens que SAÍRAM por $receiver e ao mesmo telefone — webhook de um
     * app nunca mexe em consulta de clínica que enviou por outro.
     *
     * Cada etapa decide sozinha (não "cai" na seguinte):
     *  - botão com payload nosso: só a mensagem do botão (qualquer status —
     *    o clique prova a entrega; o tipo precisa bater); não achou → pede
     *    o botão, nada muda;
     *  - context.id: só a confirmação/pesquisa citada; citou outra coisa
     *    (resposta automática, aviso de outra consulta, mensagem
     *    desconhecida) → pede o botão, nada muda;
     *  - texto livre (sem botão nem citação): só com UMA mensagem aguardando
     *    resposta daquele telefone naquele número, dentro da validade, só
     *    se ela for a ÚLTIMA que saiu por esse número para esse telefone (um
     *    "Não" depois da resposta automática de outra consulta é ambíguo) e
     *    só com resposta EXATA (lang whatsapp.replies). Senão nada muda e o
     *    paciente é orientado a tocar no botão.
     *
     * Confirmação de consulta remarcada depois do envio não é aplicada
     * (payload.schedule_at ≠ date_time atual): resposta "consulta remarcada".
     *
     * Descadastrado (SAIR) naquele número: o efeito da resposta vale, mas a
     * resposta automática só sai para VOLTAR/ATIVAR e o próprio SAIR.
     */
    public function handleInbound(WhatsAppSetting $receiver, WhatsAppMessage $inbound): ?string
    {
        $phone      = (string) $inbound->phone;
        $text       = trim((string) $inbound->body);
        $payload    = (array) ($inbound->payload ?? []);
        $button     = WhatsAppTemplates::parsePayload($payload['button_payload'] ?? null);
        $suppressed = $this->isSuppressed($receiver, $phone);

        if ($button === null) {
            $keyword = self::keyword($text);

            if ($keyword === 'out') {
                $this->optOut($receiver, $phone);

                return (string) __('whatsapp.patient.opted_out');
            }

            if ($keyword === 'in') {
                $this->optIn($receiver, $phone);

                return (string) __('whatsapp.patient.opted_in');
            }
        }

        $reply = $this->answer($receiver, $inbound, $phone, $text, $payload, $button);

        return $suppressed ? null : $reply;
    }

    /**
     * @param array<string, mixed>                                         $payload
     * @param array{action: string, message_id: string, score?: ?int}|null $button
     */
    private function answer(WhatsAppSetting $receiver, WhatsAppMessage $inbound, string $phone, string $text, array $payload, ?array $button): ?string
    {
        if ($button !== null) {
            // Payload NOSSO (confirm:/cancel:/survey:<id>): vale só a mensagem
            // do botão — nunca cai no context.id nem no texto livre (o rótulo
            // "Cancelar" não pode cancelar outra consulta). Qualquer status
            // serve (failed unknown_delivery, sending, suppressed...): o clique
            // prova que a mensagem chegou. Só o tipo precisa bater.
            $target = $this->outboundTo($receiver, $phone)->whereKey($button['message_id'])->first();

            if ($target === null || ($target->kind === WhatsAppMessage::KIND_SURVEY) !== ($button['action'] === WhatsAppTemplates::ACTION_SURVEY)) {
                return (string) __('whatsapp.patient.use_buttons');
            }

            $choice = $button['action'] === WhatsAppTemplates::ACTION_SURVEY
                ? $button['score']
                : ($button['action'] === WhatsAppTemplates::ACTION_CONFIRM ? 1 : 2);

            return $this->apply($inbound, $target, $choice, '');
        }

        $contextId = (string) ($payload['context_id'] ?? '');

        if ($contextId !== '') {
            // Resposta citando uma mensagem: só a confirmação/pesquisa citada.
            // Citou outra coisa (resposta automática, aviso de outra consulta,
            // mensagem que o sistema não conhece) → nada muda; nunca cai na
            // candidata do texto livre.
            $target = $this->outboundTo($receiver, $phone)
                ->where(fn ($q) => $q->where('provider_message_id', $contextId)->orWhere('wa_message_id', $contextId))
                ->first();

            if ($target === null) {
                return (string) __('whatsapp.patient.use_buttons');
            }

            return $this->apply($inbound, $target, null, $text !== '' ? $text : (string) ($payload['button_text'] ?? ''));
        }

        // Texto livre: só com UMA candidata aguardando resposta...
        $candidates = $this->awaitingReply($receiver, $phone)->limit(2)->get();

        if ($candidates->count() > 1) {
            return (string) __('whatsapp.patient.use_buttons');
        }

        $target = $candidates->first();

        if ($target === null) {
            return null;
        }

        // ...e só se ela for a ÚLTIMA coisa que o paciente recebeu deste
        // número: depois dela saiu outra (resposta automática, aviso de
        // outra consulta) → o "Não" pode ser sobre essa outra.
        if ($this->sentAfter($receiver, $phone, $target)) {
            return (string) __('whatsapp.patient.use_buttons');
        }

        return $this->apply($inbound, $target, null, $text !== '' ? $text : (string) ($payload['button_text'] ?? ''));
    }

    /** Aplica a resposta ($choice do botão, ou lida de $answerText) à mensagem. */
    private function apply(WhatsAppMessage $inbound, WhatsAppMessage $target, ?int $choice, string $answerText): ?string
    {
        if ($inbound->entity_id === null || $inbound->schedule_id === null) {
            $inbound->update(['entity_id' => $target->entity_id, 'schedule_id' => $target->schedule_id]);
        }

        $answer = $choice ?? ($target->kind === WhatsAppMessage::KIND_CONFIRMATION
            ? self::parseChoice($answerText)
            : self::parseScore($answerText));

        return $target->kind === WhatsAppMessage::KIND_CONFIRMATION
            ? $this->applyConfirmationReply($target, $answer)
            : $this->applySurveyReply($target, $answer);
    }

    /**
     * Confirmações/pesquisas que saíram por $receiver para $phone, em
     * qualquer status (botão e citação provam a entrega).
     */
    private function outboundTo(WhatsAppSetting $receiver, string $phone)
    {
        return WhatsAppMessage::query()
            ->where('direction', 'out')
            ->where('whatsapp_setting_id', $receiver->id)
            ->where('phone', $phone)
            ->whereIn('kind', [WhatsAppMessage::KIND_CONFIRMATION, WhatsAppMessage::KIND_SURVEY]);
    }

    /** Confirmações/pesquisas enviadas (sent) por $receiver para $phone. */
    private function sentBy(WhatsAppSetting $receiver, string $phone)
    {
        return $this->outboundTo($receiver, $phone)->where('status', WhatsAppMessage::STATUS_SENT);
    }

    /**
     * Saiu por $receiver para $phone alguma outra mensagem de OUTRA consulta
     * (ou sem consulta) depois de $target — resposta automática, confirmação/
     * pesquisa de outra consulta, "toque no botão", SAIR/VOLTAR? Conta o que pode ter chegado ao paciente — inclusive falha
     * (unknown_delivery pode ter saído); não conta o que nunca saiu
     * (pending, skipped, suppressed).
     */
    private function sentAfter(WhatsAppSetting $receiver, string $phone, WhatsAppMessage $target): bool
    {
        $since = $target->sent_at ?? $target->created_at;

        return WhatsAppMessage::query()
            ->where('direction', 'out')
            ->where('whatsapp_setting_id', $receiver->id)
            ->where('phone', $phone)
            ->whereKeyNot($target->id)
            // Resposta automática da MESMA consulta ("não entendi") não
            // atrapalha: a próxima resposta exata ainda é sobre ela.
            ->when($target->schedule_id !== null, fn ($q) => $q->where(fn ($s) => $s
                ->whereNull('schedule_id')
                ->orWhere('schedule_id', '!=', $target->schedule_id)))
            ->whereNotIn('status', [WhatsAppMessage::STATUS_PENDING, WhatsAppMessage::STATUS_SKIPPED, WhatsAppMessage::STATUS_SUPPRESSED])
            // >= : os timestamps são por segundo — no mesmo segundo não dá
            // para saber a ordem, então conta como "depois" (na dúvida, botão).
            ->where(fn ($q) => $q
                ->where('sent_at', '>=', $since)
                ->orWhere(fn ($n) => $n->whereNull('sent_at')->where('created_at', '>=', $since)))
            ->exists();
    }

    /**
     * Mensagens ainda aguardando resposta (sent) daquele telefone por aquele
     * número, dentro da validade de cada tipo (confirmação 7 dias, pesquisa
     * 14 — config whatsapp.*.reply_valid_days).
     */
    private function awaitingReply(WhatsAppSetting $receiver, string $phone)
    {
        $confirmationSince = now()->subDays((int) config('whatsapp.confirmation.reply_valid_days', 7));
        $surveySince       = now()->subDays((int) config('whatsapp.survey.reply_valid_days', 14));

        return $this->sentBy($receiver, $phone)
            ->where(fn ($q) => $q
                ->where(fn ($c) => $c->where('kind', WhatsAppMessage::KIND_CONFIRMATION)->where('sent_at', '>=', $confirmationSince))
                ->orWhere(fn ($c) => $c->where('kind', WhatsAppMessage::KIND_SURVEY)->where('sent_at', '>=', $surveySince)))
            ->orderByDesc('sent_at');
    }

    private function applyConfirmationReply(WhatsAppMessage $message, ?int $choice): ?string
    {
        $validDays = (int) config('whatsapp.confirmation.reply_valid_days', 7);

        if ($message->sent_at && $message->sent_at->lt(now()->subDays($validDays))) {
            return null; // resposta velha demais — ignora
        }

        if ($choice !== 1 && $choice !== 2) {
            return (string) __('whatsapp.patient.confirmation_not_understood');
        }

        $target  = $choice === 1 ? ScheduleSituation::Confirmed : ScheduleSituation::Cancelled;
        $notes   = (string) __($choice === 1 ? 'whatsapp.patient.confirmed_note' : 'whatsapp.patient.cancel_reason');
        $outcome = 'not_applied';

        if ($message->schedule_id) {
            // Só transiciona se a consulta ainda está aguardando (Scheduled) —
            // nunca sobrescreve Attended/Cancelled/estados de fluxo interno.
            // Reconfere com a linha travada (corrida com a recepção); a
            // transição é a mesma do painel: log com entity_user_id null
            // (ação do PACIENTE), confirmed_at/motivo e cache da sala de espera.
            $outcome = DB::transaction(function () use ($message, $target, $notes): string {
                // Sem a clínica da sessão (worker), mas COM o soft delete:
                // consulta excluída ou inativa não é confirmada/cancelada.
                $schedule = Schedule::query()
                    ->withoutGlobalScope(EntityScope::class)
                    ->where('active', true)
                    ->lockForUpdate()
                    ->find($message->schedule_id);

                if (! $schedule) {
                    return 'not_applied';
                }

                // Remarcada depois do envio: a resposta era para a data antiga
                // (o comando manda nova confirmação com a nova data).
                if (self::rescheduledSince($message, $schedule)) {
                    return 'rescheduled';
                }

                if ($schedule->situation !== ScheduleSituation::Scheduled) {
                    return 'not_applied';
                }

                return $this->schedules->changeSituation($schedule, $target, null, $notes) ? 'applied' : 'not_applied';
            });

            if ($outcome === 'rescheduled') {
                return (string) __('whatsapp.patient.rescheduled');
            }

            if ($outcome === 'applied') {
                // Mesmo e-mail que o painel manda ao paciente ao confirmar/cancelar.
                NotifyScheduleChangeJob::dispatch(
                    (string) $message->schedule_id,
                    $choice === 1 ? ScheduleNotification::EVENT_CONFIRMED : ScheduleNotification::EVENT_CANCELLED,
                    $choice === 1 ? null : $notes,
                )->afterCommit();
            }
        }

        $applied = $outcome === 'applied';

        $message->update([
            'status'      => WhatsAppMessage::STATUS_ANSWERED,
            'answered_at' => $message->answered_at ?? now(),
        ]);

        if ($choice === 1) {
            return (string) __($applied ? 'whatsapp.patient.confirmed' : 'whatsapp.patient.already_updated');
        }

        return (string) __($applied ? 'whatsapp.patient.cancelled' : 'whatsapp.patient.already_updated_cancel');
    }

    /**
     * A consulta mudou de data/hora depois que a confirmação foi montada?
     * (payload.schedule_at = date_time da consulta no envio; confirmações
     * antigas, sem ele, não são conferidas.).
     */
    public static function rescheduledSince(WhatsAppMessage $message, Schedule $schedule): bool
    {
        $sentFor = ((array) ($message->payload ?? []))['schedule_at'] ?? null;

        return is_string($sentFor) && $sentFor !== '' && $schedule->date_time !== null
            && $sentFor !== $schedule->date_time->format(self::SCHEDULE_AT_FORMAT);
    }

    private function applySurveyReply(WhatsAppMessage $message, ?int $score): ?string
    {
        $validDays = (int) config('whatsapp.survey.reply_valid_days', 14);

        if ($message->sent_at && $message->sent_at->lt(now()->subDays($validDays))) {
            return null;
        }

        if ($score === null || $score < 1 || $score > 5) {
            return (string) __('whatsapp.patient.survey_not_understood');
        }

        $message->update([
            'status'       => WhatsAppMessage::STATUS_ANSWERED,
            'answered_at'  => now(),
            'survey_score' => $score,
        ]);

        return (string) __($score >= 4 ? 'whatsapp.patient.survey_thanks_good' : 'whatsapp.patient.survey_thanks');
    }

    /** Texto sem acento, maiúsculas, pontuação nem espaços extras. */
    public static function normalizeReply(string $text): string
    {
        $t = Str::lower(Str::ascii(trim($text)));

        return trim((string) preg_replace('/\s+/', ' ', (string) preg_replace('/[^a-z0-9 ]/', ' ', $t)));
    }

    /**
     * 1 = confirmar, 2 = cancelar, null = não é uma resposta aceita. Só
     * respostas EXATAS da lista (whatsapp.replies, pt_BR + en): "Não
     * entendi..." ou "Cancelaram a outra?" não valem.
     */
    public static function parseChoice(string $text): ?int
    {
        $t = self::normalizeReply($text);

        if ($t === '') {
            return null;
        }

        foreach (['confirm' => 1, 'cancel' => 2] as $key => $choice) {
            if (in_array($t, self::replyWords($key), true)) {
                return $choice;
            }
        }

        return null;
    }

    /** "5", "5 - Excelente" (rótulo do botão) → 5; qualquer outra coisa → null. */
    public static function parseScore(string $text): ?int
    {
        $t = self::normalizeReply($text);

        if (preg_match('/^[1-5]$/', $t)) {
            return (int) $t;
        }

        foreach (['pt_BR', 'en'] as $locale) {
            foreach ((array) trans('whatsapp.replies.survey_labels', [], $locale) as $score => $label) {
                if ($t === $score . ' ' . self::normalizeReply((string) $label)) {
                    return (int) $score;
                }
            }
        }

        return null;
    }

    /** @return list<string> palavras aceitas (pt_BR e en, normalizadas) */
    private static function replyWords(string $key): array
    {
        $words = [];

        foreach (['pt_BR', 'en'] as $locale) {
            foreach ((array) trans("whatsapp.replies.{$key}", [], $locale) as $word) {
                $words[] = self::normalizeReply((string) $word);
            }
        }

        return array_values(array_unique($words));
    }

    private function isUniqueViolation(QueryException $e): bool
    {
        return in_array($e->getCode(), ['23000', '23505'], true);
    }
}
