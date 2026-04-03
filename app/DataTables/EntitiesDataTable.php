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
     * @param  Builder<Entity>  $query
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
        $btnActions = '';

        if (!$record->deleted_at) {
            $btnActions .= '<a href="javascript:void(0);"
                class="btn waves-effect waves-light btn-light btn-xs m-1 btn-edit"
                data-id="' . $record->id . '" data-bs-toggle="tooltip" data-bs-placement="bottom"
                title="' . __('actions.edit') . '"><i class="fa fa-edit"></i></a>';

            $btnActions .= '<a href="javascript:void(0);"
                class="btn waves-effect waves-light btn-light btn-xs m-1 btn-show"
                data-id="' . $record->id . '" data-bs-toggle="tooltip" data-bs-placement="bottom"
                title="' . __('actions.view') . '"><i class="fa fa-eye"></i></a>';

            if ($record->entity_users_count > 0) {
                $btnActions .= '<a href="' . route('panel.manager.entities.users', $record->id) . '"
                    class="btn waves-effect waves-light btn-light btn-xs m-1"
                    data-bs-toggle="tooltip" data-bs-placement="bottom"
                    title="Usuários"><i class="fas fa-users"></i></a>';
            } else {
                $btnActions .= '<a href="javascript:void(0);"
                    class="btn waves-effect waves-light btn-light btn-xs m-1 disabled"
                    aria-disabled="true" tabindex="-1"
                    data-bs-toggle="tooltip" data-bs-placement="bottom"
                    title="Usuários"><i class="fas fa-users"></i></a>';
            }

            if ($record->entity_user_integrators_count > 0) {
                $btnActions .= '<a href="' . route('panel.manager.entities.user-integrators.index', $record->id) . '"
                    class="btn waves-effect waves-light btn-light btn-xs m-1"
                    data-bs-toggle="tooltip" data-bs-placement="bottom"
                    title="Usuários Integradores"><i class="fas fa-user-cog"></i></a>';
            } else {
                $btnActions .= '<a href="javascript:void(0);"
                    class="btn waves-effect waves-light btn-light btn-xs m-1 disabled"
                    aria-disabled="true" tabindex="-1"
                    data-bs-toggle="tooltip" data-bs-placement="bottom"
                    title="Usuários Integradores"><i class="fas fa-user-cog"></i></a>';
            }

            $btnActions .= '<a href="javascript:void(0);"
                class="btn waves-effect waves-light btn-light btn-xs m-1 btn-active"
                data-id="' . $record->id . '" data-situation="' . ($record->active ? 0 : 1) . '"
                data-bs-toggle="tooltip" data-bs-placement="bottom"
                title="' . ($record->active ? __('actions.disable') : __('actions.enable')) . '">
                <i class="fas ' . ($record->active ? 'fa-lock-open' : 'fa-unlock') . '"></i></a>';

            $btnActions .= '<a href="javascript:void(0);"
                class="btn waves-effect waves-light btn-danger btn-xs m-1 btn-trash"
                data-id="' . $record->id . '" data-bs-toggle="tooltip" data-bs-placement="bottom"
                title="' . __('actions.delete') . '"><i class="fas fa-trash-alt"></i></a>';
        } else {
            $btnActions .= '<a href="javascript:void(0);"
                class="btn waves-effect waves-light btn-light btn-xs m-1 btn-restore"
                data-id="' . $record->id . '" data-bs-toggle="tooltip" data-bs-placement="bottom"
                title="' . __('actions.restore') . '"><i class="fas fa-recycle"></i></a>';
        }

        return $btnActions;
    }

    /**
     * Get the filename for export.
     */
    protected function filename(): string
    {
        return 'Entities_' . date('YmdHis');
    }
}
