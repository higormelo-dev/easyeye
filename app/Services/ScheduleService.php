<?php

namespace App\Services;

use App\Enums\{FinancialEntryStatus, ScheduleSituation};
use App\Exceptions\{AttendanceRequiresCashEntryException, ScheduleChangedConcurrentlyException, ScheduleSlotUnavailableException};
use App\Models\{ClinicResource, Doctor, DoctorWorkSchedule, Entity, PatientExam, ResourceBlock, ResourceWorkSchedule, Schedule, ScheduleBlock, ScheduleSituationLog};
use App\Services\Surgery\SurgeryScheduleService;
use App\Support\{ExamScheduleLink, ScheduleShift};
use Carbon\Carbon;
use Closure;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\{Cache, DB};

class ScheduleService
{
    /** Tentativas de travar paciente/dia + linha com a identidade do agendamento estável. */
    private const ATTENDANCE_LOCK_ATTEMPTS = 3;

    public function __construct(
        private readonly ExternalExamImportService $externalExamImportService,
    ) {
    }

    /**
     * Central slot validation.
     *
     * Runs all four checks in order:
     *   1. Double-booking  (exact same doctor + datetime already taken)
     *   2. Work schedule   (doctor works on that day / at that time)
     *   3. Interval align  (datetime falls on a valid slot boundary)
     *   4. Block           (absence, holiday, meeting, etc.)
     *
     * Returns an array of human-readable error messages.
     * An empty array means the slot is fully valid.
     *
     * @param array<int, string>|string|null $excludeScheduleId UUID(s) of the record(s) being updated (skips self-conflict); a
     *                                                          lista serve à remarcação da série, em que a data nova de uma
     *                                                          sessão pode ser a antiga de outra do mesmo lote
     *
     * @return string[]
     */
    /**
     * @param string[] $resourceIds
     */
    public function validateSlot(string $doctorId, Carbon $dateTime, array|string|null $excludeScheduleId = null, array $resourceIds = []): array
    {
        $errors = [];

        // 1 ── Double-booking ────────────────────────────────────────────────
        if ($this->isDoubleBooked($doctorId, $dateTime, $excludeScheduleId)) {
            $errors[] = __('validation.custom.schedule.doctor_datetime_unique');
        }

        // 2 ── Work schedule ─────────────────────────────────────────────────
        if (! $this->isWithinWorkSchedule($doctorId, $dateTime)) {
            $dayName  = $this->dayName($dateTime->dayOfWeek);
            $errors[] = __('actions.work_schedule_outside', ['day' => $dayName, 'time' => $dateTime->format('H:i')]);

            // No point checking alignment when the day/time is already outside schedule
            return $errors;
        }

        // 3 ── Interval alignment ────────────────────────────────────────────
        if (! $this->isIntervalAligned($doctorId, $dateTime)) {
            $doctor   = Doctor::find($doctorId);
            $interval = $doctor ? $doctor->effectiveInterval() : 15;
            $errors[] = __('actions.work_schedule_interval_error', ['interval' => $interval]);
        }

        // 4 ── Schedule block ────────────────────────────────────────────────
        if ($this->isBlocked($doctorId, $dateTime)) {
            $errors[] = __('actions.work_schedule_blocked');
        }

        // 4b ── Cirurgia agendada do médico (módulo cirúrgico) ─────────────────
        if ($surgery = $this->surgeryOverlapError($doctorId, $dateTime)) {
            $errors[] = $surgery;
        }

        // 5 ── Resource conflicts ─────────────────────────────────────────────
        return array_merge($errors, $this->resourceErrors($resourceIds, $dateTime, $excludeScheduleId));
    }

    /**
     * Validação de um ENCAIXE (paciente a mais, por cima da grade): pode
     * ocupar horário já tomado e fora do alinhamento do intervalo; continua
     * respeitando a escala do médico (dia/horário de expediente), os
     * bloqueios do médico, o teto de encaixes do turno e TODAS as regras da
     * sala/equipamento (escala, bloqueio, conflito físico). A leitura do teto
     * aqui é a mensagem antecipada do formulário; a conta que vale é a feita
     * sob lock na gravação (assertFitInCapacity).
     *
     * @param array<int, string>|string|null $excludeScheduleId
     * @param string[]                       $resourceIds
     * @param int                            $pendingFitIns     encaixes do mesmo turno que o chamador ainda vai gravar (prévia da série)
     *
     * @return string[]
     */
    public function validateFitIn(string $doctorId, Carbon $dateTime, array|string|null $excludeScheduleId = null, array $resourceIds = [], int $pendingFitIns = 0): array
    {
        if (! $this->isWithinWorkSchedule($doctorId, $dateTime)) {
            return [__('actions.work_schedule_outside', ['day' => $this->dayName($dateTime->dayOfWeek), 'time' => $dateTime->format('H:i')])];
        }

        $errors = [];

        if ($this->isBlocked($doctorId, $dateTime)) {
            $errors[] = __('actions.work_schedule_blocked');
        }

        if ($surgery = $this->surgeryOverlapError($doctorId, $dateTime)) {
            $errors[] = $surgery;
        }

        if ($limitError = $this->fitInLimitError($doctorId, $dateTime, $excludeScheduleId, $pendingFitIns)) {
            $errors[] = $limitError;
        }

        return array_merge($errors, $this->resourceErrors($resourceIds, $dateTime, $excludeScheduleId));
    }

    /**
     * Regra do horário conforme o tipo: encaixe (validateFitIn) ou normal
     * (validateSlot). Ponto único para agendar, editar, remarcar, lote e série.
     *
     * @param array<int, string>|string|null $excludeScheduleId
     * @param string[]                       $resourceIds
     *
     * @return string[]
     */
    public function validateFor(bool $isFitIn, string $doctorId, Carbon $dateTime, array|string|null $excludeScheduleId = null, array $resourceIds = [], int $pendingFitIns = 0): array
    {
        return $isFitIn
            ? $this->validateFitIn($doctorId, $dateTime, $excludeScheduleId, $resourceIds, $pendingFitIns)
            : $this->validateSlot($doctorId, $dateTime, $excludeScheduleId, $resourceIds);
    }

