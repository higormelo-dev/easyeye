<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\ScheduleSituation;
use App\Exceptions\ScheduleSlotUnavailableException;
use App\Models\Schedule;
use Carbon\Carbon;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Série de sessões — tratamentos seriados da oftalmologia (injeção
 * intravítrea mensal, campimetria seriada, ortóptica 2×/semana): N
 * agendamentos ligados por `series_id`, cada um com `session_number` /
 * `session_total` ("3/10"). Sem pacote financeiro.
 *
 *  - Datas: semanal, mensal (mesmo dia; 31 vira o último dia do mês) ou dias
 *    da semana escolhidos (seg..sáb), terminando em N sessões OU numa data
 *    (inclusive). Nunca mais que MAX_SESSIONS.
 *  - Prévia: cada data passa pela MESMA regra do horário principal
 *    (ScheduleService::validateFor — médico, intervalo, bloqueio, sala; ou a
 *    regra de encaixe) e volta com o motivo do conflito; a recepção remove as
 *    datas que não servem antes de salvar.
 *  - Gravação: tudo ou nada, numa transação — uma data que deixou de caber
 *    (outro usuário agendou no meio-tempo) desfaz a série inteira.
 *  - Cancelar as futuras: só sessões que ainda vão acontecer e não começaram
 *    (Agendado/Confirmado, horário a partir de agora); passadas, atendidas e
 *    em andamento ficam como estão.
 */
class ScheduleSeriesService
{
    /** Teto de sessões de uma série (inclui a 1ª). */
    public const MAX_SESSIONS = 60;

    public const FREQUENCIES = ['weekly', 'monthly', 'weekdays'];

    /**
     * Frequência por DESLOCAMENTOS: cada sessão cai N dias depois da 1ª data
     * (a âncora) — retornos D1/D7/D30 de uma cirurgia. A âncora é a data da
     * cirurgia (não é uma sessão); as datas devolvidas são âncora + offset.
     */
    public const FREQUENCY_OFFSETS = 'offsets';

    /** Situações que "Cancelar as futuras" alcança (não chegou, não começou). */
    public const CANCELLABLE_SITUATIONS = [ScheduleSituation::Scheduled, ScheduleSituation::Confirmed];

    public function __construct(private readonly ScheduleService $schedules)
    {
    }

    /**
     * Datas da série a partir da 1ª sessão (inclusive). Gera no máximo
     * MAX_SESSIONS + 1 (a sobra indica que o pedido passou do teto).
     *
     * @param list<int> $weekdays dias da semana (0 = domingo … 6 = sábado), só para `weekdays`
     *
     * @return list<Carbon>
     */
    public function generate(Carbon $first, string $frequency, array $weekdays = [], ?int $count = null, ?Carbon $until = null): array
    {
        $dates  = [$first->copy()];
        $target = $count !== null ? min($count, self::MAX_SESSIONS + 1) : self::MAX_SESSIONS + 1;
        $last   = $until?->copy()->endOfDay();
        $days   = array_values(array_unique(array_map('intval', $weekdays)));
        $cursor = $first->copy();

        // Guarda contra laço sem fim (ex.: dias da semana vazios).
        for ($guard = 0; count($dates) < $target && $guard < 800; $guard++) {
            $next = match ($frequency) {
                'weekly'   => $first->copy()->addWeeks(count($dates)),
                'monthly'  => $first->copy()->addMonthsNoOverflow(count($dates)),
                'weekdays' => $this->nextWeekday($cursor, $days),
                default    => null,
            };

            if ($next === null || ($last !== null && $next->gt($last))) {
                break;
            }

            $dates[] = $next;
            $cursor  = $next;
        }

        return $dates;
    }

    /**
     * Datas da série por deslocamento: âncora + N dias, na ordem crescente,
     * sem repetir e sem passar de MAX_SESSIONS. O horário é o da âncora; quem
     * precisa de um horário livre por dia (retornos de cirurgia) ajusta cada
     * data depois — ver App\Services\Surgery\PostOp\PostOpReturnService.
     *
     * @param list<int|string> $offsets dias a partir da âncora (>= 0)
     *
     * @return list<Carbon>
     */
    public function datesFromOffsets(Carbon $anchor, array $offsets): array
    {
        $days = array_values(array_unique(array_filter(
            array_map('intval', $offsets),
            fn (int $day) => $day >= 0,
        )));
        sort($days);

        return array_map(
            fn (int $day) => $anchor->copy()->addDays($day),
            array_slice($days, 0, self::MAX_SESSIONS),
        );
    }

    /**
     * Prévia: cada data com os conflitos (regra do horário principal) — a
     * primeira é o próprio horário escolhido no formulário.
     *
     * @param list<Carbon> $dates
     * @param string[]     $resourceIds
     *
     * @return list<array{number: int, date_time: string, errors: list<string>}>
     */
    public function preview(string $doctorId, array $dates, array $resourceIds, bool $isFitIn): array
    {
        $seen = [];

        return array_values(array_map(function (Carbon $date, int $index) use ($doctorId, $resourceIds, $isFitIn, &$seen): array {
            $key    = $date->format('Y-m-d H:i');
            $errors = $this->schedules->validateFor($isFitIn, $doctorId, $date, null, $resourceIds);

            if (isset($seen[$key])) {
                $errors[] = __('schedules.series_duplicate_date');
            }
            $seen[$key] = true;

            return [
                'number'    => $index + 1,
                'date_time' => $date->format('Y-m-d\TH:i'),
                'errors'    => array_values(array_unique($errors)),
            ];
        }, $dates, array_keys($dates)));
    }

