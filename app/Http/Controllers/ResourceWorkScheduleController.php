<?php

namespace App\Http\Controllers;

use App\Models\{ClinicResource, ResourceBlock, ResourceWorkSchedule};
use Illuminate\Http\{JsonResponse, Request};
use Illuminate\Support\Facades\{DB, Lang};
use Inertia\{Inertia, Response as InertiaResponse};

/**
 * Escala (dias/horários) e bloqueios de sala/equipamento. A Agenda já validava
 * os dois (ScheduleService::isResourceWithinSchedule/isResourceBlocked), mas
 * não havia tela: index() é a página "Escala e bloqueios", aberta pela ação
 * da linha em Configurações › Recursos. Rotas no grupo
 * entity.role:admin,doctor,secretary; recurso sempre da clínica da sessão.
 */
class ResourceWorkScheduleController extends Controller
{
    /**
     * Página "Escala e bloqueios" do recurso.
     */
    public function index(string $resourceId): InertiaResponse
    {
        $resource = $this->findResource($resourceId);

        return Inertia::render('Panel/Settings/Resources/WorkSchedule', [
            'resource' => [
                'id'         => $resource->id,
                'code'       => $resource->code,
                'name'       => $resource->name,
                'type'       => $resource->type,
                'type_label' => Lang::has('resource_schedule.resource_types.' . $resource->type)
                    ? __('resource_schedule.resource_types.' . $resource->type)
                    : $resource->typeLabel(),
                'active' => (bool) $resource->active,
            ],
            'days'       => $this->buildDays($resource),
            'blocks'     => $this->buildBlocks($resource),
            'blockTypes' => collect(ResourceBlock::typeLabels())
                ->map(fn (string $label, string $value) => ['value' => $value, 'label' => $label])
                ->values(),
            'urls' => [
                'sync'          => route('panel.resources.work-schedule.sync', $resource->id),
                'store_block'   => route('panel.resources.blocks.store', $resource->id),
                'destroy_block' => url("panel/resources/{$resource->id}/blocks"),
                'back'          => route('panel.setting.resources.index'),
            ],
            'breadcrumbs' => [
                ['label' => __('actions.sidemenu.dashboard'), 'url' => route('panel.dashboard'), 'active' => false],
                ['label' => __('actions.sidemenu.clinic_resources'), 'url' => route('panel.setting.resources.index'), 'active' => false],
                ['label' => __('resource_schedule.breadcrumb'), 'url' => '#', 'active' => true],
            ],
            'weekdays' => array_values((array) __('actions.weekdays')),
            't'        => trans('resource_schedule'),
        ]);
    }

    /**
     * Return all work schedule data as JSON (for the modal).
     */
    public function data(string $resourceId): JsonResponse
    {
        $resource = $this->findResource($resourceId);

        $existingByDay = ResourceWorkSchedule::where('resource_id', $resource->id)
            ->orderBy('day_of_week')
            ->orderBy('starts_at')
            ->get()
            ->groupBy('day_of_week');

        $dayNames = __('actions.weekdays');

        $days = [];

        for ($i = 0; $i <= 6; $i++) {
            $ranges = $existingByDay->get($i, collect())
                ->map(fn ($s) => [
                    'starts_at' => substr($s->starts_at, 0, 5),
                    'ends_at'   => substr($s->ends_at, 0, 5),
                ])
                ->values()
                ->toArray();

            $days[] = [
                'day'    => $i,
                'name'   => $dayNames[$i],
                'active' => count($ranges) > 0,
                'ranges' => count($ranges) > 0 ? $ranges : [['starts_at' => '08:00', 'ends_at' => '18:00']],
            ];
        }

        $blocks = ResourceBlock::where('resource_id', $resource->id)
            ->where('ends_at', '>=', now())
            ->orderBy('starts_at')
            ->get()
            ->map(fn ($b) => [
                'id'         => $b->id,
                'starts_at'  => $b->starts_at->format('d/m/Y H:i'),
                'ends_at'    => $b->ends_at->format('d/m/Y H:i'),
                'reason'     => $b->reason,
                'type_label' => $b->typeLabel(),
            ])
            ->values();

        return response()->json([
            'resource' => [
                'id'         => $resource->id,
                'name'       => $resource->name,
                'type'       => $resource->type,
                'type_label' => $resource->typeLabel(),
            ],
            'days'               => $days,
            'blocks'             => $blocks,
            'sync_url'           => url("panel/resources/{$resource->id}/work-schedule"),
            'store_block_url'    => url("panel/resources/{$resource->id}/blocks"),
            'destroy_block_base' => url("panel/resources/{$resource->id}/blocks"),
        ]);
    }

    /**
     * Replace all work schedule entries for the resource (full sync).
     */
    public function sync(Request $request, string $resourceId): JsonResponse
    {
        $resource = $this->findResource($resourceId);

        $request->validate([
            'days'                      => ['required', 'array'],
            'days.*.day'                => ['required', 'integer', 'min:0', 'max:6'],
            'days.*.active'             => ['required', 'boolean'],
            'days.*.ranges'             => ['required', 'array'],
            'days.*.ranges.*.starts_at' => ['required', 'date_format:H:i'],
            'days.*.ranges.*.ends_at'   => ['required', 'date_format:H:i'],
        ]);

        // Faixa invertida era descartada em silêncio e faixa repetida no mesmo
        // dia estourava o índice único (rws_resource_day_start_unique) com
        // 500: agora 422 legível, nada é gravado.
        if ($error = $this->rangesError($request->input('days'))) {
            return response()->json(['message' => $error, 'errors' => ['days' => [$error]]], 422);
        }

        DB::transaction(function () use ($resource, $request) {
            ResourceWorkSchedule::where('resource_id', $resource->id)->delete();

            foreach ($request->input('days') as $dayData) {
                if (! $dayData['active']) {
                    continue;
                }

                foreach ($dayData['ranges'] as $range) {
                    ResourceWorkSchedule::create([
                        'resource_id' => $resource->id,
                        'day_of_week' => $dayData['day'],
                        'starts_at'   => $range['starts_at'] . ':00',
                        'ends_at'     => $range['ends_at'] . ':00',
                    ]);
                }
            }
        });

        return response()->json(['message' => __('actions.resource_schedule_saved')]);
    }

