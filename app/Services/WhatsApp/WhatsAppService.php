<?php

declare(strict_types=1);

namespace App\Services\WhatsApp;

use App\Enums\ScheduleSituation;
use App\Models\{Schedule, ScheduleSituationLog};
use App\Models\WhatsApp\{WhatsAppMessage, WhatsAppSetting};
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\{Cache, DB};
use Illuminate\Support\Str;

/**
 * Regra de negócio do WhatsApp (Z-API): confirmação de consulta + pesquisa
 * de satisfação, com parsing das respostas do paciente.
 *
 * Fluxos:
 *  - Confirmação: comando whatsapp:send-confirmations cria a mensagem
 *    (pending) e o job envia. Paciente responde 1 (confirma) / 2 (cancela);
 *    o webhook aplica a transição na agenda com os MESMOS efeitos colaterais
 *    do SchedulesController::updateSituation (confirmed_at, cancellation_reason,
 *    ScheduleSituationLog com entity_user_id null = ação do paciente,
 *    invalidação do cache waiting_room).
 *  - Pesquisa: após Attended + delay, envia "avalie de 1 a 5"; a resposta
 *    vira survey_score na própria mensagem outbound (trilha completa).
 */
class WhatsAppService
{
    public function __construct(private readonly ZApiClient $client)
    {
    }

    // ──────────────────────────────────────────────────────────────────────
    // Telefone
    // ──────────────────────────────────────────────────────────────────────

    /**
     * Normaliza pro formato Z-API: DDI 55 + DDD + número, só dígitos.
     * Banco guarda dígitos sem DDI (ex.: 61999999999) — ver PatientRequest/
     * ScheduleRequest prepareForValidation.
     */
    public static function normalizePhone(?string $raw): ?string
    {
        $digits = preg_replace('/\D/', '', (string) $raw) ?? '';

        // Remove DDI 55 se já veio (evita 5555...)
        if (str_starts_with($digits, '55') && strlen($digits) >= 12) {
            $digits = substr($digits, 2);
        }

        // Aceita 10 (fixo/celular antigo) ou 11 (celular com 9) dígitos.
        if (strlen($digits) < 10 || strlen($digits) > 11) {
            return null;
        }

        return '55' . $digits;
    }

    /**
     * Telefone de destino da consulta: celular do agendamento primeiro
     * (snapshot na hora da marcação), fallback pro cadastro do paciente.
     */
    public static function resolveSchedulePhone(Schedule $schedule): ?string
    {
        return self::normalizePhone($schedule->cellphone)
            ?? self::normalizePhone($schedule->patient?->person?->cellphone);
    }

    // ──────────────────────────────────────────────────────────────────────
    // Criação das mensagens outbound (idempotente via unique parcial)
    // ──────────────────────────────────────────────────────────────────────

    /**
     * Cria a linha pending da confirmação (uma por consulta — unique parcial
     * whatsapp_messages_outbound_once absorve corrida entre comandos).
     * Retorna null quando já existe ou não há telefone.
     */
    public function queueConfirmation(WhatsAppSetting $setting, Schedule $schedule): ?WhatsAppMessage
    {
        $phone = self::resolveSchedulePhone($schedule);

        if ($phone === null) {
            return null;
        }

        return $this->createOutbound($setting, $schedule, WhatsAppMessage::KIND_CONFIRMATION, $phone, $this->confirmationBody($schedule));
    }

    public function queueSurvey(WhatsAppSetting $setting, Schedule $schedule): ?WhatsAppMessage
    {
        $phone = self::resolveSchedulePhone($schedule);

        if ($phone === null) {
            return null;
        }

        return $this->createOutbound($setting, $schedule, WhatsAppMessage::KIND_SURVEY, $phone, $this->surveyBody($schedule));
    }

    private function createOutbound(WhatsAppSetting $setting, Schedule $schedule, string $kind, string $phone, string $body): ?WhatsAppMessage
    {
        try {
            // Savepoint: o unique parcial (1 confirmação/pesquisa por consulta)
            // pode disparar em corrida — rollback só do savepoint, sem
            // envenenar transação externa.
            return DB::transaction(fn () => WhatsAppMessage::create([
                'entity_id'   => $setting->entity_id,
                'schedule_id' => $schedule->id,
                'direction'   => 'out',
                'kind'        => $kind,
                'phone'       => $phone,
                'body'        => $body,
                'status'      => WhatsAppMessage::STATUS_PENDING,
            ]));
        } catch (QueryException $e) {
            if ($this->isUniqueViolation($e)) {
                return null; // já enviada/enfileirada pra esta consulta
            }

            throw $e;
        }
    }

    private function confirmationBody(Schedule $schedule): string
    {
        $entityName = $schedule->entity?->name ?? 'sua clínica';
        $doctorName = $schedule->doctor?->person?->full_name;
        $when       = $schedule->date_time?->format('d/m/Y \à\s H:i') ?? '';
        $firstName  = Str::title(Str::lower(Str::before(trim((string) $schedule->full_name), ' ')));

        $doctorLine = $doctorName ? " com {$doctorName}" : '';

        return "Olá, {$firstName}! Você tem uma consulta agendada na {$entityName} em {$when}{$doctorLine}.\n\n"
            . "Responda com o número:\n"
            . "1 - CONFIRMAR presença\n"
            . "2 - CANCELAR consulta\n\n"
            . 'Obrigado!';
    }

