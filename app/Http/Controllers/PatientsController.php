<?php

namespace App\Http\Controllers;

use App\DataTables\PatientsDataTable;
use App\Http\Requests\{PatientRequest, QuickStorePatientRequest};
use App\Http\Resources\{PatientResource};
use App\Models\{Covenant, IrisType, Patient, People, SkinType};
use App\Services\PatientService;
use Illuminate\Contracts\View\{Factory, View};
use Illuminate\Foundation\Application;
use Illuminate\Http\{JsonResponse, RedirectResponse, Request};
use Illuminate\Routing\Redirector;
use Illuminate\Support\Facades\{DB, Storage};

class PatientsController extends Controller
{
    /**
     * Instance of the standard model.
     */
    protected Patient $model;

    protected PatientService $service;

    public function __construct(Patient $patient, PatientService $patientService)
    {
        $this->titleController = __('actions.sidemenu.patients');
        $this->model           = $patient;
        $this->service         = $patientService;
    }

    /**
     * Display a listing of the resource.
     */
    public function index(PatientsDataTable $dataTable): Factory|Application|View|JsonResponse
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
                    'url'    => route('panel.patients.index'),
                    'active' => false,
                ],
                [
                    'label'  => __('actions.patients'),
                    'url'    => 'javascript:void(0);',
                    'active' => true,
                ],
            ],
        ];

        $genders         = People::$genders;
        $maritalStatuses = People::$maritalStatuses;
        $statesOfBrazil  = People::$statesOfBrazil;
        $covenants       = Covenant::all()->pluck('name', 'id')->toArray();
        $skinTypes       = SkinType::all()->pluck('name', 'id')->toArray();
        $irisTypes       = IrisType::all()->pluck('name', 'id')->toArray();

        return $dataTable->render('system.patients.index', compact(
            'meta', 'genders', 'maritalStatuses', 'statesOfBrazil', 'covenants', 'skinTypes', 'irisTypes'
        ));
    }

    /**
     * Return paginated patients as JSON for the card view.
     */
    public function cards(Request $request): JsonResponse
    {
        $search  = $request->string('search')->trim()->value();
        $perPage = 12;

        $patients = Patient::query()
            ->withTrashed()
            ->with(['person', 'covenant', 'skinType', 'irisType'])
            ->join('people', 'patients.person_id', '=', 'people.id')
            ->where(function ($query) {
                $query->where('patients.entity_id', session()->get('selected_entity_id'))
                    ->orWhere(function ($q) {
                        $q->whereNull('patients.entity_id')
                            ->whereNull('patients.deleted_at');
                    });
            })
            ->when($search, function ($q) use ($search) {
                $lower = mb_strtolower($search, 'UTF-8');
                $q->where(function ($inner) use ($lower) {
                    $inner->whereRaw('LOWER(people.full_name) LIKE ?', ["%{$lower}%"])
                        ->orWhereRaw('LOWER(patients.code) LIKE ?', ["%{$lower}%"]);
                });
            })
            ->select('patients.*')
            ->orderBy('patients.created_at', 'desc')
            ->paginate($perPage);

        $data = $patients->map(fn (Patient $p) => [
            'id'        => $p->id,
            'full_name' => $p->person->full_name,
            'code'      => $p->code,
            'active'    => (bool) $p->active,
            'age'       => $p->person->birth_date?->age
                ? $p->person->birth_date->age . ' ' . __('actions.years')
                : null,
            'photo_url' => $p->photo && Storage::disk('public')->exists('images/patient/' . $p->photo)
                ? asset('storage/images/patient/' . $p->photo)
                : asset('system/images/team.png'),
            'skin'     => $p->skinType?->name,
            'iris'     => $p->irisType?->name,
            'covenant' => $p->covenant?->name,
        ]);

        return response()->json([
            'data' => $data,
            'meta' => [
                'total'        => $patients->total(),
                'per_page'     => $patients->perPage(),
                'current_page' => $patients->currentPage(),
                'last_page'    => $patients->lastPage(),
            ],
        ]);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(PatientRequest $request): Application|RedirectResponse|Redirector|JsonResponse
    {
        $record        = $this->service->create($request);
        $messageReturn = $this->getCreateMessage();

        if (request()->wantsJson()) {
            return response()->json(
                [
                    'message' => $messageReturn,
                    'data'    => new PatientResource($record),
                ]
            );
        }

        return redirect(action('\\' . static::class . '@index'))
            ->with('message', $messageReturn);

    }

    /**
     * Display the specified resource.
     */
    public function show(string $id): Application|View|JsonResponse
    {
        $record = $this->service->findByIdOrCode($id);

        if (request()->wantsJson()) {
            return response()->json(
                [
                    'data' => new PatientResource($record),
                ]
            );
        }

        return view(
            'system.patients.show',
            compact('record')
        );
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(PatientRequest $request, string $id): Application|JsonResponse|Redirector|RedirectResponse
    {
        $record        = $this->service->findByIdOrCode($id);
        $updatedRecord = $this->service->update($record, $request);
        $messageReturn = $this->getUpdateMessage($request);

        if (request()->wantsJson()) {
            return response()->json([
                'message' => $messageReturn,
                'data'    => new PatientResource($updatedRecord),
            ]);
        }

        return redirect(action('\\' . static::class . '@index'))
            ->with('message', $messageReturn);
    }

    /**
     * Search patients for the schedule quick-link (AJAX).
     */
    public function search(Request $request): JsonResponse
    {
        $q        = $request->string('q')->trim()->value();
        $entityId = session()->get('selected_entity_id');

        if (mb_strlen($q) < 2) {
            return response()->json([]);
        }

        $lower = mb_strtolower($q, 'UTF-8');

        $patients = Patient::query()
            ->join('people', 'patients.person_id', '=', 'people.id')
            ->where('patients.entity_id', $entityId)
            ->where('patients.active', true)
            ->where(function ($inner) use ($lower) {
                $inner->whereRaw('LOWER(people.full_name) LIKE ?', ["%{$lower}%"])
                    ->orWhereRaw('LOWER(people.cellphone) LIKE ?', ["%{$lower}%"])
                    ->orWhereRaw('LOWER(people.telephone) LIKE ?', ["%{$lower}%"])
                    ->orWhereRaw('LOWER(people.national_registry) LIKE ?', ["%{$lower}%"])
                    ->orWhereRaw('LOWER(patients.code) LIKE ?', ["%{$lower}%"]);
            })
            ->select('patients.id', 'patients.code', 'people.full_name', 'people.cellphone', 'people.telephone')
            ->orderBy('people.full_name')
            ->limit(10)
            ->get();

        return response()->json($patients);
    }

    /**
     * Quick-create a patient and link to schedule (AJAX).
     */
    public function quickStore(QuickStorePatientRequest $request): JsonResponse
    {
        $entityId = session()->get('selected_entity_id');

        $patient = DB::transaction(function () use ($request, $entityId) {
            $person = People::create([
                'full_name' => $request->input('name'),
                'cellphone' => $request->input('cellphone'),
            ]);

            return Patient::create([
                'entity_id' => $entityId,
                'person_id' => $person->id,
                'active'    => true,
            ]);
        });

        $patient->load('person');

        return response()->json([
            'patient' => [
                'id'        => $patient->id,
                'code'      => $patient->code,
                'full_name' => $patient->person->full_name,
                'cellphone' => $patient->person->cellphone ?? '',
                'telephone' => $patient->person->telephone ?? '',
            ],
        ], 201);
    }

    /**
     * Return flat JSON of a patient's data for the crudForm modal.
     */
    public function editData(Patient $patient): JsonResponse
    {
        $patient->load('person');
        $person = $patient->person;

        return response()->json([
            'data' => [
                'covenant_id'            => $patient->covenant_id,
                'card_number'            => $patient->card_number,
                'skin_id'                => $patient->skin_id,
                'iris_id'                => $patient->iris_id,
                'active'                 => (bool) $patient->active,
                'name'                   => $person->full_name,
                'nickname'               => $person->nickname,
                'national_registry'      => $person->national_registry,
                'birth_date'             => $person->birth_date?->format('Y-m-d'),
                'gender'                 => $person->gender,
                'marital_status'         => $person->marital_status,
                'email'                  => $person->email,
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
                'country'                => $person->country,
            ],
        ]);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $id): Application|View|JsonResponse
    {
        $record = $this->service->findByIdOrCode($id);

        return DB::transaction(function () use ($record) {
            $recordData = $record->toArray();

            $patientHasOtherEntities = Patient::query()
                ->where('person_id', $record->person_id)
                ->count();
            $record->delete();

            if ($patientHasOtherEntities <= 1) {
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
