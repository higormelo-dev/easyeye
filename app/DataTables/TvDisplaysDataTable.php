<?php

namespace App\DataTables;

use App\Models\TvDisplay;
use Illuminate\Database\Eloquent\Builder;
use Yajra\DataTables\EloquentDataTable;
use Yajra\DataTables\Html\{Builder as HtmlBuilder, Column};

class TvDisplaysDataTable extends BaseDataTable
{
    protected string $entityId = '';

    public function forEntity(string $entityId): static
    {
        $this->entityId = $entityId;

        return $this;
    }

    /**
     * Build the DataTable class.
     *
     * @param  Builder<TvDisplay>  $query
     */
    public function dataTable(Builder $query): EloquentDataTable
    {
        return (new EloquentDataTable($query))
            ->addColumn('action', function (TvDisplay $record) {
                if (! $record->token) {
                    return '—';
                }

                $url     = route('tv.show', $record->token);
                $btnCopy = '<a href="javascript:void(0);"
                    class="btn waves-effect waves-light btn-secondary btn-xs m-1 btn-copy-link"
                    data-url="' . e($url) . '"
                    data-bs-toggle="tooltip" data-bs-placement="bottom"
                    title="Copiar link"><i class="fas fa-copy"></i></a>';

                $btnQr = '<a href="javascript:void(0);"
                    class="btn waves-effect waves-light btn-info btn-xs m-1 btn-qr"
                    data-url="' . e($url) . '"
                    data-name="' . e($record->name) . '"
                    data-bs-toggle="tooltip" data-bs-placement="bottom"
                    title="Ver QR Code"><i class="fas fa-qrcode"></i></a>';

                $btnDelete = '';
                if (! $record->deleted_at) {
                    $btnDelete = '<a href="javascript:void(0);"
                        class="btn waves-effect waves-light btn-danger btn-xs m-1 btn-trash"
                        data-id="' . $record->id . '"
                        data-bs-toggle="tooltip" data-bs-placement="bottom"
                        title="' . __('actions.delete') . '"><i class="fas fa-trash-alt"></i></a>';
                }

                return $btnCopy . $btnQr . $btnDelete;
            })
            ->editColumn('token', fn (TvDisplay $record) => $record->token ? substr($record->token, 0, 8) . '***' : '—')
            ->editColumn('last_seen_at', function (TvDisplay $record) {
                return $record->last_seen_at
                    ? $record->last_seen_at->diffForHumans()
                    : 'Nunca';
            })
            ->editColumn('active', fn (TvDisplay $record) => $this->formatActiveColumn($record))
            ->rawColumns(['active', 'action'])
            ->setRowId('id');
    }

    /**
     * Get the query source of dataTable.
     */
    public function query(TvDisplay $model): Builder
    {
        return $model->newQuery()
            ->withTrashed()
            ->select('tv_displays.*')
            ->where('tv_displays.entity_id', $this->entityId ?: session()->get('selected_entity_id'))
            ->where('tv_displays.status', '!=', TvDisplay::STATUS_PENDING);
    }

    /**
     * Optional method if you want to use the html builder.
     */
    public function html(): HtmlBuilder
    {
        return $this->builder()
            ->setTableId('tv_displays_datatable')
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
                ->title(__('actions.name')),
            Column::make('token')
                ->title('Token')
                ->searchable(false),
            Column::make('last_seen_at')
                ->title('Último acesso')
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
     * Get filename for export.
     */
    protected function filename(): string
    {
        return 'TvDisplays_' . date('YmdHis');
    }
}
