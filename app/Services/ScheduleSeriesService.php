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
use InvalidArgumentException;

/**
 * Série de sessões — tratamentos seriados da oftalmologia (injeção
 * intravítrea mensal, campimetria seriada, ortóptica 2×/semana): N
 * agendamentos ligados por `series_id`, cada um com `session_number` /
 * `session_total` ("3/10"). Sem pacote financeiro.
 *
 *  - Datas: semanal, mensal (mesmo dia; 31 vira o último dia do mês), a cada
 *    N semanas (2 a 16 — o intervalo do "trate e estenda") ou dias da semana
 *    escolhidos (seg..sáb), terminando em N sessões OU numa data (inclusive).
 *    Nunca mais que MAX_SESSIONS.
 *  - Série ABERTA (`session_total` nulo, rótulo "n"): não se sabe quantas
 *    sessões terá — o intervalo se decide a cada visita. Nasce de um
 *    agendamento solto (`startOpenSeries`) e cresce uma sessão por vez
 *    (`addSession`), com a mesma regra de horário do agendamento comum.
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

    public const FREQUENCIES = ['weekly', 'monthly', 'weekdays', 'every_weeks'];

    /** Faixa do intervalo "a cada N semanas" (o mesmo do curso de injeções intravítreas). */
    public const INTERVAL_WEEKS_MIN = 2;

    public const INTERVAL_WEEKS_MAX = 16;

    /**
     * Frequência por DESLOCAMENTOS: cada sessão cai N dias depois da 1ª data
     * (a âncora) — retornos D1/D7/D30 de uma cirurgia. A âncora é a data da
     * cirurgia (não é uma sessão); as datas devolvidas são âncora + offset.
     */
    public const FREQUENCY_OFFSETS = 'offsets';

    /** Situações que "Cancelar as futuras" alcança (não chegou, não começou). */
    public const CANCELLABLE_SITUATIONS = [ScheduleSituation::Scheduled, ScheduleSituation::Confirmed];

    /** Situações em que a sessão liberou o horário: não ocupa a data nem conta como duplicata ao acrescentar outra. */
    public const FREED_SITUATIONS = [ScheduleSituation::Cancelled, ScheduleSituation::NoShow];

    public function __construct(private readonly ScheduleService $schedules)
    {
    }

    /**
     * Datas da série a partir da 1ª sessão (inclusive). Gera no máximo
     * MAX_SESSIONS + 1 (a sobra indica que o pedido passou do teto).
     *
     * @param list<int> $weekdays      dias da semana (0 = domingo … 6 = sábado), só para `weekdays`
     * @param int|null  $intervalWeeks semanas entre as sessões, só para `every_weeks` (2 a 16; fora disso não gera nada além da 1ª)
     *
     * @return list<Carbon>
     */
    public function generate(Carbon $first, string $frequency, array $weekdays = [], ?int $count = null, ?Carbon $until = null, ?int $intervalWeeks = null): array
    {
        $dates  = [$first->copy()];
        $target = $count !== null ? min($count, self::MAX_SESSIONS + 1) : self::MAX_SESSIONS + 1;
        $last   = $until?->copy()->endOfDay();
        $days   = array_values(array_unique(array_map('intval', $weekdays)));
        $cursor = $first->copy();

        // Guarda contra laço sem fim (ex.: dias da semana vazios).
        for ($guard = 0; count($dates) < $target && $guard < 800; $guard++) {
            $next = match ($frequency) {
                'weekly'      => $first->copy()->addWeeks(count($dates)),
                'every_weeks' => $this->validInterval($intervalWeeks) ? $first->copy()->addWeeks(count($dates) * $intervalWeeks) : null,
                'monthly'     => $first->copy()->addMonthsNoOverflow(count($dates)),
                'weekdays'    => $this->nextWeekday($cursor, $days),
                default       => null,
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
     * Transforma um agendamento solto na 1ª sessão de uma série ABERTA (sem total): a base do tratamento em que o
     * intervalo se decide a cada visita. Quem chama já está numa transação. Série já existente não muda.
     */
    public function startOpenSeries(Schedule $first): void
    {
        if ($first->series_id) {
            return;
        }

        $first->forceFill(['series_id' => (string) Str::uuid(), 'session_number' => 1, 'session_total' => null])->save();
    }

    /**
     * Acrescenta UMA sessão à série aberta de `$anchor`, copiando os dados dele (médico, paciente, convênio, sala,
     * encaixe) e conferindo o horário pela MESMA regra do agendamento comum (médico, intervalo, bloqueio, sala; ou o
     * teto do encaixe sob lock). Quem chama traz a transação; horário recusado volta como exceção e nada é gravado.
     *
     * Ordem de locks: cota do encaixe → trava da SÉRIE → linhas da série → INSERT (a mesma de store(): cota →
     * paciente/dia → linha). A numeração da sessão (máximo + 1) é lida SOB a trava da série: `SELECT … FOR UPDATE` só
     * trava as linhas que já existem no snapshot do comando, então duas sessões acrescentadas ao mesmo tempo, a segunda
     * esperando a primeira, não enxergavam a linha que a primeira acabou de inserir e gravavam o MESMO número.
     *
     * @throws ScheduleSlotUnavailableException horário que não cabe
     * @throws InvalidArgumentException         série fechada (com total) ou sem série, ou data que já passou
     */
    public function addSession(Schedule $anchor, Carbon $dateTime): Schedule
    {
        if (! $anchor->series_id || $anchor->session_total !== null) {
            throw new InvalidArgumentException(__('schedules.series_add_closed'));
        }

        if ($dateTime->lte(now())) {
            throw new InvalidArgumentException(__('schedules.series_add_past'));
        }

        $resourceIds = $anchor->resources()->pluck('clinic_resources.id')->map(fn ($id) => (string) $id)->all();

        // A MESMA regra do agendamento comum para os dois tipos: escala do médico, bloqueio e sala (e, no encaixe, o teto
        // do turno como mensagem antecipada). O encaixe ainda confere o teto SOB a trava da cota, que é o que vale.
        if (($errors = $this->schedules->validateFor((bool) $anchor->is_fit_in, (string) $anchor->doctor_id, $dateTime, null, $resourceIds)) !== []) {
            throw new ScheduleSlotUnavailableException((string) $errors[0], 'date_time');
        }

        if ($anchor->is_fit_in) {
            $this->schedules->assertFitInCapacity((string) $anchor->doctor_id, $dateTime);
        }

        $this->lockSeries((string) $anchor->entity_id, (string) $anchor->series_id);

        $existing = Schedule::query()
            ->where('entity_id', $anchor->entity_id)
            ->where('series_id', $anchor->series_id)
            ->lockForUpdate()
            ->get(['id', 'session_number', 'date_time', 'situation']);

        // Sessão cancelada/faltada no mesmo horário não é duplicata: o horário voltou a ser da agenda (o índice único
        // do médico também a ignora), e remarcar exatamente onde estava é o caso comum.
        if ($existing->contains(fn (Schedule $session) => ! in_array($session->situation, self::FREED_SITUATIONS, true) && $session->date_time->equalTo($dateTime))) {
            throw new ScheduleSlotUnavailableException(__('schedules.series_duplicate_date'), 'date_time');
        }

        try {
            $session = Schedule::query()->create([
                ...$this->copyableAttributes($anchor),
                'date_time'      => $dateTime,
                'session_number' => (int) $existing->max('session_number') + 1,
                'session_total'  => null,
            ]);
        } catch (UniqueConstraintViolationException $e) {
            if (! Schedule::isDoctorSlotConflict($e)) {
                throw $e;
            }

            throw new ScheduleSlotUnavailableException(__('validation.custom.schedule.doctor_datetime_unique'), 'date_time');
        }

        if ($resourceIds !== []) {
            $session->resources()->sync($resourceIds);
        }

        return $session;
    }

    /**
     * Trava a série até o fim da transação corrente. PostgreSQL: advisory lock de transação (só quem acrescenta à
     * MESMA série espera); demais drivers: lock nas linhas da série (as próprias leituras seguintes já as travam).
     */
    private function lockSeries(string $entityId, string $seriesId): void
    {
        $connection = DB::connection();

        if ($connection->getDriverName() === 'pgsql') {
            $connection->select('select pg_advisory_xact_lock(?)', [self::seriesLockKey($entityId, $seriesId)]);

            return;
        }

        Schedule::query()->where('entity_id', $entityId)->where('series_id', $seriesId)->lockForUpdate()->get(['id']);
    }

    /** Chave bigint estável da trava da série (60 bits do sha1, sempre positiva). Pública para os testes disputarem o mesmo lock. */
    public static function seriesLockKey(string $entityId, string $seriesId): int
    {
        return (int) hexdec(substr(sha1('schedule_series|' . $entityId . '|' . $seriesId), 0, 15));
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

    private function validInterval(?int $weeks): bool
    {
        return $weeks !== null && $weeks >= self::INTERVAL_WEEKS_MIN && $weeks <= self::INTERVAL_WEEKS_MAX;
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
