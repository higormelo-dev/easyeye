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
            ->editColumn('active', fn (Plan $record) => $record->active
                ? '<span class="badge bg-success">' . __('actions.yes') . '</span>'
                : '<span class="badge bg-dark">' . __('actions.no') . '</span>')
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
            <a href="javascript:void(0);"
                class="btn waves-effect waves-light btn-light btn-xs m-1 btn-edit"
                data-id="' . $record->id . '" data-bs-toggle="tooltip" data-bs-placement="bottom"
                title="' . __('actions.edit') . '"><i class="fa fa-edit"></i></a>
            <a href="javascript:void(0);"
                class="btn waves-effect waves-light btn-light btn-xs m-1 btn-show"
                data-id="' . $record->id . '" data-bs-toggle="tooltip" data-bs-placement="bottom"
                title="' . __('actions.view') . '"><i class="fa fa-eye"></i></a>
            <a href="javascript:void(0);"
                class="btn waves-effect waves-light btn-light btn-xs m-1 btn-active"
                data-id="' . $record->id . '" data-situation="' . ($record->active ? 0 : 1) . '"
                data-bs-toggle="tooltip" data-bs-placement="bottom"
                title="' . ($record->active ? __('actions.disable') : __('actions.enable')) . '">
                <i class="fas ' . ($record->active ? 'fa-lock-open' : 'fa-unlock') . '"></i></a>
            <a href="javascript:void(0);"
                class="btn waves-effect waves-light btn-danger btn-xs m-1 btn-trash"
                data-id="' . $record->id . '" data-bs-toggle="tooltip" data-bs-placement="bottom"
                title="' . __('actions.delete') . '"><i class="fas fa-trash-alt"></i></a>';
    }

    /**
     * Get the filename for export.
     */
    protected function filename(): string
    {
        return 'Plans_' . date('YmdHis');
    }
}
