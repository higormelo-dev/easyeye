<?php

namespace App\Http\Controllers;

use App\Enums\{ClientRule, ScheduleSituation};
use App\Http\Requests\ScheduleRequest;
use App\Models\{Covenant, Doctor, Schedule, VisitType};
use App\Services\ScheduleService;
use Carbon\Carbon;
use Illuminate\Http\{JsonResponse, Request};
use Illuminate\Support\Facades\Cache;

class SchedulesController extends Controller
{
    protected Schedule $model;

    protected ScheduleService $service;

    public function __construct(Schedule $schedule, ScheduleService $scheduleService)
    {
        $this->titleController = __('actions.sidemenu.schedules');
        $this->model           = $schedule;
        $this->service         = $scheduleService;
    }

    public function index()
    {
        $entityId     = session()->get('selected_entity_id');
        $loggedDoctor = $this->loggedDoctor();

        $doctors = $loggedDoctor
            ? collect([$loggedDoctor])
            : $this->doctorsByEntity($entityId);
        $covenants = Covenant::where(function ($q) use ($entityId) {
            $q->where('entity_id', $entityId)->orWhereNull('entity_id');
        })->where('active', true)->orderBy('name')->get();
        $visitTypes = VisitType::where(function ($q) use ($entityId) {
            $q->where('entity_id', $entityId)->orWhereNull('entity_id');
        })->where('active', true)->orderBy('name')->get();

        $meta = [
            'title'       => $this->titleController,
            'action'      => __('actions.records'),
            'breadcrumbs' => [
                [
                    'label'  => __('actions.sidemenu.dashboard'),
                    'url'    => route('panel.dashboard'),
                    'active' => false,
                ],
                [
                    'label'  => $this->titleController,
                    'url'    => route('panel.schedules.index'),
                    'active' => false,
                ],
                [
                    'label'  => __('actions.records'),
                    'url'    => 'javascript:void(0);',
                    'active' => true,
                ],
            ],
        ];

        return view('system.schedules.index', compact('doctors', 'covenants', 'visitTypes', 'meta', 'loggedDoctor'));
    }

    /**
     * Return the rendered schedule list partial (AJAX).
     */
    public function ajaxList(Request $request)
    {
        $entityId = session()->get('selected_entity_id');
        $date     = Carbon::createFromFormat('d/m/Y', $request->string('date')->toString());
        $doctor   = $request->string('doctor')->toString();
        $bout     = $request->integer('bout');
        $search   = $request->string('search')->trim()->value();

        $query = $this->model->query()
            ->with(['doctor', 'covenant', 'patient.person', 'visitType'])
            ->leftJoin('patients', 'patients.id', '=', 'schedules.patient_id')
            ->leftJoin('people', 'people.id', '=', 'patients.person_id')
            ->where('schedules.entity_id', $entityId)
            ->whereDate('schedules.date_time', $date)
            ->select('schedules.*');

        $loggedDoctor = $this->loggedDoctor();

        if ($loggedDoctor) {
            $query->where('schedules.doctor_id', $loggedDoctor->id);
        } elseif ($doctor !== 'tudo') {
            $query->where('schedules.doctor_id', $doctor);
        }

        if ($search) {
            $lower = mb_strtolower($search, 'UTF-8');
            $query->where(function ($q) use ($lower) {
                $q->whereRaw('LOWER(schedules.full_name) LIKE ?', ["%{$lower}%"])
                    ->orWhereRaw('LOWER(people.full_name) LIKE ?', ["%{$lower}%"]);
            });
        }

        match ($bout) {
            2       => $query->whereTime('date_time', '<', '13:00:00'),
            3       => $query->whereTime('date_time', '>=', '13:00:00')->whereTime('date_time', '<', '18:00:00'),
            4       => $query->whereTime('date_time', '>=', '18:00:00'),
            default => null,
        };

        $schedules = $query->orderBy('date_time')->get();

        return view('system.schedules.list', compact('schedules'));
    }

    public function show(Schedule $schedule): JsonResponse
    {
        return response()->json([
            'data' => array_merge($schedule->toArray(), [
                'date_time' => $schedule->date_time->format('Y-m-d\TH:i'),
            ]),
        ]);
    }

    public function store(ScheduleRequest $request): JsonResponse
    {
        $entityId = session()->get('selected_entity_id');

        $schedule = $this->model->create(
            array_merge($request->validated(), ['entity_id' => $entityId])
        );

        return response()->json([
            'message' => 'Agendamento criado com sucesso.',
            'data'    => $schedule,
        ], 201);
    }

    public function update(ScheduleRequest $request, Schedule $schedule): JsonResponse
    {
        $schedule->update($request->validated());

        return response()->json([
            'message' => 'Agendamento atualizado com sucesso.',
            'data'    => $schedule->fresh(),
        ]);
    }

    public function destroy(Schedule $schedule): JsonResponse
    {
        $schedule->delete();

        return response()->json([
            'message' => 'Agendamento excluído com sucesso.',
        ]);
    }

    public function updateSituation(Request $request, Schedule $schedule): JsonResponse
    {
        $situation = ScheduleSituation::from($request->integer('situation'));

        $data = ['situation' => $situation->value];

        if ($situation === ScheduleSituation::Waiting) {
            $data['arrived_at'] = now();
        }

        $schedule->update($data);

        Cache::forget("waiting_room:{$schedule->entity_id}");

        return response()->json([
            'message'   => 'Situação atualizada.',
            'situation' => $situation->value,
            'label'     => $situation->label(),
        ]);
    }

    private function loggedDoctor(): ?Doctor
    {
        if (session('user_rule') !== ClientRule::Doctor->value) {
            return null;
        }

        $entityUserId = session('selected_entity_user_id');

        return Doctor::query()
            ->join('entity_users', 'doctors.entity_user_id', '=', 'entity_users.id')
            ->join('users', 'entity_users.user_id', '=', 'users.id')
            ->where('doctors.entity_user_id', $entityUserId)
            ->select('doctors.*', 'users.name as user_name', 'users.id as user_id')
            ->first();
    }

    private function doctorsByEntity(string $entityId)
    {
        return Doctor::query()
            ->join('entity_users', 'doctors.entity_user_id', '=', 'entity_users.id')
            ->join('users', 'entity_users.user_id', '=', 'users.id')
            ->where('entity_users.entity_id', $entityId)
            ->select('doctors.*', 'users.name as user_name', 'users.id as user_id')
            ->orderBy('users.name')
            ->get();
    }
}
