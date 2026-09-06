<?php

namespace App\Http\Controllers;

use App\Models\ScheduleEvent;
use Closure;
use Illuminate\Http\{JsonResponse, Request};
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class ScheduleEventsController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $entityId = session('selected_entity_id');

        $request->validate([
            'doctor_id' => ['nullable', 'uuid', $this->doctorBelongsToEntityRule()],
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
            'doctor_id' => ['nullable', 'uuid', $this->doctorBelongsToEntityRule()],
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

    /**
     * Achado de segurança (auditoria panel.* IDOR — doctor_id via request
     * body): Doctor não tem entity_id direto (só via entity_user_id ->
     * entity_users.entity_id), então 'uuid'/Rule::exists('doctors', 'id')
     * sozinho deixa passar médico de OUTRA clínica. Mesma closure usada em
     * ScheduleRequest::rules() — extraída aqui para compartilhar entre
     * store() e update() e evitar drift entre os dois.
     */
    private function doctorBelongsToEntityRule(): Closure
    {
        return function ($attribute, $value, $fail) {
            $exists = DB::table('doctors')
                ->join('entity_users', 'entity_users.id', '=', 'doctors.entity_user_id')
                ->where('doctors.id', $value)
                ->where('entity_users.entity_id', session()->get('selected_entity_id'))
                ->whereNull('doctors.deleted_at')
                ->exists();

            if (! $exists) {
                $fail(__('validation.custom.schedule.doctor_not_found'));
            }
        };
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
