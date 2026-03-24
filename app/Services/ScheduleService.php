<?php

namespace App\Services;

use App\Enums\ScheduleSituation;
use App\Models\{Doctor, DoctorWorkSchedule, Schedule, ScheduleBlock};
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

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
     * @param  string       $doctorId
     * @param  Carbon       $dateTime
     * @param  string|null  $excludeScheduleId  UUID of the record being updated (skips self-conflict)
     * @return string[]
     */
    public function validateSlot(string $doctorId, Carbon $dateTime, ?string $excludeScheduleId = null): array
    {
        $errors = [];

        // 1 ── Double-booking ────────────────────────────────────────────────
        if ($this->isDoubleBooked($doctorId, $dateTime, $excludeScheduleId)) {
            $errors[] = __('validation.custom.schedule.doctor_datetime_unique');
        }

        // 2 ── Work schedule ─────────────────────────────────────────────────
        if (! $this->isWithinWorkSchedule($doctorId, $dateTime)) {
            $dayName  = $this->dayName($dateTime->dayOfWeek);
            $errors[] = "Horário fora da escala de atendimento do médico ({$dayName} às {$dateTime->format('H:i')}).";

            // No point checking alignment when the day/time is already outside schedule
            return $errors;
        }

        // 3 ── Interval alignment ────────────────────────────────────────────
        if (! $this->isIntervalAligned($doctorId, $dateTime)) {
            $doctor   = Doctor::find($doctorId);
            $interval = $doctor ? $doctor->effectiveInterval() : 15;
            $errors[] = "Horário não corresponde a um slot válido. O intervalo do médico é de {$interval} minutos.";
        }

        // 4 ── Schedule block ────────────────────────────────────────────────
        if ($this->isBlocked($doctorId, $dateTime)) {
            $errors[] = 'Médico está bloqueado neste horário (ausência, feriado ou compromisso agendado).';
        }

        return $errors;
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
    public function getAvailableSlots(Doctor $doctor, Carbon $date): array
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
            array_filter(ScheduleSituation::cases(), fn ($s) => $s->isTerminal())
        );

        $booked = Schedule::where('doctor_id', $doctor->id)
            ->whereDate('date_time', $date->toDateString())
            ->whereNotIn('situation', $terminalValues)
            ->whereNull('deleted_at')
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
                    fn ($block) => $current->gte($block->starts_at) && $current->lt($block->ends_at)
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

    // ─────────────────────────────────────────────────────────────────────────
    // Private helpers
    // ─────────────────────────────────────────────────────────────────────────

    private function isDoubleBooked(string $doctorId, Carbon $dateTime, ?string $excludeScheduleId): bool
    {
        $query = DB::table('schedules')
            ->where('doctor_id', $doctorId)
            ->where('date_time', $dateTime->format('Y-m-d H:i:s'))
            ->whereNull('deleted_at');

        if ($excludeScheduleId) {
            $query->where('id', '!=', $excludeScheduleId);
        }

        return $query->exists();
    }

    private function dayName(int $dayOfWeek): string
    {
        return match ($dayOfWeek) {
            0 => 'Domingo',
            1 => 'Segunda-feira',
            2 => 'Terça-feira',
            3 => 'Quarta-feira',
            4 => 'Quinta-feira',
            5 => 'Sexta-feira',
            6 => 'Sábado',
        };
    }
}
