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

        $date = request()->has('date')
            ? \Carbon\Carbon::parse(request()->date)->toDateString()
            : now()->toDateString();

        $schedules = $schedules->whereDate('date_time', $date);

        if (request()->has('search')) {
            $search    = request()->search;
            $schedules = $schedules->where(function ($query) use ($search) {
                $query->whereHas('patient', function ($q) use ($search) {
                    $q->whereHas('person', function ($qq) use ($search) {
                        $qq->where('full_name', 'ilike', '%' . $search . '%')
                            ->orWhere('nickname', 'ilike', '%' . $search . '%');
                    });
                })
                    ->orWhereHas('doctor', function ($q) use ($search) {
                        $q->whereHas('person', function ($qq) use ($search) {
                            $qq->where('full_name', 'ilike', '%' . $search . '%')
                                ->orWhere('nickname', 'ilike', '%' . $search . '%');
                        });
                    })
                    ->orWhereHas('covenant', function ($q) use ($search) {
                        $q->where('name', 'ilike', '%' . $search . '%');
                    })
                    ->orWhere('code', 'ilike', '%' . $search . '%')
                    ->orWhere('full_name', 'ilike', '%' . $search . '%');
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

        [$column, $value] = match (true) {
            Str::isUuid($idOrCode) => ['id',   $idOrCode],
            ctype_digit($idOrCode) => ['code', sprintf('SDL-%010d', (int) $idOrCode)],
            default                => ['code', $idOrCode],
        };

        $schedule = $this->model->query()
            ->with(['doctor', 'patient', 'covenant', 'visitType'])
            ->where('entity_id', $integrator->user->entity_id)
            ->where($column, $value)
            ->firstOrFail();

        return new ScheduleResource($schedule);
    }
}
