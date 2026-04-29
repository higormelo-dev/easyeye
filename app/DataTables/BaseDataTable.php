<?php

namespace App\DataTables;

use App\DTOs\ActionPolicy;
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
                'sPrevious' => '<i class="ti ti-arrow-left text-body"></i>',
                'sNext'     => '<i class="ti ti-arrow-right"></i>',
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
            'pagingType' => 'simple_numbers',
            'dom'        => 'rt<"d-flex justify-content-between align-items-center mt-3"lp>',
        ];
    }

    /**
     * Build action buttons for datatable (Preclinic dropdown style).
     *
     * @param mixed $record  The model record
     * @param array $options Options for buttons (edit, show, active, delete, restore)
     */
    protected function buildActionButtons(mixed $record, array $options = []): string
    {
        $defaults = [
            'variant'     => 'legacy',
            'edit'        => true,
            'show'        => false,
            'active'      => true,
            'delete'      => true,
            'restore'     => true,
            'global_view' => false,
        ];

        $options = array_merge($defaults, $options);

        $policy = ActionPolicy::from($record, session()->get('selected_entity_id'));

        if ($options['variant'] === 'dropdown') {
            return $this->buildDropdownActionButtons($record, $options, $policy);
        }

        if ($policy->mode === ActionPolicy::MODE_RESTORE && $options['restore']) {
            return '<a href="javascript:void(0);"
                class="btn waves-effect waves-light btn-light btn-xs m-1 btn-restore"
                data-id="' . $record->id . '" data-bs-toggle="tooltip" data-bs-placement="bottom"
                title="' . __('actions.restore') . '"><i class="fas fa-recycle"></i></a>';
        }

        if ($policy->mode !== ActionPolicy::MODE_FULL) {
            return '';
        }

        $btnActions = '';

        if ($options['edit']) {
            $btnActions .= '<a href="javascript:void(0);"
                class="btn waves-effect waves-light btn-light btn-xs m-1 btn-edit"
                data-id="' . $record->id . '" data-bs-toggle="tooltip" data-bs-placement="bottom"
                title="' . __('actions.edit') . '"><i class="fa fa-edit"></i></a>';
        }

        if ($options['show']) {
            $btnActions .= '<a href="javascript:void(0);"
                class="btn waves-effect waves-light btn-light btn-xs m-1 btn-show"
                data-id="' . $record->id . '" data-bs-toggle="tooltip" data-bs-placement="bottom"
                title="' . __('actions.view') . '"><i class="fa fa-eye"></i></a>';
        }

        if ($options['active']) {
            $activeSituation = $policy->active ? 0 : 1;
            $activeTitle     = $policy->active ? __('actions.disable') : __('actions.enable');
            $activeIcon      = $policy->active ? 'fa-lock-open' : 'fa-unlock';

            $btnActions .= '<a href="javascript:void(0);"
                class="btn waves-effect waves-light btn-light btn-xs m-1 btn-active"
                data-id="' . $record->id . '" data-situation="' . $activeSituation . '"
                data-bs-toggle="tooltip" data-bs-placement="bottom"
                title="' . $activeTitle . '"><i class="fas ' . $activeIcon . '"></i></a>';
        }

        if ($options['delete']) {
            $btnActions .= '<a href="javascript:void(0);"
                class="btn waves-effect waves-light btn-danger btn-xs m-1 btn-trash"
                data-id="' . $record->id . '" data-bs-toggle="tooltip" data-bs-placement="bottom"
                title="' . __('actions.delete') . '"><i class="fas fa-trash-alt"></i></a>';
        }

        return $btnActions;
    }

    /**
     * Build action buttons in the same dropdown style used by patients/doctors.
     */
    private function buildDropdownActionButtons(mixed $record, array $options, ActionPolicy $policy): string
    {
        if ($policy->mode === ActionPolicy::MODE_RESTORE && $options['restore']) {
            return '<a href="javascript:void(0);" class="btn-restore shadow-sm fs-14 d-inline-flex border rounded-2 p-1"
                data-id="' . $record->id . '" data-bs-toggle="tooltip" data-bs-placement="bottom"
                title="' . __('actions.restore') . '"><i class="ti ti-recycle"></i></a>';
        }

        if ($policy->mode === ActionPolicy::MODE_VIEW_ONLY && $options['show'] && $options['global_view']) {
            return '<a href="javascript:void(0);" class="btn-show shadow-sm fs-14 d-inline-flex border rounded-2 p-1"
                data-id="' . $record->id . '" data-bs-toggle="tooltip" data-bs-placement="bottom"
                title="' . __('actions.view') . '"><i class="ti ti-eye"></i></a>';
        }

        if ($policy->mode !== ActionPolicy::MODE_FULL) {
            return '';
        }

        $menuItems = '';

        if ($options['edit']) {
            $menuItems .= '<li><a class="dropdown-item btn-edit" href="javascript:void(0);" data-id="' . $record->id . '">
                <i class="ti ti-edit me-1"></i>' . __('actions.edit') . '</a></li>';
        }

        if ($options['active']) {
            $activeSituation = $policy->active ? 0 : 1;
            $activeIcon      = $policy->active ? 'ti-lock-open' : 'ti-lock';
            $activeTitle     = $policy->active ? __('actions.disable') : __('actions.enable');

            $menuItems .= '<li><a class="dropdown-item btn-active" href="javascript:void(0);"
                    data-id="' . $record->id . '" data-situation="' . $activeSituation . '">
                <i class="ti ' . $activeIcon . ' me-1"></i>' . $activeTitle . '</a></li>';
        }

        if ($options['delete']) {
            $menuItems .= '<li><a class="dropdown-item btn-trash text-danger" href="javascript:void(0);"
                    data-id="' . $record->id . '">
                <i class="ti ti-trash me-1"></i>' . __('actions.delete') . '</a></li>';
        }

        $showButton = '';

        if ($options['show']) {
            $showButton = '<a href="javascript:void(0);" class="btn-show shadow-sm fs-14 d-inline-flex border rounded-2 p-1"
               data-id="' . $record->id . '" data-bs-toggle="tooltip" data-bs-placement="bottom"
               title="' . __('actions.view') . '"><i class="ti ti-eye"></i></a>';
        }

        $dropdown = '';

        if ($menuItems !== '') {
            $dropdown = '
            <a href="javascript:void(0);" class="shadow-sm fs-14 d-inline-flex border rounded-2 p-1"
               data-bs-toggle="dropdown" aria-expanded="false"><i class="ti ti-dots-vertical"></i></a>
            <ul class="dropdown-menu p-2">' . $menuItems . '</ul>';
        }

        return '<div class="d-flex align-items-center float-end gap-1">' . $showButton . $dropdown . '</div>';
    }

    /**
     * Format active column with badge (Preclinic badge-soft style).
     */
    protected function formatActiveColumn(mixed $record): string
    {
        if ($record->deleted_at) {
            return '<span class="badge badge-soft-secondary rounded fs-13 fw-medium">' . __('actions.delete') . '</span>';
        }

        return $record->active
            ? '<span class="badge badge-soft-success rounded text-success border border-success fs-13 fw-medium">' . __('actions.yes') . '</span>'
            : '<span class="badge badge-soft-danger rounded text-danger border border-danger fs-13 fw-medium">' . __('actions.no') . '</span>';
    }

    /**
     * Format date column.
     */
    protected function formatDateColumn(mixed $date, string $format = 'd/m/Y H:i'): string
    {
        return $date ? $date->format($format) : '-';
    }
}
