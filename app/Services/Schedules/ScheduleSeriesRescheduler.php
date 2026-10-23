<?php

declare(strict_types=1);

namespace App\Services\Schedules;

use App\Enums\ScheduleSituation;
use App\Exceptions\{ScheduleSeriesChangedException, ScheduleSlotUnavailableException};
use App\Models\{Covenant, Schedule, ScheduleSituationLog};
use App\Rules\ActiveCovenant;
use App\Services\{ScheduleSeriesService, ScheduleService};
use App\Services\Surgery\PostOp\PostOpReturnService;
use App\Support\ScheduleShift;
use Carbon\Carbon;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\{Cache, DB};
use InvalidArgumentException;

/**
 * "Reagendar a série": a sessão escolhida (a âncora) e as seguintes da MESMA série deslocam pelo mesmo intervalo
 * (novo início da âncora − antigo, em segundos). Entra no lote só o que ainda vai acontecer e não começou (Agendado ou
 * Confirmado, a partir de agora e a partir da âncora); anteriores, passadas, atendidas, em andamento, canceladas e
 * faltas ficam como estão. Qualquer conflito recusa a série inteira.
 *
 *  - Prévia: lê sem travar e valida cada sessão pela MESMA regra do agendamento comum, EXCLUINDO todo o lote da
 *    checagem de conflito (com +1 semana a data nova de uma sessão é a antiga da seguinte — não é conflito).
 *  - Escrita, numa transação: pré-leitura → cirurgia do médico → cotas de encaixe de destino → trava da SÉRIE → linhas
 *    da série (FOR UPDATE) → relê e compara (a série cresceu ou uma sessão do lote mudou = 409; só encolheu = segue) →
 *    revalida tudo → cancela TODAS as antigas → cria as novas (o cancelamento antes do INSERT libera o índice único do
 *    médico) → nota e histórico. A mesma família de travas de store()/addSession(): cota → série → linhas.
 *  - A numeração ("3/10") e o total são os da sessão antiga. Não avisa o paciente (igual ao reagendar unitário).
 */
final class ScheduleSeriesRescheduler
{
    public function __construct(private readonly ScheduleService $schedules)
    {
    }

    /**
     * @return array{total: int, delta_seconds: int, sessions: list<array<string, mixed>>}
     *
     * @throws ScheduleSlotUnavailableException âncora fora do lote, horário igual ao atual ou no passado
     * @throws InvalidArgumentException         o agendamento não é de uma série
     */
    public function preview(Schedule $anchor, Carbon $newDateTime, ?string $doctorOverride, ?bool $fitInOverride, ?string $doctorScope): array
    {
        $this->assertInSeries($anchor);

        $set   = $this->affectedSet($anchor, $doctorScope);
        $delta = $this->deltaFor($this->anchorOf($set, $anchor), $newDateTime);
        $plan  = $this->plan($set, $delta, $doctorOverride, $fitInOverride);

        return [
            'total'         => count($plan),
            'delta_seconds' => $delta,
            'sessions'      => array_map(fn (array $entry): array => [
                'id'             => (string) $entry['session']->id,
                'session_number' => $entry['session']->session_number,
                'session_total'  => $entry['session']->session_total,
                'doctor_id'      => $entry['doctor_id'],
                'is_fit_in'      => $entry['is_fit_in'],
                'old_date_time'  => $entry['session']->date_time->format('Y-m-d\TH:i'),
                'new_date_time'  => $entry['new_date']->format('Y-m-d\TH:i'),
                'old_label'      => $entry['session']->date_time->format('d/m/Y H:i'),
                'new_label'      => $entry['new_date']->format('d/m/Y H:i'),
                'errors'         => array_values(array_unique($entry['errors'])),
            ], $plan),
        ];
    }