    /**
     * O horário da consulta (início + intervalo do médico) cai dentro de uma
     * cirurgia AGENDADA em que o médico opera (cirurgião ou equipe)? Devolve a
     * mensagem (null = livre). Vale para consulta e encaixe: quem está em
     * cirurgia não atende.
     */
    public function surgeryOverlapError(string $doctorId, Carbon $dateTime): ?string
    {
        $doctor = Doctor::query()->find($doctorId);

        if (! $doctor) {
            return null; // doctor_id inválido já é recusado pela regra do campo
        }

        $end   = $dateTime->copy()->addMinutes(max(1, $doctor->effectiveInterval()));
        $other = app(SurgeryScheduleService::class)->windowsOverlapping([$doctorId], $dateTime, $end)->first();

        if ($other === null) {
            return null;
        }

        return __('surgery_schedule.agenda_conflict', [
            'start' => $other->scheduled_start->format(__('surgery_operation.date_format') . ' H:i'),
            'end'   => $other->scheduled_end->format('H:i'),
        ]);
    }

    /**
     * Versão sob lock de surgeryOverlapError(): dentro da transação de gravação
     * da consulta, trava as janelas cirúrgicas do médico (mesma chave que
     * agendar a cirurgia usa) e reconfere — uma consulta e uma cirurgia no mesmo
     * horário não passam as duas.
     *
     * @throws ScheduleSlotUnavailableException
     */
    public function assertDoctorFreeOfSurgery(string $doctorId, Carbon $dateTime): void
    {
        app(SurgeryScheduleService::class)->lockDoctorWindows($doctorId);

        if ($message = $this->surgeryOverlapError($doctorId, $dateTime)) {
            throw new ScheduleSlotUnavailableException($message);
        }
    }

    /**
     * Teto de encaixes do turno — conferido DENTRO da transação de gravação,
     * depois de travar a cota (médico + dia + turno): dois encaixes
     * simultâneos não furam o teto (o segundo espera o COMMIT do primeiro e
     * já o enxerga na contagem).
     *
     * @throws ScheduleSlotUnavailableException teto atingido ou turno sem encaixe
     */
    public function assertFitInCapacity(string $doctorId, Carbon $dateTime, array|string|null $excludeScheduleId = null): void
    {
        $this->lockFitInQuota($doctorId, $dateTime);

        if ($message = $this->fitInLimitError($doctorId, $dateTime, $excludeScheduleId)) {
            throw new ScheduleSlotUnavailableException($message);
        }
    }

    /**
     * Mensagem do teto de encaixes (null = cabe). Conta os encaixes NÃO
     * cancelados do médico no mesmo dia e turno (faltou também ocupa a cota).
     * `$pending` soma ao que já existe os encaixes que o chamador ainda vai gravar
     * naquele turno (a prévia da série, que não grava nada).
     *
     * @param array<int, string>|string|null $excludeScheduleId
     */
    public function fitInLimitError(string $doctorId, Carbon $dateTime, array|string|null $excludeScheduleId = null, int $pending = 0): ?string
    {
        $doctor = Doctor::query()->find($doctorId);

        if (! $doctor) {
            return null; // doctor_id inválido já é recusado pela própria regra do campo
        }

        $shift = ScheduleShift::ofTime($dateTime);
        $limit = $doctor->fitInLimit($shift);
        $label = ScheduleShift::label($shift);

        if ($limit <= 0) {
            return __('schedules.fit_in_not_accepted', ['shift' => $label]);
        }

        if ($this->fitInCount($doctorId, $dateTime, $shift, $excludeScheduleId) + $pending >= $limit) {
            return trans_choice('schedules.fit_in_limit_reached', $limit, [
                'limit' => $limit,
                'shift' => $label,
                'date'  => $dateTime->format('d/m/Y'),
            ]);
        }

        return null;
    }

    /**
     * Encaixes não cancelados do médico no turno do dia (sem o próprio, ao editar).
     *
     * @param array<int, string>|string|null $excludeScheduleId
     */
    public function fitInCount(string $doctorId, Carbon $dateTime, string $shift, array|string|null $excludeScheduleId = null): int
    {
        [$start, $end] = ScheduleShift::bounds($dateTime, $shift);

        return Schedule::query()
            ->where('doctor_id', $doctorId)
            ->where('is_fit_in', true)
            ->where('date_time', '>=', $start)
            ->where('date_time', '<', $end)
            ->where('situation', '!=', ScheduleSituation::Cancelled->value)
            ->when($this->excludedIds($excludeScheduleId) !== [], fn ($q) => $q->whereNotIn('id', $this->excludedIds($excludeScheduleId)))
            ->count();
    }

    /**
     * Encaixes usados × teto por turno do médico no dia (formulário mostra
     * "1 de 2 encaixes da manhã").
     *
     * @return array<string, array{limit: int, used: int}>
     */
    public function fitInUsage(Doctor $doctor, Carbon $date, ?string $excludeScheduleId = null): array
    {
        return collect(ScheduleShift::ALL)->mapWithKeys(fn (string $shift) => [$shift => [
            'limit' => $doctor->fitInLimit($shift),
            'used'  => $this->fitInCount((string) $doctor->id, $date, $shift, $excludeScheduleId),
        ]])->all();
    }

    /**
     * Trava a cota de encaixes (médico + dia + turno) até o fim da transação
     * corrente. PostgreSQL: advisory lock de transação (só quem disputa a
     * MESMA cota espera); demais drivers: lock na linha do médico.
     */
    public function lockFitInQuota(string $doctorId, Carbon $dateTime): void
    {
        $connection = DB::connection();

        if ($connection->getDriverName() === 'pgsql') {
            $connection->select('select pg_advisory_xact_lock(?)', [self::fitInLockKey($doctorId, $dateTime)]);

            return;
        }

        Doctor::query()->whereKey($doctorId)->lockForUpdate()->first();
    }

    /**
     * Trava, em ORDEM ESTÁVEL (médico, dia e turno), a cota de encaixe de cada (médico, horário) dado. Quem grava vários
     * encaixes numa transação só (série criada pela Agenda, "Reagendar a série") chama isto ANTES do primeiro INSERT: o
     * INSERT toma a trava do código SDL e os outros caminhos (reagendar, lote) seguem cota → código; travar uma cota depois
     * do código entra em círculo com eles (40P01). Duas séries com cotas em comum travam na mesma ordem e só esperam.
     *
     * @param list<array{0: string, 1: Carbon}> $slots pares [médico, horário]
     */
    public function lockFitInQuotas(array $slots): void
    {
        $quotas = [];

        foreach ($slots as [$doctorId, $dateTime]) {
            $quotas[$doctorId . '|' . $dateTime->toDateString() . '|' . ScheduleShift::ofTime($dateTime)] = [$doctorId, $dateTime];
        }
        ksort($quotas, SORT_STRING);

        foreach ($quotas as [$doctorId, $dateTime]) {
            $this->lockFitInQuota($doctorId, $dateTime);
        }
    }

