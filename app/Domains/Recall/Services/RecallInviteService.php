<?php

declare(strict_types=1);

namespace App\Domains\Recall\Services;

use App\Domains\Recall\Enums\{InviteBlocker, InviteOrigin};
use App\Domains\Recall\Exceptions\RecallInviteException;
use App\Domains\Recall\Models\{PatientRecallEvent, RecallClinicSettings, RecallInvite};
use App\Domains\Recall\Support\{InviteDecision, RecallLocks};
use App\Jobs\WhatsApp\SendWhatsAppMessageJob;
use App\Models\User;
use App\Services\WhatsApp\{WhatsAppService, WhatsAppTemplates};
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\{DB, Log};
use Throwable;

/**
 * O convite de retorno por WhatsApp (ADR 0002, item 9): a PRÉVIA (o que sairia, para quem, e por que não sai), o
 * envio por uma PESSOA e a RODADA AUTOMÁTICA de uma clínica. As duas formas de enviar passam pela MESMA criação de
 * onda (`createWave`): uma transação que toma a trava da clínica e a do paciente, reconfere tudo no
 * RecallInviteGuard sob trava e só então grava a onda, os itens, a mensagem e os eventos. Depois do commit, enfileira
 * o job — que revalida de novo no momento do envio (I3).
 *
 * Uma onda por paciente por dia (`wave_key` único por clínica): a rodada e o clique de uma pessoa nunca mandam dois
 * convites no mesmo dia, nem duas rodadas simultâneas.
 */
final class RecallInviteService
{
    public function __construct(
        private readonly RecallInviteGuard $guard,
        private readonly RecallInvitePacing $pacing,
        private readonly WhatsAppService $whatsApp,
        private readonly WhatsAppTemplates $templates,
    ) {
    }

    // ── prévia ──────────────────────────────────────────────────────────────

    /**
     * O que um convite a este paciente faria AGORA, sem gravar nada (a tela mostra antes de a pessoa confirmar).
     *
     * @return array{can_send: bool, blockers: list<array{code: string, label: string}>, phone: ?string, recalls: int, message: ?array{body: string, footer: ?string, buttons: list<string>}, last_invite_at: ?string, next_allowed_on: ?string, daily: array{used: int, cap: ?int}}
     */
    public function preview(string $entityId, string $recallId, ?CarbonImmutable $now = null): array
    {
        $now      = $now ?? CarbonImmutable::now();
        $decision = $this->guard->decide($entityId, $this->patientOf($entityId, $recallId), InviteOrigin::Manual, $now);

        return [
            'can_send'        => $decision->allowed(),
            'blockers'        => array_map(static fn (InviteBlocker $blocker): array => ['code' => $blocker->value, 'label' => $blocker->label()], $decision->blockers),
            'phone'           => $decision->phone !== null ? self::maskPhone($decision->phone) : null,
            'recalls'         => count($decision->recalls),
            'message'         => $decision->firstName !== '' ? $this->messageText($decision->firstName, $decision->clinicName) : null,
            'last_invite_at'  => $decision->pacing['last_invite_at']?->toIso8601String(),
            'next_allowed_on' => $decision->pacing['next_allowed_on'],
            'daily'           => ['used' => $decision->pacing['used_today'], 'cap' => $decision->pacing['cap_today']],
        ];
    }

    /**
     * O texto exato do template (corpo, rodapé do SAIR e rótulos dos botões), com as variáveis de exemplo do paciente.
     *
     * @return array{body: string, footer: ?string, buttons: list<string>}
     */
    public function messageText(string $firstName, string $clinic): array
    {
        $lines   = explode("\n", trim($this->templates->render('care_recall', ['first_name' => $firstName, 'clinic' => $clinic], 'pt_BR')));
        $buttons = [];

        if ($lines !== [] && str_starts_with(trim((string) end($lines)), '[')) {
            preg_match_all('/\[([^\]]+)\]/u', (string) array_pop($lines), $matches);
            $buttons = $matches[1];
        }

        $footer = count($lines) > 1 ? trim((string) array_pop($lines)) : null;

        return ['body' => trim(implode("\n", $lines)), 'footer' => $footer, 'buttons' => $buttons];
    }

