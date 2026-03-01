<?php

namespace App\DataTables;

use Yajra\DataTables\Services\DataTable;

abstract class BaseDataTable extends DataTable
{
    /**
     * Get the language configuration for DataTables.
     */
    protected function getLanguageConfig(): array
    {
        return [
            'sEmptyTable'   => __('actions.datatable.sEmptyTable'),
            'sProcessing'   => __('actions.datatable.sProcessing'),
            'sLengthMenu'   => __('actions.datatable.sLengthMenu'),
            'sZeroRecords'  => __('actions.datatable.sZeroRecords'),
            'sInfo'         => __('actions.datatable.sInfo'),
            'sInfoEmpty'    => __('actions.datatable.sInfoEmpty'),
            'sInfoFiltered' => __('actions.datatable.sInfoFiltered'),
            'sSearch'       => __('actions.datatable.sSearch'),
            'oPaginate'     => [
                'sFirst'    => __('actions.datatable.oPaginate.sFirst'),
                'sPrevious' => __('actions.datatable.oPaginate.sPrevious'),
                'sNext'     => __('actions.datatable.oPaginate.sNext'),
                'sLast'     => __('actions.datatable.oPaginate.sLast'),
            ],
            'oAria' => [
                'sSortAscending'  => __('actions.datatable.oAria.sSortAscending'),
                'sSortDescending' => __('actions.datatable.oAria.sSortDescending'),
            ],
        ];
    }

    /**
     * Get default parameters for DataTables.
     */
    protected function getDefaultParameters(): array
    {
        return [
            'language'   => $this->getLanguageConfig(),
            'processing' => true,
            'serverSide' => true,
            'responsive' => true,
            'pageLength' => 10,
            'lengthMenu' => [[5, 10, 25, 50, 100], [5, 10, 25, 50, 100]],
            'pagingType' => 'full_numbers',
        ];
    }

    /**
     * Build action buttons for datatable.
     *
     * @param  mixed  $record  The model record
     * @param  array  $options  Options for buttons (edit, show, active, delete, restore)
     */
    protected function buildActionButtons(mixed $record, array $options = []): string
    {
        $defaults = [
            'edit'    => true,
            'show'    => true,
            'active'  => true,
            'delete'  => true,
            'restore' => true,
        ];

        $options    = array_merge($defaults, $options);
        $btnActions = '';
        $entityId   = session()->get('selected_entity_id');

        if (! $record->deleted_at && $record->entity_id === $entityId) {
            if ($options['edit']) {
                $btnActions .= '<a href="javascript:void(0);"
                    class="btn waves-effect waves-light btn-secondary btn-xs m-1 btn-edit"
                    data-id="' . $record->id . '" data-bs-toggle="tooltip" data-bs-placement="bottom"
                    title="' . __('actions.edit') . '"><i class="fa fa-edit"></i></a>';
            }

            if ($options['show']) {
                $btnActions .= '<a href="javascript:void(0);"
                    class="btn waves-effect waves-light btn-secondary btn-xs m-1 btn-show"
                    data-id="' . $record->id . '" data-bs-toggle="tooltip" data-bs-placement="bottom"
                    title="' . __('actions.view') . '"><i class="fa fa-eye"></i></a>';
            }

            if ($options['active']) {
                $btnActions .= '<a href="javascript:void(0);"
                    class="btn waves-effect waves-light btn-secondary btn-xs m-1 btn-active"
                    data-id="' . $record->id . '" data-situation="' . ($record->active ? 0 : 1) . '"
                    data-bs-toggle="tooltip" data-bs-placement="bottom"
                    title="' . ($record->active ? __('actions.disable') : __('actions.enable')) . '">
                    <i class="fas ' . ($record->active ? 'fa-lock-open' : 'fa-unlock') . '"></i></a>';
            }

            if ($options['delete']) {
                $btnActions .= '<a href="javascript:void(0);"
                    class="btn waves-effect waves-light btn-danger btn-xs m-1 btn-trash"
                    data-id="' . $record->id . '" data-bs-toggle="tooltip" data-bs-placement="bottom"
                    title="' . __('actions.delete') . '"><i class="fas fa-trash-alt"></i></a>';
            }
        } elseif ($record->deleted_at && $record->entity_id === $entityId && $options['restore']) {
            $btnActions .= '<a href="javascript:void(0);"
                class="btn waves-effect waves-light btn-warning btn-xs m-1 btn-restore"
                data-id="' . $record->id . '" data-bs-toggle="tooltip" data-bs-placement="bottom"
                title="' . __('actions.restore') . '"><i class="fas fa-recycle"></i></a>';
        }

        return $btnActions;
    }

    /**
     * Format active column with badge.
     */
    protected function formatActiveColumn(mixed $record): string
    {
        if ($record->deleted_at) {
            return '<span class="badge bg-secondary text-dark">' . __('actions.delete') . '</span>';
        }

        return $record->active
            ? '<span class="badge bg-success">' . __('actions.yes') . '</span>'
            : '<span class="badge bg-dark">' . __('actions.no') . '</span>';
    }

    /**
     * Format date column.
     */
    protected function formatDateColumn(mixed $date, string $format = 'd/m/Y H:i'): string
    {
        return $date ? $date->format($format) : '-';
    }
}
