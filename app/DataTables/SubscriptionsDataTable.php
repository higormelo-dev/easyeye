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
        $cancelItem = '';

        if ($record->status->isAccessible()) {
            $cancelItem = '<li><a class="dropdown-item btn-cancel text-warning" href="javascript:void(0);"
                    data-id="' . $record->id . '" data-entity-id="' . $entityId . '">
                <i class="ti ti-ban me-1"></i>Cancelar assinatura</a></li>';
        }

        $blockTitle = $record->entity_active ? 'Bloquear acesso' : 'Desbloquear acesso';
        $blockIcon  = $record->entity_active ? 'ti-lock' : 'ti-lock-open';

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
        ' . $cancelItem . '
        <li><a class="dropdown-item btn-block" href="javascript:void(0);"
               data-entity-id="' . $entityId . '" data-active="' . ($record->entity_active ? 0 : 1) . '">
            <i class="ti ' . $blockIcon . ' me-1"></i>' . $blockTitle . '</a></li>
    </ul>
</div>';
    }

    protected function filename(): string
    {
        return 'Subscriptions_' . date('YmdHis');
    }
}