    /**
     * Store a new resource block (maintenance, holiday, etc).
     */
    public function storeBlock(Request $request, string $resourceId): JsonResponse
    {
        $resource = $this->findResource($resourceId);

        $validated = $request->validate([
            'starts_at' => ['required', 'date'],
            'ends_at'   => ['required', 'date', 'after:starts_at'],
            'reason'    => ['nullable', 'string', 'max:255'],
            'type'      => ['required', 'in:absence,holiday,meeting,other'],
        ]);

        $block = ResourceBlock::create(array_merge($validated, ['resource_id' => $resource->id]));

        return response()->json([
            'message' => __('actions.block_created'),
            'data'    => $this->blockRow($block),
        ], 201);
    }

    /**
     * Delete a resource block.
     */
    public function destroyBlock(string $resourceId, string $blockId): JsonResponse
    {
        $resource = $this->findResource($resourceId);

        $block = ResourceBlock::where('id', $blockId)
            ->where('resource_id', $resource->id)
            ->firstOrFail();

        $block->delete();

        return response()->json(['message' => __('actions.block_deleted')]);
    }

    /** @return list<array{day: int, name: string, active: bool, ranges: list<array{starts_at: string, ends_at: string}>}> */
    private function buildDays(ClinicResource $resource): array
    {
        $existingByDay = ResourceWorkSchedule::where('resource_id', $resource->id)
            ->orderBy('day_of_week')
            ->orderBy('starts_at')
            ->get()
            ->groupBy('day_of_week');

        $dayNames = __('actions.weekdays');
        $days     = [];

        for ($i = 0; $i <= 6; $i++) {
            $ranges = $existingByDay->get($i, collect())
                ->map(fn ($s) => ['starts_at' => substr($s->starts_at, 0, 5), 'ends_at' => substr($s->ends_at, 0, 5)])
                ->values()
                ->toArray();

            $days[] = [
                'day'    => $i,
                'name'   => $dayNames[$i],
                'active' => count($ranges) > 0,
                'ranges' => count($ranges) > 0 ? $ranges : [['starts_at' => '08:00', 'ends_at' => '18:00']],
            ];
        }

        return $days;
    }

    private function buildBlocks(ClinicResource $resource): array
    {
        return ResourceBlock::where('resource_id', $resource->id)
            ->where('ends_at', '>=', now())
            ->orderBy('starts_at')
            ->get()
            ->map(fn (ResourceBlock $b) => $this->blockRow($b))
            ->values()
            ->all();
    }

    private function blockRow(ResourceBlock $block): array
    {
        return [
            'id'         => $block->id,
            'starts_at'  => $block->starts_at->format('d/m/Y H:i'),
            'ends_at'    => $block->ends_at->format('d/m/Y H:i'),
            'reason'     => $block->reason,
            'type'       => $block->type,
            'type_label' => $block->typeLabel(),
        ];
    }

    /**
     * Primeira inconsistência das faixas dos dias ativos (fim antes do
     * início, início repetido ou sobreposição no mesmo dia) — null se ok.
     *
     * @param array<int, array{day: int, active: bool, ranges: list<array{starts_at: string, ends_at: string}>}> $days
     */
    private function rangesError(array $days): ?string
    {
        $dayNames = __('actions.weekdays');

        foreach ($days as $dayData) {
            if (! $dayData['active']) {
                continue;
            }

            $day    = $dayNames[(int) $dayData['day']] ?? (string) $dayData['day'];
            $ranges = collect($dayData['ranges'])->sortBy('starts_at')->values();

            foreach ($ranges as $i => $range) {
                if ($range['ends_at'] <= $range['starts_at']) {
                    return __('resource_schedule.range_invalid', ['day' => $day, 'start' => $range['starts_at'], 'end' => $range['ends_at']]);
                }

                $previous = $ranges[$i - 1] ?? null;

                if ($previous === null) {
                    continue;
                }

                if ($previous['starts_at'] === $range['starts_at']) {
                    return __('resource_schedule.range_duplicate', ['day' => $day, 'start' => $range['starts_at']]);
                }

                if ($range['starts_at'] < $previous['ends_at']) {
                    return __('resource_schedule.range_overlap', [
                        'day'    => $day,
                        'first'  => $previous['starts_at'] . '–' . $previous['ends_at'],
                        'second' => $range['starts_at'] . '–' . $range['ends_at'],
                    ]);
                }
            }
        }

        return null;
    }

    /**
     * Find a resource scoped to the current entity (tenant guard).
     */
    private function findResource(string $resourceId): ClinicResource
    {
        return ClinicResource::where('id', $resourceId)
            ->where('entity_id', session('selected_entity_id'))
            ->whereNull('deleted_at')
            ->firstOrFail();
    }
}
