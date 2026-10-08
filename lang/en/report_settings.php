<?php

/**
 * Clinic document templates (Pages/Panel/Settings/ReportSettings/*) —
 * `status` is used by App\Enums\ReportSettingStatus; the rest is the listing
 * `t` prop and Setting\ReportSettingsController messages.
 */
return [
    'status' => [
        'draft'     => 'Draft',
        'published' => 'Published',
        'archived'  => 'Archived',
    ],

    // Page
    'page_title'         => 'Document templates',
    'form_title_create'  => 'New document template',
    'form_title_edit'    => 'Edit document template',
    'total_label'        => 'Total:',
    'view_table'         => 'Table',
    'view_cards'         => 'Cards',
    'btn_new'            => 'New template',
    'search_placeholder' => 'Search by title or description...',
    'search_clear'       => 'Clear search',
    'close'              => 'Close',

    // Filters
    'filter_category_label'  => 'Filter by category',
    'filter_category_all'    => 'All categories',
    'filter_status_label'    => 'Filter by status',
    'filter_status_all'      => 'All',
    'filter_status_active'   => 'Active',
    'filter_status_inactive' => 'Inactive',

    // Columns
    'col_title'      => 'Template',
    'col_category'   => 'Category',
    'col_paper'      => 'Paper',
    'col_blocks'     => 'Blocks',
    'col_origin'     => 'Origin',
    'col_updated_at' => 'Updated',
    'col_status'     => 'Status',
    'col_actions'    => 'Actions',
    'sort_by'        => 'Sort by :column',
    'no_description' => 'No description',

    // Document blocks
    'block_header'    => 'Header',
    'block_signature' => 'Signature',
    'block_footer'    => 'Footer',
    'block_on'        => ':block: included',
    'block_off'       => ':block: not included',

    // Origin
    'origin_own'       => 'Own',
    'origin_adopted'   => 'Adopted',
    'update_available' => 'Update available',

    // Status
    'status_active'   => 'Active',
    'status_inactive' => 'Inactive',

    // Customize columns
    'columns_label'       => 'Columns',
    'columns_customize'   => 'Customize columns',
    'columns_order_title' => 'Column order',
    'columns_move_up'     => 'Move up',
    'columns_move_down'   => 'Move down',
    'columns_reset'       => 'Restore default',

    // Actions
    'action_preview'   => 'Preview',
    'action_edit'      => 'Edit',
    'action_reimport'  => 'Re-import global template',
    'action_delete'    => 'Delete',
    'more_actions'     => 'More actions',
    'confirm_delete'   => 'Delete the template ":title"?',
    'confirm_reimport' => 'Re-import the current global template version into ":title"? Texts changed in this clinic will be replaced.',

    // States
    'empty_list'   => 'No templates yet.',
    'empty_search' => 'No templates match these filters.',

    // Pagination ("Showing 1–12 of 40 templates")
    'pagination_showing'  => 'Showing',
    'pagination_of'       => 'of',
    'pagination_suffix'   => 'templates',
    'pagination_label'    => 'Pagination',
    'pagination_previous' => 'Previous',
    'pagination_next'     => 'Next',

    // Action feedback
    'flash_saved'      => 'Template saved successfully.',
    'flash_updated'    => 'Template updated successfully.',
    'flash_deleted'    => 'Template deleted successfully.',
    'flash_adopted'    => 'Template adopted successfully.',
    'flash_reimported' => 'Content re-imported successfully.',
    'error_reimport'   => 'Could not re-import: the source global template is no longer available.',
    'error_adopt'      => 'This template is not available for adoption.',

    // Default template update on content customized by the clinic
    'upstream_badge'       => 'Default template update available',
    'upstream_hint'        => 'The clinic customized this text and the EasyEye default template changed afterwards. Nothing was changed without your decision.',
    'upstream_view'        => 'View default template',
    'upstream_apply'       => 'Apply update',
    'upstream_keep'        => 'Keep mine',
    'upstream_confirm'     => 'Replace the ":label" text with the current default template? The clinic\'s customized text will be replaced (the change is logged).',
    'upstream_title'       => 'Your text × updated default template',
    'upstream_local'       => 'Your text (customized)',
    'upstream_global'      => 'Updated default template',
    'upstream_loading'     => 'Loading…',
    'upstream_error'       => 'Could not load the default template.',
    'upstream_unsaved'     => 'Save or discard the changes in this form before applying or keeping.',
    'flash_update_applied' => 'Default template update applied.',
    'flash_update_kept'    => 'Clinic text kept. The notice comes back if the default template changes again.',
];
