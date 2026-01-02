<?php

namespace App\Http\Controllers;

use App\Http\Requests\EntityUserRequest;
use App\Http\Resources\EntityUserResource;
use App\Models\{EntityUser, User};
use Illuminate\Contracts\View\{Factory, View};
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\{JsonResponse, RedirectResponse, Request};
use Illuminate\Routing\Redirector;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

class UsersController extends Controller
{
    protected string $titleController = 'Usuários';

    /**
     * Instance of the standard model.
     */
    protected EntityUser $model;

    public function __construct(EntityUser $entityUser)
    {
        $this->model = $entityUser;
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

        return view('system.users.index', compact('meta'));
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        $roles = ['' => 'Selecione uma opção'];

        if (! session()->get('selected_entity_is_client')) {
            $roles = array_merge($roles, User::$rolesOfManager);
        } else {
            $roles = array_merge($roles, User::$rolesOfClients);
        }

        return view('system.users.form', compact('roles'));
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(EntityUserRequest $request)
    {
        try {
            return DB::transaction(function () use ($request) {
                $existingUser = User::query()->withTrashed()
                    ->where('email', $request->email)->first();

                if ($existingUser) {
                    if ($existingUser->trashed()) {
                        $existingUser->restore();
                        $existingUser->update([
                            'name'     => $request->name,
                            'password' => $request->password,
                        ]);
                        $existingUser->markEmailAsVerified();
                        $user = $existingUser;
                    } else {
                        $existingUser->update([
                            'name'     => $request->name,
                            'password' => $request->password,
                        ]);
                        $user = $existingUser;
                    }
                } else {
                    $user = User::create($request->only(['name', 'email', 'password']));
                    $user->markEmailAsVerified();
                }

                $existingEntityUser = EntityUser::query()->withTrashed()
                    ->where('user_id', $user->id)
                    ->where('entity_id', session()->get('selected_entity_id'))
                    ->first();

                if ($existingEntityUser) {
                    if ($existingEntityUser->trashed()) {
                        $existingEntityUser->restore();
                        $existingEntityUser->update([
                            'rule'   => $request->rule,
                            'active' => true,
                        ]);
                    } else {
                        $existingEntityUser->update([
                            'rule'   => $request->rule,
                            'active' => true,
                        ]);
                    }
                } else {
                    $existingEntityUser = EntityUser::create([
                        'entity_id' => session()->get('selected_entity_id'),
                        'user_id'   => $user->id,
                        'rule'      => $request->rule,
                        'active'    => true,
                    ]);
                }

                return new EntityUserResource($existingEntityUser);
            });
        } catch (\Throwable $e) {
            return $this->serverErrorResponse();
        }
    }

    /**
     * Display the specified resource.
     */
    public function show(string $id)
    {
        try {
            $record = $this->findEntityUser($id);

            if (! $record) {
                return $this->notFoundResponse();
            }

            return view('system.users.show', compact('record'));
        } catch (\Throwable $e) {
            return $this->serverErrorResponse();
        }
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(string $id)
    {
        try {
            $record = $this->findEntityUser($id);

            if (! $record) {
                return $this->notFoundResponse();
            }

            $roles = ['' => 'Selecione uma opção'];

            if (! session()->get('selected_entity_is_client')) {
                $roles = array_merge($roles, User::$rolesOfManager);
            } else {
                $roles = array_merge($roles, User::$rolesOfClients);
            }

            return view('system.users.form', compact('record', 'roles'));
        } catch (\Throwable $e) {
            return $this->serverErrorResponse();
        }
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(EntityUserRequest $request, string $id)
    {
        try {
            $record = $this->findEntityUser($id);

            if (! $record) {
                return $this->notFoundResponse();
            }

            $record->update($request->only(['rule', 'active']));
            $record->user->update($request->only(['name', 'email']));

            if ($request->has('type_method')) {
                $messageReturn = $this->titleController .
                    ($request->active ? ' desbloqueado(a) ' : ' bloqueado(a) ') . ' com sucesso.';

            } else {
                $messageReturn = $this->titleController . ' alterado(a) com sucesso.';
            }

            if (request()->wantsJson()) {

                return response()->json(
                    [
                        'message' => $messageReturn,
                        'data'    => (new EntityUserResource($record))['data'],
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
     * Remove the specified resource from storage.
     */
    public function destroy(string $id): Application|JsonResponse|Redirector|RedirectResponse
    {
        try {
            $record = $this->findEntityUser($id);

            if (! $record) {
                return $this->notFoundResponse();
            }

            $userHasOtherEntityUsers = $this->model->query()
                ->where('user_id', $record->user_id)
                ->count();
            $record->delete();

            if ($userHasOtherEntityUsers <= 1) {
                $user = User::query()->find($record->user_id);

                if ($user) {
                    $user->delete();
                }
            }

            if (request()->wantsJson()) {
                return response()->json(
                    [
                        'message' => $this->titleController . ' deletada(a) com sucesso.',
                        'deleted' => $record->toArray(),
                    ]
                );
            }

            return redirect(action('\\' . static::class . '@index'))
                ->with('message-error', $this->titleController . ' deletada(a) com sucesso.');

        } catch (\Throwable $e) {
            return $this->serverErrorResponse();
        }
    }

    public function ajaxDatatable(Request $request): JsonResponse
    {
        $columns = [
            0 => 'created_at',
            1 => 'name',
            2 => 'email',
            3 => 'active',
            4 => 'action',
        ];

        $totalRecords = $this->model->query()->select('entity_users.*', 'users.name', 'users.email')
            ->join('users', 'entity_users.user_id', '=', 'users.id')
            ->where('entity_users.entity_id', session()->get('selected_entity_id'))
            ->whereNot('entity_users.rule', 'doctor')
            ->count();

        $limit = $request->get('length');
        $start = $request->get('start');
        $order = $columns[$request->get('order')[0]['column']];
        $dir   = $request->get('order')[0]['dir'];

        if (empty($request->get('search')['value'])) {
            $records = $this->model->query()->select('entity_users.*', 'users.name', 'users.email')
                ->join('users', 'entity_users.user_id', '=', 'users.id')
                ->where('entity_users.entity_id', session()->get('selected_entity_id'))
                ->whereNot('entity_users.rule', 'doctor')
                ->skip($start)
                ->take($limit)
                ->orderBy($order, $dir)
                ->get();
            $totalFiltered = $this->model->query()->select('entity_users.*', 'users.name', 'users.email')
                ->join('users', 'entity_users.user_id', '=', 'users.id')
                ->where('entity_users.entity_id', session()->get('selected_entity_id'))
                ->whereNot('entity_users.rule', 'doctor')
                ->count();
        } else {
            $search  = $request->get('search')['value'];
            $records = $this->model->query()->select('entity_users.*', 'users.name', 'users.email')
                ->join('users', 'entity_users.user_id', '=', 'users.id')
                ->where('entity_users.entity_id', session()->get('selected_entity_id'))
                ->whereNot('entity_users.rule', 'doctor')
                ->where('users.name', 'like', "%{$search}%")
                ->skip($start)
                ->take($limit)
                ->orderBy($order, $dir)
                ->get();
            $totalFiltered = $this->model->query()->select('entity_users.*', 'users.name', 'users.email')
                ->join('users', 'entity_users.user_id', '=', 'users.id')
                ->where('entity_users.entity_id', session()->get('selected_entity_id'))
                ->whereNot('entity_users.rule', 'doctor')
                ->where('users.name', 'like', "%{$search}%")
                ->skip($start)
                ->take($limit)
                ->orderBy($order, $dir)
                ->count();
        }
        $data = [];

        if (count($records)) {
            foreach ($records as $record) {
                $btnActions                = '';
                $information['created_at'] = $record->created_at->format('d/m/Y H:i');
                $information['name']       = $record->name;
                $information['email']      = $record->email;
                $information['active']     = $record->active ?
                    '<span class="badge bg-success">SIM</span>' :
                    '<span class="badge bg-dark">NÃO</span>';
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
                    data-name="' . $record->name . '" data-email="' . $record->email . '"
                    data-bs-toggle="tooltip" data-bs-placement="bottom"
                    title="' . (($record->active) ? 'Inativar' : 'Ativar') . '">
                    <i class="fas ' . (($record->active) ? 'fa-lock-open' : 'fa-unlock') . '"></i></a>';
                $btnActions .= '<a href="javascript:void(0);"
                    class="btn waves-effect waves-danger btn-danger btn-xs m-1 btn-trash"
                    data-id="' . $record->id . '" data-bs-toggle="tooltip" data-bs-placement="bottom"
                    title="Deletar"><i class="fas fa-trash-alt"></i></a>';
                $information['action'] = $btnActions;
                $data[]                = $information;
            }

        }

        return response()->json(
            [
                'draw'            => (int) $request->get('draw'),
                'recordsTotal'    => (int) $totalRecords,
                'recordsFiltered' => (int) $totalFiltered,
                'data'            => $data,
            ]
        );
    }

    /**
     * Find equipment for specific integrator
     */
    private function findEntityUser(string $entityUserId): ?EntityUser
    {
        return $this->model->query()->where('id', $entityUserId)->first();
    }

    /**
     * Return not found response
     */
    private function notFoundResponse(): JsonResponse
    {
        return response()->json(['message' => 'Equipment not found.'], HttpResponse::HTTP_NOT_FOUND);
    }

    /**
     * Return server error response
     */
    private function serverErrorResponse(): JsonResponse
    {
        return response()->json(['message' => 'An error occurred.'], HttpResponse::HTTP_INTERNAL_SERVER_ERROR);
    }
}