    /** Chave bigint estável da cota (60 bits do sha1, sempre positiva). */
    public static function fitInLockKey(string $doctorId, Carbon $dateTime): int
    {
        $quota = implode('|', ['schedule_fit_in', $doctorId, $dateTime->toDateString(), ScheduleShift::ofTime($dateTime)]);

        return (int) hexdec(substr(sha1($quota), 0, 15));
    }

    /**
     * Sala/equipamento no horário: ocupado por outro agendamento, fora da
     * escala do recurso ou bloqueado. Mensagens dizem QUAL recurso e por quê.
     * Usado pelo validateSlot (agendar/editar), pela remarcação individual,
     * pela recorrência e pela remarcação em lote — a sala acompanha o
     * agendamento em todos esses caminhos.
     *
     * @param string[] $resourceIds
     *
     * @return string[]
     */
    public function resourceErrors(array $resourceIds, Carbon $dateTime, array|string|null $excludeScheduleId = null): array
    {
        $errors = [];

        foreach ($resourceIds as $resourceId) {
            $resource = ClinicResource::find($resourceId);

            if (! $resource) {
                continue;
            }

            if ($this->isResourceDoubleBooked($resourceId, $dateTime, $excludeScheduleId)) {
                $errors[] = __('actions.resource_double_booked', ['name' => $resource->name]);

                continue;
            }

            if (! $this->isResourceWithinSchedule($resourceId, $dateTime)) {
                $dayName  = $this->dayName($dateTime->dayOfWeek);
                $errors[] = __('actions.resource_unavailable', ['name' => $resource->name, 'day' => $dayName, 'time' => $dateTime->format('H:i')]);

                continue;
            }

            if ($this->isResourceBlocked($resourceId, $dateTime)) {
                $errors[] = __('actions.resource_blocked', ['name' => $resource->name]);
            }
        }

        return $errors;
    }

    /**
     * Checks if a resource is already reserved at the given datetime (by an active schedule).
     */
    public function isResourceDoubleBooked(string $resourceId, Carbon $dateTime, array|string|null $excludeScheduleId): bool
    {
        $query = DB::table('schedule_resources')
            ->join('schedules', 'schedules.id', '=', 'schedule_resources.schedule_id')
            ->where('schedule_resources.resource_id', $resourceId)
            ->where('schedules.date_time', $dateTime->format('Y-m-d H:i:s'))
            ->whereNull('schedules.deleted_at')
            ->whereNotIn('schedules.situation', $this->terminalSituationValues());

        if (($excluded = $this->excludedIds($excludeScheduleId)) !== []) {
            $query->whereNotIn('schedules.id', $excluded);
        }

        return $query->exists();
    }

    /**
     * Checks if a datetime falls within the resource's defined work schedule.
     * Returns true (no restriction) when the resource has no schedule configured.
     */
    public function isResourceWithinSchedule(string $resourceId, Carbon $dateTime): bool
    {
        $hasAny = ResourceWorkSchedule::where('resource_id', $resourceId)->exists();

        if (! $hasAny) {
            return true;
        }

        $time = $dateTime->format('H:i:s');

        return ResourceWorkSchedule::where('resource_id', $resourceId)
            ->where('day_of_week', $dateTime->dayOfWeek)
            ->where('starts_at', '<=', $time)
            ->where('ends_at', '>', $time)
            ->exists();
    }

    /**
     * Checks if the resource is blocked at the given datetime.
     */
    public function isResourceBlocked(string $resourceId, Carbon $dateTime): bool
    {
        return ResourceBlock::where('resource_id', $resourceId)
            ->where('starts_at', '<=', $dateTime->toDateTimeString())
            ->where('ends_at', '>', $dateTime->toDateTimeString())
            ->exists();
    }

    /**
     * Returns the availability status of all active resources for a given datetime.
     *
     * @return array<int, array{id: string, name: string, type: string, type_label: string, available: bool}>
     */
    public function getResourceAvailability(string $entityId, Carbon $dateTime, ?string $excludeScheduleId = null): array
    {
        $resources = ClinicResource::where('entity_id', $entityId)
            ->where('active', true)
            ->orderBy('type')
            ->orderBy('name')
            ->get();

        return $resources->map(function (ClinicResource $resource) use ($dateTime, $excludeScheduleId) {
            $available = ! $this->isResourceDoubleBooked($resource->id, $dateTime, $excludeScheduleId)
                && $this->isResourceWithinSchedule($resource->id, $dateTime)
                && ! $this->isResourceBlocked($resource->id, $dateTime);

            return [
                'id'         => $resource->id,
                'name'       => $resource->name,
                'type'       => $resource->type,
                'type_label' => $resource->typeLabel(),
                'available'  => $available,
            ];
        })->toArray();
    }

    /**
     * Checks if a datetime is within the doctor's defined work schedule.
     * Returns true (no restriction) when the doctor has no schedule configured.
     */
    public function isWithinWorkSchedule(string $doctorId, Carbon $dateTime): bool
    {
        $hasAny = DoctorWorkSchedule::where('doctor_id', $doctorId)->exists();

        if (! $hasAny) {
            return true; // No schedule defined → no restriction (backward-compatible)
        }

        $dayOfWeek = $dateTime->dayOfWeek;
        $time      = $dateTime->format('H:i:s');

        return DoctorWorkSchedule::where('doctor_id', $doctorId)
            ->where('day_of_week', $dayOfWeek)
            ->where('starts_at', '<=', $time)
            ->where('ends_at', '>', $time)
            ->exists();
    }

    /**
     * Checks if the datetime aligns with the doctor's slot interval grid.
     *
     * Example: interval = 15 min, range starts at 08:00
     *   Valid   → 08:00, 08:15, 08:30 …
     *   Invalid → 08:07, 08:22 …
     *
     * Returns true when no work schedule is defined for that day (no restriction).
     */
    public function isIntervalAligned(string $doctorId, Carbon $dateTime): bool
    {
        $dayOfWeek = $dateTime->dayOfWeek;
        $timeStr   = $dateTime->format('H:i:s');

        $range = DoctorWorkSchedule::where('doctor_id', $doctorId)
            ->where('day_of_week', $dayOfWeek)
            ->where('starts_at', '<=', $timeStr)
            ->where('ends_at', '>', $timeStr)
            ->first();

        if (! $range) {
            // isWithinWorkSchedule already rejects out-of-schedule times;
            // here we just avoid a false "misaligned" error for those cases.
            return true;
        }

        $doctor   = Doctor::find($doctorId);
        $interval = $doctor ? $doctor->effectiveInterval() : 15;

        $rangeStart       = Carbon::parse($dateTime->toDateString() . ' ' . $range->starts_at);
        $minutesFromStart = (int) $rangeStart->diffInMinutes($dateTime);

        return $minutesFromStart % $interval === 0;
    }