    /**
     * Conflitos das sessões 2..N (a 1ª já passou pela regra do formulário).
     *
     * @param list<Carbon> $extraDates
     * @param string[]     $resourceIds
     *
     * @return list<string> "dd/mm/aaaa HH:mm: motivo"
     */
    public function conflicts(string $doctorId, array $extraDates, array $resourceIds, bool $isFitIn): array
    {
        $messages = [];

        foreach ($extraDates as $date) {
            $errors = $this->schedules->validateFor($isFitIn, $doctorId, $date, null, $resourceIds);

            if ($errors !== []) {
                $messages[] = __('schedules.series_conflict', ['date' => $date->format('d/m/Y H:i'), 'reason' => $errors[0]]);
            }
        }

        return $messages;
    }

    /**
     * Grava as sessões 2..N da série cujo 1º agendamento (`$first`) acabou
     * de ser criado — DENTRO da transação de quem chama (tudo ou nada). Cada
     * sessão copia os dados do 1º agendamento, a sala/equipamento e o tipo
     * (encaixe ou normal); encaixe confere o teto do turno sob lock.
     *
     * @param list<Carbon> $extraDates
     * @param string[]     $resourceIds
     *
     * @throws ScheduleSlotUnavailableException data que deixou de caber
     */
    public function createSessions(Schedule $first, array $extraDates, array $resourceIds): void
    {
        $total = count($extraDates) + 1;

        // O 1º agendamento já nasce como sessão 1/N da série (quem chama).
        if (! $first->series_id || (int) $first->session_total !== $total) {
            $first->forceFill(['series_id' => $first->series_id ?: (string) Str::uuid(), 'session_number' => 1, 'session_total' => $total])->save();
        }

        foreach (array_values($extraDates) as $index => $date) {
            // Médico em cirurgia na data da sessão não atende (módulo cirúrgico).
            $this->schedules->assertDoctorFreeOfSurgery((string) $first->doctor_id, $date);

            if ($first->is_fit_in) {
                $this->schedules->assertFitInCapacity((string) $first->doctor_id, $date);
            }

            try {
                $session = Schedule::query()->create([
                    ...$this->copyableAttributes($first),
                    'date_time'      => $date,
                    'session_number' => $index + 2,
                    'session_total'  => $total,
                ]);
            } catch (UniqueConstraintViolationException $e) {
                if (! Schedule::isDoctorSlotConflict($e)) {
                    throw $e;
                }

                throw new ScheduleSlotUnavailableException(__('schedules.series_conflict', [
                    'date'   => $date->format('d/m/Y H:i'),
                    'reason' => __('validation.custom.schedule.doctor_datetime_unique'),
                ]), 'series_dates');
            }

            if ($resourceIds !== []) {
                $session->resources()->sync($resourceIds);
            }
        }
    }

    /**
     * "Cancelar as futuras": sessões da série a partir de agora que ainda não
     * começaram, com o motivo no histórico (ScheduleService::changeSituation).
     *
     * @param string|null $doctorId perfil médico: só as sessões dele
     *
     * @return list<string> ids cancelados
     */
    public function cancelFuture(Schedule $schedule, string $reason, ?string $entityUserId, ?string $doctorId = null): array
    {
        if (! $schedule->series_id) {
            return [];
        }

        return DB::transaction(function () use ($schedule, $reason, $entityUserId, $doctorId): array {
            $sessions = Schedule::query()
                ->where('entity_id', $schedule->entity_id)
                ->where('series_id', $schedule->series_id)
                ->where('date_time', '>=', now())
                ->whereIn('situation', array_map(fn (ScheduleSituation $s) => $s->value, self::CANCELLABLE_SITUATIONS))
                ->when($doctorId, fn ($q) => $q->where('doctor_id', $doctorId))
                ->orderBy('date_time')
                ->get();

            $cancelled = [];

            foreach ($sessions as $session) {
                if ($this->schedules->changeSituation($session, ScheduleSituation::Cancelled, $entityUserId, $reason)) {
                    $cancelled[] = (string) $session->id;
                }
            }

            return $cancelled;
        });
    }

    /**
     * Dados que toda sessão herda do 1º agendamento (situação sempre Agendado).
     *
     * @return array<string, mixed>
     */
    private function copyableAttributes(Schedule $first): array
    {
        return [
            'entity_id'           => $first->entity_id,
            'doctor_id'           => $first->doctor_id,
            'patient_id'          => $first->patient_id,
            'covenant_id'         => $first->covenant_id,
            'visit_id'            => $first->visit_id,
            'attendance_type'     => $first->attendance_type,
            'specialty_area'      => $first->specialty_area,
            'full_name'           => $first->full_name,
            'telephone'           => $first->telephone,
            'cellphone'           => $first->cellphone,
            'cellphone_whatsapp'  => (bool) $first->cellphone_whatsapp,
            'notes'               => $first->notes,
            'situation'           => ScheduleSituation::Scheduled->value,
            'is_fit_in'           => (bool) $first->is_fit_in,
            'series_id'           => $first->series_id,
            'recurrence_group_id' => $first->recurrence_group_id,
            'surgical_case_id'    => $first->surgical_case_id,
            'recurrence_type'     => $first->recurrence_type,
            'recurrence_until'    => $first->recurrence_until,
            'active'              => true,
        ];
    }

    /** Próxima data (depois de `$after`) num dos dias da semana pedidos, mesmo horário. */
    private function nextWeekday(Carbon $after, array $days): ?Carbon
    {
        if ($days === []) {
            return null;
        }

        $next = $after->copy();

        for ($i = 0; $i < 7; $i++) {
            $next->addDay();

            if (in_array($next->dayOfWeek, $days, true)) {
                return $next->copy();
            }
        }

        return null;
    }
}
