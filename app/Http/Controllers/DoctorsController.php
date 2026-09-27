<?php

namespace App\Http\Controllers;

use App\DTOs\ActionPolicy;
use App\Enums\EntityGate;
use App\Http\Controllers\Concerns\RedirectsToListing;
use App\Http\Requests\DoctorRequest;
use App\Http\Resources\{DoctorResource, EntityUserResource};
use App\Models\{Doctor, Entity, EntityUser, People, User};
use App\Services\{DoctorService, PatientService};
use App\Support\BrazilianFormat;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Application;
use Illuminate\Http\{JsonResponse, RedirectResponse, Request};
use Illuminate\Routing\Redirector;
use Illuminate\Support\Facades\{DB, Gate, Storage, Vite};
use Inertia\{Inertia, Response};

class DoctorsController extends Controller
{
    use RedirectsToListing;

    /** Parâmetros da listagem preservados ao voltar de uma ação (RedirectsToListing). */
    private const LISTING_PARAMS = ['search', 'sort', 'direction', 'page'];

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

    /** Colunas ordenáveis (whitelist) → coluna real no banco. */
    private const SORTABLE = [
        'created_at' => 'doctors.created_at',
        'full_name'  => 'users.name',
        'email'      => 'users.email',
        'code'       => 'doctors.code',
        'record'     => 'doctors.record',
        'cellphone'  => 'people.cellphone',
    ];

    /**
     * Return paginated JSON for the card view — mesma query, busca e linha
     * da tabela (toTableRow), para os dois modos exibirem os mesmos dados.
     */
    public function cards(Request $request): JsonResponse
    {
        $entityId = session()->get('selected_entity_id');

        $doctors = $this->searchListing($this->listingQuery($entityId), $request->string('search')->trim()->value())
            ->orderBy('doctors.created_at', 'desc')
            ->orderBy('doctors.id');
        $doctors = $this->paginateClamped($doctors, 12);

        return response()->json([
            'data' => $doctors->getCollection()->map(fn (Doctor $d) => $this->toTableRow($d, $entityId))->values(),
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

        $sortBy  = array_key_exists($sortBy, self::SORTABLE) ? $sortBy : 'created_at';
        $sortDir = in_array($sortDir, ['asc', 'desc'], true) ? $sortDir : 'desc';

        // `id` desempata valores iguais (nome, telefone...) — sem ele a ordem
        // entre páginas não é determinística no PostgreSQL.
        $doctors = $this->searchListing($this->listingQuery($entityId), $search)
            ->orderBy(self::SORTABLE[$sortBy], $sortDir)
            ->orderBy('doctors.id');
        $doctors = $this->paginateClamped($doctors, 15)->withQueryString();

        return Inertia::render('Panel/Doctors/Index', [
            'doctors'      => $doctors->through(fn ($d) => $this->toTableRow($d, $entityId)),
            'totalDoctors' => fn () => Doctor::query()
                ->join('entity_users', 'doctors.entity_user_id', '=', 'entity_users.id')
                ->where('entity_users.entity_id', $entityId)
                ->count(),
            'genders'         => People::$genders,
            'maritalStatuses' => People::$maritalStatuses,
            'statesOfBrazil'  => People::$statesOfBrazil,
            // Normalizados: a UI mostra o ícone da ordenação realmente aplicada.
            'filters' => ['search' => $search, 'sort' => $sortBy, 'direction' => $sortDir],
            't'       => trans('doctors'),
        ]);
    }

    /** Médicos da clínica ativa com os dados de usuário/pessoa usados na listagem. */
    private function listingQuery(?string $entityId): Builder
    {
        return Doctor::query()
            ->select(
                'doctors.*',
                'entity_users.entity_id',
                'entity_users.user_id',
                'users.name as full_name',
                'users.email',
                'people.cellphone',
                'people.whatsapp',
            )
            ->join('entity_users', 'doctors.entity_user_id', '=', 'entity_users.id')
            ->join('users', 'entity_users.user_id', '=', 'users.id')
            ->join('people', 'doctors.person_id', '=', 'people.id')
            ->where('entity_users.entity_id', $entityId);
    }

    /** Busca por nome, e-mail, código ou CRM — mesma na tabela e nos cards. */
    private function searchListing(Builder $query, string $search): Builder
    {
        return $query->when($search !== '', fn ($q) => $q->where(
            fn ($w) => $w
                ->whereLikeUnaccent('users.name', $search)
                ->orWhereLikeUnaccent('users.email', $search)
                ->orWhereLikeUnaccent('doctors.code', $search)
                ->orWhereLikeUnaccent('doctors.record', $search)
                ->orWhereLikeUnaccent('people.cellphone', $search)
                ->when(
                    BrazilianFormat::searchDigits($search),
                    fn ($q, $digits) => $q->orWhereLikeUnaccent('people.cellphone', $digits),
                ),
        ));
    }

    /**
     * Pagina e, se a página pedida passou da última (ex.: excluiu o único
     * médico da última página e o redirect manteve ?page=N), usa a última válida.
     */
    private function paginateClamped(Builder $query, int $perPage): LengthAwarePaginator
    {
        $paginator = (clone $query)->paginate($perPage);

        if ($paginator->isEmpty() && $paginator->currentPage() > 1 && $paginator->lastPage() >= 1) {
            $paginator = $query->paginate($perPage, ['*'], 'page', $paginator->lastPage());
        }

        return $paginator;
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
            'cellphone'        => BrazilianFormat::phone($d->cellphone),
            'whatsapp'         => (bool) $d->whatsapp,
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

        return $this->redirectToListing('panel.doctors.index', self::LISTING_PARAMS)->with('success', $message);
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
                'cpf'               => BrazilianFormat::cpf($person->national_registry),
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
                'telephone'         => BrazilianFormat::phone($person->telephone),
                'cellphone'         => BrazilianFormat::phone($person->cellphone),
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

        return $this->redirectToListing('panel.doctors.index', self::LISTING_PARAMS)->with('message', $messageReturn);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $id, PatientService $patientService): Application|View|JsonResponse|RedirectResponse
    {
        Gate::authorize(EntityGate::ManageSettings->value, Entity::findOrFail(session('selected_entity_id')));

        $record = $this->service->findByIdOrCode($id);

        return DB::transaction(function () use ($record, $patientService) {
            $userId     = $record->entityUser->user_id;
            $recordData = $record->toArray();

            $userHasOtherEntityUsers = EntityUser::query()
                ->where('user_id', $userId)
                ->count();

            $record->entityUser->delete();
            $record->delete();

            if ($userHasOtherEntityUsers <= 1) {
                $user = User::query()->find($userId);
                $user?->delete();
            }

            // People é identidade GLOBAL: antes contava só Patients da clínica
            // atual (EntityScope) e ignorava médicos — excluir o médico em A
            // apagava o People do paciente/médico de B (ou do paciente de A).
            $patientService->deletePersonIfUnused($record->person_id, exceptDoctorId: $record->id);

            if (request()->wantsJson()) {
                return response()->json([
                    'message' => $this->getDeleteMessage(),
                    'deleted' => $recordData,
                ]);
            }

            return $this->redirectToListing('panel.doctors.index', self::LISTING_PARAMS)->with('message', $this->getDeleteMessage());
        });
    }
}
