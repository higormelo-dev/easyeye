<?php

namespace App\DataTables;

use App\Models\EntityIntegrator;
use Illuminate\Database\Eloquent\Builder;
use Yajra\DataTables\EloquentDataTable;
use Yajra\DataTables\Html\{Builder as HtmlBuilder, Column};

class EntityIntegratorsDataTable extends BaseDataTable
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
     * @param  Builder<EntityIntegrator>  $query
     */
    public function dataTable(Builder $query): EloquentDataTable
    {
        return (new EloquentDataTable($query))
            ->addColumn('action', fn (EntityIntegrator $record) => $this->buildIntegratorActionButtons($record))
            ->editColumn('created_at', fn (EntityIntegrator $record) => $this->formatDateColumn($record->created_at))
            ->editColumn('active', fn (EntityIntegrator $record) => $this->formatIntegratorActiveColumn($record))
            ->rawColumns(['active', 'action'])
            ->filterColumn('name', function ($query, $keyword) {
                $query->whereRaw('LOWER(entity_integrators.name) LIKE ?', ['%' . mb_strtolower($keyword, 'UTF-8') . '%']);
            })
            ->setRowId('id');
    }

    /**
     * Get the query source of dataTable.
     */
    public function query(EntityIntegrator $model): Builder
    {
        $query = $model->newQuery()
            ->withTrashed()
            ->select('entity_integrators.*');

        if ($this->entityId) {
            $query->whereHas('user', fn ($q) => $q->where('entity_id', $this->entityId));
        }

        return $query;
    }

    /**
     * Optional method if you want to use the html builder.
     */
    public function html(): HtmlBuilder
    {
        return $this->builder()
            ->setTableId('integrators_datatable')
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
            Column::make('name')
                ->title(__('actions.name')),
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

    private function buildIntegratorActionButtons(EntityIntegrator $record): string
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

            $btnActions .= '<a href="' . route('panel.manager.entities.integrators.equipments.index', [$this->entityId, $record->id]) . '"
                class="btn waves-effect waves-light btn-secondary btn-xs m-1"
                data-bs-toggle="tooltip" data-bs-placement="bottom"
                title="' . __('actions.equipments') . '"><i class="fas fa-satellite-dish"></i></a>';

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

    private function formatIntegratorActiveColumn(EntityIntegrator $record): string
    {
        if ($record->deleted_at) {
            return '<span class="badge bg-secondary">' . __('actions.deleted_record') . '</span>';
        }

        return $record->active
            ? '<span class="badge bg-success">' . __('actions.yes') . '</span>'
            : '<span class="badge bg-dark">' . __('actions.no') . '</span>';
    }

    /**
     * Get the filename for export.
     */
    protected function filename(): string
    {
        return 'EntityIntegrators_' . date('YmdHis');
    }
}
