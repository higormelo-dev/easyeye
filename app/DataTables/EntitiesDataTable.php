<?php

namespace App\DataTables;

use App\Models\Entity;
use Illuminate\Database\Eloquent\Builder;
use Yajra\DataTables\EloquentDataTable;
use Yajra\DataTables\Html\{Builder as HtmlBuilder, Column};

class EntitiesDataTable extends BaseDataTable
{
    /**
     * Build the DataTable class.
     *
     * @param Builder<Entity> $query
     */
    public function dataTable(Builder $query): EloquentDataTable
    {
        return (new EloquentDataTable($query))
            ->addColumn('action', fn (Entity $record) => $this->buildEntityActionButtons($record))
            ->editColumn('created_at', fn (Entity $record) => $this->formatDateColumn($record->created_at))
            ->editColumn('active', fn (Entity $record) => $this->formatActiveColumn($record))
            ->rawColumns(['active', 'action'])
            ->filterColumn('name', function ($query, $keyword) {
                $query->whereRaw('LOWER(entities.name) LIKE ?', ['%' . mb_strtolower($keyword, 'UTF-8') . '%']);
            })
            ->filterColumn('code', function ($query, $keyword) {
                $query->whereRaw('LOWER(entities.code) LIKE ?', ['%' . mb_strtolower($keyword, 'UTF-8') . '%']);
            })
            ->setRowId('id');
    }

    /**
     * Get the query source of dataTable.
     */
    public function query(Entity $model): Builder
    {
        return $model->newQuery()
            ->withTrashed()
            ->select('entities.*')
            ->selectRaw('(SELECT COUNT(*) FROM entity_users WHERE entity_users.entity_id = entities.id AND entity_users.deleted_at IS NULL) as entity_users_count')
            ->selectRaw('(SELECT COUNT(*) FROM entity_user_integrators WHERE entity_user_integrators.entity_id = entities.id AND entity_user_integrators.deleted_at IS NULL) as entity_user_integrators_count')
            ->where('code', '!=', 'ENT-0000000001');
    }

    /**
     * Optional method if you want to use the html builder.
     */
    public function html(): HtmlBuilder
    {
        return $this->builder()
            ->setTableId('entities_datatable')
            ->columns($this->getColumns())
            ->minifiedAjax()
            ->orderBy(0, 'desc')
            ->selectStyleSingle()
            ->parameters($this->getDefaultParameters());
    }

    /**
     * Get the dataTable columns definition.
     */
    public function getColumns(): array
    {
        return [
            Column::make('created_at')
                ->title(__('actions.created_at'))
                ->searchable(false),
            Column::make('code')
                ->title(__('actions.code'))
                ->searchable(true),
            Column::make('name')
                ->title(__('actions.entity')),
            Column::make('active')
                ->title(__('actions.active'))
                ->searchable(false)
                ->className('text-center'),
            Column::computed('action')
                ->title(__('actions.actions'))
                ->exportable(false)
                ->printable(false)
                ->orderable(false)
                ->searchable(false)
                ->className('text-end'),
        ];
    }

    /**
     * Build action buttons specific to the Entities module (no entity_id scoping).
     */
    private function buildEntityActionButtons(Entity $record): string
    {
        if ($record->deleted_at) {
            return '<a href="javascript:void(0);" class="btn-restore shadow-sm fs-14 d-inline-flex border rounded-2 p-1"
                data-id="' . $record->id . '" data-bs-toggle="tooltip" data-bs-placement="bottom"
                title="' . __('actions.restore') . '"><i class="ti ti-recycle"></i></a>';
        }

        $usersItem = $record->entity_users_count > 0
            ? '<li><a class="dropdown-item" href="' . route('manager.entities.users', $record->id) . '">
                <i class="ti ti-users me-1"></i>Usuários</a></li>'
            : '<li><span class="dropdown-item text-muted disabled" aria-disabled="true">
                <i class="ti ti-users me-1"></i>Usuários</span></li>';

        $userIntegratorsItem = $record->entity_user_integrators_count > 0
            ? '<li><a class="dropdown-item" href="' . route('manager.entities.user-integrators.index', $record->id) . '">
                <i class="ti ti-user-cog me-1"></i>Usuários Integradores</a></li>'
            : '<li><span class="dropdown-item text-muted disabled" aria-disabled="true">
                <i class="ti ti-user-cog me-1"></i>Usuários Integradores</span></li>';

        $activeSituation = $record->active ? 0 : 1;
        $activeIcon      = $record->active ? 'ti-lock-open' : 'ti-lock';
        $activeTitle     = $record->active ? __('actions.disable') : __('actions.enable');

        return '
<div class="d-flex align-items-center float-end gap-1">
    <a href="javascript:void(0);" class="btn-show shadow-sm fs-14 d-inline-flex border rounded-2 p-1"
       data-id="' . $record->id . '" data-bs-toggle="tooltip" data-bs-placement="bottom"
       title="' . __('actions.view') . '"><i class="ti ti-eye"></i></a>
    <a href="javascript:void(0);" class="shadow-sm fs-14 d-inline-flex border rounded-2 p-1"
       data-bs-toggle="dropdown" aria-expanded="false"><i class="ti ti-dots-vertical"></i></a>
    <ul class="dropdown-menu p-2">
        <li><a class="dropdown-item btn-edit" href="javascript:void(0);" data-id="' . $record->id . '">
            <i class="ti ti-edit me-1"></i>' . __('actions.edit') . '</a></li>
        ' . $usersItem . '
        ' . $userIntegratorsItem . '
        <li><a class="dropdown-item btn-active" href="javascript:void(0);"
               data-id="' . $record->id . '" data-situation="' . $activeSituation . '">
            <i class="ti ' . $activeIcon . ' me-1"></i>' . $activeTitle . '</a></li>
        <li><a class="dropdown-item btn-trash text-danger" href="javascript:void(0);"
               data-id="' . $record->id . '">
            <i class="ti ti-trash me-1"></i>' . __('actions.delete') . '</a></li>
    </ul>
</div>';
    }

    /**
     * Get the filename for export.
     */
    protected function filename(): string
    {
        return 'Entities_' . date('YmdHis');
    }
}
