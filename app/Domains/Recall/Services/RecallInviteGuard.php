<?php

declare(strict_types=1);

namespace App\Domains\Recall\Services;

use App\Domains\Recall\Engine\RecallItem;
use App\Domains\Recall\Enums\{InviteBlocker, InviteOrigin, RecallLifecycle};
use App\Domains\Recall\Intravitreal\Enums\CourseStatus;
use App\Domains\Recall\Intravitreal\Models\IntravitrealCourse;
use App\Domains\Recall\Models\{PatientRecall, RecallClinicProtocol, RecallClinicSettings, RecallInvite};
use App\Domains\Recall\Support\InviteDecision;
use App\Enums\{ConsentType, FeatureKey};
use App\Models\Entity;
use App\Models\WhatsApp\{WhatsAppMessage, WhatsAppOptOut, WhatsAppSetting};
use App\Services\Billing\ClinicServiceGate;
use App\Services\FeatureGateService;
use App\Services\WhatsApp\WhatsAppService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Quem decide se um convite de retorno por WhatsApp pode sair (ADR 0002, item 9; invariantes I1 e I3). É a ÚNICA porta:
 * a prévia, o envio de uma pessoa, a rodada automática e a revalidação no momento do envio leem daqui.
 *
 * Quatro camadas, todas conferidas:
 *  1. o CANAL da clínica — pausa global do EasyEye, plano com `has_patient_recall` (rebaixar o plano não apaga as
 *     configurações nem os retornos, e a tela e o avaliador já param: o envio também), chave da clínica no Manager,
 *     número que consegue enviar, assinatura em dia e chave da clínica nas configurações do recall;
 *  2. o PACIENTE — cadastro ativo, sem bloqueio de contato, com consentimento de lembretes (CareReminders), celular
 *     marcado como WhatsApp e válido, e que não respondeu SAIR ao número que envia;
 *  3. os RETORNOS — em aberto, sem agendamento, dentro da janela de convite, com a regra ainda em uso (versão liberada
 *     e íntegra, e a tarefa ainda ligada na adoção; no curso de injeções, o curso ainda ativo: a linha de retorno é
 *     derivada e atrasa em relação a quem a muda) e, no automático, com `auto_contact` ligado no protocolo e o teto por
 *     ciclo respeitado;
 *  4. o RITMO — janela de horário, intervalo mínimo e teto diário com ramp-up (RecallInvitePacing).
 *
 * Nada aqui grava. Com `$lock`, os retornos são lidos sob FOR UPDATE (o chamador já tomou a trava do paciente).
 */
final class RecallInviteGuard
{
    public function __construct(
        private readonly RecallKillSwitch $killSwitch,
        private readonly RecallInvitePacing $pacing,
        private readonly ClinicServiceGate $gate,
        private readonly FeatureGateService $features,
    ) {
    }

    // ── camada 1: o canal da clínica ────────────────────────────────────────

    /** O que impede a clínica de mandar QUALQUER convite agora (nulo = canal aberto): os três níveis de chave e o número. */
    public function channelBlocker(string $entityId): ?InviteBlocker
    {
        return $this->providerBlocker($entityId)
            ?? (RecallClinicSettings::forEntity($entityId)->whatsapp_invites_enabled ? null : InviteBlocker::ClinicSwitchOff);
    }

    /**
     * O que está do lado do EASYEYE (nada que a clínica resolva sozinha): pausa global, plano com o recurso de retorno,
     * chave da clínica no Manager, número que consegue enviar e assinatura em dia. A tela de configuração mostra isto antes de a clínica ligar a
     * própria chave.
     */
    public function providerBlocker(string $entityId): ?InviteBlocker
    {
        if ($this->killSwitch->isPaused()) {
            return InviteBlocker::PausedGlobal;
        }

        if (! $this->features->can($entityId, FeatureKey::HasPatientRecall)) {
            return InviteBlocker::FeatureOff;
        }

        $setting = $this->setting($entityId);

        if ($setting === null || ! $setting->recall_enabled) {
            return InviteBlocker::ClinicNotEnabled;
        }

        if (! $setting->canSend()) {
            return InviteBlocker::NoSender;
        }

        if (! $this->gate->allowsAutomation($entityId)) {
            return InviteBlocker::AccessBlocked;
        }

        return null;
    }

    public function setting(string $entityId): ?WhatsAppSetting
    {
        return WhatsAppSetting::query()->where('entity_id', $entityId)->first();
    }

    // ── a decisão completa ──────────────────────────────────────────────────