    // ── envio por uma pessoa ────────────────────────────────────────────────

    /**
     * Manda o convite ao paciente do retorno (a onda cobre TODOS os retornos dele que podem ser convidados hoje).
     *
     * @throws RecallInviteException
     */
    public function inviteManual(string $entityId, string $recallId, ?User $actor, ?CarbonImmutable $now = null): RecallInvite
    {
        return $this->createWave($entityId, $this->patientOf($entityId, $recallId), InviteOrigin::Manual, $actor, $now ?? CarbonImmutable::now());
    }

    // ── rodada automática ───────────────────────────────────────────────────

    /**
     * A rodada automática de UMA clínica: pacientes com retorno a convidar, do vencimento mais antigo para o mais novo,
     * até o teto do dia e o teto de ondas por rodada (`max_per_run_per_clinic`). O teto da rodada conta ONDAS
     * ENFILEIRADAS, não candidatos lidos: quem o guard recusa (celular inválido, SAIR, protocolo sem contato automático…)
     * não ocupa vaga, e a rodada segue pela lista até achar quem pode receber. Nunca lança por causa de um paciente:
     * cada recusa é contada no relatório.
     *
     * @return array{candidates: int, queued: int, skipped: array<string, int>, errors: int, stopped: ?string}
     */
    public function runClinic(string $entityId, ?CarbonImmutable $now = null, bool $dryRun = false): array
    {
        $now    = $now ?? CarbonImmutable::now();
        $report = ['candidates' => 0, 'queued' => 0, 'skipped' => [], 'errors' => 0, 'stopped' => null];

        if (($channel = $this->guard->channelBlocker($entityId)) !== null) {
            $report['stopped'] = $channel->value;

            return $report;
        }

        $settings = RecallClinicSettings::forEntity($entityId);

        if (! $this->pacing->inWindow($settings, $now)) {
            $report['stopped'] = InviteBlocker::OutsideWindow->value;

            return $report;
        }

        $cap    = $this->pacing->dailyCap($settings, $now);
        $used   = $this->pacing->sentToday($entityId, $now);
        $perRun = max(1, (int) config('recall.invites.max_per_run_per_clinic', 200));

        foreach ($this->candidates($entityId, $settings, $now) as $patientId) {
            if ($report['queued'] >= $perRun) {
                break;
            }

            $report['candidates']++;

            if ($cap !== null && $used + $report['queued'] >= $cap) {
                $report['stopped'] = InviteBlocker::DailyCap->value;

                break;
            }

            try {
                if ($dryRun) {
                    $decision = $this->guard->decide($entityId, $patientId, InviteOrigin::Auto, $now);
                    $decision->allowed() ? $report['queued']++ : $this->countSkipped($report, $decision->blockers);

                    continue;
                }

                $this->createWave($entityId, $patientId, InviteOrigin::Auto, null, $now);
                $report['queued']++;
            } catch (RecallInviteException $e) {
                $this->countSkipped($report, $e->blockers() !== [] ? $e->blockers() : [$e->reason()]);

                if (in_array(InviteBlocker::DailyCap, $e->blockers(), true)) {
                    $report['stopped'] = InviteBlocker::DailyCap->value;

                    break;
                }
            } catch (Throwable $e) {
                $report['errors']++;
                Log::error('Convite de retorno: falha ao criar a onda do paciente.', ['entity_id' => $entityId, 'patient_id' => $patientId, 'exception' => $e]);
            }
        }

        return $report;
    }

