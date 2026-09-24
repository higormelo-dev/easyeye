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
    'report_insert_image'        => 'Insert image',
    'report_phrases'             => 'Quick phrases',
    'report_phrases_empty'       => 'No saved phrases yet.',
    'report_phrases_save'        => 'Save selection as phrase',
    'report_phrases_save_hint'   => 'Select a piece of the text above before saving it as a phrase.',
    'report_phrases_label_title' => 'Phrase label',
    'report_phrases_saved'       => 'Phrase saved.',
    'report_phrases_deleted'     => 'Phrase removed.',
    'phrase_limit_reached'       => 'Quick phrase limit reached.',

    // Native equipment PDF text extraction — 09/18/2026 benchmark.
    'pdf_no_text_found'          => 'Could not extract text from any selected PDF.',
    'pdf_extract_text'           => 'Extract text from PDF',
    'pdf_extracting'             => 'Extracting text from PDF…',
    'pdf_extract_failed'         => 'Could not extract text from the PDF.',
    'report_title_placeholder'   => 'Report title (optional)',
    'report_save'                => 'Save report',
    'report_saved'               => 'Report saved successfully.',
    'report_save_failed'         => 'Could not save the report.',
    'report_confirm_open_record' => 'There is no medical record for the visit date. Open a new record to save the report?',
    'report_content_required'    => 'Write the report content before saving.',
    'report_inactive_exam'       => 'One or more selected images are disabled — enable them before generating the report.',

    // Batch reporting: a selection spanning 2+ exam types becomes 1 report
    // per group (e.g. Pentacam + Retinography on the same day), in sequence.
    'report_queue_confirm_title' => 'Report exams separately?',
    'report_queue_confirm_text'  => 'A separate report will be created for each of the selected exams:',
    'report_queue_confirm_ok'    => 'Start',
    'report_queue_label'         => 'Report',
    'report_queue_of'            => 'of',
    'report_queue_next'          => 'Next report',
    'report_queue_previous'      => 'Reports already generated this session:',
    'merge_split_same_patient'   => 'You can only merge/split images from the same patient.',
    'merge_action'               => 'Merge exams',
    'merge_select_two'           => 'Select 2 or more images from the same patient to merge.',
    'merge_success'              => 'Images merged into the same exam.',
    'split_action'               => 'Split exam',
    'split_select_one'           => 'Select at least 1 image from the group to split off.',
    'split_success'              => 'Image(s) split into a new exam.',
    'merged_badge'               => 'Merged',
    'undo_merge_split'           => 'Manually merged/split — click to undo (back to automatic grouping).',

    // Lens calculator (vertex + spherical equivalent) — competitor benchmark 2026-09-09
    'lens_calc_title'           => 'Lens calculator',
    'lens_calc_disclaimer'      => 'Reference optics formulas (vertex distance and spherical equivalent) — always double-check the result before using it. Does not include IOL (intraocular lens) power calculation: use a dedicated, validated biometry calculator for cataract surgery.',
    'lens_calc_vertex_title'    => 'Vertex distance conversion',
    'lens_calc_vertex_hint'     => 'Converts spectacle prescription to the equivalent contact lens power (zero vertex).',
    'lens_calc_vertex_distance' => 'Vertex distance (mm)',
    'lens_calc_sphere_od'       => 'Sphere OD (D)',
    'lens_calc_sphere_oe'       => 'Sphere OS (D)',
    'lens_calc_result'          => 'Contact lens',
    'lens_calc_se_title'        => 'Spherical equivalent',
    'lens_calc_se_hint'         => 'SE = Sphere + Cylinder / 2.',

    // Group by Equipment (default) / Group by Exam — 09/18/2026 benchmark
    'group_by_equipment' => 'Group by Equipment',
    'group_by_exam'      => 'Group by Exam',

    // Montage (image collage) — 09/18/2026 benchmark.
    'montage_action'      => 'Montage',
    'montage_select_two'  => 'Select 2 or more images to build the montage.',
    'montage_unavailable' => 'Feature unavailable in this environment (Imagick extension not installed on the server).',
    'montage_no_images'   => 'None of the selected images could be read to build the montage.',
    'montage_failed'      => 'Could not build the montage.',

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

    // Context menu (right-click on thumbnail) — competitor benchmark 2026-09-09
    'context_menu_title'          => 'Quick actions',
    'context_menu_report'         => 'Make manual report',
    'context_menu_compare'        => 'Compare / Align',
    'context_menu_share'          => 'Share with patient',
    'context_menu_unshare'        => 'Revoke from Patient Portal',
    'context_menu_download'       => 'Download image',
    'context_menu_eye'            => 'Eye',
    'context_menu_quality'        => 'Capture quality',
    'context_menu_quality_hint'   => 'Click to rate — click the same star again to clear',
    'context_menu_disable'        => 'Disable image',
    'context_menu_enable'         => 'Enable image',
    'context_menu_info_type'      => 'Type',
    'context_menu_info_created'   => 'Captured at',
    'context_menu_info_equipment' => 'Equipment',
    'context_menu_info_doctor'    => 'Doctor',
    'context_menu_info_origin'    => 'Origin',
];
