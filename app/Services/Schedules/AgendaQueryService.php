<?php

declare(strict_types=1);

namespace App\Services\Schedules;

use App\Models\{ResourceBlock, Schedule, ScheduleBlock, ScheduleEvent};
use App\Services\Dashboard\ClinicOperationsService;
use App\Support\ScheduleShift;
use Carbon\{Carbon, CarbonInterface};
use Illuminate\Database\Eloquent\{Builder, Collection};
use Illuminate\Support\Collection as SupportCollection;
use Illuminate\Support\Facades\DB;

/**
 * Consultas da Agenda a partir dos filtros (AgendaFilters): o recorte
 * (clínica + período + médico + turno + especialidade + sala), os contadores
 * do topo (mesma conta do Dashboard — ClinicOperationsService::dayCounters),
 * a lista filtrada pelo chip e os bloqueios/compromissos do chip
 * "Bloqueados". Usado pela tela, pelo calendário e pela impressão.
 */
class AgendaQueryService
{
    public function __construct(private readonly ClinicOperationsService $operations)
    {
    }

    /**
     * Recorte SEM chip de situação nem busca (os contadores contam isto).
     *
     * @return Builder<Schedule>
     */
    public function scope(AgendaFilters $filters): Builder
    {
        $query = Schedule::query()
            ->where('schedules.entity_id', $filters->entityId)
            ->where('schedules.date_time', '>=', $filters->from)
            ->where('schedules.date_time', '<', $filters->to);

        if ($filters->doctorId) {
            $query->where('schedules.doctor_id', $filters->doctorId);
        }

        $this->applyShift($query, 'schedules.date_time', $filters->shift());

        if ($filters->specialty !== null) {
            $query->where('schedules.specialty_area', $filters->specialty);
        }

        if ($filters->resourceId) {
            $query->whereExists(fn ($sub) => $sub->select(DB::raw(1))
                ->from('schedule_resources')
                ->whereColumn('schedule_resources.schedule_id', 'schedules.id')
                ->where('schedule_resources.resource_id', $filters->resourceId));
        }

        return $query;
    }

    /**
     * Contadores do topo: os do Dashboard + "Bloqueados" (bloqueios e
     * compromissos no período).
     *
     * @return array<string, int|null>
     */
    public function counters(AgendaFilters $filters): array
    {
        return [
            ...$this->operations->dayCounters($this->scope($filters)),
            'blocked'     => $this->events($filters, true)->count() + $this->blocks($filters)->count(),
            'urgent_care' => $this->urgentCareQueued($filters),
        ];
    }

    /**
     * Fila do pronto atendimento AGORA (mesma conta do Dashboard) — só quando
     * o período da Agenda inclui hoje (null = outro período: o chip some);
     * respeita o médico do filtro/escopo (dele + sem médico preferido). Não é
     * chip de filtro da lista: leva à tela da fila.
     */
    private function urgentCareQueued(AgendaFilters $filters): ?int
    {
        $today = now()->startOfDay();

        if ($filters->from->gt($today) || $filters->to->lte($today)) {
            return null;
        }

        return $this->operations->urgentCareQueued($filters->entityId, $filters->doctorId);
    }

    /**
     * Agendamentos da lista/calendário/impressão (chip + busca aplicados).
     *
     * @return Collection<int, Schedule>
     */
    public function schedules(AgendaFilters $filters): Collection
    {
        if ($filters->status === 'blocked') {
            return new Collection();
        }

        $query = $this->scope($filters)
            ->with(['doctor.entityUser.user', 'covenant', 'patient.person', 'patient.covenantPlan', 'visitType', 'resources'])
            ->leftJoin('patients', 'patients.id', '=', 'schedules.patient_id')
            ->leftJoin('people', 'people.id', '=', 'patients.person_id')
            ->select('schedules.*');

        ClinicOperationsService::applyCounterFilter($query, $filters->status);

        if ($filters->search !== '') {
            $search = $filters->search;
            $query->where(function ($q) use ($search) {
                $q->whereLikeUnaccent('schedules.full_name', $search)
                    ->orWhereLikeUnaccent('people.full_name', $search);
            });
        }

        return $query->orderBy('schedules.date_time')->get();
    }

    /**
     * Compromissos (ScheduleEvent) do período. Com chip de situação, só no
     * chip "Bloqueados" (`$forBlocked`) — os demais chips mostram só
     * agendamentos.
     *
     * @return Collection<int, ScheduleEvent>
     */
    public function events(AgendaFilters $filters, bool $forBlocked = false): Collection
    {
        if (! $forBlocked && $filters->status !== null && $filters->status !== 'blocked') {
            return new Collection();
        }

        $query = ScheduleEvent::with('doctor.entityUser.user')
            ->where('entity_id', $filters->entityId)
            ->where('starts_at', '>=', $filters->from)
            ->where('starts_at', '<', $filters->to)
            ->whereNull('deleted_at');

        if ($filters->doctorId) {
            $query->where(fn ($q) => $q->where('doctor_id', $filters->doctorId)->orWhereNull('doctor_id'));
        }

        $this->applyShift($query, 'starts_at', $filters->shift());

        return $query->orderBy('starts_at')->get();
    }

