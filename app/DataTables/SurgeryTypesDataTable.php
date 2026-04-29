<?php

namespace App\DataTables;

use App\Models\SurgeryType;
use Illuminate\Database\Eloquent\Builder;
use Yajra\DataTables\EloquentDataTable;
use Yajra\DataTables\Html\{Builder as HtmlBuilder, Column};

class SurgeryTypesDataTable extends BaseDataTable
{
    /**
     * Build the DataTable class.
     *
     * @param Builder<SurgeryType> $query
     */
    public function dataTable(Builder $query): EloquentDataTable
    {
        return (new EloquentDataTable($query))
            ->addColumn('action', fn (SurgeryType $record) => $this->buildActionButtons($record, ['variant' => 'dropdown', 'show' => true, 'global_view' => true]))
            ->editColumn('created_at', fn (SurgeryType $record) => $this->formatDateColumn($record->created_at))
            ->editColumn('active', fn (SurgeryType $record) => $this->formatActiveColumn($record))
            ->editColumn('category', fn (SurgeryType $record) => $record->present()->getCategory())
            ->rawColumns(['active', 'action'])
            ->setRowId('id');
    }

    /**
     * Get the query source of dataTable.
     */
    public function query(SurgeryType $model): Builder
    {
        return $model->newQuery()
            ->withTrashed()
            ->select('surgery_types.*')
            ->where(function ($query) {
                $query->where('surgery_types.entity_id', session()->get('selected_entity_id'))
                    ->orWhere(function ($q) {
                        $q->whereNull('surgery_types.entity_id')
                            ->whereNull('surgery_types.deleted_at');
                    });
            });
    }

    /**
     * Optional method if you want to use the html builder.
     */
    public function html(): HtmlBuilder
    {
        return $this->builder()
            ->setTableId('surgerytypes_datatable')
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
            Column::make('category')
                ->title(__('actions.category'))
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

    /**
     * Get the filename for export.
     */
    protected function filename(): string
    {
        return 'SurgeryTypes_' . date('YmdHis');
    }
}
