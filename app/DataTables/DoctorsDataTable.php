<?php

namespace App\DataTables;

use App\Models\Doctor;
use Illuminate\Database\Eloquent\Builder;
use Yajra\DataTables\EloquentDataTable;
use Yajra\DataTables\Html\{Builder as HtmlBuilder, Column};

class DoctorsDataTable extends BaseDataTable
{
    /**
     * Build the DataTable class.
     *
     * @param  Builder<Doctor>  $query
     */
    public function dataTable(Builder $query): EloquentDataTable
    {
        return (new EloquentDataTable($query))
            ->addColumn('action', fn (Doctor $record) => $this->buildDoctorActionButtons($record))
            ->addColumn('name', fn (Doctor $record) => $record->user_name)
            ->editColumn('created_at', fn (Doctor $record) => $this->formatDateColumn($record->created_at))
            ->editColumn('active', fn (Doctor $record) => $this->formatActiveColumn($record))
            ->filterColumn('name', function ($query, $keyword) {
                $query->whereRaw('LOWER(people.full_name) LIKE ?', ['%' . mb_strtolower($keyword, 'UTF-8') . '%']);
            })
            ->filterColumn('email', function ($query, $keyword) {
                $query->whereRaw('LOWER(users.email) LIKE ?', ['%' . mb_strtolower($keyword, 'UTF-8') . '%']);
            })
            ->rawColumns(['active', 'action'])
            ->setRowId('id');
    }

    /**
     * Get the query source of dataTable.
     */
    public function query(Doctor $model): Builder
    {
        return $model->newQuery()
            ->select(
                'doctors.*',
                'entity_users.entity_id',
                'users.name as user_name',
                'users.email',
                'people.full_name',
                'people.nickname'
            )
            ->join('entity_users', 'doctors.entity_user_id', '=', 'entity_users.id')
            ->join('users', 'entity_users.user_id', '=', 'users.id')
            ->join('people', 'doctors.person_id', '=', 'people.id')
            ->where('entity_users.entity_id', session()->get('selected_entity_id'));
    }

    /**
     * Optional method if you want to use the html builder.
     */
    public function html(): HtmlBuilder
    {
        return $this->builder()
            ->setTableId('doctors_datatable')
            ->columns($this->getColumns())
            ->minifiedAjax()
            ->orderBy(0, 'desc')
            ->selectStyleSingle()
            ->parameters(array_merge($this->getDefaultParameters(), [
                'dom' => 'lrtip',
            ]));
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
                ->searchable(false),
            Column::make('name')
                ->title(__('actions.name'))
                ->data('name')
                ->name('people.full_name'),
            Column::make('record')
                ->title(__('actions.record_advicen')),
            Column::make('email')
                ->title(__('actions.email'))
                ->name('users.email'),
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
        return 'Doctors_' . date('YmdHis');
    }

    /**
     * Build action buttons for the doctors DataTable, including the work-schedule link.
     */
    private function buildDoctorActionButtons(Doctor $record): string
    {
        $entityId = session()->get('selected_entity_id');
        $isOwned  = $record->entity_id === $entityId;

        // Build without the delete button so we can append it last
        $buttons = $this->buildActionButtons($record, ['delete' => false]);

        if (! $record->deleted_at && $isOwned) {
            $buttons .= '<button type="button"
                class="btn waves-effect waves-light btn-secondary btn-xs m-1 btn-work-schedule"
                data-id="' . $record->id . '"
                data-bs-toggle="tooltip" data-bs-placement="bottom"
                title="Escala de Atendimento"><i class="fas fa-clock"></i></button>';

            $buttons .= '<a href="javascript:void(0);"
                class="btn waves-effect waves-light btn-danger btn-xs m-1 btn-trash"
                data-id="' . $record->id . '" data-bs-toggle="tooltip" data-bs-placement="bottom"
                title="' . __('actions.delete') . '"><i class="fas fa-trash-alt"></i></a>';
        }

        return $buttons;
    }
}
