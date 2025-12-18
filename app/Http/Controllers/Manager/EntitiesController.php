<?php

namespace App\Http\Controllers\Manager;

use App\Http\Controllers\Controller;
use App\Models\Entity;
use Illuminate\Contracts\View\{Factory, View};
use Illuminate\Foundation\Application;
use Illuminate\Http\{JsonResponse, Request};

class EntitiesController extends Controller
{
    /**
     * @var string
     */
    protected string $titleController = 'Empresas';

    /**
     * Instance of the standard model.
     */
    protected Entity $model;

    public function __construct(Entity $entity)
    {
        $this->model = $entity;
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
                    'url'    => route('panel.manager.entities.index'),
                    'active' => false,
                ],
                [
                    'label'  => __('actions.records'),
                    'url'    => 'javascript:void(0);',
                    'active' => true,
                ],
            ],
        ];

        return view('system.manager.entities.index', compact('meta'));
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        //
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        //
    }

    /**
     * Display the specified resource.
     */
    public function show(string $id)
    {
        //
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(string $id)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, string $id)
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $id)
    {
        //
    }

    /**
     * @param  Request $request
     * @return JsonResponse
     */
    public function ajaxDatatable(Request $request): JsonResponse
    {
        $columns = [
            0 => 'created_at',
            1 => 'name',
            2 => 'active',
            3 => 'action',
        ];

        $totalRecords = $this->model->count();
        $limit        = $request->get('length');
        $start        = $request->get('start');
        $order        = $columns[$request->get('order')[0]['column']];
        $dir          = $request->get('order')[0]['dir'];

        if (empty($request->get('search')['value'])) {
            $records = $this->model->skip($start)->take($limit)
                ->orderBy($order, $dir)->get();
            $totalFiltered = $this->model->count();
        } else {
            $search  = $request->get('search')['value'];
            $records = $this->model->where('name', 'like', "%{$search}%")
                ->skip($start)
                ->take($limit)
                ->orderBy($order, $dir)
                ->get();
            $totalFiltered = $this->model->where('name', 'like', "%{$search}%")
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

                $btnActions .= '<a href="' . route('panel.manager.entities.integrators.index', $record->id) . '"
                    class="btn waves-effect waves-light btn-secondary btn-xs m-1 btn-show"
                    data-bs-toggle="tooltip" data-bs-placement="bottom"
                    title="Integradores"><i class="fas fa-cogs"></i></a>';

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
}
