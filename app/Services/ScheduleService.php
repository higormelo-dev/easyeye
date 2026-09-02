<?php

namespace App\Services;

use App\Enums\{FinancialEntryStatus, ScheduleSituation};
use App\Models\{ClinicResource, Doctor, DoctorWorkSchedule, Entity, ResourceBlock, ResourceWorkSchedule, Schedule, ScheduleBlock, ScheduleSituationLog};
use Carbon\Carbon;
use Illuminate\Support\Facades\{Cache, DB};

class ScheduleService
{
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
     * @param string|null $excludeScheduleId UUID of the record being updated (skips self-conflict)
     *
     * @return string[]
     */
    /**
     * @param string[] $resourceIds
     */
    public function validateSlot(string $doctorId, Carbon $dateTime, ?string $excludeScheduleId = null, array $resourceIds = []): array
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

        // 5 ── Resource conflicts ─────────────────────────────────────────────
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
    public function isResourceDoubleBooked(string $resourceId, Carbon $dateTime, ?string $excludeScheduleId): bool
    {
        $query = DB::table('schedule_resources')
            ->join('schedules', 'schedules.id', '=', 'schedule_resources.schedule_id')
            ->where('schedule_resources.resource_id', $resourceId)
            ->where('schedules.date_time', $dateTime->format('Y-m-d H:i:s'))
            ->whereNull('schedules.deleted_at')
            ->whereNotIn('schedules.situation', $this->terminalSituationValues());

        if ($excludeScheduleId) {
            $query->where('schedules.id', '!=', $excludeScheduleId);
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

        $slots = [];

        foreach ($ranges as $range) {
            $current = Carbon::parse($date->toDateString() . ' ' . $range->starts_at);
            $end     = Carbon::parse($date->toDateString() . ' ' . $range->ends_at);

            while ($current->lt($end)) {
                $timeStr   = $current->format('H:i');
                $isBooked  = in_array($timeStr, $booked, true);
                $isInBlock = $blocks->contains(
                    fn ($block) => $current->gte($block->starts_at) && $current->lt($block->ends_at),
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
     * @param string      $newDate Format: Y-m-d
     * @param string|null $newTime Format: H:i — if null, each schedule keeps its original time
     *
     * @return array{ updated: int, skipped: int }
     */
    public function bulkReschedule(
        array $ids,
        string $newDate,
        ?string $newTime,
        string $entityId,
        string $entityUserId,
    ): array {
        $updated    = 0;
        $skipped    = 0;
        $updatedIds = [];

        foreach ($ids as $id) {
            $schedule = Schedule::where('id', $id)
                ->where('entity_id', $entityId)
                ->first();

            if (! $schedule || $schedule->situation->isTerminal()) {
                $skipped++;

                continue;
            }

            $time        = $newTime ?? Carbon::parse($schedule->date_time)->format('H:i');
            $newDateTime = Carbon::parse("{$newDate} {$time}");

            // Skip if the slot is already taken or outside the doctor's schedule
            $errors = $this->validateSlot(
                $schedule->doctor_id,
                $newDateTime,
                $schedule->id,  // exclude self to allow same-day same-time move
            );

            if (! empty($errors)) {
                $skipped++;

                continue;
            }

            $schedule->update(['date_time' => $newDateTime]);
            $updatedIds[] = $id;
            $updated++;
        }

        if ($updated > 0) {
            Cache::forget("waiting_room:{$entityId}");
        }

        return ['updated' => $updated, 'skipped' => $skipped, 'updated_ids' => $updatedIds];
    }

    /**
     * Transição de situação de UM agendamento — caminho único usado pelo
     * endpoint PATCH schedules/{id}/situation e pelo fluxo do prontuário
     * (Salvar/Finalizar/Dilatar/Exame). Grava ScheduleSituationLog, ajusta
     * arrived_at/confirmed_at/cancellation_reason e limpa o cache da sala de
     * espera. Situação é livremente editável (sem grafo de transições).
     *
     * Retorna false quando é no-op (já está na situação-alvo) — evita reset
     * indevido de timestamps e ruído no histórico.
     */
    public function changeSituation(
        Schedule $schedule,
        ScheduleSituation $situation,
        ?string $entityUserId,
        ?string $notes = null,
    ): bool {
        if ($situation === $schedule->situation) {
            return false;
        }

        $data = ['situation' => $situation->value];

        if ($situation === ScheduleSituation::Waiting) {
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

        Cache::forget("waiting_room:{$schedule->entity_id}");

        return true;
    }

    /**
     * Marcar "Atendido" exige lançamento no caixa quando a clínica habilita
     * `requires_cash_to_complete` — mesma trava do endpoint de situação,
     * reaproveitada pelo "Finalizar consulta" do prontuário.
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
     * transições permitidas nem bloqueio por "terminal". Skips agora só
     * cobrem registros que não existem/não pertencem à entity, e o próprio
     * item já estar na situação-alvo (no-op — evita log/timestamp redundante).
     *
     * Writes a ScheduleSituationLog entry for every record actually updated.
     *
     * @param string[] $ids
     *
     * @return array{ updated: int, skipped: int }
     */
    public function bulkUpdateSituation(
        array $ids,
        ScheduleSituation $situation,
        string $entityId,
        string $entityUserId,
        ?string $notes = null,
    ): array {
        $updated    = 0;
        $skipped    = 0;
        $updatedIds = [];

        foreach ($ids as $id) {
            $schedule = Schedule::where('id', $id)
                ->where('entity_id', $entityId)
                ->first();

            if (! $schedule) {
                $skipped++;

                continue;
            }

            if ($schedule->situation === $situation) {
                $skipped++;

                continue;
            }

            $data = ['situation' => $situation->value];

            if ($situation === ScheduleSituation::Waiting) {
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

            $updatedIds[] = $schedule->id;
            $updated++;
        }

        if ($updated > 0) {
            Cache::forget("waiting_room:{$entityId}");
        }

        return ['updated' => $updated, 'skipped' => $skipped, 'updated_ids' => $updatedIds];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Private helpers
    // ─────────────────────────────────────────────────────────────────────────

    private function terminalSituationValues(): array
    {
        return array_map(
            fn (ScheduleSituation $s) => $s->value,
            array_filter(ScheduleSituation::cases(), fn ($s) => $s->isTerminal()),
        );
    }

    private function isDoubleBooked(string $doctorId, Carbon $dateTime, ?string $excludeScheduleId): bool
    {
        $query = DB::table('schedules')
            ->where('doctor_id', $doctorId)
            ->where('date_time', $dateTime->format('Y-m-d H:i:s'))
            ->whereNull('deleted_at')
            ->whereNotIn('situation', $this->terminalSituationValues());

        if ($excludeScheduleId) {
            $query->where('id', '!=', $excludeScheduleId);
        }

        return $query->exists();
    }

    private function dayName(int $dayOfWeek): string
    {
        return __('actions.weekdays')[$dayOfWeek] ?? '?';
    }
}