    /**
     * Checks if a datetime falls within a schedule block (absence, holiday, etc).
     */
    public function isBlocked(string $doctorId, Carbon $dateTime): bool
    {
        return ScheduleBlock::where('doctor_id', $doctorId)
            ->where('starts_at', '<=', $dateTime->toDateTimeString())
            ->where('ends_at', '>', $dateTime->toDateTimeString())
            ->exists();
    }

    /**
     * Returns all time slots for a doctor on a given date, with availability status.
     * Returns empty array when no work schedule is configured for that day.
     *
     * @return array<int, array{time: string, datetime: string, available: bool}>
     */
    public function getAvailableSlots(Doctor $doctor, Carbon $date, ?string $excludeScheduleId = null): array
    {
        $interval  = $doctor->effectiveInterval();
        $dayOfWeek = $date->dayOfWeek;

        $ranges = DoctorWorkSchedule::where('doctor_id', $doctor->id)
            ->where('day_of_week', $dayOfWeek)
            ->orderBy('starts_at')
            ->get();

        if ($ranges->isEmpty()) {
            return [];
        }

        $terminalValues = array_map(
            fn (ScheduleSituation $s) => $s->value,
            array_filter(ScheduleSituation::cases(), fn ($s) => $s->isTerminal()),
        );

        $booked = Schedule::where('doctor_id', $doctor->id)
            ->whereDate('date_time', $date->toDateString())
            ->where('is_fit_in', false) // encaixe não ocupa o horário da grade
            ->whereNull('origin')       // nem o atendimento do pronto atendimento
            ->whereNotIn('situation', $terminalValues)
            ->whereNull('deleted_at')
            ->when($excludeScheduleId, fn ($q) => $q->where('id', '!=', $excludeScheduleId))
            ->pluck('date_time')
            ->map(fn ($dt) => Carbon::parse($dt)->format('H:i'))
            ->toArray();

        $blocks = ScheduleBlock::where('doctor_id', $doctor->id)
            ->where('starts_at', '<', $date->copy()->endOfDay())
            ->where('ends_at', '>', $date->copy()->startOfDay())
            ->get();

        // Cirurgias agendadas do médico no dia: o horário que encosta numa delas
        // não é vaga (módulo cirúrgico).
        $surgeries = app(SurgeryScheduleService::class)
            ->windowsOverlapping([(string) $doctor->id], $date->copy()->startOfDay(), $date->copy()->endOfDay());

        $slots = [];

        foreach ($ranges as $range) {
            $current = Carbon::parse($date->toDateString() . ' ' . $range->starts_at);
            $end     = Carbon::parse($date->toDateString() . ' ' . $range->ends_at);

            while ($current->lt($end)) {
                $timeStr   = $current->format('H:i');
                $isBooked  = in_array($timeStr, $booked, true);
                $slotEnd   = $current->copy()->addMinutes($interval);
                $isInBlock = $blocks->contains(
                    fn ($block) => $current->gte($block->starts_at) && $current->lt($block->ends_at),
                ) || $surgeries->contains(
                    fn ($case) => $case->scheduled_start->lt($slotEnd) && $case->scheduled_end->gt($current),
                );

                $slots[] = [
                    'time'      => $timeStr,
                    'datetime'  => $current->format('Y-m-d\TH:i'),
                    'available' => ! $isBooked && ! $isInBlock,
                ];

                $current->addMinutes($interval);
            }
        }

        return $slots;
    }

    /**
     * Moves multiple schedules to a new date, keeping individual times unless a
     * specific time override is provided.
     *
     * Silently skips terminal schedules or those that fail slot validation
     * (double-booking, outside work schedule, block, etc.).
     *
     * @param string[]    $ids
     * @param string      $newDate  Format: Y-m-d
     * @param string|null $newTime  Format: H:i — if null, each schedule keeps its original time
     * @param string|null $doctorId perfil médico: só os agendamentos dele (os demais entram em skipped)
     *
     * @return array{ updated: int, skipped: int }
     */
    public function bulkReschedule(
        array $ids,
        string $newDate,
        ?string $newTime,
        string $entityId,
        string $entityUserId,
        ?string $doctorId = null,
    ): array {
        $updated    = 0;
        $skipped    = 0;
        $updatedIds = [];
        $blocked    = [];

        foreach ($ids as $id) {
            $schedule = Schedule::where('id', $id)
                ->where('entity_id', $entityId)
                ->when($doctorId, fn ($q) => $q->where('doctor_id', $doctorId))
                ->first();

            if (! $schedule || $schedule->situation->isTerminal()) {
                $skipped++;

                continue;
            }

            $time        = $newTime ?? Carbon::parse($schedule->date_time)->format('H:i');
            $newDateTime = Carbon::parse("{$newDate} {$time}");

            // Skip if the slot is already taken or outside the doctor's schedule
            // — e também se a sala/equipamento reservado estiver ocupado, fora
            // da escala do recurso ou bloqueado no novo horário (antes o lote
            // movia sem olhar os recursos e furava escala/bloqueio da sala).
            // Encaixe segue como encaixe: regra de encaixe (pode cair em
            // horário ocupado, respeita expediente/bloqueio/sala) e o teto do
            // turno de destino, conferido sob lock na gravação.
            $isFitIn = (bool) $schedule->is_fit_in;
            $errors  = $this->validateFor(
                $isFitIn,
                $schedule->doctor_id,
                $newDateTime,
                $schedule->id,  // exclude self to allow same-day same-time move
                $schedule->resources()->pluck('clinic_resources.id')->map(fn ($id) => (string) $id)->all(),
            );

            if (empty($errors)) {
                try {
                    DB::transaction(function () use ($schedule, $newDateTime, $isFitIn): void {
                        // Médico em cirurgia não atende — reconferido SOB a trava das
                        // janelas cirúrgicas, como remarcar um e criar fazem. A
                        // validação acima leu sem trava: uma cirurgia agendada ao mesmo
                        // tempo passava as duas e a consulta caía dentro dela.
                        $this->assertDoctorFreeOfSurgery((string) $schedule->doctor_id, $newDateTime);

                        if ($isFitIn) {
                            $this->assertFitInCapacity($schedule->doctor_id, $newDateTime, (string) $schedule->id);
                        }

                        $schedule->update(['date_time' => $newDateTime]);
                    });
                } catch (ScheduleSlotUnavailableException $e) {
                    $errors = [$e->getMessage()];
                } catch (UniqueConstraintViolationException $e) {
                    if (! Schedule::isDoctorSlotConflict($e)) {
                        throw $e;
                    }

                    $errors = [__('validation.custom.schedule.doctor_datetime_unique')];
                }
            }

            if (! empty($errors)) {
                $skipped++;
                // Motivo por linha (inclusive sala ocupada/fora da escala/
                // bloqueada) — o lote não some com o agendamento em silêncio.
                $blocked[] = ['id' => $schedule->id, 'code' => $schedule->code, 'message' => $schedule->code . ': ' . $errors[0]];

                continue;
            }

            $updatedIds[] = $id;
            $updated++;
        }

        if ($updated > 0) {
            Cache::forget("waiting_room:{$entityId}");
        }

        return ['updated' => $updated, 'skipped' => $skipped, 'updated_ids' => $updatedIds, 'blocked' => $blocked];
    }

