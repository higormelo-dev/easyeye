<?php

namespace App\Http\Controllers;

use App\Http\Requests\PatientRequest;
use App\Http\Resources\{PatientResource};
use App\Models\{Covenant, IrisType, Patient, People, SkinType};
use App\Services\PatientService;
use Illuminate\Contracts\View\{Factory, View};
use Illuminate\Foundation\Application;
use Illuminate\Http\{JsonResponse, RedirectResponse, Request};
use Illuminate\Routing\Redirector;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

class PatientsController extends Controller
{
    protected string $titleController = 'Pacientes';

    /**
     * Instance of the standard model.
     */
    protected Patient $model;

    protected PatientService $patientService;

    public function __construct(Patient $patient, PatientService $patientService)
    {
        $this->model          = $patient;
        $this->patientService = $patientService;
    }

    /**
     * Display a listing of the resource.
     */
    public function index(): Factory|Application|View
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

        return view('system.patients.index', compact('meta'));
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create(): Factory|Application|View|JsonResponse
    {
        try {
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
        } catch (\Throwable $e) {
            return $this->serverErrorResponse();
        }
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(PatientRequest $request): Application|RedirectResponse|Redirector|JsonResponse
    {
        try {
            $patient       = $this->patientService->createPatient($request);
            $messageReturn = $this->titleController . ' cadastradp(a) com sucesso.';

            if (request()->wantsJson()) {
                return response()->json(
                    [
                        'message' => $messageReturn,
                        'data'    => (new PatientResource($patient))['data'],
                    ]
                );
            }

            return redirect(action('\\' . static::class . '@index'))
                ->with('message', $messageReturn);
        } catch (\Throwable $e) {
            return $this->serverErrorResponse();
        }

    }

    /**
     * Display the specified resource.
     */
    public function show(string $id): Application|View|JsonResponse
    {
        try {
            $record = $this->findPatient($id);

            if (! $record) {
                return $this->notFoundResponse();
            }

            return view(
                'system.patients.show',
                compact('record')
            );
        } catch (\Throwable $e) {
            return $this->serverErrorResponse();
        }
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(string $id): Application|View|JsonResponse
    {
        try {
            $record = $this->findPatient($id);

            if (! $record) {
                return $this->notFoundResponse();
            }

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
        } catch (\Throwable $e) {
            return $this->serverErrorResponse();
        }
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(PatientRequest $request, string $id): Application|JsonResponse|Redirector|RedirectResponse
    {
        try {
            $record = $this->findPatient($id);

            if (! $record) {
                return $this->notFoundResponse();
            }

            $updatedRecord = $this->patientService->updatePatient($record, $request);
            $messageReturn = $this->getUpdateMessage($request);

            if (request()->wantsJson()) {
                return response()->json([
                    'message' => $messageReturn,
                    'data'    => (new PatientResource($updatedRecord))['data'],
                ]);
            }

            return redirect(action('\\' . static::class . '@index'))
                ->with('message', $messageReturn);
        } catch (\Throwable $e) {
            return $this->serverErrorResponse();
        }
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $id): Application|View|JsonResponse
    {
        try {
            $record = $this->findPatient($id);

            if (! $record) {
                return $this->notFoundResponse();
            }

            return DB::transaction(function () use ($record) {
                $recordData = $record->toArray();

                $patientHasOtherEntities = Patient::query()
                    ->where('person_id', $record->person_id)
                    ->count();
                $record->delete();

                if ($patientHasOtherEntities <= 1) {
                    $person = People::query()->find($record->person_id);

                    if ($person) {
                        $person->delete();
                    }
                }

                // Retornar resposta
                if (request()->wantsJson()) {
                    return response()->json([
                        'message' => $this->titleController . ' deletado(a) com sucesso.',
                        'deleted' => $recordData,
                    ]);
                }

                return redirect(action('\\' . static::class . '@index'))
                    ->with('message', $this->titleController . ' deletado(a) com sucesso.');
            });
        } catch (\Throwable $e) {
            return $this->serverErrorResponse();
        }
    }

    public function ajaxDatatable(Request $request): JsonResponse
    {
        $columns = [
            0 => 'created_at',
            1 => 'code',
            2 => 'name',
            3 => 'gender',
            4 => 'cellphone',
            5 => 'active',
            6 => 'action',
        ];

        $totalRecords = $this->model->query()
            ->select(
                'patients.*', 'people.full_name', 'people.gender',
                'people.cellphone', 'people.whatsapp'
            )
            ->join('people', 'patients.person_id', '=', 'people.id')
            ->where('patients.entity_id', session()->get('selected_entity_id'))
            ->count();

        $limit = $request->get('length');
        $start = $request->get('start');
        $order = $columns[$request->get('order')[0]['column']];
        $dir   = $request->get('order')[0]['dir'];

        if (empty($request->get('search')['value'])) {
            $records = $this->model->query()
                ->select(
                    'patients.*', 'people.full_name', 'people.gender',
                    'people.cellphone', 'people.whatsapp'
                )
                ->join('people', 'patients.person_id', '=', 'people.id')
                ->where('patients.entity_id', session()->get('selected_entity_id'))
                ->skip($start)
                ->take($limit)
                ->orderBy($order, $dir)
                ->get();

            $totalFiltered = $totalRecords;
        } else {
            $search = $request->get('search')['value'];
            $query  = $this->model->query()
                ->select(
                    'patients.*', 'people.full_name', 'people.gender',
                    'people.cellphone', 'people.whatsapp'
                )
                ->join('people', 'patients.person_id', '=', 'people.id')
                ->where('patients.entity_id', session()->get('selected_entity_id'))
                ->where(function ($query) use ($search) {
                    $query->where('people.full_name', 'like', "%{$search}%")
                        ->orWhere('people.nickname', 'like', "%{$search}%")
                        ->orWhere('patients.code', 'like', "%{$search}%");
                });

            $records       = $query->skip($start)->take($limit)->orderBy($order, $dir)->get();
            $totalFiltered = $query->count();

        }
        $data = [];

        if (count($records)) {
            foreach ($records as $record) {
                $information['created_at'] = $record->created_at->format('d/m/Y H:i');
                $information['code']       = $record->code;
                $information['name']       = $record->full_name;
                $information['gender']     = $record->person->present()->getGender();
                $information['cellphone']  = $record->person->present()->getCellphone();
                $information['active']     = $record->active ?
                    '<span class="badge bg-success">SIM</span>' :
                    '<span class="badge bg-dark">NÃO</span>';
                $information['action'] = $this->buildActionButtons($record);
                $data[]                = $information;
            }

        }

        return response()->json([
            'draw'            => (int) $request->get('draw'),
            'recordsTotal'    => (int) $totalRecords,
            'recordsFiltered' => (int) $totalFiltered,
            'data'            => $data,
        ]);

    }

    /**
     * Find patient by ID
     */
    private function findPatient(string $id): ?Patient
    {
        return $this->model->query()
            ->with('person')
            ->whereHas('entity', function ($query) {
                $query->where('entities.id', session()->get('selected_entity_id'));
            })->find($id);
    }

    /**
     * Return not found response
     */
    private function notFoundResponse(): JsonResponse
    {
        return response()->json(['message' => 'Patient not found.'], HttpResponse::HTTP_NOT_FOUND);
    }
    /**
     * Return server error response
     */
    private function serverErrorResponse(): JsonResponse
    {
        return response()->json(['message' => 'An error occurred.'], HttpResponse::HTTP_INTERNAL_SERVER_ERROR);
    }

    /**
     * Get update message based on request type
     */
    private function getUpdateMessage(Request $request): string
    {
        if ($request->has('type_method')) {
            return $this->titleController .
                ($request->active ? ' desbloqueado(a) ' : ' bloqueado(a) ') . ' com sucesso.';
        }

        return $this->titleController . ' alterado(a) com sucesso.';
    }

    /**
     * Build action buttons for datatable
     */
    private function buildActionButtons($record): string
    {
        $btnActions = '';
        $btnActions .= '<a href="javascript:void(0);"
                    class="btn waves-effect waves-light btn-secondary btn-xs m-1 btn-edit"
                    data-id="' . $record->id . '" data-bs-toggle="tooltip" data-bs-placement="bottom"
                    title="Editar"><i class="fa fa-edit"></i></a>';
        $btnActions .= '<a href="javascript:void(0);"
                    class="btn waves-effect waves-light btn-secondary btn-xs m-1 btn-show"
                    data-id="' . $record->id . '" data-bs-toggle="tooltip" data-bs-placement="bottom"
                    title="Visualizar"><i class="fa fa-eye"></i></a>';
        $btnActions .= '<a href="javascript:void(0);"
                    class="btn waves-effect waves-light btn-secondary btn-xs m-1 btn-active"
                    data-id="' . $record->id . '" data-situation="' . (($record->active) ? 0 : 1) . '"
                    data-bs-toggle="tooltip" data-bs-placement="bottom"
                    title="' . (($record->active) ? 'Inativar' : 'Ativar') . '">
                    <i class="fas ' . (($record->active) ? 'fa-lock-open' : 'fa-unlock') . '"></i></a>';
        $btnActions .= '<a href="javascript:void(0);"
                    class="btn waves-effect waves-danger btn-danger btn-xs m-1 btn-trash"
                    data-id="' . $record->id . '" data-bs-toggle="tooltip" data-bs-placement="bottom"
                    title="Deletar"><i class="fas fa-trash-alt"></i></a>';

        return $btnActions;
    }
}
