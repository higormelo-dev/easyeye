<?php

namespace App\DataTables;

use App\Models\EntityIntegratorEquipment;
use Illuminate\Database\Eloquent\Builder;
use Yajra\DataTables\EloquentDataTable;
use Yajra\DataTables\Html\{Builder as HtmlBuilder, Column};

class EntityIntegratorEquipmentsDataTable extends BaseDataTable
{
    protected ?string $integratorId = null;

    public function forIntegrator(string $integratorId): static
    {
        $this->integratorId = $integratorId;

        return $this;
    }

    /**
     * Build the DataTable class.
     *
     * @param Builder<EntityIntegratorEquipment> $query
     */
    public function dataTable(Builder $query): EloquentDataTable
    {
        return (new EloquentDataTable($query))
            ->addColumn('action', fn (EntityIntegratorEquipment $record) => $this->buildEquipmentActionButtons($record))
            ->editColumn('created_at', fn (EntityIntegratorEquipment $record) => $this->formatDateColumn($record->created_at))
            ->editColumn('active', fn (EntityIntegratorEquipment $record) => $this->formatActiveColumn($record))
            ->rawColumns(['active', 'action'])
            ->filterColumn('name', function ($query, $keyword) {
                $query->whereRaw('LOWER(entity_integrator_equipments.name) LIKE ?', ['%' . mb_strtolower($keyword, 'UTF-8') . '%']);
            })
            ->filterColumn('code', function ($query, $keyword) {
                $query->whereRaw('LOWER(entity_integrator_equipments.code) LIKE ?', ['%' . mb_strtolower($keyword, 'UTF-8') . '%']);
            })
            ->setRowId('id');
    }

    /**
     * Get the query source of dataTable.
     */
    public function query(EntityIntegratorEquipment $model): Builder
    {
        $query = $model->newQuery()
            ->withTrashed()
            ->select('entity_integrator_equipments.*');

        if ($this->integratorId) {
            $query->where('integrator_id', $this->integratorId);
        }

        return $query;
    }

    /**
     * Optional method if you want to use the html builder.
     */
    public function html(): HtmlBuilder
    {
        return $this->builder()
            ->setTableId('equipments_datatable')
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
                ->title(__('actions.name')),
            Column::make('ip')
                ->title('IP')
                ->searchable(false),
            Column::make('mac')
                ->title('MAC')
                ->searchable(false),
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

    private function buildEquipmentActionButtons(EntityIntegratorEquipment $record): string
    {
        return '<a href="javascript:void(0);"
            class="btn-show shadow-sm fs-14 d-inline-flex border rounded-2 p-1"
            data-id="' . $record->id . '" data-bs-toggle="tooltip" data-bs-placement="bottom"
            title="' . __('actions.view') . '"><i class="ti ti-eye"></i></a>';
    }

    /**
     * Get the filename for export.
     */
    protected function filename(): string
    {
        return 'EntityIntegratorEquipments_' . date('YmdHis');
    }
}
