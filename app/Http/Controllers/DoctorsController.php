<?php

namespace App\Http\Controllers;

use App\Http\Requests\{DoctorRequest};
use App\Http\Resources\{DoctorResource, EntityUserResource};
use App\Models\{Doctor, EntityUser, Patient, People, User};
use App\Services\DoctorService;
use Illuminate\Contracts\View\{Factory, View};
use Illuminate\Foundation\Application;
use Illuminate\Http\{JsonResponse, RedirectResponse, Request};
use Illuminate\Routing\Redirector;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

class DoctorsController extends Controller
{
    /*
     * Name of the controller
     */
    protected string $titleController;

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

        return view('system.doctors.index', compact('meta'));
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
                    'message' => $this->titleController . ' deletado(a) com sucesso.',
                    'deleted' => $recordData,
                ]);
            }

            return redirect(action('\\' . static::class . '@index'))
                ->with('message', $this->titleController . ' deletado(a) com sucesso.');
        });
    }

    public function ajaxDatatable(Request $request): JsonResponse
    {
        $columns = [
            0 => 'created_at',
            1 => 'code',
            2 => 'name',
            3 => 'record',
            4 => 'email',
            5 => 'active',
            6 => 'action',
        ];

        $totalRecords = $this->model->query()
            ->select(
                'doctors.*', 'users.name as user_name', 'users.email',
                'people.full_name', 'people.nickname'
            )
            ->join('entity_users', 'doctors.entity_user_id', '=', 'entity_users.id')
            ->join('users', 'entity_users.user_id', '=', 'users.id')
            ->join('people', 'doctors.person_id', '=', 'people.id')
            ->where('entity_users.entity_id', session()->get('selected_entity_id'))
            ->count();

        $limit = $request->get('length');
        $start = $request->get('start');
        $order = $columns[$request->get('order')[0]['column']];
        $dir   = $request->get('order')[0]['dir'];

        if (empty($request->get('search')['value'])) {
            $records = $this->model->query()
                ->select(
                    'doctors.*', 'users.name as user_name', 'users.email',
                    'people.full_name', 'people.nickname'
                )
                ->join('entity_users', 'doctors.entity_user_id', '=', 'entity_users.id')
                ->join('users', 'entity_users.user_id', '=', 'users.id')
                ->join('people', 'doctors.person_id', '=', 'people.id')
                ->where('entity_users.entity_id', session()->get('selected_entity_id'))
                ->skip($start)
                ->take($limit)
                ->orderBy($order, $dir)
                ->get();

            $totalFiltered = $totalRecords;
        } else {
            $search = $request->get('search')['value'];
            $query  = $this->model->query()
                ->select(
                    'doctors.*', 'users.name as user_name', 'users.email',
                    'people.full_name', 'people.nickname'
                )
                ->join('entity_users', 'doctors.entity_user_id', '=', 'entity_users.id')
                ->join('users', 'entity_users.user_id', '=', 'users.id')
                ->join('people', 'doctors.person_id', '=', 'people.id')
                ->where('entity_users.entity_id', session()->get('selected_entity_id'))
                ->where(function ($query) use ($search) {
                    $query->where('people.full_name', 'like', "%{$search}%")
                        ->orWhere('people.nickname', 'like', "%{$search}%")
                        ->orWhere('users.email', 'like', "%{$search}%")
                        ->orWhere('doctors.record', 'like', "%{$search}%");
                });

            $records       = $query->skip($start)->take($limit)->orderBy($order, $dir)->get();
            $totalFiltered = $query->count();

        }
        $data = [];

        if (count($records)) {
            foreach ($records as $record) {
                $information['created_at'] = $record->created_at->format('d/m/Y H:i');
                $information['code']       = $record->code;
                $information['name']       = $record->user_name;
                $information['record']     = $record->record;
                $information['email']      = $record->email;
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
