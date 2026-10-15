<?php

declare(strict_types=1);

namespace App\Services\Schedules;

use App\Enums\ScheduleSituation;
use App\Models\{Doctor, DoctorWorkSchedule, Entity, Schedule, ScheduleBlock};
use App\Services\Dashboard\ClinicOperationsService;
use App\Services\Surgery\SurgeryScheduleService;
use Carbon\Carbon;
use Illuminate\Support\{Collection, Str};
use Illuminate\Support\Facades\Cache;

/**
 * Vagas do mês no calendário lateral da Agenda (por médico ou agregado):
 *
 *  - capacidade do dia = horários da escala do médico no intervalo dele
 *    (DoctorWorkSchedule + effectiveInterval) fora dos bloqueios;
 *  - ocupados = horários dessa grade com agendamento ativo (não cancelado nem
 *    falta) que NÃO é encaixe — encaixe não consome vaga da grade;
 *  - livres = capacidade − ocupados. Hoje conta só os horários que ainda não
 *    passaram; dias passados não têm estado (nada a marcar).
 *
 * Estados: `available` (com vagas), `few` (≤ % da clínica livre, padrão 20%),
 * `full` (sem vagas), `off` (sem expediente), `blocked` (expediente todo
 * bloqueado), `past`, `none` (ninguém no recorte tem escala — a agenda não
 * tem grade para contar). Médico sem escala nenhuma não entra na conta.
 * `doctor_days` detalha cada médico no mesmo cálculo, para explicar a soma.
 *
 * Cache curto por clínica/médico/mês com chave versionada: qualquer mudança
 * de agendamento, escala, bloqueio ou intervalo troca a versão da clínica
 * (bumpVersion, via eventos dos modelos — AppServiceProvider).
 */
class ScheduleAvailabilityService
{
    public const CACHE_TTL_SECONDS = 120;

    public const STATES = ['available', 'few', 'full', 'off', 'blocked', 'past', 'none'];

    /**
     * @return array{
     *     month: string,
     *     threshold: int,
     *     days: array<string, array{state: string, free: int, capacity: int}>,
     *     doctor_days: array<string, array<string, array{state: string, free: int, capacity: int}>>
     * }
     */
    public function month(string $entityId, Carbon $month, ?string $doctorId): array
    {
        $key = sprintf(
            'schedules.availability.v2.%s.%s.%s.%s',
            self::version($entityId),
            $entityId,
            $doctorId ?? 'all',
            $month->format('Y-m'),
        );

        return Cache::remember($key, self::CACHE_TTL_SECONDS, fn () => $this->compute($entityId, $month->copy()->startOfMonth(), $doctorId));
    }

    public static function version(string $entityId): string
    {
        return (string) Cache::get(self::versionKey($entityId), '0');
    }

    /** Invalida as vagas em cache da clínica (todos os médicos e meses). */
    public static function bumpVersion(?string $entityId): void
    {
        if (blank($entityId)) {
            return;
        }

        Cache::put(self::versionKey((string) $entityId), (string) Str::ulid(), now()->addDays(2));
    }

    private static function versionKey(string $entityId): string
    {
        return "schedules.availability.version.{$entityId}";
    }

