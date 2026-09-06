<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\ScheduleResource;
use App\Models\Schedule;
use Carbon\Carbon;
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
        $search     = request()->string('search')->trim()->value();

        $schedules = $this->model->query()
            ->with(['doctor', 'patient', 'covenant', 'visitType'])
            ->where('entity_id', $integrator->user->entity_id);

        $identifierSearch = $this->resolveIdentifierSearch($search);

        if (request()->has('date')) {
            $date      = Carbon::parse(request()->date)->toDateString();
            $schedules = $schedules->whereDate('date_time', $date);
        } elseif ($identifierSearch === null) {
            $schedules = $schedules->whereDate('date_time', now()->toDateString());
        }

        if ($identifierSearch !== null) {
            $schedules = $schedules->where(
                $identifierSearch['column'],
                $identifierSearch['value'],
            );
        } elseif (filled($search)) {
            $schedules = $schedules->where(function ($query) use ($search) {
                $query->whereHas('patient', function ($q) use ($search) {
                    $q->whereHas('person', function ($qq) use ($search) {
                        $qq->whereLikeUnaccent('full_name', $search)
                            ->orWhereLikeUnaccent('nickname', $search);
                    });
                })
                    ->orWhereHas('doctor', function ($q) use ($search) {
                        $q->whereHas('person', function ($qq) use ($search) {
                            $qq->whereLikeUnaccent('full_name', $search)
                                ->orWhereLikeUnaccent('nickname', $search);
                        });
                    })
                    ->orWhereHas('covenant', function ($q) use ($search) {
                        $q->whereLikeUnaccent('name', $search);
                    })
                    ->orWhereLikeUnaccent('code', $search)
                    ->orWhereLikeUnaccent('full_name', $search);
            });
        }

        $schedules = $schedules->paginate($this->perPage());

        return ScheduleResource::collection($schedules);
    }

    /**
     * @return array{column: 'id'|'code', value: string}|null
     */
    private function resolveIdentifierSearch(?string $search): ?array
    {
        if (blank($search)) {
            return null;
        }

        if (Str::isUuid($search)) {
            return ['column' => 'id', 'value' => $search];
        }

        if (ctype_digit($search)) {
            return ['column' => 'code', 'value' => sprintf('SDL-%010d', (int) $search)];
        }

        $normalizedCode = mb_strtoupper($search, 'UTF-8');

        if (preg_match('/^SDL-\d{1,10}$/', $normalizedCode) === 1) {
            $numericPart = (int) substr($normalizedCode, 4);

            return ['column' => 'code', 'value' => sprintf('SDL-%010d', $numericPart)];
        }

        return null;
    }

    /**
     * Display the specified resource.
     */
    public function show(string $idOrCode): ScheduleResource
    {
        $integrator = request()->attributes->get('integrator');

        [$column, $value] = match (true) {
            Str::isUuid($idOrCode) => ['id', $idOrCode],
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