    /**
     * @return array{anchor_id: string, updated: int, new_schedules: list<array{old_id: string, schedule: Schedule}>}
     *
     * @throws ScheduleSlotUnavailableException conflito em qualquer sessão, convênio inativo, âncora fora do lote
     * @throws ScheduleSeriesChangedException   a série mudou entre a prévia e a trava
     * @throws InvalidArgumentException         o agendamento não é de uma série
     */
    public function reschedule(Schedule $anchor, Carbon $newDateTime, ?string $doctorOverride, ?bool $fitInOverride, ?string $entityUserId, ?string $doctorScope): array
    {
        $this->assertInSeries($anchor);

        $result = DB::transaction(function () use ($anchor, $newDateTime, $doctorOverride, $fitInOverride, $entityUserId, $doctorScope): array {
            // 1 ── Pré-leitura sem trava: de onde saem os destinos cujas travas precisam vir ANTES da série.
            $before = $this->affectedSet($anchor, $doctorScope);
            $delta  = $this->deltaFor($this->anchorOf($before, $anchor), $newDateTime);
            $dests  = $this->destinations($before, $delta, $doctorOverride, $fitInOverride);

            // 2 ── Cirurgia do médico (travas das janelas + conferência) e 3 ── cota de encaixe, em ordem estável.
            $this->lockSurgeryWindows($dests);
            $this->schedules->lockFitInQuotas(array_map(
                fn (array $dest): array => [$dest['doctor_id'], $dest['date']],
                array_filter($dests, fn (array $dest): bool => $dest['is_fit_in']),
            ));

            // 4 ── Trava da série e 5 ── linhas da série (ordenadas), relidas sob a trava.
            ScheduleSeriesService::lockSeries((string) $anchor->entity_id, (string) $anchor->series_id);
            $set = $this->affectedSet($anchor, $doctorScope, lock: true);
            $this->assertUnchanged($before, $set);

            // Revalida tudo ANTES de escrever: escala, bloqueio, sala, dupla marcação, cirurgia, encaixe, convênio.
            $plan = $this->plan($set, $delta, $doctorOverride, $fitInOverride);
            $this->assertNoConflict($plan);

            // 6 ── Cancela TODAS as antigas primeiro: libera o horário para as novas (índice único do médico).
            $from = [];

            foreach ($plan as $entry) {
                $from[(string) $entry['session']->id] = $entry['session']->situation;
                $entry['session']->update(['situation' => ScheduleSituation::Cancelled->value, 'cancellation_reason' => null]);
            }

            // 7 ── Cria as novas, uma a uma (o teto do encaixe conta as novas anteriores sob a cota já travada).
            $pairs = array_map(fn (array $entry): array => ['old' => $entry['session'], 'new' => $this->createReplacement($entry)], $plan);

            // 8 ── Nota e histórico de situação das antigas (mesma forma do reagendar unitário).
            foreach ($pairs as $pair) {
                $note = __('schedules.reschedule_note', ['code' => $pair['new']->code]);
                $pair['old']->update(['cancellation_reason' => $note]);

                ScheduleSituationLog::create([
                    'schedule_id'    => $pair['old']->id,
                    'entity_user_id' => $entityUserId,
                    'from_situation' => $from[(string) $pair['old']->id]->value,
                    'to_situation'   => ScheduleSituation::Cancelled->value,
                    'notes'          => $note,
                    'created_at'     => now(),
                ]);
            }

            $replacements = array_map(fn (array $pair): array => ['old_id' => (string) $pair['old']->id, 'schedule' => $pair['new']], $pairs);
            $anchorPair   = collect($replacements)->firstWhere('old_id', (string) $anchor->id);

            return ['anchor_id' => (string) $anchorPair['schedule']->id, 'updated' => count($replacements), 'new_schedules' => $replacements];
        });

        Cache::forget("waiting_room:{$anchor->entity_id}");

        return $result;
    }

