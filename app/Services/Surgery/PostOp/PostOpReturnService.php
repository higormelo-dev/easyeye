<?php

declare(strict_types=1);

namespace App\Services\Surgery\PostOp;

use App\Enums\{ScheduleAttendanceType, ScheduleSituation};
use App\Enums\Surgery\{PostOpMilestone, PostOpReturnStatus, SurgicalCaseStatus};
use App\Exceptions\ScheduleSlotUnavailableException;
use App\Models\{Doctor, Schedule, VisitType};
use App\Models\Surgery\{SurgicalCase, SurgicalPostopEvaluation, SurgicalPostopReturn};
use App\Services\{ScheduleSeriesService, ScheduleService};
use Carbon\Carbon;
use Generator;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\{Collection, Str};
use Illuminate\Support\Facades\{DB, Log};
use Ramsey\Uuid\Uuid;
use RuntimeException;
use Throwable;

/**
 * Retornos do pós-operatório na Agenda (ADR 0003, D12): D1/D7/D30 planejados
 * quando a cirurgia é realizada e o retorno urgente (24 h) pedido por uma
 * complicação grave.
 *
 * Roda FORA de qualquer trava do caso (listener depois do commit): cada
 * retorno é um agendamento comum, criado como a Agenda cria (INSERT protegido
 * pelo índice único do horário do médico), sem sala cirúrgica, no PRIMEIRO
 * horário livre do cirurgião a partir do dia-alvo — avança para o próximo dia
 * com expediente quando o dia-alvo não tem horário, e guarda a data-alvo
 * original para a tela mostrar o desvio.
 *
 * Idempotência: `surgical_postop_returns` tem uma linha por caso + marco
 * (índice único). A criação planeja com INSERT … ON CONFLICT DO NOTHING e cada
 * retorno é REIVINDICADO por um UPDATE atômico — só o vencedor cria o
 * agendamento, e o agendamento + a marcação "agendado" entram na MESMA
 * transação (worker que morre no meio não deixa agendamento órfão nem
 * duplicado). Retorno cancelado pela recepção continua sendo "já existiu":
 * reentregar o evento não o recria.
 */
class PostOpReturnService
{
    private const CLAIM_LOST = 'postop_return_claim_lost';

    public function __construct(
        private readonly ScheduleService $schedules,
        private readonly ScheduleSeriesService $series,
    ) {
    }

    // ── Plano padrão (D1/D7/D30) ────────────────────────────────────────────

    /**
     * Planeja e agenda os retornos do caso. Seguro para repetir e para rodar em
     * dois processos ao mesmo tempo.
     *
     * @return list<SurgicalPostopReturn> os retornos do plano, já atualizados
     */
    public function plan(string $caseId): array
    {
        $case = SurgicalCase::query()->withoutEntityScope()->find($caseId);

        // Só cirurgia REALIZADA e ainda não encerrada gera retorno: alta dada
        // antes do worker rodar (ou caso cancelado) não cria agenda nova.
        if ($case === null || $case->status !== SurgicalCaseStatus::Performed) {
            return [];
        }

        $anchor  = ($case->performed_at ?? now())->copy()->startOfDay();
        $offsets = $this->offsets();
        $now     = now();

        DB::table('surgical_postop_returns')->insertOrIgnore(array_map(
            fn (PostOpMilestone $m) => [
                'id'               => (string) Str::uuid(),
                'entity_id'        => $case->entity_id,
                'surgical_case_id' => $case->getKey(),
                'milestone'        => $m->value,
                'offset_days'      => $offsets[$m->value],
                'target_date'      => $this->series->datesFromOffsets($anchor, [$offsets[$m->value]])[0]->toDateString(),
                'status'           => PostOpReturnStatus::Pending->value,
                'attempts'         => 0,
                'created_at'       => $now,
                'updated_at'       => $now,
            ],
            PostOpMilestone::planned(),
        ));

        foreach ($this->plannedReturns($case)->reject(fn (SurgicalPostopReturn $r) => $r->milestone === PostOpMilestone::Urgent) as $return) {
            $this->attempt($case, $return, includeFailed: false);
        }

        return $this->plannedReturns($case)->all();
    }

