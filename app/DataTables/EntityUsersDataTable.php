<?php

namespace App\DataTables;

use App\Models\EntityUser;
use Illuminate\Database\Eloquent\Builder;
use Yajra\DataTables\EloquentDataTable;
use Yajra\DataTables\Html\{Builder as HtmlBuilder, Column};

class EntityUsersDataTable extends BaseDataTable
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
     * @param  Builder<EntityUser>  $query
     */
    public function dataTable(Builder $query): EloquentDataTable
    {
        return (new EloquentDataTable($query))
            ->editColumn('rule', fn (EntityUser $record) => $record->rule
                ? '<span class="badge bg-light text-dark border">' . ucfirst($record->rule) . '</span>'
                : '-')
            ->editColumn('active', fn (EntityUser $record) => $this->formatEntityUserStatus($record))
            ->editColumn('created_at', fn (EntityUser $record) => $record->created_at?->format('d/m/Y H:i'))
            ->addColumn('action', fn (EntityUser $record) => $this->buildEntityUserActionButtons($record))
            ->rawColumns(['rule', 'active', 'action'])
            ->filterColumn('name', function ($query, $keyword) {
                $query->whereRaw('LOWER(users.name) LIKE ?', ['%' . mb_strtolower($keyword, 'UTF-8') . '%']);
            })
            ->filterColumn('email', function ($query, $keyword) {
                $query->whereRaw('LOWER(users.email) LIKE ?', ['%' . mb_strtolower($keyword, 'UTF-8') . '%']);
            })
            ->setRowId('id');
    }

    /**
     * Get the query source of dataTable.
     */
    public function query(EntityUser $model): Builder
    {
        $query = $model->newQuery()
            ->withTrashed()
            ->join('users', 'users.id', '=', 'entity_users.user_id')
            ->select('entity_users.*', 'users.name', 'users.email');

        if ($this->entityId) {
            $query->where('entity_users.entity_id', $this->entityId);
        }

        return $query;
    }

    /**
     * Optional method if you want to use the html builder.
     */
    public function html(): HtmlBuilder
    {
        return $this->builder()
            ->setTableId('entity_users_datatable')
            ->columns($this->getColumns())
            ->minifiedAjax()
            ->orderBy(0, 'asc')
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
            Column::make('email')
                ->title(__('actions.email')),
            Column::make('rule')
                ->title(__('actions.rule'))
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

    private function buildEntityUserActionButtons(EntityUser $record): string
    {
        if (!$record->deleted_at && $record->active && !session('impersonating')) {
            $url = route('panel.manager.entities.impersonate', [$record->entity_id, $record->id]);

            return '<a href="javascript:void(0);"
                class="btn waves-effect waves-light btn-warning btn-xs m-1 btn-impersonate"
                data-url="' . $url . '"
                data-name="' . e($record->name) . '"
                data-bs-toggle="tooltip" data-bs-placement="bottom"
                title="' . __('actions.impersonate.use_as') . '"><i class="fas fa-user-secret"></i></a>';
        }

        return '';
    }

    private function formatEntityUserStatus(EntityUser $record): string
    {
        if ($record->deleted_at) {
            return '<span class="badge bg-danger">Removido</span>';
        }

        return $record->active
            ? '<span class="badge bg-success">' . __('actions.active') . '</span>'
            : '<span class="badge bg-secondary">' . __('actions.inactive') . '</span>';
    }

    /**
     * Get the filename for export.
     */
    protected function filename(): string
    {
        return 'EntityUsers_' . date('YmdHis');
    }
}