    /**
     * "Reagendar" de UMA sessão da série: toma a trava da série (serializa com o lote, com "Cancelar as futuras" e com o
     * acréscimo de sessão) e confere que a sessão ainda é a que quem chama leu. Sem isto, um reagendar que esperou o lote
     * criaria uma segunda sessão no lugar da que o lote acabou de cancelar. Agendamento solto não trava nada.
     *
     * @throws ScheduleSeriesChangedException a sessão foi movida, cancelada ou mudou de situação enquanto esperava
     */
    public function lockForSingleReschedule(Schedule $session): void
    {
        if (! $session->series_id) {
            return;
        }

        ScheduleSeriesService::lockSeries((string) $session->entity_id, (string) $session->series_id);

        $current = Schedule::query()->whereKey($session->getKey())->first(['id', 'date_time', 'situation']);

        if ($current === null || $current->situation !== $session->situation || ! $current->date_time->equalTo($session->date_time)) {
            throw new ScheduleSeriesChangedException();
        }
    }

    private function assertInSeries(Schedule $anchor): void
    {
        if (! $anchor->series_id) {
            throw new InvalidArgumentException(__('schedules.series_reschedule_no_sessions'));
        }
    }

    /**
     * O lote: Agendado/Confirmado, a partir de agora e da âncora (inclusive); perfil médico, só as dele. As consultas
     * são LIMITADAS ao teto da série (ScheduleSeriesService::MAX_SESSIONS + 1): a série aberta cresce por sessão e não
     * tem total, e nem a prévia (consultas por sessão) nem a escrita (travas e INSERTs) podem escalar com ela. Passar do
     * teto recusa o pedido inteiro — nunca move só parte. Com `$lock`, trava a âncora e as linhas do lote (FOR UPDATE).
     *
     * @return Collection<int, Schedule>
     *
     * @throws ScheduleSlotUnavailableException âncora fora do lote, ou mais sessões que o teto
     */
    private function affectedSet(Schedule $anchor, ?string $doctorScope, bool $lock = false): Collection
    {
        $series   = fn () => Schedule::query()->where('entity_id', $anchor->entity_id)->where('series_id', $anchor->series_id);
        $eligible = fn () => $series()
            ->whereIn('situation', array_map(fn (ScheduleSituation $s): int => $s->value, ScheduleSeriesService::CANCELLABLE_SITUATIONS))
            ->where('date_time', '>=', now())
            ->when($doctorScope, fn ($query) => $query->where('doctor_id', $doctorScope));

        $anchorRow = $series()->whereKey($anchor->id)->when($lock, fn ($query) => $query->lockForUpdate())->first();

        if ($anchorRow === null
            || ! in_array($anchorRow->situation, ScheduleSeriesService::CANCELLABLE_SITUATIONS, true)
            || $anchorRow->date_time->lt(now())
            || ($doctorScope !== null && (string) $anchorRow->doctor_id !== $doctorScope)) {
            throw new ScheduleSlotUnavailableException($eligible()->exists()
                ? __('schedules.series_reschedule_anchor_not_cancellable')
                : __('schedules.series_reschedule_no_sessions'));
        }

        $set = $eligible()
            ->where('date_time', '>=', $anchorRow->date_time)
            ->orderBy('date_time')
            ->orderBy('id')
            ->limit(ScheduleSeriesService::MAX_SESSIONS + 1)
            ->when($lock, fn ($query) => $query->lockForUpdate())
            ->get();

        if ($set->count() > ScheduleSeriesService::MAX_SESSIONS) {
            throw new ScheduleSlotUnavailableException(__('schedules.series_reschedule_too_many', ['max' => ScheduleSeriesService::MAX_SESSIONS]));
        }

        return $set->values();
    }

    /** @param Collection<int, Schedule> $set */
    private function anchorOf(Collection $set, Schedule $anchor): Schedule
    {
        return $set->first(fn (Schedule $session): bool => (string) $session->id === (string) $anchor->id);
    }