    /**
     * Pacientes que valem a tentativa, do retorno mais atrasado para o menos: só quem tem retorno a convidar, celular
     * com WhatsApp, consentimento e sem bloqueio de contato, e NÃO foi convidado há pouco nem esgotou o teto por ciclo
     * (contado como o RecallInvitePacing: mensagem pulada/cancelada não pesa). A lista vem INTEIRA, sem `LIMIT`: o
     * teto da rodada é de ondas enfileiradas (runClinic), então os que o guard recusa depois (celular inválido, SAIR,
     * protocolo sem contato automático) não seguram os demais. A decisão final é do RecallInviteGuard sob trava.
     *
     * @return list<string>
     */
    private function candidates(string $entityId, RecallClinicSettings $settings, CarbonImmutable $now): array
    {
        $today      = $now->startOfDay();
        $day        = $today->toDateString();
        $recentFrom = $today->subDays(max(1, (int) $settings->min_days_between_invites) - 1)->toDateTimeString();
        $maxAuto    = max(1, (int) $settings->max_auto_invites_per_cycle);
        $uncounted  = RecallInvitePacing::UNCOUNTED_STATUSES;
        $marks      = implode(', ', array_fill(0, count($uncounted), '?'));

        return DB::table('patient_recalls as r')
            ->join('patients as p', 'p.id', '=', 'r.patient_id')
            ->join('people as pe', 'pe.id', '=', 'p.person_id')
            ->where('r.entity_id', $entityId)
            ->where('p.entity_id', $entityId)
            ->where('p.active', true)
            ->whereNull('p.deleted_at')
            ->whereNull('pe.deleted_at')
            ->where('pe.whatsapp', true)
            ->where('r.lifecycle', 'open')
            ->where('r.rule_suspended', false)
            ->whereNull('r.scheduled_schedule_id')
            ->where('r.invite_from', '<=', $day)
            ->where('r.lost_from', '>', $day)
            ->where('r.due_at', '>=', $today->subDays(max(1, (int) $settings->auto_invite_max_overdue_days))->toDateString())
            ->whereExists(static function ($query) use ($entityId): void {
                $query->selectRaw('1')->from('patient_consents as pc')
                    ->whereColumn('pc.patient_id', 'r.patient_id')
                    ->where('pc.entity_id', $entityId)
                    ->where('pc.consent_type', 'care_reminders')
                    ->where('pc.status', 'granted')
                    ->whereNull('pc.revoked_at');
            })
            ->whereNotExists(static function ($query) use ($entityId): void {
                $query->selectRaw('1')->from('patient_recall_blocks as prb')
                    ->whereColumn('prb.patient_id', 'r.patient_id')
                    ->where('prb.entity_id', $entityId)
                    ->whereNull('prb.released_at');
            })
            ->whereNotExists(static function ($query) use ($entityId, $recentFrom): void {
                $query->selectRaw('1')->from('recall_invites as ri')
                    ->leftJoin('whatsapp_messages as m', static function ($join): void {
                        $join->on('m.recall_invite_id', '=', 'ri.id')->where('m.direction', '=', 'out');
                    })
                    ->whereColumn('ri.patient_id', 'r.patient_id')
                    ->where('ri.entity_id', $entityId)
                    ->where('ri.created_at', '>=', $recentFrom)
                    ->where(static function ($inner): void {
                        $inner->whereNull('m.id')->orWhereNotIn('m.status', RecallInvitePacing::UNCOUNTED_STATUSES);
                    });
            })
            ->whereRaw(
                '(SELECT COUNT(*) FROM recall_invite_items it JOIN recall_invites ri2 ON ri2.id = it.recall_invite_id '
                . "LEFT JOIN whatsapp_messages m2 ON m2.recall_invite_id = ri2.id AND m2.direction = 'out' "
                . "WHERE it.patient_recall_id = r.id AND ri2.origin = 'auto' AND ri2.created_at >= r.invite_from "
                . "AND (m2.id IS NULL OR m2.status NOT IN ({$marks}))) < ?",
                [...$uncounted, $maxAuto],
            )
            ->groupBy('r.patient_id')
            ->orderByRaw('MIN(r.due_at) ASC')
            ->orderBy('r.patient_id')
            ->pluck('r.patient_id')
            ->map(static fn (mixed $id): string => (string) $id)
            ->all();
    }

