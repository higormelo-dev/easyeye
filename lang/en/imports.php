<?php

return [
    'status' => [
        'pending'    => 'Pending',
        'processing' => 'Processing',
        'done'       => 'Done',
        'failed'     => 'Failed',
    ],

    'patients' => [
        'title'    => 'Import Patients',
        'subtitle' => 'Import patients in bulk from a CSV spreadsheet.',

        'upload_title' => 'Upload CSV file',
        'upload_hint'  => 'Supports files exported from Feegow, Doctoralia, ProDoctor and any standard CSV spreadsheet.',
        'drop_here'    => 'Click or drag the file here',
        'accepted_formats' => 'Accepted formats: .csv or .txt — delimiter ; or , — up to 20 MB',
        'start_import' => 'Start Import',
        'uploading'    => 'Uploading...',
        'max_size'     => 'Max 20 MB',
        'processing'   => 'Processing import...',
        'download_template' => 'Download CSV Template',
        'download_errors'   => 'Download errors',

        'column_guide_title' => 'Column mapping',
        'required_columns'   => 'Required',
        'optional_columns'   => 'Optional',
        'col_name'           => 'Patient full name',
        'or'                 => 'or',
        'dedup_hint'         => 'Existing patients (same CPF or name+phone) are skipped automatically without overwriting data.',

        'history_title'  => 'Import History',
        'rows'           => 'rows',
        'col_file'       => 'File',
        'col_user'       => 'Sent by',
        'col_date'       => 'Date',
        'col_status'     => 'Status',
        'col_imported'   => 'Imported',
        'col_skipped'    => 'Skipped',
        'col_errors'     => 'Errors',

        'validation' => [
            'file_required' => 'Please select a CSV file to import.',
            'file_mimes'    => 'The file must be .csv or .txt.',
            'file_max'      => 'The file cannot exceed 20 MB.',
            'import_running' => 'An import is already running. Please wait for it to finish before starting another.',
        ],

        'preview_title'       => 'Confirm Import',
        'preview_subtitle'    => 'Review the column mapping before starting. The data below shows the first records from your file.',
        'preview_mapped'      => 'Recognized columns',
        'preview_unmapped'    => 'Ignored columns',
        'preview_unmapped_hint' => 'These columns will not be imported as they do not match any system field.',
        'preview_sample'      => 'Data sample',
        'preview_rows'        => ':count rows detected',
        'preview_required_ok' => 'Required columns found',
        'preview_missing'     => 'Missing required columns',
        'confirm_import'      => 'Confirm and Import',
        'cancel_import'       => 'Cancel',
        'cancelled'           => 'Import cancelled.',

        'plan_limit_title'   => 'Plan quota',
        'plan_unlimited'     => 'unlimited',
        'plan_used'          => ':used of :limit patients used',
        'plan_remaining'     => ':remaining slots available',
        'plan_full'          => 'Patient limit reached. Upgrade your plan to import more.',

        'result_title'       => 'Import complete',
        'result_imported'    => 'imported',
        'result_skipped'     => 'already existed',
        'result_errors'      => 'with errors',
        'result_view_patients' => 'View Patients',
        'result_new_import'  => 'New Import',
        'result_download_errors' => 'Download error report',
        'result_aborted'     => 'Import interrupted:',
    ],
];
