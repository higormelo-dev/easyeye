<?php

namespace App\DataTables;

use App\Models\Subscription;
use Illuminate\Database\Eloquent\Builder;
use Yajra\DataTables\EloquentDataTable;
use Yajra\DataTables\Html\{Builder as HtmlBuilder, Column};

class SubscriptionsDataTable extends BaseDataTable
{
    public function dataTable(Builder $query): EloquentDataTable
    {
        return (new EloquentDataTable($query))
            ->addColumn('action', fn (Subscription $record) => $this->buildSubscriptionActionButtons($record))
            ->editColumn('status', fn (Subscription $record) => '<span class="badge ' . $record->status->badgeClass() . '">' . $record->status->label() . '</span>')
            ->editColumn('starts_at', fn (Subscription $record) => $this->formatDateColumn($record->starts_at, 'd/m/Y'))
            ->editColumn('ends_at', fn (Subscription $record) => $record->ends_at ? $record->ends_at->format('d/m/Y') : '<span class="text-muted">Vitalício</span>')
            ->editColumn('trial_ends_at', fn (Subscription $record) => $record->trial_ends_at ? $record->trial_ends_at->format('d/m/Y') : '-')
            ->rawColumns(['status', 'ends_at', 'action'])
            ->filterColumn('entity_name', function ($query, $keyword) {
                $query->whereRaw('LOWER(entities.name) LIKE ?', ['%' . mb_strtolower($keyword, 'UTF-8') . '%']);
            })
            ->filterColumn('plan_name', function ($query, $keyword) {
                $query->whereRaw('LOWER(plans.name) LIKE ?', ['%' . mb_strtolower($keyword, 'UTF-8') . '%']);
            })
            ->setRowId('id');
    }

    public function query(Subscription $model): Builder
    {
        return $model->newQuery()
            ->select(
                'subscriptions.*',
                'entities.name as entity_name',
                'entities.active as entity_active',
                'plans.name as plan_name',
            )
            ->join('entities', 'subscriptions.entity_id', '=', 'entities.id')
            ->leftJoin('plans', 'subscriptions.plan_id', '=', 'plans.id')
            ->latest('subscriptions.created_at');
    }

    public function html(): HtmlBuilder
    {
        return $this->builder()
            ->setTableId('subscriptions_datatable')
            ->columns($this->getColumns())
            ->minifiedAjax()
            ->orderBy(0, 'desc')
            ->selectStyleSingle()
            ->parameters($this->getDefaultParameters());
    }

    public function getColumns(): array
    {
        return [
            Column::make('entity_name')
                ->title('Empresa')
                ->name('entities.name'),
            Column::make('plan_name')
                ->title('Plano')
                ->name('plans.name'),
            Column::make('status')
                ->title('Status')
                ->searchable(false)
                ->className('text-center'),
            Column::make('starts_at')
                ->title('Início')
                ->searchable(false),
            Column::make('ends_at')
                ->title('Vencimento')
                ->searchable(false),
            Column::make('trial_ends_at')
                ->title('Trial até')
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

    private function buildSubscriptionActionButtons(Subscription $record): string
    {
        $entityId = $record->entity_id;
        $buttons  = '';

        $buttons .= '<a href="javascript:void(0);"
            class="btn waves-effect waves-light btn-light btn-xs m-1 btn-edit"
            data-id="' . $record->id . '" data-bs-toggle="tooltip" data-bs-placement="bottom"
            title="' . __('actions.edit') . '"><i class="fa fa-edit"></i></a>';

        $buttons .= '<a href="javascript:void(0);"
            class="btn waves-effect waves-light btn-light btn-xs m-1 btn-show"
            data-id="' . $record->id . '" data-bs-toggle="tooltip" data-bs-placement="bottom"
            title="' . __('actions.view') . '"><i class="fa fa-eye"></i></a>';

        if ($record->status->isAccessible()) {
            $buttons .= '<a href="javascript:void(0);"
                class="btn waves-effect waves-light btn-warning btn-xs m-1 btn-cancel"
                data-id="' . $record->id . '" data-entity-id="' . $entityId . '"
                data-bs-toggle="tooltip" data-bs-placement="bottom"
                title="Cancelar assinatura"><i class="fas fa-ban"></i></a>';
        }

        $lockClass = $record->entity_active ? 'btn-danger' : 'btn-success';
        $lockIcon  = $record->entity_active ? 'fa-lock' : 'fa-lock-open';
        $lockTitle = $record->entity_active ? 'Bloquear acesso' : 'Desbloquear acesso';

        $buttons .= '<a href="javascript:void(0);"
            class="btn waves-effect waves-light ' . $lockClass . ' btn-xs m-1 btn-block"
            data-entity-id="' . $entityId . '" data-active="' . ($record->entity_active ? 0 : 1) . '"
            data-bs-toggle="tooltip" data-bs-placement="bottom"
            title="' . $lockTitle . '"><i class="fas ' . $lockIcon . '"></i></a>';

        return $buttons;
    }

    protected function filename(): string
    {
        return 'Subscriptions_' . date('YmdHis');
    }
}