    /**
     * @return array{
     *     month: string,
     *     threshold: int,
     *     days: array<string, array{state: string, free: int, capacity: int}>,
     *     doctor_days: array<string, array<string, array{state: string, free: int, capacity: int}>>
     * }
     */
    private function compute(string $entityId, Carbon $start, ?string $doctorId): array
    {
        $entity    = Entity::query()->find($entityId);
        $threshold = (int) ($entity?->few_slots_percent ?? 20);
        $end       = $start->copy()->addMonth();

        $doctors = Doctor::query()
            ->join('entity_users', 'entity_users.id', '=', 'doctors.entity_user_id')
            ->where('entity_users.entity_id', $entityId)
            ->whereNull('doctors.deleted_at')
            ->when($doctorId, fn ($q) => $q->where('doctors.id', $doctorId), fn ($q) => $q->where('doctors.active', true))
            ->select('doctors.*')
            ->get();

        $ids       = $doctors->pluck('id')->map(fn ($id) => (string) $id)->all();
        $intervals = $doctors->mapWithKeys(fn (Doctor $d) => [(string) $d->id => max(5, (int) ($d->schedule_interval ?? $entity?->schedule_interval ?? 15))]);

        /** @var Collection<string, Collection<int, Collection<int, DoctorWorkSchedule>>> $ranges médico → dia da semana → faixas */
        $ranges = DoctorWorkSchedule::query()
            ->whereIn('doctor_id', $ids)
            ->orderBy('starts_at')
            ->get()
            ->groupBy(fn (DoctorWorkSchedule $r) => (string) $r->doctor_id)
            ->map(fn (Collection $rows) => $rows->groupBy('day_of_week'));

        $blocks = ScheduleBlock::query()
            ->whereIn('doctor_id', $ids)
            ->where('starts_at', '<', $end)
            ->where('ends_at', '>', $start)
            ->get()
            ->groupBy(fn (ScheduleBlock $b) => (string) $b->doctor_id);

        // Cirurgias agendadas (cirurgião ou equipe) tiram o horário da grade. Ficam
        // à parte dos bloqueios do médico: o bloqueio vale pelo INÍCIO do horário
        // (como isBlocked()), a cirurgia vale pelo INTERVALO inteiro da consulta
        // (início + intervalo do médico, como surgeryOverlapError() e o seletor de
        // horários) — uma cirurgia às 09:10 tira o horário das 09:00–09:15 mesmo
        // que o início dele fique fora da janela.
        /** @var array<string, list<ScheduleBlock>> $surgeries médico → janelas cirúrgicas (não persistidas) */
        $surgeries = app(SurgeryScheduleService::class)->windowsByDoctor($ids, $start, $end);

        $occupied = Schedule::query()
            ->where('entity_id', $entityId)
            ->whereIn('doctor_id', $ids)
            ->where('date_time', '>=', $start)
            ->where('date_time', '<', $end)
            ->where('is_fit_in', false)
            ->whereNull('origin') // pronto atendimento: fora da grade, não ocupa vaga
            ->whereNotIn('situation', ClinicOperationsService::values([ScheduleSituation::NoShow, ScheduleSituation::Cancelled]))
            ->get(['doctor_id', 'date_time'])
            ->mapWithKeys(fn (Schedule $s) => [$s->doctor_id . '|' . $s->date_time->format('Y-m-d H:i') => true]);

        $now        = now();
        $today      = $now->copy()->startOfDay();
        $hasGrid    = $ranges->isNotEmpty();
        $days       = [];
        $doctorDays = [];

        for ($day = $start->copy(); $day->lt($end); $day->addDay()) {
            $date = $day->toDateString();

            if ($day->lt($today) || ! $hasGrid) {
                $days[$date] = ['state' => $hasGrid ? 'past' : 'none', 'free' => 0, 'capacity' => 0];

                foreach ($ids as $id) {
                    $doctorDays[$id][$date] = ['state' => $ranges->has($id) ? 'past' : 'none', 'free' => 0, 'capacity' => 0];
                }

                continue;
            }

            $capacity = 0;
            $free     = 0;
            $working  = 0;
            $raw      = 0;

            foreach ($ids as $id) {
                $doctorRanges = $ranges->get($id);

                if ($doctorRanges === null) {
                    $doctorDays[$id][$date] = ['state' => 'none', 'free' => 0, 'capacity' => 0];

                    continue; // sem escala nenhuma: agenda sem grade, fora da conta
                }

                $dayRanges = $doctorRanges->get($day->dayOfWeek);

                if ($dayRanges === null || $dayRanges->isEmpty()) {
                    $doctorDays[$id][$date] = ['state' => 'off', 'free' => 0, 'capacity' => 0];

                    continue;
                }

                $working++;
                $doctorBlocks    = $blocks->get($id, collect());
                $doctorSurgeries = collect($surgeries[$id] ?? []);
                $doctorRaw       = 0;
                $doctorCapacity  = 0;
                $doctorFree      = 0;

                foreach ($dayRanges as $range) {
                    $slot  = Carbon::parse($date . ' ' . $range->starts_at);
                    $close = Carbon::parse($date . ' ' . $range->ends_at);

                    for (; $slot->lt($close); $slot->addMinutes($intervals[$id])) {
                        if ($slot->lte($now)) {
                            continue; // hoje: horário que já passou não é vaga
                        }

                        $raw++;
                        $doctorRaw++;

                        $slotEnd = $slot->copy()->addMinutes($intervals[$id]);

                        if ($doctorBlocks->contains(fn (ScheduleBlock $b) => $slot->gte($b->starts_at) && $slot->lt($b->ends_at))
                            || $doctorSurgeries->contains(fn (ScheduleBlock $w) => $slot->lt($w->ends_at) && $slotEnd->gt($w->starts_at))) {
                            continue;
                        }

                        $capacity++;
                        $doctorCapacity++;

                        if (! isset($occupied[$id . '|' . $slot->format('Y-m-d H:i')])) {
                            $free++;
                            $doctorFree++;
                        }
                    }
                }
                $doctorDays[$id][$date] = [
                    'state'    => $this->state(1, $doctorRaw, $doctorCapacity, $doctorFree, $threshold),
                    'free'     => $doctorFree,
                    'capacity' => $doctorCapacity,
                ];
            }

            $days[$date] = [
                'state'    => $this->state($working, $raw, $capacity, $free, $threshold),
                'free'     => $free,
                'capacity' => $capacity,
            ];
        }

        return ['month' => $start->format('Y-m'), 'threshold' => $threshold, 'days' => $days, 'doctor_days' => $doctorDays];
    }

    private function state(int $working, int $raw, int $capacity, int $free, int $threshold): string
    {
        return match (true) {
            $working === 0                        => 'off',
            $raw === 0                            => 'past',    // hoje, expediente já encerrado
            $capacity === 0                       => 'blocked',
            $free === 0                           => 'full',
            $free * 100 <= $capacity * $threshold => 'few',
            default                               => 'available',
        };
    }
}
