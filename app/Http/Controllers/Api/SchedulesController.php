<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\ScheduleResource;
use App\Models\Schedule;
use Illuminate\Support\Str;

class SchedulesController extends Controller
{
    /**
     * Instance of the standard model.
     */
    protected Schedule $model;

    public function __construct(Schedule $schedule)
    {
        $this->model = $schedule;
    }

    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        $integrator = request()->attributes->get('integrator');

        $schedules = $this->model->query()
            ->with(['doctor', 'patient', 'covenant', 'visitType'])
            ->where('entity_id', $integrator->user->entity_id);

        if (request()->has('search')) {
            $search    = request()->search;
            $schedules = $schedules->where(function ($query) use ($search) {
                $query->whereHas('patient', function ($q) use ($search) {
                    $q->whereHas('person', function ($qq) use ($search) {
                        $qq->whereRaw('LOWER(full_name) LIKE LOWER(?)', ["%{$search}%"])
                            ->orWhereRaw('LOWER(nickname) LIKE LOWER(?)', ["%{$search}%"]);
                    });
                })
                    ->orWhereHas('doctor', function ($q) use ($search) {
                        $q->whereHas('person', function ($qq) use ($search) {
                            $qq->whereRaw('LOWER(full_name) LIKE LOWER(?)', ["%{$search}%"])
                                ->orWhereRaw('LOWER(nickname) LIKE LOWER(?)', ["%{$search}%"]);
                        });
                    })
                    ->orWhereHas('covenant', function ($q) use ($search) {
                        $q->whereRaw('LOWER(name) LIKE LOWER(?)', ["%{$search}%"]);
                    })
                    ->orWhereRaw('LOWER(code) LIKE LOWER(?)', ["%{$search}%"])
                    ->orWhereRaw('LOWER(full_name) LIKE LOWER(?)', ["%{$search}%"]);
            });
        }

        $schedules = $schedules->paginate(min((int) request()->get('per_page', 10), 10));

        return ScheduleResource::collection($schedules);
    }

    /**
     * Display the specified resource.
     */
    public function show(string $idOrCode): ScheduleResource
    {
        $integrator = request()->attributes->get('integrator');

        $patient = $this->model->query()
            ->with(['doctor', 'patient', 'covenant', 'visitType'])
            ->where('entity_id', $integrator->user->entity_id)
            ->when(
                Str::isUuid($idOrCode),
                static fn ($q) => $q->where('id', $idOrCode),
                static fn ($q) => $q->where('code', $idOrCode)
            )
            ->firstOrFail();

        return new ScheduleResource($patient);
    }
}
