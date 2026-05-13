<?php

namespace App\DataTables\Manager;

use App\DataTables\BaseDataTable;
use App\Models\ReportSetting;
use Illuminate\Database\Eloquent\Builder;
use Yajra\DataTables\EloquentDataTable;
use Yajra\DataTables\Html\{Builder as HtmlBuilder, Column};

class ManagerReportSettingsDataTable extends BaseDataTable
{
    public function dataTable(Builder $query): EloquentDataTable
    {
        return (new EloquentDataTable($query))
            ->addColumn('action', fn (ReportSetting $record) => $this->buildManagerActionButtons($record))
            ->editColumn('active', fn (ReportSetting $record) => $this->formatActiveColumn($record))
            ->addColumn('header', fn (ReportSetting $record) => $record->show_header
                ? '<span class="badge badge-soft-primary text-primary border border-primary">Sim</span>'
                : '<span class="badge badge-soft-secondary text-secondary">Não</span>')
            ->addColumn('signature', fn (ReportSetting $record) => $record->show_signature
                ? '<span class="badge badge-soft-primary text-primary border border-primary">Sim</span>'
                : '<span class="badge badge-soft-secondary text-secondary">Não</span>')
            ->addColumn('footer', fn (ReportSetting $record) => $record->show_footer
                ? '<span class="badge badge-soft-primary text-primary border border-primary">Sim</span>'
                : '<span class="badge badge-soft-secondary text-secondary">Não</span>')
            ->rawColumns(['header', 'signature', 'footer', 'active', 'action'])
            ->setRowId('id');
    }

    public function query(ReportSetting $model): Builder
    {
        return $model->newQuery()
            ->select('report_settings.*')
            ->whereNull('entity_id')
            ->orderBy('title');
    }

    public function html(): HtmlBuilder
    {
        return $this->builder()
            ->setTableId('manager_report_settings_datatable')
            ->columns($this->getColumns())
            ->minifiedAjax()
            ->orderBy(0, 'asc')
            ->selectStyleSingle()
            ->parameters($this->getDefaultParameters());
    }

    public function getColumns(): array
    {
        return [
            Column::make('title')
                ->title(__('forms.title')),
            Column::make('paper_size')
                ->title(__('forms.paper_size'))
                ->searchable(false)
                ->className('text-center'),
            Column::computed('header')
                ->title(__('actions.report_settings.header'))
                ->exportable(false)->printable(false)->orderable(false)->searchable(false)
                ->className('text-center'),
            Column::computed('signature')
                ->title(__('actions.report_settings.signature'))
                ->exportable(false)->printable(false)->orderable(false)->searchable(false)
                ->className('text-center'),
            Column::computed('footer')
                ->title(__('actions.report_settings.footer'))
                ->exportable(false)->printable(false)->orderable(false)->searchable(false)
                ->className('text-center'),
            Column::make('active')
                ->title(__('actions.active'))
                ->searchable(false)
                ->className('text-center'),
            Column::computed('action')
                ->title(__('actions.actions'))
                ->exportable(false)->printable(false)->orderable(false)->searchable(false)
                ->className('text-end'),
        ];
    }

    protected function filename(): string
    {
        return 'GlobalReportSettings_' . date('YmdHis');
    }

    private function buildManagerActionButtons(ReportSetting $record): string
    {
        $editUrl    = route('panel.manager.report-settings.edit', $record);
        $previewUrl = route('panel.manager.report-settings.preview', $record);
        $deleteUrl  = route('panel.manager.report-settings.destroy', $record);

        return '
            <div class="d-flex justify-content-end gap-1">
                <button type="button" class="btn btn-light btn-sm"
                        onclick="openPdfPreviewModal(\'' . $previewUrl . '\')"
                        title="' . __('actions.report_settings.preview_pdf') . '">
                    <i class="ti ti-file-search"></i>
                </button>
                <a href="' . $editUrl . '" class="btn btn-light btn-sm" title="' . __('actions.edit') . '">
                    <i class="ti ti-edit"></i>
                </a>
                <form action="' . $deleteUrl . '" method="POST" onsubmit="return confirm(\'' . __('actions.confirm_delete') . '\')">
                    <input type="hidden" name="_token" value="' . csrf_token() . '">
                    <input type="hidden" name="_method" value="DELETE">
                    <button type="submit" class="btn btn-danger btn-sm" title="' . __('actions.delete') . '">
                        <i class="ti ti-trash"></i>
                    </button>
                </form>
            </div>';
    }
}
