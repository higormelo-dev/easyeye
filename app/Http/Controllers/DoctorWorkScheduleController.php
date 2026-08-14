<?php

namespace App\Http\Controllers;

use App\Models\{Doctor, DoctorWorkSchedule, ScheduleBlock};
use Illuminate\Http\{JsonResponse, Request};
use Illuminate\Support\Facades\{DB, Storage, Vite};
use Inertia\{Inertia, Response as InertiaResponse};

class DoctorWorkScheduleController extends Controller
{
    /**
     * Return all work schedule data as JSON (for the modal).
     */
    public function data(string $doctorId): JsonResponse
    {
        $doctor = $this->findDoctor($doctorId);

        $existingByDay = DoctorWorkSchedule::where('doctor_id', $doctor->id)
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
                'ranges' => count($ranges) > 0 ? $ranges : [['starts_at' => '08:00', 'ends_at' => '12:00']],
            ];
        }

        $userId    = $doctor->entityUser->user_id;
        $photoPath = 'users/' . $userId . '.jpg';
        $photoUrl  = Storage::disk('public')->exists($photoPath)
            ? Storage::disk('public')->url($photoPath)
            : Vite::asset('resources/img/system/team.png');

        $blocks = ScheduleBlock::where('doctor_id', $doctor->id)
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
            'doctor' => [
                'id'        => $doctor->id,
                'name'      => $doctor->entityUser->user->name,
                'record'    => $doctor->record,
                'color'     => $doctor->color ?: '#6c757d',
                'photo_url' => $photoUrl,
            ],
            'days'               => $days,
            'interval'           => $doctor->schedule_interval,
            'entity_interval'    => $doctor->entityUser->entity->schedule_interval ?? 15,
            'blocks'             => $blocks,
            'sync_url'           => url("panel/doctors/{$doctor->id}/work-schedule"),
            'store_block_url'    => url("panel/doctors/{$doctor->id}/blocks"),
            'destroy_block_base' => url("panel/doctors/{$doctor->id}/blocks"),
        ]);
    }

    /**
     * Show the work schedule management page for a doctor.
     */
    public function index(string $doctorId): InertiaResponse
    {
        $doctor = $this->findDoctor($doctorId);

        $existingByDay = DoctorWorkSchedule::where('doctor_id', $doctor->id)
            ->orderBy('day_of_week')
            ->orderBy('starts_at')
            ->get()
            ->groupBy('day_of_week');

        $dayNames = __('actions.weekdays');
        $days     = [];

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
                'ranges' => count($ranges) > 0 ? $ranges : [['starts_at' => '08:00', 'ends_at' => '12:00']],
            ];
        }

        $blocks = ScheduleBlock::where('doctor_id', $doctor->id)
            ->where('ends_at', '>=', now())
            ->orderBy('starts_at')
            ->get()
            ->map(fn ($b) => [
                'id'         => $b->id,
                'starts_at'  => $b->starts_at->format('d/m/Y H:i'),
                'ends_at'    => $b->ends_at->format('d/m/Y H:i'),
                'reason'     => $b->reason,
                'type'       => $b->type,
                'type_label' => $b->typeLabel(),
            ])
            ->values();

        $userId    = $doctor->entityUser->user_id;
        $photoPath = 'users/' . $userId . '.jpg';
        $photoUrl  = Storage::disk('public')->exists($photoPath)
            ? Storage::disk('public')->url($photoPath)
            : Vite::asset('resources/img/system/team.png');

        return Inertia::render('Panel/Doctors/WorkSchedule', [
            'doctor' => [
                'id'        => $doctor->id,
                'code'      => $doctor->code,
                'name'      => $doctor->entityUser->user->name,
                'record'    => $doctor->record,
                'color'     => $doctor->color ?: '#6c757d',
                'photo_url' => $photoUrl,
            ],
            'days'           => $days,
            'interval'       => $doctor->schedule_interval,
            'entityInterval' => $doctor->entityUser->entity->schedule_interval ?? 15,
            'blocks'         => $blocks,
            'urls'           => [
                'sync'          => route('panel.doctors.work-schedule.sync', $doctor->id),
                'store_block'   => route('panel.doctors.blocks.store', $doctor->id),
                'destroy_block' => url("panel/doctors/{$doctor->id}/blocks"),
                'back'          => route('panel.doctors.index'),
            ],
            't' => trans('actions'),
        ]);
    }

    /**
     * Replace all work schedule entries for the doctor (full sync).
     */
    public function sync(Request $request, string $doctorId): JsonResponse
    {
        $doctor = $this->findDoctor($doctorId);

        $request->validate([
            'schedule_interval'         => ['nullable', 'integer', 'min:5', 'max:120'],
            'days'                      => ['required', 'array'],
            'days.*.day'                => ['required', 'integer', 'min:0', 'max:6'],
            'days.*.active'             => ['required', 'boolean'],
            'days.*.ranges'             => ['required', 'array'],
            'days.*.ranges.*.starts_at' => ['required', 'date_format:H:i'],
            'days.*.ranges.*.ends_at'   => ['required', 'date_format:H:i'],
        ]);

        DB::transaction(function () use ($doctor, $request) {
            $doctor->update(['schedule_interval' => $request->input('schedule_interval')]);

            DoctorWorkSchedule::where('doctor_id', $doctor->id)->delete();

            foreach ($request->input('days') as $dayData) {
                if (! $dayData['active']) {
                    continue;
                }

                foreach ($dayData['ranges'] as $range) {
                    if ($range['ends_at'] <= $range['starts_at']) {
                        continue;
                    }

                    DoctorWorkSchedule::create([
                        'doctor_id'   => $doctor->id,
                        'day_of_week' => $dayData['day'],
                        'starts_at'   => $range['starts_at'] . ':00',
                        'ends_at'     => $range['ends_at'] . ':00',
                    ]);
                }
            }
        });

        return response()->json(['message' => __('actions.work_schedule_saved')]);
    }

    /**
     * Store a new schedule block (absence, holiday, etc).
     */
    public function storeBlock(Request $request, string $doctorId): JsonResponse
    {
        $doctor = $this->findDoctor($doctorId);

        $validated = $request->validate([
            'starts_at' => ['required', 'date'],
            'ends_at'   => ['required', 'date', 'after:starts_at'],
            'reason'    => ['nullable', 'string', 'max:255'],
            'type'      => ['required', 'in:absence,holiday,meeting,other'],
        ]);

        $block = ScheduleBlock::create(array_merge($validated, ['doctor_id' => $doctor->id]));

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
     * Delete a schedule block.
     */
    public function destroyBlock(string $doctorId, string $blockId): JsonResponse
    {
        $doctor = $this->findDoctor($doctorId);

        $block = ScheduleBlock::where('id', $blockId)
            ->where('doctor_id', $doctor->id)
            ->firstOrFail();

        $block->delete();

        return response()->json(['message' => __('actions.block_deleted')]);
    }

    /**
     * Find a doctor scoped to the current entity (tenant guard).
     * Eager-loads relationships needed by the work-schedule view.
     */
    private function findDoctor(string $doctorId): Doctor
    {
        return Doctor::query()
            ->with(['entityUser.user', 'entityUser.entity'])
            ->join('entity_users', 'entity_users.id', '=', 'doctors.entity_user_id')
            ->where('entity_users.entity_id', session('selected_entity_id'))
            ->where('doctors.id', $doctorId)
            ->select('doctors.*')
            ->firstOrFail();
    }
}
