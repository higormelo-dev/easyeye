<?php

namespace App\DataTables;

use App\Models\EntityUserIntegrator;
use Illuminate\Database\Eloquent\Builder;
use Yajra\DataTables\EloquentDataTable;
use Yajra\DataTables\Html\{Builder as HtmlBuilder, Column};

class EntityUserIntegratorsDataTable extends BaseDataTable
{
    protected ?string $entityId = null;

    public function forEntity(string $entityId): static
    {
        $this->entityId = $entityId;

        return $this;
    }

    /**
     * Build the DataTable class.
     *
     * @param  Builder<EntityUserIntegrator>  $query
     */
    public function dataTable(Builder $query): EloquentDataTable
    {
        return (new EloquentDataTable($query))
            ->addColumn('action', fn (EntityUserIntegrator $record) => $this->buildUserIntegratorActionButtons($record))
            ->editColumn('created_at', fn (EntityUserIntegrator $record) => $this->formatDateColumn($record->created_at))
            ->editColumn('active', fn (EntityUserIntegrator $record) => $this->formatActiveColumn($record))
            ->rawColumns(['active', 'action'])
            ->filterColumn('name', function ($query, $keyword) {
                $query->whereRaw('LOWER(entity_user_integrators.name) LIKE ?', ['%' . mb_strtolower($keyword, 'UTF-8') . '%']);
            })
            ->filterColumn('email', function ($query, $keyword) {
                $query->whereRaw('LOWER(entity_user_integrators.email) LIKE ?', ['%' . mb_strtolower($keyword, 'UTF-8') . '%']);
            })
            ->setRowId('id');
    }

    /**
     * Get the query source of dataTable.
     */
    public function query(EntityUserIntegrator $model): Builder
    {
        $query = $model->newQuery()
            ->withTrashed()
            ->select('entity_user_integrators.*')
            ->selectRaw('(SELECT COUNT(*) FROM entity_integrators WHERE entity_integrators.entity_user_integrator_id = entity_user_integrators.id AND entity_integrators.deleted_at IS NULL) as integrators_count');

        if ($this->entityId) {
            $query->where('entity_id', $this->entityId);
        }

        return $query;
    }

    /**
     * Optional method if you want to use the html builder.
     */
    public function html(): HtmlBuilder
    {
        return $this->builder()
            ->setTableId('user_integrators_datatable')
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
            Column::make('code')
                ->title(__('actions.code'))
                ->searchable(false),
            Column::make('created_at')
                ->title(__('actions.created_at'))
                ->searchable(false),
            Column::make('name')
                ->title(__('actions.name')),
            Column::make('email')
                ->title(__('actions.email')),
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

    private function buildUserIntegratorActionButtons(EntityUserIntegrator $record): string
    {
        $btnActions = '';

        if (! $record->deleted_at) {
            $btnActions .= '<a href="javascript:void(0);"
                class="btn waves-effect waves-light btn-secondary btn-xs m-1 btn-edit"
                data-id="' . $record->id . '" data-bs-toggle="tooltip" data-bs-placement="bottom"
                title="' . __('actions.edit') . '"><i class="fa fa-edit"></i></a>';

            $btnActions .= '<a href="javascript:void(0);"
                class="btn waves-effect waves-light btn-secondary btn-xs m-1 btn-show"
                data-id="' . $record->id . '" data-bs-toggle="tooltip" data-bs-placement="bottom"
                title="' . __('actions.view') . '"><i class="fa fa-eye"></i></a>';

            if ($record->integrators_count > 0) {
                $btnActions .= '<a href="' . route('panel.manager.entities.user-integrators.integrators.index', [$this->entityId, $record->id]) . '"
                    class="btn waves-effect waves-light btn-secondary btn-xs m-1"
                    data-bs-toggle="tooltip" data-bs-placement="bottom"
                    title="' . __('actions.integrators') . '"><i class="fas fa-cogs"></i></a>';
            } else {
                $btnActions .= '<a href="javascript:void(0);"
                    class="btn waves-effect waves-light btn-secondary btn-xs m-1 disabled"
                    aria-disabled="true" tabindex="-1"
                    data-bs-toggle="tooltip" data-bs-placement="bottom"
                    title="' . __('actions.integrators') . '"><i class="fas fa-cogs"></i></a>';
            }

            $btnActions .= '<a href="javascript:void(0);"
                class="btn waves-effect waves-light btn-secondary btn-xs m-1 btn-active"
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
                class="btn waves-effect waves-light btn-secondary btn-xs m-1 btn-show"
                data-id="' . $record->id . '" data-bs-toggle="tooltip" data-bs-placement="bottom"
                title="' . __('actions.view') . '"><i class="fa fa-eye"></i></a>';

            $btnActions .= '<a href="javascript:void(0);"
                class="btn waves-effect waves-light btn-warning btn-xs m-1 btn-restore"
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
        return 'EntityUserIntegrators_' . date('YmdHis');
    }
}