    /**
     * Decide o convite do paciente AGORA. Junta TODOS os impedimentos (a prévia mostra todos); quem só precisa saber
     * se pode usa `allowed()`.
     */
    public function decide(string $entityId, string $patientId, InviteOrigin $origin, CarbonImmutable $now, bool $lock = false): InviteDecision
    {
        $automatic = $origin === InviteOrigin::Auto;
        $settings  = RecallClinicSettings::forEntity($entityId);
        $blockers  = [];

        if (($channel = $this->channelBlocker($entityId)) !== null) {
            $blockers[] = $channel;
        }

        $setting = $this->setting($entityId);
        $sender  = $setting?->active ? $setting->sendingSetting() : null;
        $contact = $this->contact($entityId, $patientId, $sender);

        array_push($blockers, ...$contact['blockers']);

        $recalls = in_array(InviteBlocker::PatientUnavailable, $contact['blockers'], true)
            ? []
            : $this->eligibleRecalls($entityId, $patientId, $now->startOfDay(), $automatic, $settings, $lock);

        if ($recalls === []) {
            $blockers[] = InviteBlocker::NothingToInvite;
        }

        // Ritmo.
        $last = $this->pacing->lastInviteAt($entityId, $patientId);
        $next = $this->pacing->nextAllowedOn($settings, $last, $now);
        $cap  = $this->pacing->dailyCap($settings, $now);
        $used = $this->pacing->sentToday($entityId, $now);

        if ($automatic && ! $this->pacing->inWindow($settings, $now)) {
            $blockers[] = InviteBlocker::OutsideWindow;
        }

        if ($next !== null) {
            $blockers[] = InviteBlocker::TooSoon;
        }

        if ($cap !== null && $used >= $cap) {
            $blockers[] = InviteBlocker::DailyCap;
        }

        $clinic = Entity::query()->whereKey($entityId)->value('name');

        return new InviteDecision(
            blockers: array_values(array_unique($blockers, SORT_REGULAR)),
            recalls: $recalls,
            phone: $contact['phone'],
            firstName: $contact['first_name'],
            clinicName: (string) $clinic,
            pacing: ['last_invite_at' => $last, 'next_allowed_on' => $next, 'used_today' => $used, 'cap_today' => $cap],
        );
    }

    // ── camada 2: o paciente ────────────────────────────────────────────────

    /**
     * Cadastro, bloqueio, consentimento, WhatsApp, telefone e descadastro.
     *
     * @return array{blockers: list<InviteBlocker>, phone: ?string, first_name: string}
     */
    public function contact(string $entityId, string $patientId, ?WhatsAppSetting $sender): array
    {
        $row = DB::table('patients as p')
            ->join('people as pe', 'pe.id', '=', 'p.person_id')
            ->where('p.id', $patientId)
            ->where('p.entity_id', $entityId)
            ->whereNull('p.deleted_at')
            ->whereNull('pe.deleted_at')
            ->first(['p.active', 'pe.full_name', 'pe.cellphone', 'pe.whatsapp']);

        if ($row === null || ! $row->active) {
            return ['blockers' => [InviteBlocker::PatientUnavailable], 'phone' => null, 'first_name' => ''];
        }

        $blockers = [];

        if (DB::table('patient_recall_blocks')->where('entity_id', $entityId)->where('patient_id', $patientId)->whereNull('released_at')->exists()) {
            $blockers[] = InviteBlocker::ContactBlocked;
        }

        if (! $this->hasConsent($entityId, $patientId)) {
            $blockers[] = InviteBlocker::NoConsent;
        }

        $phone = $this->mobilePhone((string) $row->cellphone);

        if (! (bool) $row->whatsapp) {
            $blockers[] = InviteBlocker::NoWhatsapp;
        } elseif ($phone === null) {
            $blockers[] = InviteBlocker::InvalidPhone;
        } elseif ($sender !== null && WhatsAppOptOut::isSuppressed($sender, $phone)) {
            $blockers[] = InviteBlocker::OptedOut;
        }

        return ['blockers' => $blockers, 'phone' => $phone, 'first_name' => WhatsAppService::firstNameOf((string) $row->full_name)];
    }

    /** O paciente tem autorização VIGENTE de lembretes de retorno por WhatsApp? */
    public function hasConsent(string $entityId, string $patientId): bool
    {
        return DB::table('patient_consents')
            ->where('entity_id', $entityId)
            ->where('patient_id', $patientId)
            ->where('consent_type', ConsentType::CareReminders->value)
            ->where('status', 'granted')
            ->whereNull('revoked_at')
            ->exists();
    }

