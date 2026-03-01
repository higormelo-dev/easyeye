<?php

namespace App\DataTables;

use App\Models\Patient;
use Illuminate\Database\Eloquent\Builder;
use Yajra\DataTables\EloquentDataTable;
use Yajra\DataTables\Html\{Builder as HtmlBuilder, Column};

class PatientsDataTable extends BaseDataTable
{
    /**
     * Build the DataTable class.
     *
     * @param  Builder<Patient>  $query
     */
    public function dataTable(Builder $query): EloquentDataTable
    {
        return (new EloquentDataTable($query))
            ->addColumn('action', fn (Patient $record) => $this->buildActionButtons($record))
            ->addColumn('name', fn (Patient $record) => $record->full_name)
            ->addColumn('gender_label', fn (Patient $record) => $record->person->present()->getGender)
            ->addColumn('cellphone_label',
                fn (Patient $record) => ($record->person->whatsapp ?
                        '<i class="fab fa-whatsapp text-success"></i>' : '') . ' ' .
                    $record->person->present()->getCellphone
            )
            ->editColumn('created_at', fn (Patient $record) => $this->formatDateColumn($record->created_at))
            ->editColumn('active', fn (Patient $record) => $this->formatActiveColumn($record))
            ->rawColumns(['active', 'action', 'cellphone_label'])
            ->setRowId('id');
    }

    /**
     * Get the query source of dataTable.
     */
    public function query(Patient $model): Builder
    {
        return $model->newQuery()
            ->withTrashed()
            ->select(
                'patients.*',
                'people.full_name',
                'people.gender',
                'people.cellphone',
                'people.whatsapp'
            )
            ->join('people', 'patients.person_id', '=', 'people.id')
            ->where(function ($query) {
                $query->where('patients.entity_id', session()->get('selected_entity_id'))
                    ->orWhere(function ($q) {
                        $q->whereNull('patients.entity_id')
                            ->whereNull('patients.deleted_at');
                    });
            });
    }

    /**
     * Optional method if you want to use the html builder.
     */
    public function html(): HtmlBuilder
    {
        return $this->builder()
            ->setTableId('patients_datatable')
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
                ->title(__('actions.name'))
                ->data('name')
                ->name('people.full_name'),
            Column::make('gender_label')
                ->title(__('actions.gender'))
                ->data('gender_label')
                ->name('people.gender')
                ->searchable(false),
            Column::make('cellphone_label')
                ->title(__('actions.cellphone'))
                ->name('people.cellphone'),
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
        return 'Patients_' . date('YmdHis');
    }
}