    /**
     * Transição de situação de UM agendamento — caminho único de TODO writer
     * do fluxo vivo: PATCH schedules/{id}/situation, bulk-update, store/update
     * da agenda (campo `situation` do formulário) e o fluxo do prontuário
     * (Finalizar/Dilatar/Exame). Grava ScheduleSituationLog, ajusta
     * arrived_at/confirmed_at/cancellation_reason e limpa o cache da sala de
     * espera. Situação é livremente editável (sem grafo de transições), com
     * UMA trava de negócio: "Atendido exige caixa" (guardSituationChange).
     *
     * Fora daqui, de propósito: ScheduleImportService (carga de histórico/
     * migração — consultas passadas já atendidas no sistema anterior, sem
     * caixa no EasyEye; exigir caixa inviabilizaria a migração) e as escritas
     * que nunca levam a Atendido (reagendar → Cancelado, recorrência →
     * Agendado, WhatsApp → Confirmado/Cancelado).
     *
     * Retorna false quando é no-op (já está na situação-alvo) — evita reset
     * indevido de timestamps e ruído no histórico.
     *
     * REATIVAR (Cancelado/Faltou/Atendido → situação ativa) devolve a consulta
     * ao horário do médico, que foi liberado quando ela saiu da grade: uma
     * cirurgia pode ter passado a ocupá-lo desde então. Criar e remarcar já
     * recusam o horário em cirurgia; reativar recusa igual
     * (ScheduleSlotUnavailableException, nada gravado), sob a trava das janelas
     * cirúrgicas do médico (assertDoctorFreeOfSurgery). Vale para todo
     * chamador deste método: PATCH da situação, lote e `situation` do formulário.
     *
     * @throws AttendanceRequiresCashEntryException antes de qualquer escrita
     * @throws ScheduleSlotUnavailableException     reativação em horário de cirurgia agendada do médico
     */
    public function changeSituation(
        Schedule $schedule,
        ScheduleSituation $situation,
        ?string $entityUserId,
        ?string $notes = null,
    ): bool {
        return DB::transaction(function () use ($schedule, $situation, $entityUserId, $notes): bool {
            // Janelas cirúrgicas do médico ANTES da linha do agendamento (a
            // ordem de store()/update()), quando a instância já mostra uma
            // reativação.
            $this->lockSurgeryWindowsForReactivation($schedule, $situation);

            // Travas da transição (lock paciente/dia → linha) e releitura do
            // que a instância pode ter de velho. Update + log na MESMA
            // transação: sem mudança de situação sem trilha.
            $this->lockForTransition($schedule, $situation);

            if ($situation === $schedule->situation) {
                return false;
            }

            $this->guardSituationChange($schedule, $situation);

            $data = ['situation' => $situation->value];

            if ($situation === ScheduleSituation::Waiting) {
                $data['arrived_at'] = now();
            }

            // Retornando à consulta implica que já chegou: preenche só se vazio
            // (marcado direto sem passar por Aguardando), nunca reseta a chegada.
            if ($situation === ScheduleSituation::ReturningToDoctor && ! $schedule->arrived_at) {
                $data['arrived_at'] = now();
            }

            if ($situation === ScheduleSituation::Confirmed) {
                $data['confirmed_at'] = now();
            }

            if ($situation === ScheduleSituation::Cancelled && $notes) {
                $data['cancellation_reason'] = $notes;
            }

            $fromSituation = $schedule->situation;

            $schedule->update($data);

            ScheduleSituationLog::create([
                'schedule_id'    => $schedule->id,
                'entity_user_id' => $entityUserId,
                'from_situation' => $fromSituation->value,
                'to_situation'   => $situation->value,
                'notes'          => $notes,
                'created_at'     => now(),
            ]);

            // Captura pela câmera feita ANTES do "Atendido" (ex.: ainda em
            // Aguardando) nunca ganha schedule_id no import — reconcilia
            // aqui, na mesma transação, pra nunca duplicar o ato no repasse
            // médico (achado via revisão adversarial Codex).
            if ($situation === ScheduleSituation::Attended) {
                $this->externalExamImportService->relinkBrowserCapturesForSchedule($schedule);
            }

            Cache::forget("waiting_room:{$schedule->entity_id}");

            return true;
        });
    }