    /** Segundos entre o início atual da âncora e o novo (negativo = adiantar). Nada de data no passado nem de "mesmo horário". */
    private function deltaFor(Schedule $anchorRow, Carbon $newDateTime): int
    {
        if ($newDateTime->lte(now())) {
            throw new ScheduleSlotUnavailableException(__('schedules.series_reschedule_past'));
        }

        $delta = $newDateTime->getTimestamp() - $anchorRow->date_time->getTimestamp();

        if ($delta === 0) {
            throw new ScheduleSlotUnavailableException(__('schedules.series_reschedule_same_time'));
        }

        return $delta;
    }

    /**
     * Médico, encaixe e data de destino de cada sessão do lote (D3: sem sobrescrita, cada sessão mantém o seu).
     *
     * @param Collection<int, Schedule> $set
     *
     * @return list<array{doctor_id: string, is_fit_in: bool, date: Carbon}>
     */
    private function destinations(Collection $set, int $delta, ?string $doctorOverride, ?bool $fitInOverride): array
    {
        return $set->map(fn (Schedule $session): array => [
            'doctor_id' => $doctorOverride ?: (string) $session->doctor_id,
            'is_fit_in' => $fitInOverride ?? (bool) $session->is_fit_in,
            'date'      => $session->date_time->copy()->addSeconds($delta),
        ])->all();
    }

    /**
     * Valida cada sessão do lote (sem gravar). Os ids do lote inteiro ficam fora da checagem de conflito e de cota; o teto
     * do encaixe soma as sessões anteriores do lote que caem no mesmo turno.
     *
     * @param Collection<int, Schedule> $set
     *
     * @return list<array{session: Schedule, doctor_id: string, is_fit_in: bool, new_date: Carbon, resource_ids: list<string>, errors: list<string>, covenant_error: ?string}>
     */
    private function plan(Collection $set, int $delta, ?string $doctorOverride, ?bool $fitInOverride): array
    {
        $affectedIds = $set->map(fn (Schedule $session): string => (string) $session->id)->all();
        $dests       = $this->destinations($set, $delta, $doctorOverride, $fitInOverride);
        $pending     = [];
        $seen        = [];
        $plan        = [];

        foreach ($set->values() as $index => $session) {
            ['doctor_id' => $doctorId, 'is_fit_in' => $isFitIn, 'date' => $newDate] = $dests[$index];

            $resourceIds = $session->resources()->pluck('clinic_resources.id')->map(fn ($id): string => (string) $id)->all();
            $quotaKey    = $doctorId . '|' . $newDate->toDateString() . '|' . ScheduleShift::ofTime($newDate);
            $errors      = $this->schedules->validateFor($isFitIn, $doctorId, $newDate, $affectedIds, $resourceIds, $pending[$quotaKey] ?? 0);

            // Remarcar cria um agendamento NOVO com o convênio do antigo: convênio inativado não passa adiante.
            $covenantError = null;

            if ($session->covenant_id && ! ActiveCovenant::isActive((string) $session->covenant_id)) {
                $covenantError = __('validation.custom.covenant.reschedule_inactive', [
                    'name' => (string) Covenant::withoutGlobalScopes()->whereKey($session->covenant_id)->value('name'),
                ]);
                $errors[] = $covenantError;
            }

            $key = $newDate->format('Y-m-d H:i');

            if (isset($seen[$key])) {
                $errors[] = __('schedules.series_duplicate_date');
            }
            $seen[$key] = true;

            // Conta o encaixe DEPOIS de validá-lo: o primeiro do turno não pesa contra si mesmo.
            if ($isFitIn) {
                $pending[$quotaKey] = ($pending[$quotaKey] ?? 0) + 1;
            }

            $plan[] = [
                'session'        => $session,
                'doctor_id'      => $doctorId,
                'is_fit_in'      => $isFitIn,
                'new_date'       => $newDate,
                'resource_ids'   => $resourceIds,
                'errors'         => $errors,
                'covenant_error' => $covenantError,
            ];
        }

        return $plan;
    }