    private function surveyBody(Schedule $schedule): string
    {
        $entityName = $schedule->entity?->name ?? 'nossa clínica';
        $firstName  = Str::title(Str::lower(Str::before(trim((string) $schedule->full_name), ' ')));

        return "Olá, {$firstName}! Obrigado por sua visita à {$entityName}.\n\n"
            . "De 1 a 5, como você avalia o seu atendimento?\n"
            . "(1 = muito insatisfeito · 5 = muito satisfeito)\n\n"
            . 'Basta responder com o número.';
    }

    // ──────────────────────────────────────────────────────────────────────
    // Inbound — parsing da resposta do paciente
    // ──────────────────────────────────────────────────────────────────────

    /**
     * Processa uma mensagem recebida: casa com a última confirmação/pesquisa
     * pendente de resposta daquele telefone e aplica o efeito. Retorna a
     * mensagem de agradecimento a enviar (ou null se nada casou).
     */
    public function handleInbound(WhatsAppSetting $setting, string $phone, string $text): ?string
    {
        $normalized = self::normalizePhone($phone);

        if ($normalized === null) {
            return null;
        }

        $pending = WhatsAppMessage::query()
            ->where('entity_id', $setting->entity_id)
            ->where('direction', 'out')
            ->where('status', WhatsAppMessage::STATUS_SENT)
            ->where('phone', $normalized)
            ->whereIn('kind', [WhatsAppMessage::KIND_CONFIRMATION, WhatsAppMessage::KIND_SURVEY])
            ->orderByDesc('sent_at')
            ->first();

        if (! $pending) {
            return null;
        }

        return $pending->kind === WhatsAppMessage::KIND_CONFIRMATION
            ? $this->applyConfirmationReply($pending, $text)
            : $this->applySurveyReply($pending, $text);
    }

    private function applyConfirmationReply(WhatsAppMessage $message, string $text): ?string
    {
        $validDays = (int) config('whatsapp.confirmation.reply_valid_days', 7);

        if ($message->sent_at && $message->sent_at->lt(now()->subDays($validDays))) {
            return null; // resposta velha demais — ignora
        }

        $choice = $this->parseChoice($text);

        if ($choice === null) {
            return "Não entendi sua resposta. 🙂\nResponda apenas com o número:\n1 - CONFIRMAR\n2 - CANCELAR";
        }

        $schedule = $message->schedule;

        // Só transiciona se a consulta ainda está aguardando (Scheduled) —
        // nunca sobrescreve Attended/Cancelled/estados de fluxo interno.
        $applied = false;

        if ($schedule && $schedule->situation === ScheduleSituation::Scheduled) {
            $target  = $choice === 1 ? ScheduleSituation::Confirmed : ScheduleSituation::Cancelled;
            $applied = true;

            DB::transaction(function () use ($schedule, $target) {
                $data = ['situation' => $target->value];

                if ($target === ScheduleSituation::Confirmed) {
                    $data['confirmed_at'] = now();
                } else {
                    $data['cancellation_reason'] = 'Cancelado pelo paciente via WhatsApp';
                }

                $from = $schedule->situation;
                $schedule->update($data);

                // entity_user_id null = ação do PACIENTE (via WhatsApp), não
                // de um usuário do painel — coluna é nullable de propósito.
                ScheduleSituationLog::create([
                    'schedule_id'    => $schedule->id,
                    'entity_user_id' => null,
                    'from_situation' => $from->value,
                    'to_situation'   => $target->value,
                    'notes'          => 'Resposta do paciente via WhatsApp',
                    'created_at'     => now(),
                ]);
            });

            Cache::forget("waiting_room:{$schedule->entity_id}");
        }

        $message->update([
            'status'      => WhatsAppMessage::STATUS_ANSWERED,
            'answered_at' => now(),
        ]);

        if ($choice === 1) {
            return $applied
                ? "Presença confirmada! ✅\nAté lá!"
                : 'Obrigado pela resposta! Sua consulta já havia sido atualizada pela clínica.';
        }

        return $applied
            ? "Consulta cancelada. 😔\nSe quiser remarcar, entre em contato com a clínica."
            : 'Obrigado pela resposta! Sua consulta já havia sido atualizada pela clínica — confirme com a recepção.';
    }

    private function applySurveyReply(WhatsAppMessage $message, string $text): ?string
    {
        $validDays = (int) config('whatsapp.survey.reply_valid_days', 14);

        if ($message->sent_at && $message->sent_at->lt(now()->subDays($validDays))) {
            return null;
        }

        $score = $this->parseScore($text);

        if ($score === null) {
            return "Não entendi sua avaliação. 🙂\nResponda apenas com um número de 1 a 5.";
        }

        $message->update([
            'status'       => WhatsAppMessage::STATUS_ANSWERED,
            'answered_at'  => now(),
            'survey_score' => $score,
        ]);

        return $score >= 4
            ? 'Que bom que você teve uma boa experiência! Obrigado pela avaliação. 💙'
            : 'Obrigado pelo retorno! Vamos trabalhar para melhorar seu próximo atendimento.';
    }

    private function parseChoice(string $text): ?int
    {
        $t = Str::lower(trim($text));

        if ($t === '1' || str_starts_with($t, 'sim') || str_starts_with($t, 'confirm')) {
            return 1;
        }

        if ($t === '2' || str_starts_with($t, 'nao') || str_starts_with($t, 'não') || str_starts_with($t, 'cancel')) {
            return 2;
        }

        return null;
    }

    private function parseScore(string $text): ?int
    {
        $t = trim($text);

        if (preg_match('/^[1-5]$/', $t)) {
            return (int) $t;
        }

        return null;
    }

    private function isUniqueViolation(QueryException $e): bool
    {
        return in_array($e->getCode(), ['23000', '23505'], true);
    }
}