    /**
     * Botão "tentar de novo" da aba: retorna a tentar os que falharam (sem
     * horário, erro) e os que ficaram pendentes. Não recria retorno já agendado.
     *
     * @return list<SurgicalPostopReturn>
     */
    public function retry(SurgicalCase $case): array
    {
        if ($case->status !== SurgicalCaseStatus::Performed) {
            return [];
        }

        // Retorno urgente que não chegou a ser pedido (falha depois do commit da avaliação):
        // pedido de novo para toda avaliação grave finalizada — idempotente, um por avaliação.
        $severe = SurgicalPostopEvaluation::query()
            ->withoutEntityScope()
            ->where('surgical_case_id', $case->getKey())
            ->where('status', 'finalized')
            ->where('severe', true)
            ->get();

        foreach ($severe as $evaluation) {
            try {
                $this->requestUrgent($evaluation);
            } catch (Throwable $e) {
                Log::warning('surgery.postop_urgent_return_failed', ['evaluation_id' => (string) $evaluation->getKey(), 'exception' => $e::class]);
            }
        }

        foreach ($this->plannedReturns($case) as $return) {
            $this->attempt($case, $return, includeFailed: true, deadline: $this->deadlineFor($return));
        }

        return $this->plannedReturns($case)->all();
    }

    /** O retorno urgente só vale em até 24 h da avaliação grave; os demais não têm prazo. */
    private function deadlineFor(SurgicalPostopReturn $return): ?Carbon
    {
        if ($return->milestone !== PostOpMilestone::Urgent || $return->source_evaluation_id === null) {
            return null;
        }

        $evaluatedAt = SurgicalPostopEvaluation::query()->withoutEntityScope()->whereKey($return->source_evaluation_id)->value('evaluated_at');

        return $evaluatedAt === null ? null : Carbon::parse($evaluatedAt)->addHours(24);
    }

    // ── Retorno urgente (24 h) ──────────────────────────────────────────────

    /**
     * Complicação grave numa avaliação FINALIZADA: pede um retorno em até 24 h.
     * Um por avaliação (índice único). Sem horário em 24 h o retorno fica
     * "falhou" com o código `no_slot_24h` — a tela destaca para a recepção
     * encaixar à mão.
     */
    public function requestUrgent(SurgicalPostopEvaluation $evaluation): ?SurgicalPostopReturn
    {
        if (! $evaluation->severe || ! $evaluation->isFinalized()) {
            return null;
        }

        $case = SurgicalCase::query()->withoutEntityScope()->find($evaluation->surgical_case_id);

        if ($case === null || $case->status->isFinal()) {
            return null;
        }

        $now = now();

        DB::table('surgical_postop_returns')->insertOrIgnore([[
            'id'                   => (string) Str::uuid(),
            'entity_id'            => $evaluation->entity_id,
            'surgical_case_id'     => $evaluation->surgical_case_id,
            'milestone'            => PostOpMilestone::Urgent->value,
            'source_evaluation_id' => $evaluation->getKey(),
            'target_date'          => $evaluation->evaluated_at->toDateString(),
            'status'               => PostOpReturnStatus::Pending->value,
            'attempts'             => 0,
            'created_at'           => $now,
            'updated_at'           => $now,
        ]]);

        $return = SurgicalPostopReturn::query()->withoutEntityScope()->where('source_evaluation_id', $evaluation->getKey())->first();

        if ($return !== null) {
            $this->attempt($case, $return, includeFailed: false, deadline: $evaluation->evaluated_at->copy()->addHours(24));
        }

        return $return?->refresh();
    }

    // ── Remarcação pela Agenda ──────────────────────────────────────────────

    /**
     * A Agenda REMARCA criando um agendamento novo e cancelando o antigo. O retorno agendado que
     * apontava para o antigo passa a apontar para o novo (e para o horário novo), na transação da
     * remarcação — quem chama já copiou o `surgical_case_id` para o agendamento novo.
     */
    public function followReschedule(Schedule $from, Schedule $to): void
    {
        if ($from->surgical_case_id === null) {
            return;
        }

        SurgicalPostopReturn::query()
            ->withoutEntityScope()
            ->where('surgical_case_id', $from->surgical_case_id)
            ->where('schedule_id', $from->getKey())
            ->where('status', PostOpReturnStatus::Scheduled->value)
            ->update([
                'schedule_id'   => $to->getKey(),
                'scheduled_for' => $to->date_time,
                'updated_at'    => now(),
            ]);
    }

    // ── Cancelar o que ainda está aberto ────────────────────────────────────

