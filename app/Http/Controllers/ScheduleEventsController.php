<?php

namespace App\Http\Controllers;

use App\Models\{ScheduleEvent};
use Illuminate\Http\{JsonResponse, Request};
use Illuminate\Validation\Rule;

class ScheduleEventsController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $entityId = session('selected_entity_id');

        $request->validate([
            'doctor_id' => [
                'nullable',
                'uuid',
                Rule::exists('doctors', 'id')->where(function ($q) use ($entityId) {
                    $q->join('entity_users', 'entity_users.id', '=', 'doctors.entity_user_id')
                      ->where('entity_users.entity_id', $entityId)
                      ->whereNull('doctors.deleted_at');
                }),
            ],
            'title'     => ['required', 'string', 'max:150'],
            'type'      => ['required', 'string', Rule::in(array_keys(ScheduleEvent::$types))],
            'starts_at' => ['required', 'date'],
            'ends_at'   => ['required', 'date', 'after:starts_at'],
            'color'     => ['nullable', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'notes'     => ['nullable', 'string', 'max:500'],
        ]);

        $event = ScheduleEvent::create([
            'entity_id'  => $entityId,
            'doctor_id'  => $request->input('doctor_id'),
            'title'      => $request->input('title'),
            'type'       => $request->input('type'),
            'starts_at'  => $request->input('starts_at'),
            'ends_at'    => $request->input('ends_at'),
            'color'      => $request->input('color'),
            'notes'      => $request->input('notes'),
            'created_by' => session('selected_entity_user_id'),
        ]);

        return response()->json([
            'message' => 'Compromisso criado.',
            'data'    => $this->format($event),
        ], 201);
    }

    public function update(Request $request, ScheduleEvent $scheduleEvent): JsonResponse
    {
        $entityId = session('selected_entity_id');
        abort_if($scheduleEvent->entity_id !== $entityId, 403);

        $request->validate([
            'doctor_id' => ['nullable', 'uuid'],
            'title'     => ['required', 'string', 'max:150'],
            'type'      => ['required', 'string', Rule::in(array_keys(ScheduleEvent::$types))],
            'starts_at' => ['required', 'date'],
            'ends_at'   => ['required', 'date', 'after:starts_at'],
            'color'     => ['nullable', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'notes'     => ['nullable', 'string', 'max:500'],
        ]);

        $scheduleEvent->update($request->only(['doctor_id', 'title', 'type', 'starts_at', 'ends_at', 'color', 'notes']));

        return response()->json([
            'message' => 'Compromisso atualizado.',
            'data'    => $this->format($scheduleEvent->fresh()),
        ]);
    }

    public function destroy(ScheduleEvent $scheduleEvent): JsonResponse
    {
        abort_if($scheduleEvent->entity_id !== session('selected_entity_id'), 403);

        $scheduleEvent->delete();

        return response()->json(['message' => 'Compromisso removido.']);
    }

    private function format(ScheduleEvent $event): array
    {
        return [
            'id'          => $event->id,
            'doctor_id'   => $event->doctor_id,
            'doctor_name' => $event->doctor?->abbreviation ?? $event->doctor?->user_name ?? '',
            'title'       => $event->title,
            'type'        => $event->type,
            'type_label'  => $event->typeLabel(),
            'starts_at'   => $event->starts_at->format('Y-m-d\TH:i'),
            'ends_at'     => $event->ends_at->format('Y-m-d\TH:i'),
            'color'       => $event->color ?? '#6c757d',
            'notes'       => $event->notes,
        ];
    }
}
