<?php

namespace App\DataTables;

use App\Models\EntityUser;
use Illuminate\Database\Eloquent\Builder;
use Yajra\DataTables\EloquentDataTable;
use Yajra\DataTables\Html\{Builder as HtmlBuilder, Column};

class UsersDataTable extends BaseDataTable
{
    /**
     * Build the DataTable class.
     *
     * @param Builder<EntityUser> $query
     */
    public function dataTable(Builder $query): EloquentDataTable
    {
        return (new EloquentDataTable($query))
            ->addColumn('action', fn (EntityUser $record) => $this->buildActionButtons($record, [
                'variant' => 'dropdown',
                'show'    => true,
            ]))
            ->editColumn('created_at', fn (EntityUser $record) => $this->formatDateColumn($record->created_at))
            ->editColumn('active', fn (EntityUser $record) => $this->formatActiveColumn($record))
            ->addColumn(
                'rule_label',
                fn (EntityUser $record) => ! session()->get('selected_entity_is_client')
                    ? $record->present()->getRule
                    : $record->present()->getClientRule,
            )
            ->filterColumn('name', function ($query, $keyword) {
                $query->whereRaw('LOWER(users.name) LIKE ?', ['%' . mb_strtolower($keyword, 'UTF-8') . '%']);
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
    public function query(EntityUser $model): Builder
    {
        return $model->newQuery()
            ->withTrashed()
            ->select('entity_users.*', 'users.name', 'users.email')
            ->join('users', 'entity_users.user_id', '=', 'users.id')
            ->where('entity_users.entity_id', session()->get('selected_entity_id'))
            ->whereNot('entity_users.rule', 'doctor');
    }

    /**
     * Optional method if you want to use the html builder.
     */
    public function html(): HtmlBuilder
    {
        return $this->builder()
            ->setTableId('users_datatable')
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
            Column::make('name')
                ->title(__('actions.name'))
                ->name('users.name'),
            Column::make('email')
                ->title(__('actions.email'))
                ->name('users.email'),
            Column::make('rule_label')
                ->title(__('actions.rule'))
                ->name('users.rule')
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

    /**
     * Get the filename for export.
     */
    protected function filename(): string
    {
        return 'Users_' . date('YmdHis');
    }
}
