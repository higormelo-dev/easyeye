<?php

namespace App\DataTables;

use App\Models\ClinicResource;
use Illuminate\Database\Eloquent\Builder;
use Yajra\DataTables\EloquentDataTable;
use Yajra\DataTables\Html\{Builder as HtmlBuilder, Column};

class ClinicResourcesDataTable extends BaseDataTable
{
    /**
     * @param  Builder<ClinicResource>  $query
     */
    public function dataTable(Builder $query): EloquentDataTable
    {
        return (new EloquentDataTable($query))
            ->addColumn('action', fn (ClinicResource $record) => $this->buildResourceActionButtons($record))
            ->addColumn('type_label', fn (ClinicResource $record) => $record->typeLabel())
            ->editColumn('created_at', fn (ClinicResource $record) => $this->formatDateColumn($record->created_at))
            ->editColumn('active', fn (ClinicResource $record) => $this->formatActiveColumn($record))
            ->rawColumns(['active', 'action'])
            ->setRowId('id');
    }

    public function query(ClinicResource $model): Builder
    {
        return $model->newQuery()
            ->withTrashed()
            ->where('entity_id', session()->get('selected_entity_id'));
    }

    public function html(): HtmlBuilder
    {
        return $this->builder()
            ->setTableId('clinic_resources_datatable')
            ->columns($this->getColumns())
            ->minifiedAjax()
            ->orderBy(0, 'desc')
            ->selectStyleSingle()
            ->parameters($this->getDefaultParameters());
    }

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
            Column::make('type_label')
                ->title('Tipo')
                ->name('type')
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

    protected function filename(): string
    {
        return 'ClinicResources_' . date('YmdHis');
    }

    private function buildResourceActionButtons(ClinicResource $record): string
    {
        $entityId = session()->get('selected_entity_id');
        $isOwned  = $record->entity_id === $entityId;

        $buttons = $this->buildActionButtons($record, ['delete' => false]);

        if (! $record->deleted_at && $isOwned) {
            $buttons .= '<button type="button"
                class="btn waves-effect waves-light btn-secondary btn-xs m-1 btn-resource-schedule"
                data-id="' . $record->id . '"
                data-bs-toggle="tooltip" data-bs-placement="bottom"
                title="Disponibilidade"><i class="fas fa-calendar-alt"></i></button>';

            $buttons .= '<a href="javascript:void(0);"
                class="btn waves-effect waves-light btn-danger btn-xs m-1 btn-trash"
                data-id="' . $record->id . '" data-bs-toggle="tooltip" data-bs-placement="bottom"
                title="' . __('actions.delete') . '"><i class="fas fa-trash-alt"></i></a>';
        }

        return $buttons;
    }
}