    /**
     * @param array{candidates: int, queued: int, skipped: array<string, int>, errors: int, stopped: ?string} $report
     * @param list<InviteBlocker|string>                                                                      $reasons
     */
    private function countSkipped(array &$report, array $reasons): void
    {
        foreach ($reasons as $reason) {
            $code = $reason instanceof InviteBlocker ? $reason->value : $reason;

            $report['skipped'][$code] = ($report['skipped'][$code] ?? 0) + 1;
        }
    }

    // ── a onda ──────────────────────────────────────────────────────────────

    /**
     * Cria a onda do paciente: tudo numa transação sob a trava da CLÍNICA e a do PACIENTE (nessa ordem), com a decisão
     * tomada SOB trava — o teto do dia e o "já convidado hoje" valem para quem chegou primeiro.
     *
     * @throws RecallInviteException
     */
    private function createWave(string $entityId, string $patientId, InviteOrigin $origin, ?User $actor, CarbonImmutable $now): RecallInvite
    {
        [$invite, $messageId] = DB::transaction(function () use ($entityId, $patientId, $origin, $actor, $now): array {
            RecallLocks::clinicInvites($entityId);
            RecallLocks::patient($patientId);

            $decision = $this->guard->decide($entityId, $patientId, $origin, $now, lock: true);

            if (! $decision->allowed()) {
                throw RecallInviteException::blocked($decision->blockers);
            }

            $key = RecallInvite::waveKey($patientId, $now->toDateString());

            if (RecallInvite::query()->withoutGlobalScopes()->where('entity_id', $entityId)->where('wave_key', $key)->exists()) {
                throw RecallInviteException::alreadyToday();
            }

            $invite = RecallInvite::query()->withoutGlobalScopes()->create([
                'entity_id'  => $entityId,
                'patient_id' => $patientId,
                'wave_key'   => $key,
                'origin'     => $origin->value,
                'created_by' => $actor?->getKey(),
            ]);

            $invite->recalls()->attach(array_map(static fn ($recall): string => (string) $recall->id, $decision->recalls));

            $message = $this->whatsApp->queueRecall($entityId, $patientId, (string) $invite->id, (string) $decision->phone, $decision->firstName, $decision->clinicName);

            $this->record($decision, $invite, $origin, $actor);

            return [$invite, (string) $message->id];
        });

        SendWhatsAppMessageJob::dispatch($messageId);

        return $invite;
    }

    private function record(InviteDecision $decision, RecallInvite $invite, InviteOrigin $origin, ?User $actor): void
    {
        foreach ($decision->recalls as $recall) {
            PatientRecallEvent::query()->withoutGlobalScopes()->create([
                'entity_id'     => $recall->entity_id,
                'patient_id'    => $recall->patient_id,
                'recall_id'     => $recall->id,
                'event'         => 'invite_queued',
                'actor_user_id' => $actor?->getKey(),
                'actor_label'   => $actor !== null ? mb_substr((string) $actor->name, 0, 160) : 'system',
                'meta'          => ['invite_id' => $invite->id, 'origin' => $origin->value],
            ]);
        }
    }

    // ── infraestrutura ──────────────────────────────────────────────────────

    /** O paciente do retorno, ou "não encontrado" (retorno de outra clínica, inexistente ou de paciente removido). */
    private function patientOf(string $entityId, string $recallId): string
    {
        $patientId = DB::table('patient_recalls as r')
            ->join('patients as p', 'p.id', '=', 'r.patient_id')
            ->where('r.id', $recallId)
            ->where('r.entity_id', $entityId)
            ->where('p.entity_id', $entityId)
            ->whereNull('p.deleted_at')
            ->value('r.patient_id');

        if (! is_string($patientId)) {
            throw RecallInviteException::notFound();
        }

        return $patientId;
    }

    /** "(61) 9****-8888" — o telefone na tela sem mostrar tudo. */
    public static function maskPhone(string $phone): string
    {
        if (str_starts_with($phone, '55') && strlen($phone) === 13) {
            return '(' . substr($phone, 2, 2) . ') ' . $phone[4] . '****-' . substr($phone, -4);
        }

        return '+' . substr($phone, 0, 2) . '••••' . substr($phone, -3);
    }
}
