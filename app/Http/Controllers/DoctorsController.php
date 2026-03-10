<?php

namespace App\Http\Controllers;

use App\DataTables\DoctorsDataTable;
use App\Http\Requests\{DoctorRequest};
use App\Http\Resources\{DoctorResource, EntityUserResource};
use App\Models\{Doctor, EntityUser, Patient, People, User};
use App\Services\DoctorService;
use Illuminate\Contracts\View\{Factory, View};
use Illuminate\Foundation\Application;
use Illuminate\Http\{JsonResponse, RedirectResponse, Request};
use Illuminate\Routing\Redirector;
use Illuminate\Support\Facades\DB;

class DoctorsController extends Controller
{
    /**
     * Instance of the standard model.
     */
    protected Doctor $model;

    protected DoctorService $service;

    public function __construct(Doctor $doctor, DoctorService $doctorService)
    {
        $this->titleController = __('actions.sidemenu.doctors');
        $this->model           = $doctor;
        $this->service         = $doctorService;
    }

    /**
     * Return paginated JSON for the card view.
     */
    public function cards(Request $request): JsonResponse
    {
        $search  = $request->string('search')->trim()->value();
        $perPage = 12;

        $doctors = Doctor::query()
            ->join('entity_users', 'doctors.entity_user_id', '=', 'entity_users.id')
            ->join('users', 'entity_users.user_id', '=', 'users.id')
            ->join('people', 'doctors.person_id', '=', 'people.id')
            ->where('entity_users.entity_id', session()->get('selected_entity_id'))
            ->when($search, function ($q) use ($search) {
                $lower = mb_strtolower($search, 'UTF-8');
                $q->where(function ($inner) use ($lower) {
                    $inner->whereRaw('LOWER(users.name) LIKE ?', ["%{$lower}%"])
                        ->orWhereRaw('LOWER(doctors.code) LIKE ?', ["%{$lower}%"])
                        ->orWhereRaw('LOWER(users.email) LIKE ?', ["%{$lower}%"]);
                });
            })
            ->select('doctors.*', 'users.name as user_name', 'users.email', 'entity_users.user_id')
            ->orderBy('doctors.created_at', 'desc')
            ->paginate($perPage);

        $data = $doctors->map(function (Doctor $d) {
            $userPhotoPath = 'system/images/users/' . $d->user_id . '.jpg';

            return [
                'id'        => $d->id,
                'full_name' => $d->user_name,
                'code'      => $d->code,
                'record'    => $d->record,
                'email'     => $d->email,
                'active'    => (bool) $d->active,
                'photo_url' => file_exists(public_path($userPhotoPath))
                    ? asset($userPhotoPath)
                    : asset('system/images/team.png'),
            ];
        });

        return response()->json([
            'data' => $data,
            'meta' => [
                'total'        => $doctors->total(),
                'per_page'     => $doctors->perPage(),
                'current_page' => $doctors->currentPage(),
                'last_page'    => $doctors->lastPage(),
            ],
        ]);
    }

    /**
     * Display a listing of the resource.
     */
    public function index(DoctorsDataTable $dataTable): Factory|Application|View|JsonResponse
    {
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
                    'url'    => route('panel.accesscontrol.users.index'),
                    'active' => false,
                ],
                [
                    'label'  => __('actions.records'),
                    'url'    => 'javascript:void(0);',
                    'active' => true,
                ],
            ],
        ];

        return $dataTable->render('system.doctors.index', compact('meta'));
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create(): Factory|Application|View|JsonResponse
    {
        $genders         = People::$genders;
        $maritalStatuses = People::$maritalStatuses;
        $statesOfBrazil  = People::$statesOfBrazil;

        return view(
            'system.doctors.form',
            compact('genders', 'maritalStatuses', 'statesOfBrazil')
        );
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(DoctorRequest $request): JsonResponse|EntityUserResource
    {
        $entityUser = $this->service->create($request);

        return new EntityUserResource($entityUser);
    }

    /**
     * Display the specified resource.
     */
    public function show(string $id): Application|View|JsonResponse
    {
        $record = $this->service->findByIdOrCode($id);

        if (request()->wantsJson()) {
            return response()->json([
                'data' => (new DoctorResource($record))['data'],
            ]);
        }

        return view(
            'system.doctors.show',
            compact('record')
        );
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(string $id): Application|View|JsonResponse
    {
        $record          = $this->service->findByIdOrCode($id);
        $genders         = People::$genders;
        $maritalStatuses = People::$maritalStatuses;
        $statesOfBrazil  = People::$statesOfBrazil;

        if (request()->wantsJson()) {
            return response()->json([
                'data'            => (new DoctorResource($record))['data'],
                'genders'         => $genders,
                'maritalStatuses' => $maritalStatuses,
                'statesOfBrazil'  => $statesOfBrazil,
            ]);
        }

        return view(
            'system.doctors.form',
            compact('record', 'genders', 'maritalStatuses', 'statesOfBrazil')
        );
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(DoctorRequest $request, string $id): Application|JsonResponse|Redirector|RedirectResponse
    {
        $record        = $this->service->findByIdOrCode($id);
        $updatedRecord = $this->service->update($record, $request);

        $messageReturn = $this->getUpdateMessage($request);

        if (request()->wantsJson()) {
            return response()->json([
                'message' => $messageReturn,
                'data'    => (new EntityUserResource($updatedRecord))['data'],
            ]);
        }

        return redirect(action('\\' . static::class . '@index'))
            ->with('message', $messageReturn);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $id): Application|View|JsonResponse
    {
        $record = $this->service->findByIdOrCode($id);

        return DB::transaction(function () use ($record) {
            $userId     = $record->entityUser->user_id;
            $recordData = $record->toArray();

            $userHasOtherEntityUsers = EntityUser::query()
                ->where('user_id', $userId)
                ->count();
            $patientHasOtherEntityUsers = Patient::query()
                ->where('person_id', $record->person_id)
                ->count();

            $record->entityUser->delete();
            $record->delete();

            if ($userHasOtherEntityUsers <= 1) {
                $user = User::query()->find($userId);
                $user?->delete();
            }

            if ($patientHasOtherEntityUsers <= 1) {
                $person = People::query()->find($record->person_id);
                $person?->delete();
            }

            // Retornar resposta
            if (request()->wantsJson()) {
                return response()->json([
                    'message' => $this->getDeleteMessage(),
                    'deleted' => $recordData,
                ]);
            }

            return redirect(action('\\' . static::class . '@index'))
                ->with('message', $this->getDeleteMessage());
        });
    }
}
