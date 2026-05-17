<?php

namespace App\DataTables;

use App\Models\EntityUserIntegrator;
use Illuminate\Database\Eloquent\Builder;
use Yajra\DataTables\EloquentDataTable;
use Yajra\DataTables\Html\{Builder as HtmlBuilder, Column};

class EntityUserIntegratorsDataTable extends BaseDataTable
{
    protected ?string $entityId = null;

    public function forEntity(string $entityId): static
    {
        $this->entityId = $entityId;

        return $this;
    }

    /**
     * Build the DataTable class.
     *
     * @param Builder<EntityUserIntegrator> $query
     */
    public function dataTable(Builder $query): EloquentDataTable
    {
        return (new EloquentDataTable($query))
            ->addColumn('action', fn (EntityUserIntegrator $record) => $this->buildUserIntegratorActionButtons($record))
            ->editColumn('created_at', fn (EntityUserIntegrator $record) => $this->formatDateColumn($record->created_at))
            ->editColumn('active', fn (EntityUserIntegrator $record) => $this->formatActiveColumn($record))
            ->rawColumns(['active', 'action'])
            ->filterColumn('name', function ($query, $keyword) {
                $query->whereRaw('LOWER(entity_user_integrators.name) LIKE ?', ['%' . mb_strtolower($keyword, 'UTF-8') . '%']);
            })
            ->filterColumn('email', function ($query, $keyword) {
                $query->whereRaw('LOWER(entity_user_integrators.email) LIKE ?', ['%' . mb_strtolower($keyword, 'UTF-8') . '%']);
            })
            ->setRowId('id');
    }

    /**
     * Get the query source of dataTable.
     */
    public function query(EntityUserIntegrator $model): Builder
    {
        $query = $model->newQuery()
            ->withTrashed()
            ->select('entity_user_integrators.*')
            ->selectRaw('(SELECT COUNT(*) FROM entity_integrators WHERE entity_integrators.entity_user_integrator_id = entity_user_integrators.id AND entity_integrators.deleted_at IS NULL) as integrators_count');

        if ($this->entityId) {
            $query->where('entity_id', $this->entityId);
        }

        return $query;
    }

    /**
     * Optional method if you want to use the html builder.
     */
    public function html(): HtmlBuilder
    {
        return $this->builder()
            ->setTableId('user_integrators_datatable')
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
            Column::make('code')
                ->title(__('actions.code'))
                ->searchable(false),
            Column::make('created_at')
                ->title(__('actions.created_at'))
                ->searchable(false),
            Column::make('name')
                ->title(__('actions.name')),
            Column::make('email')
                ->title(__('actions.email')),
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

    private function buildUserIntegratorActionButtons(EntityUserIntegrator $record): string
    {
        if ($record->deleted_at) {
            return '<a href="javascript:void(0);" class="btn-restore shadow-sm fs-14 d-inline-flex border rounded-2 p-1"
                data-id="' . $record->id . '" data-bs-toggle="tooltip" data-bs-placement="bottom"
                title="' . __('actions.restore') . '"><i class="ti ti-recycle"></i></a>';
        }

        $integratorsItem = $record->integrators_count > 0
            ? '<li><a class="dropdown-item" href="' . route('manager.entities.user-integrators.integrators.index', [$this->entityId, $record->id]) . '">
                <i class="ti ti-settings me-1"></i>' . __('actions.integrators') . '</a></li>'
            : '<li><span class="dropdown-item text-muted disabled" aria-disabled="true">
                <i class="ti ti-settings me-1"></i>' . __('actions.integrators') . '</span></li>';

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
        ' . $integratorsItem . '
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
        return 'EntityUserIntegrators_' . date('YmdHis');
    }
}