    /**
     * Qualquer conflito recusa a série inteira ANTES de gravar: convênio inativo no campo do convênio, o resto em
     * `series_dates` com a data nova de cada sessão (como na criação da série).
     *
     * @param list<array<string, mixed>> $plan
     */
    private function assertNoConflict(array $plan): void
    {
        foreach ($plan as $entry) {
            if ($entry['covenant_error'] !== null) {
                throw new ScheduleSlotUnavailableException($entry['covenant_error'], 'covenant_id');
            }
        }

        foreach ($plan as $entry) {
            if ($entry['errors'] !== []) {
                throw $this->conflict($entry['new_date'], (string) $entry['errors'][0]);
            }
        }
    }

    /**
     * A série relida sob a trava só vale se for a mesma que a prévia viu: sessão nova (ou religada), âncora movida ou
     * sessão do lote com outra data, médico ou encaixe = 409. Sessão que SAIU do lote (cancelada, atendida) é tolerada.
     *
     * @param Collection<int, Schedule> $before
     * @param Collection<int, Schedule> $after
     */
    private function assertUnchanged(Collection $before, Collection $after): void
    {
        $known = $before->keyBy(fn (Schedule $session): string => (string) $session->id);

        foreach ($after as $session) {
            $earlier = $known->get((string) $session->id);

            if ($earlier === null
                || ! $earlier->date_time->equalTo($session->date_time)
                || (string) $earlier->doctor_id !== (string) $session->doctor_id
                || (bool) $earlier->is_fit_in !== (bool) $session->is_fit_in) {
                throw new ScheduleSeriesChangedException();
            }
        }
    }

    /**
     * Trava as janelas cirúrgicas de cada médico de destino e reconfere o horário, médico a médico e data a data.
     *
     * @param list<array{doctor_id: string, is_fit_in: bool, date: Carbon}> $dests
     */
    private function lockSurgeryWindows(array $dests): void
    {
        $pairs = [];

        foreach ($dests as $dest) {
            $pairs[$dest['doctor_id'] . '|' . $dest['date']->format('Y-m-d H:i:s')] = $dest;
        }
        ksort($pairs, SORT_STRING);

        foreach ($pairs as $dest) {
            try {
                $this->schedules->assertDoctorFreeOfSurgery($dest['doctor_id'], $dest['date']);
            } catch (ScheduleSlotUnavailableException $e) {
                throw $this->conflict($dest['date'], $e->getMessage());
            }
        }
    }

    /** Agendamento novo no horário de destino, com a lista de campos do reagendar unitário. */
    private function createReplacement(array $entry): Schedule
    {
        /** @var Schedule $old */
        $old     = $entry['session'];
        $newDate = $entry['new_date'];

        try {
            if ($entry['is_fit_in']) {
                $this->schedules->assertFitInCapacity($entry['doctor_id'], $newDate);
            }

            $new = Schedule::query()->create($this->schedules->rescheduleAttributes($old, $entry['doctor_id'], $newDate, $entry['is_fit_in']));
        } catch (ScheduleSlotUnavailableException $e) {
            throw $this->conflict($newDate, $e->getMessage());
        } catch (UniqueConstraintViolationException $e) {
            if (! Schedule::isDoctorSlotConflict($e)) {
                throw $e;
            }

            throw $this->conflict($newDate, __('validation.custom.schedule.doctor_datetime_unique'));
        }

        if ($entry['resource_ids'] !== []) {
            $new->resources()->sync($entry['resource_ids']);
        }

        // O retorno pós-operatório que apontava para a sessão antiga passa a apontar para a nova, na mesma transação.
        if ($old->surgical_case_id !== null) {
            app(PostOpReturnService::class)->followReschedule($old, $new);
        }

        return $new;
    }

    private function conflict(Carbon $date, string $reason): ScheduleSlotUnavailableException
    {
        return new ScheduleSlotUnavailableException(__('schedules.series_conflict', ['date' => $date->format('d/m/Y H:i'), 'reason' => $reason]), 'series_dates');
    }
}
