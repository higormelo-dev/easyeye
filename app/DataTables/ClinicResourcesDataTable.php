<?php

namespace App\DataTables;

use App\Models\ClinicResource;
use Illuminate\Database\Eloquent\Builder;
use Yajra\DataTables\EloquentDataTable;
use Yajra\DataTables\Html\{Builder as HtmlBuilder, Column};

class ClinicResourcesDataTable extends BaseDataTable
{
    /**
     * @param Builder<ClinicResource> $query
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

        if ($record->deleted_at && $isOwned) {
            return '<a href="javascript:void(0);" class="btn-restore shadow-sm fs-14 d-inline-flex border rounded-2 p-1"
                data-id="' . $record->id . '" data-bs-toggle="tooltip" data-bs-placement="bottom"
                title="' . __('actions.restore') . '"><i class="ti ti-recycle"></i></a>';
        }

        if (! $isOwned || $record->deleted_at) {
            return '';
        }

        $activeSituation = $record->active ? 0 : 1;
        $activeIcon      = $record->active ? 'ti-lock-open' : 'ti-lock';
        $activeTitle     = $record->active ? __('actions.disable') : __('actions.enable');

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
                       data-id="' . $record->id . '" data-situation="' . $activeSituation . '">
                    <i class="ti ' . $activeIcon . ' me-1"></i>' . $activeTitle . '</a></li>
                <li><a class="dropdown-item btn-resource-schedule" href="javascript:void(0);"
                       data-id="' . $record->id . '">
                    <i class="ti ti-calendar me-1"></i>Disponibilidade</a></li>
                <li><a class="dropdown-item btn-trash text-danger" href="javascript:void(0);"
                       data-id="' . $record->id . '">
                    <i class="ti ti-trash me-1"></i>' . __('actions.delete') . '</a></li>
            </ul>
        </div>';
    }
}
