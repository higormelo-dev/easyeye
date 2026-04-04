<?php

namespace App\DataTables;

use App\Models\Plan;
use Illuminate\Database\Eloquent\Builder;
use Yajra\DataTables\EloquentDataTable;
use Yajra\DataTables\Html\{Builder as HtmlBuilder, Column};

class PlansDataTable extends BaseDataTable
{
    /**
     * Build the DataTable class.
     *
     * @param Builder<Plan> $query
     */
    public function dataTable(Builder $query): EloquentDataTable
    {
        return (new EloquentDataTable($query))
            ->addColumn('action', fn (Plan $record) => $this->buildPlanActionButtons($record))
            ->editColumn('price', fn (Plan $record) => 'R$ ' . number_format((float) $record->price, 2, ',', '.'))
            ->editColumn('billing_cycle', fn (Plan $record) => $record->billing_cycle->label())
            ->editColumn('active', fn (Plan $record) => $this->formatActiveColumn($record))
            ->rawColumns(['active', 'action'])
            ->filterColumn('name', function ($query, $keyword) {
                $query->whereRaw('LOWER(plans.name) LIKE ?', ['%' . mb_strtolower($keyword, 'UTF-8') . '%']);
            })
            ->setRowId('id');
    }

    /**
     * Get the query source of dataTable.
     */
    public function query(Plan $model): Builder
    {
        return $model->newQuery()->select('plans.*');
    }

    /**
     * Optional method if you want to use the html builder.
     */
    public function html(): HtmlBuilder
    {
        return $this->builder()
            ->setTableId('plans_datatable')
            ->columns($this->getColumns())
            ->minifiedAjax()
            ->orderBy(0, 'asc')
            ->selectStyleSingle()
            ->parameters($this->getDefaultParameters());
    }

    /**
     * Get the dataTable columns definition.
     */
    public function getColumns(): array
    {
        return [
            Column::make('sort_order')
                ->title('Ordem')
                ->width('80px'),
            Column::make('name')
                ->title(__('actions.name')),
            Column::make('price')
                ->title('Preço')
                ->searchable(false),
            Column::make('billing_cycle')
                ->title('Ciclo')
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

    /**
     * Build action buttons for plans (no entity_id scoping).
     */
    private function buildPlanActionButtons(Plan $record): string
    {
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
        <li><a class="dropdown-item btn-active" href="javascript:void(0);"
               data-id="' . $record->id . '" data-situation="' . ($record->active ? 0 : 1) . '">
            <i class="ti ' . ($record->active ? 'ti-lock-open' : 'ti-lock') . ' me-1"></i>' . ($record->active ? __('actions.disable') : __('actions.enable')) . '</a></li>
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
        return 'Plans_' . date('YmdHis');
    }
}