    /**
     * Travas da transição de situação, na ordem em que não dão deadlock, e a
     * instância atualizada com o que o banco tem AGORA.
     *
     * Transição pra Atendido reconcilia capturas órfãs do paciente/dia
     * (relinkBrowserCapturesForSchedule), que trava TODOS os agendamentos do
     * dia: o lock paciente/dia tem que sair ANTES da linha deste agendamento,
     * senão duas transições simultâneas, cada uma já segurando a PRÓPRIA
     * linha, entram em deadlock. Mas o paciente/dia que valem são os da
     * linha TRAVADA, não os da instância carregada no início da requisição
     * (achado via revisão adversarial Codex): se outra requisição remarcou, ou
     * trocou o paciente/médico, nesse intervalo, o lock e a busca de capturas
     * olhavam a identidade antiga — a linha virava Atendido e as capturas do
     * agendamento de verdade ficavam sem vínculo (o repasse contava o ato 2x;
     * repetir "Atendido" é no-op e não repara). Por isso: trava o dia →
     * trava a linha e relê a identidade → se a chave do lock mudou, desfaz
     * (SAVEPOINT: solta a linha E o lock desta tentativa) e recomeça com o
     * lock certo, sem segurar linha nenhuma. A instância sai com paciente/
     * data/médico atuais, e é com ela que se reconcilia.
     *
     * Outras transições só travam e relêem a linha (situação/chegada): nada
     * a reconciliar, nenhuma identidade a conferir.
     *
     * @throws ScheduleChangedConcurrentlyException a identidade mudou em todas as tentativas
     */
    private function lockForTransition(Schedule $schedule, ScheduleSituation $situation): void
    {
        if ($situation !== ScheduleSituation::Attended || ! $schedule->exists) {
            $this->lockAttendanceReconciliation($situation, $schedule);
            // Situação-alvo ativa pode ser reativação: a cirurgia é conferida
            // contra o médico e o horário ATUAIS da linha travada (outra
            // requisição pode tê-la remarcado), não os do route binding.
            $this->refreshSituationUnderLock($schedule, withIdentity: $situation->isActive());

            return;
        }

        $this->lockPatientDaysThenRow($schedule, fn (Schedule $s): array => [[$s->patient_id, $s->date_time]]);
    }

    /**
     * Travas de uma EDIÇÃO do formulário (PUT), ANTES do UPDATE: a linha do
     * agendamento e, se ele vai ficar Atendido, o paciente/dia de ORIGEM e de
     * DESTINO em ordem determinística — ver lockPatientDaysThenRow(). Só o
     * destino não basta: duas edições em sentidos opostos (X→Y e Y→X) do mesmo
     * paciente, ambas reconciliando capturas, seguravam a linha do PRÓPRIO
     * agendamento e esperavam a do outro, que pro banco ainda estava no dia
     * original — deadlock (achado via revisão adversarial Codex).
     *
     * "Vai ficar Atendido" é a situação FINAL da edição: a pedida, ou — se o
     * formulário a omitiu — a que o BANCO tem, lida sob a trava da linha, não a
     * da instância do route binding (achado via revisão adversarial Codex): um
     * agendamento carregado Aguardando que outra requisição comitou Atendido no
     * meio saía daqui sem locks e sem releitura, e a edição de médico/dia
     * deixava as capturas da identidade nova sem vínculo (o repasse contava o
     * agendamento de exame E a captura). Mudou a situação final, a tentativa é
     * desfeita e refeita com os locks antes da linha. A linha é travada sempre
     * (o UPDATE a travaria logo depois); quem não fica Atendido não toma lock
     * paciente/dia — nada a reconciliar.
     *
     * A instância sai com situação, paciente, médico e data ATUAIS do banco; os
     * $attributes ainda não foram aplicados.
     *
     * @param array<string, mixed> $attributes o que o formulário vai gravar
     *
     * @throws ScheduleChangedConcurrentlyException a situação ou a identidade mudou em todas as tentativas
     */
    public function lockForEdit(Schedule $schedule, ?ScheduleSituation $requested, array $attributes): void
    {
        if (! $schedule->exists) {
            return;
        }

        $this->lockPatientDaysThenRow($schedule, function (Schedule $s) use ($requested, $attributes): array {
            if (($requested ?? $s->situation) !== ScheduleSituation::Attended) {
                return [];
            }

            return [
                [$s->patient_id, $s->date_time],
                [$attributes['patient_id'] ?? $s->patient_id, $attributes['date_time'] ?? $s->date_time],
            ];
        });
    }

    /**
     * Lock(s) paciente/dia → linha do agendamento, com a identidade (e a
     * situação, se o closure depender dela) conferida sob a trava. Cada
     * tentativa roda num SAVEPOINT: trava os paciente/dia que a instância
     * (possivelmente velha) indica, trava a linha e relê situação e identidade;
     * se agora ela pede um paciente/dia que não está entre os travados, desfaz
     * (solta a linha E os locks da tentativa) e recomeça com o estado novo, sem
     * segurar linha nenhuma — a ordem que evita deadlock (ver
     * Schedule::lockPatientDay).
     *
     * @param Closure(Schedule): list<array{0: ?string, 1: mixed}> $patientDays pares [paciente, dia] a travar, pelo estado ATUAL da instância (vazio = nenhum lock paciente/dia)
     *
     * @throws ScheduleChangedConcurrentlyException a identidade mudou em todas as tentativas
     */
    private function lockPatientDaysThenRow(Schedule $schedule, Closure $patientDays): void
    {
        for ($attempt = 1;; $attempt++) {
            try {
                DB::transaction(function () use ($schedule, $patientDays): void {
                    $entityId = (string) $schedule->entity_id;
                    $held     = Schedule::lockPatientDays($entityId, $patientDays($schedule));

                    $this->refreshSituationUnderLock($schedule, withIdentity: true);

                    if (array_diff(array_keys(Schedule::patientDayPairs($entityId, $patientDays($schedule))), $held) !== []) {
                        throw new ScheduleChangedConcurrentlyException($schedule);
                    }
                });

                return;
            } catch (ScheduleChangedConcurrentlyException $e) {
                if ($attempt >= self::ATTENDANCE_LOCK_ATTEMPTS) {
                    throw $e;
                }
            }
        }
    }

    /**
     * Lock paciente/dia da reconciliação de capturas órfãs — só a transição
     * pra Atendido a faz (ver Schedule::lockPatientDay, regra de ordem).
     * Quem escreve a linha do agendamento ANTES de chamar changeSituation()
     * (ex.: o formulário da agenda, que dá UPDATE e só depois aplica a
     * situação) precisa chamar isto primeiro, com os $attributes que vai
     * gravar — o dia/paciente que valem são os de DEPOIS da gravação.
     *
     * @param array<string, mixed> $attributes
     */
    public function lockAttendanceReconciliation(?ScheduleSituation $situation, Schedule $schedule, array $attributes = []): void
    {
        if ($situation !== ScheduleSituation::Attended) {
            return;
        }

        $entityId  = $attributes['entity_id'] ?? $schedule->entity_id;
        $patientId = $attributes['patient_id'] ?? $schedule->patient_id;
        $dateTime  = $attributes['date_time'] ?? $schedule->date_time;

        if ($entityId === null || $patientId === null || $dateTime === null) {
            return;
        }

        Schedule::lockPatientDay((string) $entityId, (string) $patientId, Carbon::parse($dateTime));
    }

