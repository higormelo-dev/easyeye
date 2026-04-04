<?php

namespace App\DataTables;

use App\Models\EntityIntegrator;
use Illuminate\Database\Eloquent\Builder;
use Yajra\DataTables\EloquentDataTable;
use Yajra\DataTables\Html\{Builder as HtmlBuilder, Column};

class EntityIntegratorsDataTable extends BaseDataTable
{
    protected ?string $entityId = null;

    protected ?string $userIntegratorId = null;

    public function forUserIntegrator(string $entityId, string $userIntegratorId): static
    {
        $this->entityId         = $entityId;
        $this->userIntegratorId = $userIntegratorId;

        return $this;
    }

    /**
     * Build the DataTable class.
     *
     * @param Builder<EntityIntegrator> $query
     */
    public function dataTable(Builder $query): EloquentDataTable
    {
        return (new EloquentDataTable($query))
            ->addColumn('action', fn (EntityIntegrator $record) => $this->buildIntegratorActionButtons($record))
            ->editColumn('created_at', fn (EntityIntegrator $record) => $this->formatDateColumn($record->created_at))
            ->editColumn('active', fn (EntityIntegrator $record) => $this->formatActiveColumn($record))
            ->rawColumns(['active', 'action'])
            ->filterColumn('name', function ($query, $keyword) {
                $query->whereRaw('LOWER(entity_integrators.name) LIKE ?', ['%' . mb_strtolower($keyword, 'UTF-8') . '%']);
            })
            ->filterColumn('code', function ($query, $keyword) {
                $query->whereRaw('LOWER(entity_integrators.code) LIKE ?', ['%' . mb_strtolower($keyword, 'UTF-8') . '%']);
            })
            ->setRowId('id');
    }

    /**
     * Get the query source of dataTable.
     */
    public function query(EntityIntegrator $model): Builder
    {
        $query = $model->newQuery()
            ->withTrashed()
            ->select('entity_integrators.*')
            ->selectRaw('(SELECT COUNT(*) FROM entity_integrator_equipments WHERE entity_integrator_equipments.integrator_id = entity_integrators.id AND entity_integrator_equipments.deleted_at IS NULL) as equipments_count');

        if ($this->userIntegratorId) {
            $query->where('entity_user_integrator_id', $this->userIntegratorId);
        }

        return $query;
    }

    /**
     * Optional method if you want to use the html builder.
     */
    public function html(): HtmlBuilder
    {
        return $this->builder()
            ->setTableId('integrators_datatable')
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
                ->title(__('actions.code'))
                ->searchable(true),
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

    private function buildIntegratorActionButtons(EntityIntegrator $record): string
    {
        if ($record->deleted_at) {
            return '<a href="javascript:void(0);" class="btn-restore shadow-sm fs-14 d-inline-flex border rounded-2 p-1"
                data-id="' . $record->id . '" data-bs-toggle="tooltip" data-bs-placement="bottom"
                title="' . __('actions.restore') . '"><i class="ti ti-recycle"></i></a>';
        }

        $equipmentsItem = $record->equipments_count > 0
            ? '<li><a class="dropdown-item" href="' . route('panel.manager.entities.user-integrators.integrators.equipments.index', [$this->entityId, $this->userIntegratorId, $record->id]) . '">
                <i class="ti ti-settings me-1"></i>' . __('actions.equipments') . '</a></li>'
            : '<li><span class="dropdown-item text-muted disabled" aria-disabled="true">
                <i class="ti ti-settings me-1"></i>' . __('actions.equipments') . '</span></li>';

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
        ' . $equipmentsItem . '
        <li><a class="dropdown-item btn-active" href="javascript:void(0);"
               data-id="' . $record->id . '" data-situation="' . $activeSituation . '">
            <i class="ti ' . $activeIcon . ' me-1"></i>' . $activeTitle . '</a></li>
        <li><a class="dropdown-item btn-trash text-danger" href="javascript:void(0);"
               data-id="' . $record->id . '">
            <i class="ti ti-trash me-1"></i>' . __('actions.delete') . '</a></li>
    </ul>
</div>';
    }

    /**
     * Get the filename for export.
     */
    protected function filename(): string
    {
        return 'EntityIntegrators_' . date('YmdHis');
    }
}
