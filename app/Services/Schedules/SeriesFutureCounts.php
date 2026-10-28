<?php

declare(strict_types=1);

namespace App\Services\Schedules;

use App\Enums\ScheduleSituation;
use App\Models\Schedule;
use App\Services\ScheduleSeriesService;
use Illuminate\Support\Collection;

/**
 * Quantas sessões "Esta e as seguintes" moveria a partir de cada sessão da Agenda (`series_future_count`): ela mesma e
 * as elegíveis da série com data igual ou posterior — a mesma régua do lote (Agendado/Confirmado, a partir de agora).
 * Sessão que não é elegível não entra no mapa (a UI lê 0 e esconde a escolha).
 */
final class SeriesFutureCounts
{
    /**
     * Pura sobre a coleção recebida; quem chama já aplicou o escopo do perfil médico.
     *
     * @param Collection<int, Schedule> $sessions sessões de uma ou mais séries (basta `series_id`, `date_time` e `situation`)
     *
     * @return array<string, int> id da sessão → quantas se moveriam
     */
    public function of(Collection $sessions): array
    {
        $now      = now();
        $eligible = $sessions
            ->filter(fn (Schedule $s): bool => $s->series_id !== null
                && in_array($s->situation, ScheduleSeriesService::CANCELLABLE_SITUATIONS, true)
                && $s->date_time->gte($now))
            ->groupBy('series_id');

        $counts = [];

        foreach ($eligible as $members) {
            foreach ($members as $member) {
                $counts[(string) $member->id] = $members->filter(fn (Schedule $s): bool => $s->date_time->gte($member->date_time))->count();
            }
        }

        return $counts;
    }

    /**
     * Mapa de of() para as linhas de uma página da Agenda: UMA consulta traz as sessões elegíveis das séries da página
     * (a página pode ter só algumas sessões de cada série).
     *
     * @param Collection<int, Schedule> $pageSchedules
     *
     * @return array<string, int>
     */
    public function forPage(string $entityId, Collection $pageSchedules, ?string $doctorScope): array
    {
        $seriesIds = $pageSchedules->pluck('series_id')->filter()->unique()->values()->all();

        if ($seriesIds === []) {
            return [];
        }

        return $this->of(Schedule::query()
            ->where('entity_id', $entityId)
            ->whereIn('series_id', $seriesIds)
            ->where('date_time', '>=', now())
            ->whereIn('situation', array_map(fn (ScheduleSituation $s) => $s->value, ScheduleSeriesService::CANCELLABLE_SITUATIONS))
            ->when($doctorScope, fn ($q) => $q->where('doctor_id', $doctorScope))
            ->get(['id', 'series_id', 'date_time', 'situation']));
    }
}