    /**
     * Cancela os retornos futuros ainda abertos do caso (alta dada, caso
     * encerrado): agendamento Agendado/Confirmado a partir de agora vai a
     * Cancelado com o motivo no histórico; pendentes/falhos viram cancelados.
     * Atendido, passado e em andamento ficam como estão. Fora da trava do caso.
     *
     * @return int quantos retornos foram cancelados
     */
    public function cancelOpen(SurgicalCase $case, string $reason): int
    {
        $returns = SurgicalPostopReturn::query()
            ->withoutEntityScope()
            ->where('surgical_case_id', $case->getKey())
            ->whereIn('status', [
                PostOpReturnStatus::Pending->value,
                PostOpReturnStatus::Scheduling->value,
                PostOpReturnStatus::Scheduled->value,
                PostOpReturnStatus::Failed->value,
            ])
            ->get();

        $count = 0;

        foreach ($returns as $return) {
            if ($this->cancelOne($return, $reason)) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * Cancela UM retorno sem pisar em quem está trabalhando nele: a gravação só vale
     * se o retorno ainda está no estado que foi lido (compare-and-set). Se mudou no
     * meio — um worker acabou de agendá-lo —, relê e reavalia (até 3 vezes): o que
     * foi agendado agora tem o agendamento cancelado também. Retorno `scheduling`
     * vira cancelado de uma vez: o worker em voo perde a reivindicação e desfaz o
     * agendamento que ia criar.
     */
    private function cancelOne(SurgicalPostopReturn $return, string $reason): bool
    {
        for ($try = 0; $try < 3; $try++) {
            $status = $return->status;

            if ($status === PostOpReturnStatus::Scheduled) {
                $schedule = $return->schedule_id === null ? null : Schedule::query()->withoutEntityScope()->find($return->schedule_id);

                // Passado, atendido, em andamento (ou sem agendamento): segue como está.
                if ($schedule === null
                    || $schedule->date_time->lt(now())
                    || ! in_array($schedule->situation, ScheduleSeriesService::CANCELLABLE_SITUATIONS, true)
                    || ! $this->schedules->changeSituation($schedule, ScheduleSituation::Cancelled, null, $reason)
                ) {
                    // A Agenda pode ter REMARCADO o retorno entre a leitura e agora (o antigo foi cancelado
                    // e o retorno passou a apontar para o novo): relê e reavalia com o agendamento atual.
                    $current = SurgicalPostopReturn::query()->withoutEntityScope()->find($return->getKey());

                    if ($current !== null && $current->status === PostOpReturnStatus::Scheduled && (string) $current->schedule_id !== (string) $return->schedule_id) {
                        $return = $current;

                        continue;
                    }

                    return false;
                }
            }

            $affected = SurgicalPostopReturn::query()
                ->withoutEntityScope()
                ->whereKey($return->getKey())
                ->where('status', $status->value)
                ->update([
                    'status'        => PostOpReturnStatus::Cancelled->value,
                    'cancel_reason' => mb_substr($reason, 0, 255),
                    'claim_token'   => null,
                    'updated_at'    => now(),
                ]);

            if ($affected === 1) {
                return true;
            }

            $return = SurgicalPostopReturn::query()->withoutEntityScope()->find($return->getKey());

            if ($return === null || ! in_array($return->status, [PostOpReturnStatus::Pending, PostOpReturnStatus::Scheduling, PostOpReturnStatus::Scheduled, PostOpReturnStatus::Failed], true)) {
                return false;
            }
        }

        return false;
    }

    // ── Leitura para a aba ──────────────────────────────────────────────────

    /** @return Collection<int, SurgicalPostopReturn> */
    public function plannedReturns(SurgicalCase $case): Collection
    {
        return SurgicalPostopReturn::query()
            ->withoutEntityScope()
            ->where('surgical_case_id', $case->getKey())
            ->with('schedule')
            ->orderByRaw("case milestone when 'd1' then 1 when 'd7' then 2 when 'd30' then 3 else 4 end")
            ->orderBy('created_at')
            ->get();
    }

    // ── Um retorno ──────────────────────────────────────────────────────────

    /** Reivindica o retorno (UPDATE atômico) e, se ganhou, agenda. Nunca lança. */
    private function attempt(SurgicalCase $case, SurgicalPostopReturn $return, bool $includeFailed, ?Carbon $deadline = null): void
    {
        $lease = (int) config('surgery_postop.claim_lease_minutes', 5);
        $token = (string) Str::uuid();

        $claimed = SurgicalPostopReturn::query()
            ->withoutEntityScope()
            ->whereKey($return->getKey())
            ->where(function ($q) use ($includeFailed, $lease): void {
                $retryable = [PostOpReturnStatus::Pending->value];

                if ($includeFailed) {
                    $retryable[] = PostOpReturnStatus::Failed->value;
                }

                $q->whereIn('status', $retryable)
                    ->orWhere(fn ($stale) => $stale
                        ->where('status', PostOpReturnStatus::Scheduling->value)
                        ->where('claimed_at', '<', now()->subMinutes($lease)));
            })
            ->update([
                'status'       => PostOpReturnStatus::Scheduling->value,
                'claimed_at'   => now(),
                'claim_token'  => $token,
                'failure_code' => null,
                'attempts'     => DB::raw('attempts + 1'),
            ]);

        if ($claimed !== 1) {
            return; // outro processo é o dono (ou já está agendado/cancelado)
        }

        try {
            $code = $this->book($case, $return->refresh(), $deadline, $token);
        } catch (RuntimeException $e) {
            if ($e->getMessage() === self::CLAIM_LOST) {
                return; // perdeu a reivindicação (cancelado ou retomado por outro worker): não escreve nada
            }

            Log::warning('surgery.postop_return_failed', ['case_id' => (string) $case->getKey(), 'milestone' => $return->milestone->value, 'exception' => $e::class]);
            $code = 'error';
        } catch (Throwable $e) {
            // Sem a mensagem da exceção: ela pode trazer SQL com dado do paciente.
            Log::warning('surgery.postop_return_failed', [
                'case_id'   => (string) $case->getKey(),
                'milestone' => $return->milestone->value,
                'exception' => $e::class,
            ]);
            $code = 'error';
        }

        if ($code !== null) {
            // Só quem ainda tem a reivindicação grava a falha; claim perdido não escreve nada.
            SurgicalPostopReturn::query()
                ->withoutEntityScope()
                ->whereKey($return->getKey())
                ->where('status', PostOpReturnStatus::Scheduling->value)
                ->where('claim_token', $token)
                ->update([
                    'status'       => PostOpReturnStatus::Failed->value,
                    'failure_code' => $code,
                    'claim_token'  => null,
                    'updated_at'   => now(),
                ]);
        }
    }

    /**
     * Cria o agendamento e marca o retorno como agendado na MESMA transação.
     *
     * @return string|null null = agendado; senão o código da falha
     */
    private function book(SurgicalCase $case, SurgicalPostopReturn $return, ?Carbon $deadline, string $token): ?string
    {
        $doctor = Doctor::query()->whereKey($case->surgeon_doctor_id)->first();

        if ($doctor === null || ! $doctor->active) {
            return 'surgeon_inactive';
        }

        $patient = $case->patient()->withoutGlobalScopes()->with('person')->first();

        if ($patient === null) {
            return 'patient_missing';
        }

        $isUrgent = $return->milestone === PostOpMilestone::Urgent;
        $days     = $isUrgent ? (int) config('surgery_postop.urgent_search_days', 2) : (int) config('surgery_postop.search_days', 21);
        $attempts = (int) config('surgery_postop.slot_attempts', 25);
        $tries    = 0;

        foreach ($this->candidateSlots($doctor, $return->target_date->copy()->startOfDay(), $days, $deadline) as $slot) {
            if (++$tries > $attempts) {
                break;
            }

            try {
                DB::transaction(function () use ($case, $return, $patient, $doctor, $slot, $isUrgent, $token): void {
                    // Cirurgião em cirurgia não atende: reconferido sob a trava das janelas cirúrgicas (mesma regra da Agenda).
                    $this->schedules->assertDoctorFreeOfSurgery((string) $doctor->getKey(), $slot);

                    $schedule = Schedule::query()->create($this->scheduleAttributes($case, $return, $patient, $doctor, $slot, $isUrgent));

                    $updated = SurgicalPostopReturn::query()->withoutEntityScope()
                        ->whereKey($return->getKey())
                        ->where('status', PostOpReturnStatus::Scheduling->value)
                        ->where('claim_token', $token)
                        ->update([
                            'status'        => PostOpReturnStatus::Scheduled->value,
                            'schedule_id'   => $schedule->getKey(),
                            'scheduled_for' => $slot,
                            'failure_code'  => null,
                            'claimed_at'    => null,
                            'claim_token'   => null,
                            'updated_at'    => now(),
                        ]);

                    // Perdeu a reivindicação (lease vencido e outro worker assumiu, ou o retorno foi
                    // cancelado): desfaz o agendamento.
                    if ($updated !== 1) {
                        throw new RuntimeException(self::CLAIM_LOST);
                    }
                });

                return null;
            } catch (ScheduleSlotUnavailableException) {
                // Entrou uma cirurgia do cirurgião nesse horário: próximo candidato.
            } catch (UniqueConstraintViolationException $e) {
                if (! Schedule::isDoctorSlotConflict($e)) {
                    throw $e;
                }
                // Outro usuário tomou o horário no meio-tempo: próximo candidato.
            }
        }

        return $isUrgent ? 'no_slot_24h' : 'no_slot';
    }

    /** @return array<string, mixed> */
    private function scheduleAttributes(SurgicalCase $case, SurgicalPostopReturn $return, $patient, Doctor $doctor, Carbon $slot, bool $isUrgent): array
    {
        $person  = $patient->person;
        $planned = PostOpMilestone::planned();
        $index   = array_search($return->milestone, $planned, true);

        $series = [];

        if (! $isUrgent && $index !== false) {
            $series = [
                'series_id'      => $this->seriesId($case),
                'session_number' => $index + 1,
                'session_total'  => count($planned),
            ];
        }

        return [
            'entity_id'          => $case->entity_id,
            'doctor_id'          => $doctor->getKey(),
            'patient_id'         => $patient->getKey(),
            'covenant_id'        => $case->covenant_id ?? $patient->covenant_id,
            'visit_id'           => $this->postOpVisitTypeId(),
            'attendance_type'    => ScheduleAttendanceType::PostOpEvaluation->value,
            'full_name'          => (string) ($person?->full_name ?? ''),
            'telephone'          => $person?->telephone,
            'cellphone'          => $person?->cellphone,
            'cellphone_whatsapp' => (bool) ($person?->whatsapp ?? false),
            'notes'              => (string) __('surgery_postop.return_note', ['milestone' => $return->milestone->short(), 'code' => $case->code]),
            'date_time'          => $slot,
            'situation'          => ScheduleSituation::Scheduled->value,
            'is_fit_in'          => false,
            'active'             => true,
            'surgical_case_id'   => $case->getKey(),
            ...$series,
        ];
    }

    /** Mesma série para todos os marcos do caso, mesmo criados por processos diferentes. */
    private function seriesId(SurgicalCase $case): string
    {
        return Uuid::uuid5(Uuid::NAMESPACE_URL, 'easyeye:surgical-case-postop:' . $case->getKey())->toString();
    }

    private function postOpVisitTypeId(): ?string
    {
        return VisitType::query()
            ->withoutGlobalScopes()
            ->whereNull('entity_id')
            ->where('name', ScheduleAttendanceType::PostOpEvaluation->visitTypeName())
            ->where('active', true)
            ->value('id');
    }

    /**
     * Horários livres do cirurgião, em ordem, a partir do dia-alvo: só os que
     * ainda não passaram, alinhados à grade, fora de bloqueio e passando pela
     * MESMA validação do agendamento comum.
     *
     * @return Generator<int, Carbon>
     */
    private function candidateSlots(Doctor $doctor, Carbon $target, int $days, ?Carbon $deadline): Generator
    {
        $now = now();

        for ($offset = 0; $offset < $days; $offset++) {
            $date = $target->copy()->addDays($offset);

            if ($deadline !== null && $date->copy()->startOfDay()->gt($deadline)) {
                return;
            }

            foreach ($this->schedules->getAvailableSlots($doctor, $date) as $slot) {
                if (! $slot['available']) {
                    continue;
                }

                $dateTime = Carbon::parse($slot['datetime']);

                if ($dateTime->lte($now) || ($deadline !== null && $dateTime->gt($deadline))) {
                    continue;
                }

                if ($this->schedules->validateSlot((string) $doctor->getKey(), $dateTime) !== []
                    || $this->schedules->surgeryOverlapError((string) $doctor->getKey(), $dateTime) !== null) {
                    continue;
                }

                yield $dateTime;
            }
        }
    }

    /** @return array<string, int> dias por marco (config) */
    private function offsets(): array
    {
        $configured = (array) config('surgery_postop.offsets', []);

        return [
            'd1'  => max(0, (int) ($configured['d1'] ?? 1)),
            'd7'  => max(0, (int) ($configured['d7'] ?? 7)),
            'd30' => max(0, (int) ($configured['d30'] ?? 30)),
        ];
    }
}