    /**
     * Identidade do agendamento a que os exames vinculados pertencem:
     * paciente, médico e dia (ver App\Support\ExamScheduleLink). Tirada ANTES
     * de gravar uma edição para reconcileExamLinks() saber o que mudou.
     *
     * @return array{patient_id: ?string, doctor_id: ?string, day: ?string}
     */
    public function examLinkIdentity(Schedule $schedule): array
    {
        return [
            'patient_id' => $schedule->patient_id === null ? null : (string) $schedule->patient_id,
            'doctor_id'  => $schedule->doctor_id === null ? null : (string) $schedule->doctor_id,
            'day'        => $schedule->date_time?->toDateString(),
        ];
    }

    /**
     * Depois de gravar uma EDIÇÃO do agendamento (e de aplicar a situação):
     * desvincula os exames cujo vínculo deixou de valer — sempre, pelo dado, e
     * não só quando a identidade parece ter mudado — e, se paciente, médico ou
     * dia mudaram e ele segue Atendido, vincula as capturas órfãs da identidade
     * nova. Sem isso, editar um Atendido deixava as capturas presas à identidade
     * antiga (o repasse as engolia na competência errada; o laudo ancorava no
     * atendimento de outro paciente) e as da identidade nova sem vínculo — a
     * reconciliação só rodava na transição pra Atendido, que aqui é no-op
     * (achado via revisão adversarial Codex).
     *
     * O exame desvinculado segue existindo (mesmo paciente/dia/médico) e passa a
     * contar como exame avulso; a mudança fica na trilha de auditoria do exame.
     * A reconciliação precisa do lock paciente/dia da identidade nova tomado
     * ANTES do UPDATE do agendamento (lockAttendanceReconciliation).
     *
     * @param array{patient_id: ?string, doctor_id: ?string, day: ?string} $before identidade antes da edição
     *
     * @return int exames desvinculados
     */
    public function reconcileExamLinks(Schedule $schedule, array $before): int
    {
        $unlinked = 0;

        ExamScheduleLink::staleFor($schedule)->get()->each(function (PatientExam $exam) use (&$unlinked): void {
            $exam->update(['schedule_id' => null]);
            $unlinked++;
        });

        if ($schedule->situation === ScheduleSituation::Attended && $this->examLinkIdentity($schedule) !== $before) {
            $this->externalExamImportService->relinkBrowserCapturesForSchedule($schedule);
        }

        return $unlinked;
    }

    /**
     * Trava a linha do agendamento (FOR UPDATE, mesmo lock do lançamento de
     * caixa da chegada) até o COMMIT e traz para a instância a situação/
     * chegada ATUAIS do banco, como originais — os demais atributos (inclusive
     * alterações ainda não salvas do chamador) ficam como estão. Agendamento
     * não gravado: nada.
     *
     * Dois cliques simultâneos ("Chegou" 2x, duas abas) liam a mesma situação
     * antiga e os dois gravavam — 2 logs, arrived_at resetado e o "de" do
     * histórico errado. Com a releitura sob trava, o segundo vê a situação
     * nova (no-op).
     *
     * Com $withIdentity, traz também paciente/data/médico atuais (a identidade
     * da reconciliação de capturas) — menos o que o chamador alterou em
     * memória e ainda não gravou: isso é dele.
     */
    private function refreshSituationUnderLock(Schedule $schedule, bool $withIdentity = false): void
    {
        if (! $schedule->exists) {
            return;
        }

        $identity = ['patient_id', 'date_time', 'doctor_id'];

        $current = Schedule::query()
            ->whereKey($schedule->getKey())
            ->lockForUpdate()
            ->first($withIdentity ? ['situation', 'arrived_at', ...$identity] : ['situation', 'arrived_at']);

        if ($current === null) {
            return;
        }

        $fresh = array_diff_key($current->getAttributes(), array_flip(array_intersect($identity, array_keys($schedule->getDirty()))));

        $schedule->setRawAttributes(array_merge($schedule->getAttributes(), $fresh));
        $schedule->syncOriginalAttributes(array_keys($fresh));
    }

    /**
     * Marcar "Atendido" exige lançamento no caixa quando a clínica habilita
     * `requires_cash_to_complete`. Conta só lançamento ativo (não cancelado)
     * da MESMA clínica do agendamento (Schedule::financialEntries é escopado
     * por tenant) — linha de outra clínica apontando para o agendamento não
     * libera. Agendamento ainda não gravado nunca tem caixa.
     */
    public function attendedBlockedByCash(Schedule $schedule): bool
    {
        if (! Entity::whereKey($schedule->entity_id)->value('requires_cash_to_complete')) {
            return false;
        }

        return ! $schedule->financialEntries()
            ->where('status', '!=', FinancialEntryStatus::Cancelled->value)
            ->whereNull('deleted_at')
            ->exists();
    }

