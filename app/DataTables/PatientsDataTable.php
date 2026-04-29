<?php

namespace App\DataTables;

use App\DTOs\ActionPolicy;
use App\Models\Patient;
use Illuminate\Database\Eloquent\Builder;
use Yajra\DataTables\EloquentDataTable;
use Yajra\DataTables\Html\{Builder as HtmlBuilder, Column};

class PatientsDataTable extends BaseDataTable
{
    /**
     * Build the DataTable class.
     *
     * @param Builder<Patient> $query
     */
    public function dataTable(Builder $query): EloquentDataTable
    {
        return (new EloquentDataTable($query))
            ->addColumn('action', fn (Patient $record) => $this->buildPatientActions($record))
            ->addColumn('name', fn (Patient $record) => $record->full_name)
            ->addColumn('gender_label', fn (Patient $record) => $record->person->present()->getGender)
            ->addColumn(
                'cellphone_label',
                fn (Patient $record) => ($record->person->whatsapp ?
                        '<i class="fab fa-whatsapp text-success"></i>' : '') . ' ' .
                    $record->person->present()->getCellphone,
            )
            ->editColumn('created_at', fn (Patient $record) => $this->formatDateColumn($record->created_at))
            ->editColumn('active', fn (Patient $record) => $this->formatActiveColumn($record))
            ->rawColumns(['active', 'action', 'cellphone_label'])
            ->filterColumn('name', function ($query, $keyword) {
                $query->whereRaw('LOWER(people.full_name) LIKE ?', ['%' . mb_strtolower($keyword, 'UTF-8') . '%']);
            })
            ->filterColumn('code', function ($query, $keyword) {
                $query->whereRaw('LOWER(patients.code) LIKE ?', ['%' . mb_strtolower($keyword, 'UTF-8') . '%']);
            })
            ->filterColumn('cellphone_label', function ($query, $keyword) {
                $query->whereRaw('LOWER(people.cellphone) LIKE ?', ['%' . mb_strtolower($keyword, 'UTF-8') . '%']);
            })
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
                'people.whatsapp',
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
     * Action buttons custom: dropdown padrão + item extra de prontuário.
     */
    private function buildPatientActions(Patient $record): string
    {
        $policy = ActionPolicy::from($record, session()->get('selected_entity_id'));

        if ($policy->mode === ActionPolicy::MODE_RESTORE) {
            return '<a href="javascript:void(0);" class="btn-restore shadow-sm fs-14 d-inline-flex border rounded-2 p-1"
                data-id="' . $record->id . '" data-bs-toggle="tooltip" data-bs-placement="bottom"
                title="' . __('actions.restore') . '"><i class="ti ti-recycle"></i></a>';
        }

        if ($policy->mode === ActionPolicy::MODE_VIEW_ONLY) {
            return '<a href="javascript:void(0);" class="btn-show shadow-sm fs-14 d-inline-flex border rounded-2 p-1"
                data-id="' . $record->id . '" data-bs-toggle="tooltip" data-bs-placement="bottom"
                title="' . __('actions.view') . '"><i class="ti ti-eye"></i></a>';
        }

        if ($policy->mode !== ActionPolicy::MODE_FULL) {
            return '';
        }

        $situation        = $policy->active ? 0 : 1;
        $toggleIcon       = $policy->active ? 'ti-lock-open' : 'ti-lock';
        $toggleLabel      = $policy->active ? __('actions.disable') : __('actions.enable');
        $medicalRecordUrl = route('panel.patients.medicalrecords.index', $record->id);

        return '
<div class="d-flex align-items-center float-end gap-1">
    <a href="javascript:void(0);" class="btn-show shadow-sm fs-14 d-inline-flex border rounded-2 p-1"
       data-id="' . $record->id . '" data-bs-toggle="tooltip" data-bs-placement="bottom"
       title="' . __('actions.view') . '"><i class="ti ti-eye"></i></a>
    <a href="' . $medicalRecordUrl . '" class="shadow-sm fs-14 d-inline-flex border rounded-2 p-1"
       data-bs-toggle="tooltip" data-bs-placement="bottom"
       title="' . __('actions.sidemenu.medical_records') . '"><i class="ti ti-stethoscope"></i></a>
    <a href="javascript:void(0);" class="shadow-sm fs-14 d-inline-flex border rounded-2 p-1"
       data-bs-toggle="dropdown" aria-expanded="false"><i class="ti ti-dots-vertical"></i></a>
    <ul class="dropdown-menu p-2">
        <li><a class="dropdown-item btn-edit" href="javascript:void(0);" data-id="' . $record->id . '">
            <i class="ti ti-edit me-1"></i>' . __('actions.edit') . '</a></li>
        <li><a class="dropdown-item btn-active" href="javascript:void(0);"
               data-id="' . $record->id . '" data-situation="' . $situation . '">
            <i class="ti ' . $toggleIcon . ' me-1"></i>' . $toggleLabel . '</a></li>
        <li><a class="dropdown-item btn-trash text-danger" href="javascript:void(0);"
               data-id="' . $record->id . '">
            <i class="ti ti-trash me-1"></i>' . __('actions.delete') . '</a></li>
    </ul>
</div>';
    }

    /**
     * Override: badge-soft para coluna de status (estilo Preclinic).
     */
    protected function formatActiveColumn(mixed $record): string
    {
        if ($record->deleted_at) {
            return '<span class="badge badge-soft-secondary rounded fs-13 fw-medium">' . __('actions.delete') . '</span>';
        }

        return $record->active
            ? '<span class="badge badge-soft-success rounded text-success border border-success fs-13 fw-medium">' . __('actions.yes') . '</span>'
            : '<span class="badge badge-soft-danger rounded text-danger border border-danger fs-13 fw-medium">' . __('actions.no') . '</span>';
    }

    /**
     * Get the filename for export.
     */
    protected function filename(): string
    {
        return 'Patients_' . date('YmdHis');
    }
}