    /**
     * Bloqueios do período (médico e sala/equipamento), já como linhas da
     * Agenda. Médico: os do escopo (perfil médico, só os dele); sala: as
     * ativas da clínica, ou só a do filtro.
     *
     * @return SupportCollection<int, array<string, mixed>>
     */
    public function blocks(AgendaFilters $filters): SupportCollection
    {
        $doctorBlocks = ScheduleBlock::query()
            ->with('doctor.entityUser.user')
            ->join('doctors', 'doctors.id', '=', 'schedule_blocks.doctor_id')
            ->join('entity_users', 'entity_users.id', '=', 'doctors.entity_user_id')
            ->where('entity_users.entity_id', $filters->entityId)
            ->whereNull('doctors.deleted_at')
            ->when($filters->doctorId, fn ($q) => $q->where('schedule_blocks.doctor_id', $filters->doctorId))
            ->where('schedule_blocks.starts_at', '<', $filters->to)
            ->where('schedule_blocks.ends_at', '>', $filters->from)
            ->select('schedule_blocks.*')
            ->get()
            ->map(fn (ScheduleBlock $block) => $this->blockRow(
                id: 'doctor-' . $block->id,
                startsAt: $block->starts_at,
                endsAt: $block->ends_at,
                title: $block->reason ?: __('actions.block_type_' . $block->type),
                typeLabel: __('actions.block_type_' . $block->type),
                owner: $block->doctor?->entityUser?->user?->name,
                ownerKind: 'doctor',
                filters: $filters,
            ));

        $resourceBlocks = ResourceBlock::query()
            ->with('resource')
            ->join('clinic_resources', 'clinic_resources.id', '=', 'resource_blocks.resource_id')
            ->where('clinic_resources.entity_id', $filters->entityId)
            ->where('clinic_resources.active', true)
            ->whereNull('clinic_resources.deleted_at')
            ->when($filters->resourceId, fn ($q) => $q->where('resource_blocks.resource_id', $filters->resourceId))
            ->where('resource_blocks.starts_at', '<', $filters->to)
            ->where('resource_blocks.ends_at', '>', $filters->from)
            ->select('resource_blocks.*')
            ->get()
            ->map(fn (ResourceBlock $block) => $this->blockRow(
                id: 'resource-' . $block->id,
                startsAt: $block->starts_at,
                endsAt: $block->ends_at,
                title: $block->reason ?: $block->typeLabel(),
                typeLabel: $block->typeLabel(),
                owner: $block->resource?->name,
                ownerKind: 'resource',
                filters: $filters,
            ));

        return $doctorBlocks->toBase()
            ->merge($resourceBlocks->toBase())
            ->filter(fn (array $row) => $this->overlapsShift($row['_starts'], $row['_ends'], $filters))
            ->map(fn (array $row) => array_diff_key($row, ['_starts' => true, '_ends' => true]))
            ->sortBy('sort_time')
            ->values();
    }

    /** Turno da URL (`bout`) como filtro de hora numa coluna de data/hora. */
    private function applyShift(Builder $query, string $column, ?string $shift): void
    {
        match ($shift) {
            ScheduleShift::MORNING   => $query->whereTime($column, '<', '13:00:00'),
            ScheduleShift::AFTERNOON => $query->whereTime($column, '>=', '13:00:00')->whereTime($column, '<', '18:00:00'),
            ScheduleShift::EVENING   => $query->whereTime($column, '>=', '18:00:00'),
            default                  => null,
        };
    }

    /** Bloqueio entra no turno filtrado se cobre algum pedaço dele em algum dia do período. */
    private function overlapsShift(CarbonInterface $startsAt, CarbonInterface $endsAt, AgendaFilters $filters): bool
    {
        $shift = $filters->shift();

        if ($shift === null) {
            return true;
        }

        for ($day = $filters->from->copy(); $day->lt($filters->to); $day->addDay()) {
            [$start, $end] = ScheduleShift::bounds($day, $shift);

            if ($startsAt->lt($end) && $endsAt->gt($start)) {
                return true;
            }
        }

        return false;
    }

    /** @return array<string, mixed> */
    private function blockRow(
        string $id,
        CarbonInterface $startsAt,
        CarbonInterface $endsAt,
        string $title,
        string $typeLabel,
        ?string $owner,
        string $ownerKind,
        AgendaFilters $filters,
    ): array {
        // Bloqueio que começa antes do período (ex.: férias) aparece no início dele.
        $visibleStart = $startsAt->lt($filters->from) ? $filters->from->copy() : Carbon::parse($startsAt);
        $sameDay      = $startsAt->isSameDay($endsAt);

        return [
            'type'          => 'block',
            'id'            => $id,
            'sort_time'     => $visibleStart->toISOString(),
            'title'         => $title,
            'event_type'    => 'block',
            'type_label'    => $typeLabel,
            'owner_kind'    => $ownerKind,
            'doctor_name'   => $ownerKind === 'doctor' ? $owner : null,
            'resource_name' => $ownerKind === 'resource' ? $owner : null,
            'color'         => '#6c757d',
            'starts_at_fmt' => $sameDay ? $startsAt->format('H:i') : $this->dateTimeLabel($startsAt),
            'ends_at_fmt'   => $sameDay ? $endsAt->format('H:i') : $this->dateTimeLabel($endsAt),
            'ends_at_iso'   => Carbon::parse($endsAt)->toISOString(),
            'notes'         => null,
            '_starts'       => $startsAt,
            '_ends'         => $endsAt,
        ];
    }

    /** Data no idioma + hora (bloqueio de vários dias). */
    private function dateTimeLabel(CarbonInterface $at): string
    {
        return Carbon::parse($at)->locale(app()->getLocale())->isoFormat('L') . ' ' . $at->format('H:i');
    }
}