    /**
     * Applies a situation transition to multiple schedules belonging to the entity.
     *
     * AJUSTE: situação é um campo livremente editável (ver
     * SchedulesController::updateSituation) — não existe mais grafo de
     * transições permitidas nem bloqueio por "terminal". Cada linha passa por
     * changeSituation() (mesmo log/timestamps/cache e a MESMA trava de caixa
     * do fluxo unitário).
     *
     * Contrato de bulk (sucesso parcial, nunca aborta o lote): linha que não
     * existe/não é da entity, que já está na situação-alvo (no-op), que
     * esbarra na trava "Atendido exige caixa" ou cuja reativação cai numa
     * cirurgia agendada do médico é IGNORADA e contada em `skipped` (updated +
     * skipped = total de ids). As barradas por regra também saem em `blocked`,
     * com mensagem traduzida por linha e o `reason` (`cash_entry` |
     * `slot_unavailable`).
     *
     * @param string[]    $ids
     * @param string|null $doctorId perfil médico: só os agendamentos dele (os demais entram em skipped)
     *
     * @return array{updated: int, skipped: int, updated_ids: string[], blocked: list<array{id: string, code: ?string, message: string, reason: string}>}
     */
    public function bulkUpdateSituation(
        array $ids,
        ScheduleSituation $situation,
        string $entityId,
        string $entityUserId,
        ?string $notes = null,
        ?string $doctorId = null,
    ): array {
        $skipped    = 0;
        $updatedIds = [];
        $blocked    = [];

        foreach ($ids as $id) {
            $schedule = Schedule::where('id', $id)
                ->where('entity_id', $entityId)
                ->when($doctorId, fn ($q) => $q->where('doctor_id', $doctorId))
                ->first();

            if (! $schedule) {
                $skipped++;

                continue;
            }

            try {
                $changed = $this->changeSituation($schedule, $situation, $entityUserId, $notes);
            } catch (AttendanceRequiresCashEntryException) {
                $skipped++;
                $blocked[] = [
                    'id'      => $schedule->id,
                    'code'    => $schedule->code,
                    'message' => __('schedules.bulk_row_cash_entry_required', ['code' => $schedule->code]),
                    'reason'  => 'cash_entry',
                ];

                continue;
            } catch (ScheduleSlotUnavailableException $e) {
                // Reativação num horário que uma cirurgia do médico passou a ocupar.
                $skipped++;
                $blocked[] = [
                    'id'      => $schedule->id,
                    'code'    => $schedule->code,
                    'message' => $schedule->code . ': ' . $e->getMessage(),
                    'reason'  => 'slot_unavailable',
                ];

                continue;
            }

            if (! $changed) {
                $skipped++;

                continue;
            }

            $updatedIds[] = $schedule->id;
        }

        return [
            'updated'     => count($updatedIds),
            'skipped'     => $skipped,
            'updated_ids' => $updatedIds,
            'blocked'     => $blocked,
        ];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Private helpers
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Trava única de negócio da transição de situação: "Atendido exige caixa"
     * quando a clínica habilita `requires_cash_to_complete`.
     *
     * @throws AttendanceRequiresCashEntryException
     */
    private function guardSituationChange(Schedule $schedule, ScheduleSituation $situation): void
    {
        if ($situation === ScheduleSituation::Attended && $this->attendedBlockedByCash($schedule)) {
            throw new AttendanceRequiresCashEntryException($schedule);
        }

        // Reativação: médico em cirurgia agendada não volta a ter a consulta. A
        // linha já está travada e relida (situação e identidade atuais).
        if ($this->isReactivation($schedule, $situation) && $schedule->date_time !== null) {
            $this->assertDoctorFreeOfSurgery((string) $schedule->doctor_id, Carbon::parse($schedule->date_time));
        }
    }

    /**
     * Cancelado/Faltou/Atendido voltando a uma situação ativa, num agendamento
     * da grade. O atendimento do pronto atendimento fica de fora: nasce fora da
     * grade, sem passar pela conferência de cirurgia (UrgentCareService::attend),
     * e reabri-lo não pode ser mais restrito do que abri-lo.
     */
    private function isReactivation(Schedule $schedule, ScheduleSituation $to): bool
    {
        return $schedule->situation->isTerminal()
            && $to->isActive()
            && ! $schedule->isUrgentCare()
            && filled($schedule->doctor_id);
    }

    /**
     * FOR SHARE na linha do médico (janelas cirúrgicas) ANTES de travar a linha
     * do agendamento, como a criação e a edição fazem. Só lê a instância: se ela
     * estava velha e a reativação só aparece depois da releitura sob a trava, o
     * guardSituationChange() trava então — sem ciclo, porque quem agenda cirurgia
     * (FOR NO KEY UPDATE no médico) nunca espera por linha de agendamento.
     */
    private function lockSurgeryWindowsForReactivation(Schedule $schedule, ScheduleSituation $to): void
    {
        if ($schedule->exists && $this->isReactivation($schedule, $to)) {
            app(SurgeryScheduleService::class)->lockDoctorWindows((string) $schedule->doctor_id);
        }
    }

    private function terminalSituationValues(): array
    {
        return array_map(
            fn (ScheduleSituation $s) => $s->value,
            array_filter(ScheduleSituation::cases(), fn ($s) => $s->isTerminal()),
        );
    }

    private function isDoubleBooked(string $doctorId, Carbon $dateTime, array|string|null $excludeScheduleId): bool
    {
        // Encaixe e atendimento do pronto atendimento não ocupam o horário
        // (mesma regra do índice único): o horário normal continua livre para
        // um agendamento normal.
        $query = DB::table('schedules')
            ->where('doctor_id', $doctorId)
            ->where('date_time', $dateTime->format('Y-m-d H:i:s'))
            ->whereNull('deleted_at')
            ->where('is_fit_in', false)
            ->whereNull('origin')
            ->whereNotIn('situation', $this->terminalSituationValues());

        if (($excluded = $this->excludedIds($excludeScheduleId)) !== []) {
            $query->whereNotIn('id', $excluded);
        }

        return $query->exists();
    }

    /**
     * Campos que o agendamento NOVO herda do antigo ao remarcar (uma sessão ou a série): o paciente, o convênio, o tipo e
     * os contatos ficam; a situação volta a Agendado; a sessão continua na série ("3/10") e no caso cirúrgico. `origin`
     * não é copiado (o remarcado vira agendamento normal) nem a recorrência. Ponto único dos dois caminhos.
     *
     * @return array<string, mixed>
     */
    public function rescheduleAttributes(Schedule $old, string $doctorId, Carbon $dateTime, bool $isFitIn): array
    {
        return [
            'entity_id'          => $old->entity_id,
            'doctor_id'          => $doctorId,
            'patient_id'         => $old->patient_id,
            'covenant_id'        => $old->covenant_id,
            'visit_id'           => $old->visit_id,
            'attendance_type'    => $old->attendance_type,
            'specialty_area'     => $old->specialty_area,
            'full_name'          => $old->full_name,
            'date_time'          => $dateTime,
            'telephone'          => $old->telephone,
            'cellphone'          => $old->cellphone,
            'cellphone_whatsapp' => (bool) $old->cellphone_whatsapp,
            'notes'              => $old->notes,
            'situation'          => ScheduleSituation::Scheduled->value,
            'is_fit_in'          => $isFitIn,
            'series_id'          => $old->series_id,
            'session_number'     => $old->session_number,
            'session_total'      => $old->session_total,
            // Retorno de uma cirurgia continua sendo dela: o agendamento novo herda o caso.
            'surgical_case_id' => $old->surgical_case_id,
            'active'           => true,
        ];
    }

    /**
     * Ids a ignorar nas conferências de conflito: um id (edição/remarcação), vários (remarcação da série) ou nenhum.
     *
     * @param array<int, string>|string|null $ids
     *
     * @return list<string>
     */
    private function excludedIds(array|string|null $ids): array
    {
        return array_values(array_filter(array_map('strval', (array) $ids), fn (string $id) => $id !== ''));
    }

    private function dayName(int $dayOfWeek): string
    {
        return __('actions.weekdays')[$dayOfWeek] ?? '?';
    }
}
