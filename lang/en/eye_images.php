<?php

declare(strict_types=1);

return [
    // Filter bar
    'search_placeholder' => 'Patient name or code...',
    'period_today'       => 'Today',
    'period_7'           => 'Last 7 days',
    'period_15'          => 'Last 15 days',
    'period_30'          => 'Last 30 days',
    'period_90'          => 'Last 90 days',
    'filters_btn'        => 'Filters',
    'eye_label'          => 'Eye:',
    'all'                => 'All',
    'all_exams'          => 'All exams',
    'all_statuses'       => 'All statuses',
    'status_requested'   => 'Requested',
    'status_done'        => 'Done',
    'status_reported'    => 'Reported',
    'status_cancelled'   => 'Cancelled',
    'all_doctors'        => 'All doctors',
    'clear_btn'          => 'Clear',

    // Sidebar
    'patients_title'        => 'Patients',
    'no_patients'           => 'No patients.',
    'patients_count_suffix' => 'patient(s)',

    // Main area
    'medical_record'      => 'Medical Record',
    'view_selected'       => 'View selected',
    'view_all'            => 'View all',
    'print_btn'           => 'Print',
    'selected_suffix'     => 'selected',
    'select_patient_hint' => 'Select a patient on the sidebar to view their exams.',
    'loading_images'      => 'Loading images…',
    'no_exams'            => 'No exams for the selected filters.',
    'upload_btn'          => 'Upload',
    'download_btn'        => 'Download',
    'panel_prefix'        => 'Panel ',
    'no_image'            => 'No image',
    'not_found'           => 'Not found',

    // Print modal
    'portrait'    => 'Portrait',
    'landscape'   => 'Landscape',
    'close_btn'   => 'Close',
    'report_date' => 'Report date:',

    // AI — ocular image analysis
    'ai_analyze'         => 'Analyze with AI',
    'ai_selected_images' => 'Selected images',
    'ai_no_selection'    => 'Select at least one image to analyze.',
    'ai_report'          => 'AI report',
    'ai_reported_badge'  => 'Reported (AI)',
    'download_pdf'       => 'Download PDF',

    // Manual report (Templates) — reuses the same catalog used by the
    // medical record's Documentations, filtered to reports/specialized exams.
    'report_doctor_required'     => 'Select a responsible doctor before issuing the report.',
    'report_default_title'       => 'Imaging Exam Report',
    'report_title'               => 'New report',
    'report_new'                 => 'New report',
    'report_templates'           => 'Templates',
    'report_template_blank'      => 'Blank',
    'report_loading_templates'   => 'Loading templates…',
    'report_no_templates'        => 'No templates available.',
    'report_content_label'       => 'Report content',
    'report_title_placeholder'   => 'Report title (optional)',
    'report_save'                => 'Save report',
    'report_saved'               => 'Report saved successfully.',
    'report_save_failed'         => 'Could not save the report.',
    'report_confirm_open_record' => 'There is no medical record for the visit date. Open a new record to save the report?',
    'report_content_required'    => 'Write the report content before saving.',

    // Compare / Align (progression across exams)
    'compare_title'             => 'Compare exams',
    'compare_action'            => 'Compare',
    'compare_select_two'        => 'Select exactly 2 images to compare.',
    'compare_mode_overlay'      => 'Overlay',
    'compare_mode_side_by_side' => 'Side by side',
    'compare_opacity'           => 'Opacity',
    'compare_reset'             => 'Reset position',
    'compare_hint'              => 'Drag the top image to align reference points.',

    // Import external exam
    'import' => [
        'success' => 'Exam imported successfully.',
    ],
];
