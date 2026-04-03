<?php

namespace App\Http\Controllers;

use App\Models\{ClinicResource, ResourceBlock, ResourceWorkSchedule};
use Illuminate\Http\{JsonResponse, Request};
use Illuminate\Support\Facades\DB;

class ResourceWorkScheduleController extends Controller
{
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

        DB::transaction(function () use ($resource, $request) {
            ResourceWorkSchedule::where('resource_id', $resource->id)->delete();

            foreach ($request->input('days') as $dayData) {
                if (!$dayData['active']) {
                    continue;
                }

                foreach ($dayData['ranges'] as $range) {
                    if ($range['ends_at'] <= $range['starts_at']) {
                        continue;
                    }

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
            'data'    => [
                'id'         => $block->id,
                'starts_at'  => $block->starts_at->format('d/m/Y H:i'),
                'ends_at'    => $block->ends_at->format('d/m/Y H:i'),
                'reason'     => $block->reason,
                'type_label' => $block->typeLabel(),
            ],
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