    /** Celular que recebe WhatsApp: no Brasil, móvel (DDD + 9 + 8 dígitos); no exterior, o número como veio (com "+"). */
    public function mobilePhone(?string $raw): ?string
    {
        $phone = WhatsAppService::normalizePhone($raw);

        if ($phone === null) {
            return null;
        }

        if (str_starts_with($phone, '55') && (strlen($phone) !== 13 || $phone[4] !== '9')) {
            return null;
        }

        return $phone;
    }

    // ── camada 3: os retornos ───────────────────────────────────────────────

    /**
     * Os retornos do paciente que a onda pode cobrir hoje.
     *
     * @return list<PatientRecall>
     */
    public function eligibleRecalls(string $entityId, string $patientId, CarbonImmutable $today, bool $automatic, RecallClinicSettings $settings, bool $lock = false): array
    {
        $day = $today->toDateString();

        $query = PatientRecall::query()->withoutGlobalScopes()
            ->where('entity_id', $entityId)
            ->where('patient_id', $patientId)
            ->where('lifecycle', RecallLifecycle::Open->value)
            ->where('rule_suspended', false)
            ->whereNull('scheduled_schedule_id')
            ->whereNotNull('invite_from')
            ->where('invite_from', '<=', $day)
            ->where('lost_from', '>', $day)
            ->orderBy('due_at')
            ->orderBy('id');

        if ($automatic) {
            // Vencido há muito tempo sai do robô (vai para a busca ativa por ligação).
            $query->where('due_at', '>=', $today->subDays(max(1, (int) $settings->auto_invite_max_overdue_days))->toDateString());
        }

        if ($lock) {
            $query->lockForUpdate();
        }

        return $this->usable($query->get(), $entityId, $automatic, $settings);
    }

    /**
     * Mantém só os retornos cuja REGRA ainda vale agora (I3): retorno do médico com o recurso ligado; retorno de
     * protocolo com adoção ativa, versão liberada/substituída, íntegra e igual à adotada E a tarefa ainda ligada na
     * adoção (`computesTask`: a clínica desliga uma tarefa opcional pelos parâmetros e a linha persistida segue aberta
     * até o avaliador rodar — o convite não pode sair por uma tarefa que ela já desligou) — e, no automático,
     * protocolo com contato automático ligado e teto por ciclo respeitado.
     *
     * @param Collection<int, PatientRecall> $recalls
     * @param bool                           $cycleCap aplica o teto de convites automáticos por ciclo (na criação da onda; não na revalidação)
     *
     * @return list<PatientRecall>
     */
    private function usable(Collection $recalls, string $entityId, bool $automatic, RecallClinicSettings $settings, bool $cycleCap = true): array
    {
        if ($recalls->isEmpty()) {
            return [];
        }

        $adoptions = RecallClinicProtocol::query()->withoutGlobalScopes()
            ->where('entity_id', $entityId)
            ->whereNull('deactivated_at')
            ->with(['protocol', 'version'])
            ->get()
            ->keyBy(static fn (RecallClinicProtocol $adoption): string => (string) $adoption->protocol->key);

        $usable = [];

        foreach ($recalls as $recall) {
            if ($recall->isDoctorOrder()) {
                $ruleOk = $settings->doctor_order_enabled;
            } elseif ($recall->isCourse()) {
                // Tratamento com injeções: o intervalo é decisão do médico (sem protocolo). Convite manual sempre
                // pode; o automático segue a mesma chave do retorno pedido pelo médico — em ambos, só enquanto o CURSO
                // desta linha segue ativo com próxima aplicação.
                $ruleOk = $this->courseIsLive($entityId, $recall) && (! $automatic || $settings->doctor_order_enabled);
            } else {
                $adoption = $adoptions->get((string) $recall->protocol_key);
                $ruleOk   = $adoption !== null && $adoption->computesTask((string) $recall->task_key) && (! $automatic || $adoption->auto_contact);
            }

            if (! $ruleOk) {
                continue;
            }

            if ($automatic && $cycleCap && $this->pacing->autoInvitesInCycle($entityId, (string) $recall->id, $recall->invite_from !== null ? CarbonImmutable::parse($recall->invite_from) : null) >= max(1, (int) $settings->max_auto_invites_per_cycle)) {
                continue;
            }

            $usable[] = $recall;
        }

        return $usable;
    }

    /**
     * O curso de injeções a que este retorno pertence segue ATIVO e com próxima aplicação? A linha do curso é derivada:
     * concluir, interromper ou pausar o tratamento não a fecha na hora (o avaliador o faz em poucos minutos), e o convite
     * ("sua aplicação está próxima") não se desfaz — não pode sair para um tratamento que o médico acabou de encerrar. A
     * mesma conta de `RecallEvaluator::courseItems` (ativo, com próxima aplicação e não apagado).
     */
    private function courseIsLive(string $entityId, PatientRecall $recall): bool
    {
        $courseId = RecallItem::courseIdOf((string) $recall->task_key);

        return $courseId !== null
            && IntravitrealCourse::query()->withoutGlobalScopes()
                ->where('entity_id', $entityId)
                ->where('patient_id', $recall->patient_id)
                ->whereKey($courseId)
                ->whereNull('deleted_at')
                ->where('status', CourseStatus::Active->value)
                ->whereNotNull('next_due_on')
                ->exists();
    }

