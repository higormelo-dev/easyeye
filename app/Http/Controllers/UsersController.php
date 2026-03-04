<?php

namespace App\Http\Controllers;

use App\DataTables\UsersDataTable;
use App\Http\Requests\EntityUserRequest;
use App\Http\Resources\EntityUserResource;
use App\Models\{EntityUser, User};
use App\Services\EntityUserService;
use Illuminate\Contracts\View\{Factory, View};
use Illuminate\Foundation\Application;
use Illuminate\Http\{JsonResponse, RedirectResponse, Request};
use Illuminate\Routing\Redirector;
use Illuminate\Support\Facades\DB;

class UsersController extends Controller
{
    /**
     * Instance of the standard model.
     */
    protected EntityUser $model;

    protected EntityUserService $service;

    public function __construct(EntityUser $entityUser, EntityUserService $entityUserService)
    {
        $this->titleController = __('actions.users');
        $this->model           = $entityUser;
        $this->service         = $entityUserService;
    }

    /**
     * Display a listing of the resource.
     */
    public function index(UsersDataTable $dataTable): Factory|Application|View|JsonResponse
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

        return $dataTable->render('system.users.index', compact('meta'));
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
            $clientRoles = User::$rolesOfClients;
            unset($clientRoles['doctor']);
            $roles = array_merge($roles, $clientRoles);
        }

        return view('system.users.form', compact('roles'));
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(EntityUserRequest $request): Application|RedirectResponse|Redirector|JsonResponse|EntityUserResource
    {
        $record = $this->service->create($request);

        $messageReturn = $this->getCreateMessage();

        if (request()->wantsJson()) {
            return response()->json([
                'message' => $messageReturn,
                'data'    => (new EntityUserResource($record))['data'],
            ]);
        }

        return redirect(action('\\' . static::class . '@index'))
            ->with('message', $messageReturn);
    }

    /**
     * Display the specified resource.
     */
    public function show(string $id): Application|View|JsonResponse|EntityUserResource
    {
        $record = $this->service->findByIdOrCode($id);

        if (request()->wantsJson()) {
            return response()->json([
                'data' => (new EntityUserResource($record))['data'],
            ]);
        }

        return view(
            'system.users.show',
            compact('record')
        );
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(string $id): Application|View|JsonResponse|EntityUserResource
    {
        $record = $this->service->findByIdOrCode($id);
        $roles  = ['' => 'Selecione uma opção'];

        if (! session()->get('selected_entity_is_client')) {
            $roles = array_merge($roles, User::$rolesOfManager);
        } else {
            $clientRoles = User::$rolesOfClients;
            unset($clientRoles['doctor']);
            $roles = array_merge($roles, $clientRoles);
        }

        if (request()->wantsJson()) {
            return response()->json([
                'data' => (new EntityUserResource($record))['data'],
            ]);
        }

        return view('system.users.form', compact('record', 'roles'));
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(EntityUserRequest $request, string $id): Application|JsonResponse|Redirector|RedirectResponse|EntityUserResource
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
            $messageReturn = $this->getDeleteMessage();
            $recordData    = $record->toArray();
            $record->delete();

            // Retornar resposta
            if (request()->wantsJson()) {
                return response()->json([
                    'message' => $messageReturn,
                    'deleted' => $recordData,
                ]);
            }

            return redirect(action('\\' . static::class . '@index'))
                ->with('message', $messageReturn);
        });
    }

    /**
     * Restore the specified resource from storage.
     */
    public function restore(string $id): Application|View|JsonResponse
    {
        $record = $this->service->findByIdOrCode($id);

        return DB::transaction(function () use ($record) {
            $messageReturn = $this->getRestoreMessage();
            $recordData    = $record->toArray();
            $record->restore();

            // Retornar resposta
            if (request()->wantsJson()) {
                return response()->json([
                    'message'  => $messageReturn,
                    'restored' => $recordData,
                ]);
            }

            return redirect(action('\\' . static::class . '@index'))
                ->with('message', $messageReturn);
        });
    }
}
