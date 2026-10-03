<?php

return [
    // Header
    'greeting_morning'   => 'Good morning',
    'greeting_afternoon' => 'Good afternoon',
    'greeting_evening'   => 'Good evening',
    'operational_panel'  => ':app operational panel',

    // Header buttons
    'btn_patients'    => 'Patients',
    'btn_new_patient' => 'New patient',

    // KPIs
    'kpi_patients'       => 'Patients',
    'kpi_today'          => 'Today\'s appointments',
    'kpi_doctors'        => 'Active doctors',
    'kpi_surgeries'      => 'Surgeries today',
    'kpi_exams_pending'  => 'Pending exams',
    'kpi_guides_waiting' => 'Pending guides',
    'kpi_receivable'     => 'Receivable',
    'kpi_satisfaction'   => 'Satisfaction',
    'kpi_coming_soon'    => 'Coming soon',
    'kpi_open_list'      => 'View :label list',

    // Modules / Shortcuts
    'module_schedule'     => 'Schedule',
    'module_waiting_room' => 'Waiting Room',
    'module_eye_images'   => 'Eye Images',
    'module_tiss'         => 'TISS Guides',
    'module_financial'    => 'Financial',
    'module_surgery'      => 'Surgical Center',
    'coming_soon'         => 'Coming soon',

    // Sections
    'section_recent_patients' => 'Recent patients',
    'section_day_summary'     => 'Day summary',

    // Patient table columns
    'col_name'      => 'Patient',
    'col_phone'     => 'Phone',
    'col_code'      => 'Code',
    'col_actions'   => 'Actions',
    'col_doctor'    => 'Doctor',
    'col_time'      => 'Time',
    'col_situation' => 'Situation',

    // Day summary
    'summary_total'     => 'Total appointments',
    'summary_attended'  => 'Attended',
    'summary_pending'   => 'In progress / waiting',
    'summary_cancelled' => 'Cancelled / no-show',

    // Actions
    'btn_see_all'      => 'See all',
    'btn_view'         => 'View',
    'btn_waiting_room' => 'Waiting room',
    'btn_see_schedule' => 'See full schedule',

    // Empty states
    'empty_schedules' => 'No appointments scheduled for today.',
    'empty_patients'  => 'No patients registered.',

    // Activation / Setup
    'activation_title'        => 'Set up your clinic',
    'activation_subtitle'     => 'Complete the steps to get the most out of the system.',
    'activation_done'         => 'setup complete',
    'activation_optional'     => 'optional',
    'activation_completed_on' => 'Completed on',

    // Live / Polling
    'live_label'      => 'Live',
    'live_refreshing' => 'Refreshing...',
    'last_updated_at' => 'Updated at',
    'btn_refresh'     => 'Refresh',

    // Today's schedule
    'section_schedule_today' => "Today's schedule",

    // Demo
    'demo_title'       => 'Demo environment',
    'demo_description' => 'Populate test data or reset the environment for demonstrations.',
    'demo_btn_seed'    => 'Populate data',
    'demo_btn_reset'   => 'Reset environment',

    // Header and customization
    'page_title'      => 'Dashboard',
    'customize'       => 'Customize',
    'customize_title' => 'Customize the dashboard',
    'sections_order'  => 'Section order',

    // Reorderable sections
    'section_kpis'      => 'Indicators',
    'section_shortcuts' => 'Shortcuts',
    'section_agenda'    => "Today's schedule",
    'section_patients'  => 'Recent patients',
    'section_stock'     => 'Stock alerts',

    // Favorite shortcuts
    'shortcuts'       => 'Shortcuts',
    'shortcuts_title' => 'Choose favorite shortcuts',
    'shortcuts_menu'  => 'Favorite shortcuts',

    // Reorder menu (show/hide/move)
    'order_show'      => 'Show',
    'order_hide'      => 'Hide',
    'order_move_up'   => 'Move up',
    'order_move_down' => 'Move down',
    'order_reset'     => 'Restore default',

    // Today's schedule
    'arrived' => 'Arrived',

    // Stock alerts
    'stock_title'               => 'Stock alerts',
    'stock_see'                 => 'View stock',
    'stock_below_minimum_one'   => ':count product below the minimum',
    'stock_below_minimum_other' => ':count products below the minimum',
    'stock_below_minimum_hint'  => 'Restock so you do not run out of supplies.',
    'stock_expiring_one'        => ':count product with a lot expiring',
    'stock_expiring_other'      => ':count products with lots expiring',
    'stock_expiring_hint'       => 'Expired or expiring in the next 30 days.',

    // Steps of the "Set up your clinic" card (App\Enums\ActivationStep)
    'activation_steps' => [
        'integrator_registered'    => 'Integrator registered', 'integrator_capture_observed' => 'Capture observed by integrator', 'integrator_receipt_confirmed' => 'First receipt confirmed',
        'entity_profile_completed' => 'Clinic profile completed',
        'first_doctor_added'       => 'First doctor registered',
        'first_patient_added'      => 'First patient registered',
        'first_schedule_created'   => 'First appointment booked',
        'first_medical_record'     => 'First medical record created',
        'team_member_invited'      => 'Team member invited',
        'integrator_connected'     => 'Integrator authenticated',
    ],

    // Today's schedule: limited list
    'schedule_showing' => 'Showing :shown of :total appointments today.',
];
