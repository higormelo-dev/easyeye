<?php

namespace App\Http\Controllers;

use App\DTOs\ActionPolicy;
use App\Enums\EntityGate;
use App\Http\Requests\DoctorRequest;
use App\Http\Resources\{DoctorResource, EntityUserResource};
use App\Models\{Doctor, Entity, EntityUser, Patient, People, User};
use App\Services\DoctorService;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Application;
use Illuminate\Http\{JsonResponse, RedirectResponse, Request};
use Illuminate\Routing\Redirector;
use Illuminate\Support\Facades\{DB, Gate, Storage, Vite};
use Inertia\{Inertia, Response};

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
            ->select(
                'doctors.*',
                'users.name as user_name',
                'users.email',
                'entity_users.user_id',
                'entity_users.entity_id',
            )
            ->orderBy('doctors.created_at', 'desc')
            ->paginate($perPage);

        $entityId = session()->get('selected_entity_id');

        $data = $doctors->map(function (Doctor $d) use ($entityId) {
            $userPhotoPath = 'users/' . $d->user_id . '.jpg';

            return [
                'id'        => $d->id,
                'full_name' => $d->user_name,
                'code'      => $d->code,
                'record'    => $d->record,
                'email'     => $d->email,
                'photo_url' => Storage::disk('public')->exists($userPhotoPath)
                    ? Storage::disk('public')->url($userPhotoPath)
                    : Vite::asset('resources/img/system/team.png'),
                ...ActionPolicy::from($d, $entityId)->toArray(),
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
    public function index(Request $request): Response
    {
        $entityId = session('selected_entity_id');
        $search   = $request->string('search')->trim()->value();
        $sortBy   = $request->string('sort', 'created_at')->value();
        $sortDir  = $request->string('direction', 'desc')->value();

        $allowedSorts = ['created_at', 'full_name', 'email', 'code', 'record'];
        $sortBy       = in_array($sortBy, $allowedSorts, true) ? $sortBy : 'created_at';
        $sortDir      = in_array($sortDir, ['asc', 'desc'], true) ? $sortDir : 'desc';

        $query = Doctor::query()
            ->select(
                'doctors.*',
                'entity_users.entity_id',
                'entity_users.user_id',
                'users.name as full_name',
                'users.email',
            )
            ->join('entity_users', 'doctors.entity_user_id', '=', 'entity_users.id')
            ->join('users', 'entity_users.user_id', '=', 'users.id')
            ->join('people', 'doctors.person_id', '=', 'people.id')
            ->where('entity_users.entity_id', $entityId);

        if ($search !== '') {
            $lower = mb_strtolower($search, 'UTF-8');
            $query->where(
                fn ($q) => $q
                    ->whereRaw('LOWER(users.name) LIKE ?', ["%{$lower}%"])
                    ->orWhereRaw('LOWER(users.email) LIKE ?', ["%{$lower}%"])
                    ->orWhereRaw('LOWER(doctors.code) LIKE ?', ["%{$lower}%"])
                    ->orWhereRaw('LOWER(doctors.record) LIKE ?', ["%{$lower}%"]),
            );
        }

        $dbCol = match ($sortBy) {
            'full_name' => 'users.name',
            'email'     => 'users.email',
            default     => "doctors.{$sortBy}",
        };
        $query->orderBy($dbCol, $sortDir);

        $doctors = $query->paginate(15)->withQueryString();

        return Inertia::render('Panel/Doctors/Index', [
            'doctors'      => $doctors->through(fn ($d) => $this->toTableRow($d, $entityId)),
            'totalDoctors' => fn () => Doctor::query()
                ->join('entity_users', 'doctors.entity_user_id', '=', 'entity_users.id')
                ->where('entity_users.entity_id', $entityId)
                ->count(),
            'genders'         => People::$genders,
            'maritalStatuses' => People::$maritalStatuses,
            'statesOfBrazil'  => People::$statesOfBrazil,
            'filters'         => $request->only(['search', 'sort', 'direction']),
        ]);
    }

    private function toTableRow(Doctor $d, string $entityId): array
    {
        $policy        = ActionPolicy::from($d, $entityId);
        $userPhotoPath = 'users/' . $d->user_id . '.jpg';

        return [
            'id'               => $d->id,
            'code'             => $d->code,
            'record'           => $d->record,
            'record_specialty' => $d->record_specialty,
            'color'            => $d->color,
            'active'           => (bool) $d->active,
            'deleted_at'       => $d->deleted_at,
            'created_at'       => $d->created_at?->format('d/m/Y'),
            'full_name'        => $d->full_name,
            'email'            => $d->email,
            'user_id'          => $d->user_id,
            'photo_url'        => Storage::disk('public')->exists($userPhotoPath)
                ? Storage::disk('public')->url($userPhotoPath)
                : Vite::asset('resources/img/system/team.png'),
            'work_schedule_url' => route('panel.doctors.work-schedule.index', $d->id),
            'mode'              => $policy->mode,
            'is_owned'          => $policy->isOwned,
            'is_global'         => $policy->isGlobal,
            'deleted'           => $policy->deleted,
        ];
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(DoctorRequest $request): JsonResponse|RedirectResponse
    {
        Gate::authorize(EntityGate::ManageSettings->value, Entity::findOrFail(session('selected_entity_id')));

        $entityUser = $this->service->create($request);
        $message    = $this->getCreateMessage();

        if ($request->wantsJson()) {
            return response()->json(['message' => $message, 'data' => new EntityUserResource($entityUser)]);
        }

        return redirect()->route('panel.doctors.index')->with('success', $message);
    }

    /**
     * Display the specified resource (JSON only — viewed via modal drawer on index).
     */
    public function show(string $id): JsonResponse|RedirectResponse
    {
        $record = $this->service->findByIdOrCode($id);

        if (! request()->wantsJson()) {
            return redirect()->route('panel.doctors.index');
        }

        $person        = $record->person;
        $userPhotoPath = 'users/' . $record->user_id . '.jpg';
        $photoUrl      = Storage::disk('public')->exists($userPhotoPath)
            ? Storage::disk('public')->url($userPhotoPath)
            : Vite::asset('resources/img/system/team.png');

        return response()->json([
            'data' => [
                'id'                => $record->id,
                'code'              => $record->code,
                'record'            => $record->record,
                'record_specialty'  => $record->record_specialty,
                'color'             => $record->color,
                'observation'       => $record->observation,
                'partner'           => (bool) $record->partner,
                'active'            => (bool) $record->active,
                'photo_url'         => $photoUrl,
                'created_at'        => $record->created_at?->format('d/m/Y H:i'),
                'updated_at'        => $record->updated_at?->format('d/m/Y H:i'),
                'deleted_at'        => $record->deleted_at?->format('d/m/Y H:i'),
                'full_name'         => $person->full_name,
                'nickname'          => $person->nickname,
                'cpf'               => $person->national_registry ? $person->present()->getNationalRegistry() : null,
                'birth_date'        => $person->birth_date ? $person->present()->getBirthDate() : null,
                'age'               => $person->birth_date ? $person->present()->getAge() : null,
                'gender'            => $person->present()->getGender(),
                'marital_status'    => $person->present()->getMaritalStatus(),
                'email'             => $record->email,
                'mother_name'       => $person->mother_name,
                'father_name'       => $person->father_name,
                'rg'                => $person->state_registry,
                'rg_agency'         => $person->state_registry_agency,
                'rg_state'          => $person->state_registry_initial,
                'rg_date'           => $person->state_registry_date ? $person->present()->getStateRegistryDate() : null,
                'telephone'         => $person->telephone ? $person->present()->getTelephone() : null,
                'cellphone'         => $person->cellphone ? $person->present()->getCellphone() : null,
                'whatsapp'          => (bool) $person->whatsapp,
                'zipcode'           => $person->zipcode ? $person->present()->getZipcode() : null,
                'address'           => $person->address,
                'number'            => $person->number,
                'complement'        => $person->complement,
                'district'          => $person->district,
                'city'              => $person->city,
                'state'             => $person->state,
                'work_schedule_url' => route('panel.doctors.work-schedule.index', $record->id),
            ],
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
     * Return doctor data for the edit modal (JSON only — UI is Vue/Inertia).
     */
    public function edit(string $id): JsonResponse|RedirectResponse
    {
        if (! request()->wantsJson()) {
            return redirect()->route('panel.doctors.index');
        }

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
        Gate::authorize(EntityGate::ManageSettings->value, Entity::findOrFail(session('selected_entity_id')));

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
    public function destroy(string $id): Application|View|JsonResponse|RedirectResponse
    {
        Gate::authorize(EntityGate::ManageSettings->value, Entity::findOrFail(session('selected_entity_id')));

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
