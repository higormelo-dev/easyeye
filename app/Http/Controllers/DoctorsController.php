<?php

namespace App\Http\Controllers;

use App\Http\Requests\{DoctorRequest};
use App\Http\Resources\{DoctorResource, EntityUserResource};
use App\Models\{Doctor, EntityUser, Patient, People, User};
use App\Services\DoctorService;
use Illuminate\Foundation\Application;
use Illuminate\Http\{JsonResponse, RedirectResponse, Request};
use Illuminate\Routing\Redirector;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

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
    public function index(): \Inertia\Response
    {
        $totalDoctors = Doctor::query()
            ->join('entity_users', 'doctors.entity_user_id', '=', 'entity_users.id')
            ->where('entity_users.entity_id', session('selected_entity_id'))
            ->count();

        return Inertia::render('Doctors/Index', [
            'total_doctors'   => $totalDoctors,
            'genders'         => People::$genders,
            'maritalStatuses' => People::$maritalStatuses,
            'statesOfBrazil'  => People::$statesOfBrazil,
        ]);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(DoctorRequest $request): JsonResponse
    {
        $entityUser = $this->service->create($request);

        return response()->json([
            'message' => $this->getCreateMessage(),
            'data'    => new EntityUserResource($entityUser),
        ]);
    }

    /**
     * Display the specified resource.
     */
    public function show(string $id): Application|\Inertia\Response|JsonResponse
    {
        $record = $this->service->findByIdOrCode($id);

        if (request()->wantsJson()) {
            return response()->json([
                'data' => new DoctorResource($record),
            ]);
        }

        $record->load(['person', 'entityUser.user']);

        return Inertia::render('Doctors/Show', [
            'record' => $record,
        ]);
    }

    /**
     * Return flat JSON for the crudForm edit modal.
     */
    public function editData(string $id): JsonResponse
    {
        $record = $this->service->findByIdOrCode($id);
        $person = $record->person;
        $user   = $record->entityUser->user;

        return response()->json(['data' => [
            'name'                   => $person->full_name,
            'nickname'               => $person->nickname,
            'national_registry'      => $person->national_registry,
            'birth_date'             => $person->birth_date?->format('Y-m-d'),
            'gender'                 => $person->gender,
            'marital_status'         => $person->marital_status,
            'email'                  => $user->email,
            'mother_name'            => $person->mother_name,
            'father_name'            => $person->father_name,
            'state_registry'         => $person->state_registry,
            'state_registry_agency'  => $person->state_registry_agency,
            'state_registry_initial' => $person->state_registry_initial,
            'state_registry_date'    => $person->state_registry_date?->format('Y-m-d'),
            'telephone'              => $person->telephone,
            'cellphone'              => $person->cellphone,
            'whatsapp'               => (bool) $person->whatsapp,
            'zipcode'                => $person->zipcode,
            'address'                => $person->address,
            'number'                 => $person->number,
            'complement'             => $person->complement,
            'district'               => $person->district,
            'city'                   => $person->city,
            'state'                  => $person->state,
            'record'                 => $record->record,
            'record_specialty'       => $record->record_specialty,
            'color'                  => $record->color,
            'observation'            => $record->observation,
            'partner'                => (bool) $record->partner,
            'active'                 => (bool) $record->active,
        ]]);
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(string $id): JsonResponse
    {
        $record = $this->service->findByIdOrCode($id);

        return response()->json([
            'data'            => new DoctorResource($record),
            'genders'         => People::$genders,
            'maritalStatuses' => People::$maritalStatuses,
            'statesOfBrazil'  => People::$statesOfBrazil,
        ]);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(DoctorRequest $request, string $id): Application|JsonResponse|Redirector|RedirectResponse
    {
        $record        = $this->service->findByIdOrCode($id);
        $updatedRecord = $this->service->update($record, $request);
        $updatedRecord->refresh();

        $messageReturn = $this->getUpdateMessage($request);

        if (request()->wantsJson()) {
            return response()->json([
                'message' => $messageReturn,
                'data'    => new DoctorResource($updatedRecord),
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