    /**
     * Os limites de DATA que `eligibleRecalls` aplica no SQL, para a mesma conta feita sobre a linha já carregada
     * (revalidação no envio): já na janela de convite, ainda não perdido de vista e — só no automático — não atrasado
     * demais. Data ausente reprova, como a comparação com nulo no SQL.
     */
    private function withinInviteDates(PatientRecall $recall, CarbonImmutable $today, bool $automatic, RecallClinicSettings $settings): bool
    {
        $day = $today->toDateString();

        if ($recall->invite_from === null || $recall->invite_from->toDateString() > $day) {
            return false;
        }

        if ($recall->lost_from === null || $recall->lost_from->toDateString() <= $day) {
            return false;
        }

        return ! $automatic
            || ($recall->due_at !== null && $recall->due_at->toDateString() >= $today->subDays(max(1, (int) $settings->auto_invite_max_overdue_days))->toDateString());
    }

    // ── a revalidação no momento do envio ───────────────────────────────────

    /**
     * O convite JÁ ENFILEIRADO ainda pode sair? Reconfere o canal, o paciente (consentimento, bloqueio, descadastro,
     * telefone igual ao da mensagem) e os retornos da onda (ainda em aberto, sem agendamento, dentro da janela de convite
     * de hoje e com a regra em uso). O ritmo (intervalo e tetos) NÃO se reconfere: ele vale para criar a onda. Retorna
     * o impedimento, ou nulo se pode sair. `OutsideWindow` só no automático: o job adia (não cancela).
     */
    public function stillEligible(WhatsAppMessage $message, ?CarbonImmutable $now = null): ?InviteBlocker
    {
        $now ??= CarbonImmutable::now();

        $invite = $message->recall_invite_id !== null
            ? RecallInvite::query()->withoutGlobalScopes()->find((string) $message->recall_invite_id)
            : null;

        if ($invite === null || $message->entity_id === null || $message->patient_id === null) {
            return InviteBlocker::NothingToInvite;
        }

        $entityId  = (string) $message->entity_id;
        $patientId = (string) $message->patient_id;

        // O worker é um processo longo e o FeatureGateService guarda a assinatura por clínica: sem isto, o plano lido
        // no 1º envio valeria até o worker reiniciar e o rebaixamento não alcançaria o que já está na fila.
        $this->features->forgetCache($entityId);

        if (($channel = $this->channelBlocker($entityId)) !== null) {
            return $channel;
        }

        $setting = $this->setting($entityId);
        $sender  = $setting?->active ? $setting->sendingSetting() : null;
        $contact = $this->contact($entityId, $patientId, $sender);

        if ($contact['blockers'] !== []) {
            return $contact['blockers'][0];
        }

        if ($contact['phone'] !== (string) $message->phone) {
            return InviteBlocker::InvalidPhone;
        }

        $automatic = $invite->origin === InviteOrigin::Auto;
        $settings  = RecallClinicSettings::forEntity($entityId);

        // Em aberto, sem agendamento E ainda na janela de convite de HOJE: se o paciente voltou enquanto o convite esperava
        // na fila, o avaliador empurra a MESMA linha para o ciclo seguinte (continua aberta, mas o convite só vale meses
        // depois) e o convite velho não pode sair.
        $recalls = $invite->recalls()->withoutGlobalScopes()->get()
            ->filter(fn (PatientRecall $recall): bool => $recall->lifecycle === RecallLifecycle::Open
                && ! $recall->rule_suspended
                && $recall->scheduled_schedule_id === null
                && $this->withinInviteDates($recall, $now->startOfDay(), $automatic, $settings));

        // Reconfere a regra de cada retorno (a versão pode ter sido suspensa depois de enfileirar). O teto por ciclo
        // não entra: a onda já existe e este próprio convite é um dos que ele conta.
        $stillUsable = $this->usable($recalls->values(), $entityId, $automatic, $settings, cycleCap: false);

        if ($stillUsable === []) {
            return InviteBlocker::NothingToInvite;
        }

        if ($automatic && ! $this->pacing->inWindow($settings, $now)) {
            return InviteBlocker::OutsideWindow;
        }

        return null;
    }
}
