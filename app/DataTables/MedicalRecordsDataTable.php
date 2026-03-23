<?php

namespace App\DataTables;

use App\Models\MedicalRecord;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;
use Yajra\DataTables\EloquentDataTable;
use Yajra\DataTables\Html\{Builder as HtmlBuilder, Column};

class MedicalRecordsDataTable extends BaseDataTable
{
    protected string $patientId;

    public function forPatient(string $patientId): self
    {
        $this->patientId = $patientId;

        return $this;
    }

    /**
     * Build the DataTable class.
     *
     * @param  Builder<MedicalRecord>  $query
     */
    public function dataTable(Builder $query): EloquentDataTable
    {
        return (new EloquentDataTable($query))
            ->addColumn('action', fn (MedicalRecord $record) => $this->buildActionButtons($record, [
                'active'  => false,
                'restore' => false,
            ]))
            ->addColumn('doctor_name', fn (MedicalRecord $record) => $record->doctor?->person?->full_name ?? __('actions.not_informed'))
            ->addColumn('complaint', fn (MedicalRecord $record) => Str::limit(strip_tags($record->main_complaint ?? ''), 80))
            ->editColumn('created_at', fn (MedicalRecord $record) => $this->formatDateColumn($record->created_at))
            ->rawColumns(['action'])
            ->setRowId('id');
    }

    /**
     * Get the query source of dataTable.
     */
    public function query(MedicalRecord $model): Builder
    {
        return $model->newQuery()
            ->with(['doctor.person'])
            ->where('patient_id', $this->patientId)
            ->select('medical_records.*');
    }

    /**
     * Optional method if you want to use the html builder.
     */
    public function html(): HtmlBuilder
    {
        return $this->builder()
            ->setTableId('medical_records_datatable')
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
            Column::make('doctor_name')
                ->title(__('actions.doctor'))
                ->orderable(false),
            Column::make('complaint')
                ->title(__('actions.main_complaint'))
                ->orderable(false)
                ->searchable(false),
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
        return 'MedicalRecords_' . date('YmdHis');
    }
}
