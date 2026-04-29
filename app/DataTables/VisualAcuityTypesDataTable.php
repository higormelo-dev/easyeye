<?php

namespace App\DataTables;

use App\Models\VisualAcuityType;
use Illuminate\Database\Eloquent\Builder;
use Yajra\DataTables\EloquentDataTable;
use Yajra\DataTables\Html\{Builder as HtmlBuilder, Column};

class VisualAcuityTypesDataTable extends BaseDataTable
{
    /**
     * Build the DataTable class.
     *
     * @param Builder<VisualAcuityType> $query
     */
    public function dataTable(Builder $query): EloquentDataTable
    {
        return (new EloquentDataTable($query))
            ->addColumn('action', fn (VisualAcuityType $record) => $this->buildActionButtons($record, ['variant' => 'dropdown', 'show' => true, 'global_view' => true]))
            ->editColumn('created_at', fn (VisualAcuityType $record) => $this->formatDateColumn($record->created_at))
            ->editColumn('active', fn (VisualAcuityType $record) => $this->formatActiveColumn($record))
            ->rawColumns(['active', 'action'])
            ->setRowId('id');
    }

    /**
     * Get the query source of dataTable.
     */
    public function query(VisualAcuityType $model): Builder
    {
        return $model->newQuery()
            ->withTrashed()
            ->select('visual_acuity_types.*')
            ->where('visual_acuity_types.entity_id', session()->get('selected_entity_id'))
            ->orWhere(function ($query) {
                $query->whereNull('visual_acuity_types.entity_id')
                    ->whereNull('visual_acuity_types.deleted_at');
            });
    }

    /**
     * Optional method if you want to use the html builder.
     */
    public function html(): HtmlBuilder
    {
        return $this->builder()
            ->setTableId('visualacuitytypes_datatable')
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
                ->title(__('actions.code')),
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

    /**
     * Get the filename for export.
     */
    protected function filename(): string
    {
        return 'VisualAcuityTypes_' . date('YmdHis');
    }
}
