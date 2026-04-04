<?php

namespace App\DataTables;

use App\Models\Lense;
use Illuminate\Database\Eloquent\Builder;
use Yajra\DataTables\EloquentDataTable;
use Yajra\DataTables\Html\{Builder as HtmlBuilder, Column};

class LensesDataTable extends BaseDataTable
{
    /**
     * Build the DataTable class.
     *
     * @param  Builder<Lense>  $query
     */
    public function dataTable(Builder $query): EloquentDataTable
    {
        return (new EloquentDataTable($query))
            ->addColumn('action', fn (Lense $record) => $this->buildActionButtons($record, ['variant' => 'dropdown', 'show' => true, 'global_view' => true]))
            ->editColumn('created_at', fn (Lense $record) => $this->formatDateColumn($record->created_at))
            ->editColumn('active', fn (Lense $record) => $this->formatActiveColumn($record))
            ->editColumn('type', fn (Lense $record) => $this->formatTypesColumn($record))
            ->rawColumns(['active', 'action', 'type'])
            ->setRowId('id');
    }

    /**
     * Get the query source of dataTable.
     */
    public function query(Lense $model): Builder
    {
        return $model->newQuery()
            ->withTrashed()
            ->select('lenses.*')
            ->where('lenses.entity_id', session()->get('selected_entity_id'))
            ->orWhere(function ($query) {
                $query->whereNull('lenses.entity_id')
                    ->whereNull('lenses.deleted_at');
            });
    }

    /**
     * Optional method if you want to use the html builder.
     */
    public function html(): HtmlBuilder
    {
        return $this->builder()
            ->setTableId('lenses_datatable')
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
            Column::make('type')
                ->title(__('actions.type')),
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
        return 'Lenses_' . date('YmdHis');
    }

    /**
     * Format active column with badge.
     */
    protected function formatTypesColumn(mixed $record): string
    {
        $actions = '';

        if ($record->away) {
            $actions .= '<span class="badge bg-info text-dark">' . __('actions.away') . '</span> ';
        }

        if ($record->near) {
            $actions .= '<span class="badge bg-success text-dark">' . __('actions.near') . '</span> ';
        }

        return $actions;
    }
}
