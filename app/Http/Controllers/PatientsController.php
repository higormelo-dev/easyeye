<?php

namespace App\Http\Controllers;

use App\DataTables\PatientsDataTable;
use App\Http\Requests\PatientRequest;
use App\Http\Resources\{PatientResource};
use App\Models\{Covenant, IrisType, Patient, People, SkinType};
use App\Services\PatientService;
use Illuminate\Contracts\View\{Factory, View};
use Illuminate\Foundation\Application;
use Illuminate\Http\{JsonResponse, RedirectResponse, Request};
use Illuminate\Routing\Redirector;
use Illuminate\Support\Facades\DB;

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

        return $dataTable->render('system.patients.index', compact('meta'));
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create(): Factory|Application|View|JsonResponse
    {
        $genders         = People::$genders;
        $maritalStatuses = People::$maritalStatuses;
        $statesOfBrazil  = People::$statesOfBrazil;
        $covenants       = Covenant::all()->pluck('name', 'id')->toArray();
        $skinTypes       = SkinType::all()->pluck('name', 'id')->toArray();
        $irisTypes       = IrisType::all()->pluck('name', 'id')->toArray();

        return view(
            'system.patients.form',
            compact(
                'genders', 'maritalStatuses', 'statesOfBrazil',
                'covenants', 'skinTypes', 'irisTypes'
            )
        );
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
                    'data'    => (new PatientResource($record))['data'],
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
                    'data' => (new PatientResource($record))['data'],
                ]
            );
        }

        return view(
            'system.patients.show',
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
        $covenants       = Covenant::all()->pluck('name', 'id')->toArray();
        $skinTypes       = SkinType::all()->pluck('name', 'id')->toArray();
        $irisTypes       = IrisType::all()->pluck('name', 'id')->toArray();

        return view(
            'system.patients.form',
            compact(
                'record',
                'genders',
                'maritalStatuses',
                'statesOfBrazil',
                'covenants',
                'skinTypes',
                'irisTypes'
            )
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
                'data'    => (new PatientResource($updatedRecord))['data'],
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
