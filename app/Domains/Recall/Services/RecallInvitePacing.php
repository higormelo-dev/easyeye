<?php

declare(strict_types=1);

namespace App\Domains\Recall\Services;

use App\Domains\Recall\Models\RecallClinicSettings;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * O RITMO dos convites de retorno (ADR 0002, item 9): janela de horário, intervalo mínimo entre convites do mesmo
 * paciente, teto por ciclo e teto diário com ramp-up. Protege o número de WhatsApp (compartilhado com a confirmação
 * de consulta) e o paciente. Só leitura; quem decide é o RecallInviteGuard.
 *
 * "Conta" como convite o que saiu ou ainda pode sair: a mensagem `skipped`/`suppressed` nunca chegou ao paciente e
 * não pesa nos tetos.
 */
final class RecallInvitePacing
{
    /**
     * Status da mensagem que tiram o convite da conta (tetos e intervalo): a mensagem cancelada/pulada nunca chegou ao
     * paciente. A rodada automática escolhe candidatos com a MESMA lista (RecallInviteService::candidates), senão o
     * robô descartaria um retorno que o guard considera elegível.
     *
     * @var list<string>
     */
    public const UNCOUNTED_STATUSES = ['skipped', 'suppressed'];

    /** Dentro da janela de horário e nos dias da semana da clínica (horário do app). */
    public function inWindow(RecallClinicSettings $settings, CarbonImmutable $now): bool
    {
        $time = $now->format('H:i');

        return in_array($now->isoWeekday(), array_map('intval', (array) $settings->invite_weekdays), true)
            && $time >= (string) $settings->invite_window_start
            && $time <= (string) $settings->invite_window_end;
    }

    /**
     * O teto de convites por dia hoje: o menor entre o degrau do ramp-up (dias desde que a clínica ligou os convites)
     * e o teto da própria clínica. Nulo = sem teto (clínica sem limite configurado e ramp-up concluído).
     */
    public function dailyCap(RecallClinicSettings $settings, CarbonImmutable $now): ?int
    {
        $own  = $settings->daily_invite_cap > 0 ? (int) $settings->daily_invite_cap : null;
        $ramp = $this->rampStep($settings, $now);

        return match (true) {
            $own === null  => $ramp,
            $ramp === null => $own,
            default        => min($own, $ramp),
        };
    }

    /** O degrau do ramp-up vigente (nulo = ramp-up concluído, sem limite por esta regra). */
    private function rampStep(RecallClinicSettings $settings, CarbonImmutable $now): ?int
    {
        $started = $settings->ramp_started_on !== null ? CarbonImmutable::parse($settings->ramp_started_on)->startOfDay() : $now->startOfDay();
        $days    = max(0, (int) $started->diffInDays($now->startOfDay()));
        $steps   = (array) config('recall.invites.ramp', []);

        ksort($steps);

        $cap = null;

        foreach ($steps as $fromDay => $stepCap) {
            if ($days >= (int) $fromDay) {
                $cap = $stepCap === null ? null : (int) $stepCap;
            }
        }

        return $cap;
    }

    /** Quantos convites a clínica já mandou (ou vai mandar) hoje. */
    public function sentToday(string $entityId, CarbonImmutable $now): int
    {
        return $this->counted($entityId)->where('ri.created_at', '>=', $now->startOfDay())->count();
    }

    /** Quando o paciente recebeu o último convite (de qualquer origem) que conta. */
    public function lastInviteAt(string $entityId, string $patientId): ?CarbonImmutable
    {
        $at = $this->counted($entityId)->where('ri.patient_id', $patientId)->max('ri.created_at');

        return $at !== null ? CarbonImmutable::parse($at) : null;
    }

    /** Dia a partir do qual o paciente pode receber novo convite (nulo = já pode). */
    public function nextAllowedOn(RecallClinicSettings $settings, ?CarbonImmutable $lastInviteAt, CarbonImmutable $now): ?string
    {
        if ($lastInviteAt === null) {
            return null;
        }

        $next = $lastInviteAt->startOfDay()->addDays(max(1, (int) $settings->min_days_between_invites));

        return $next->greaterThan($now->startOfDay()) ? $next->toDateString() : null;
    }

    /**
     * Quantos convites AUTOMÁTICOS já cobriram este retorno no ciclo atual (desde o dia em que começou a poder ser
     * convidado). O convite que uma pessoa mandou não conta: o teto é para o robô.
     */
    public function autoInvitesInCycle(string $entityId, string $recallId, ?CarbonImmutable $cycleStart): int
    {
        return $this->counted($entityId)
            ->join('recall_invite_items as it', 'it.recall_invite_id', '=', 'ri.id')
            ->where('it.patient_recall_id', $recallId)
            ->where('ri.origin', 'auto')
            ->when($cycleStart !== null, static fn (Builder $query) => $query->where('ri.created_at', '>=', $cycleStart->startOfDay()))
            ->count();
    }

    /** Convites da clínica que contam (a mensagem saiu, está na fila ou falhou — não a cancelada/pulada). */
    private function counted(string $entityId): Builder
    {
        return DB::table('recall_invites as ri')
            ->leftJoin('whatsapp_messages as m', static function ($join): void {
                $join->on('m.recall_invite_id', '=', 'ri.id')->where('m.direction', '=', 'out');
            })
            ->where('ri.entity_id', $entityId)
            ->where(static function (Builder $query): void {
                $query->whereNull('m.id')->orWhereNotIn('m.status', self::UNCOUNTED_STATUSES);
            });
    }
}
