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
    'kpi_patients'           => 'Patients',
    'kpi_today'              => 'Today\'s appointments',
    'kpi_doctors'            => 'Active doctors',
    'kpi_surgeries'          => 'Surgeries today',
    'kpi_exams_pending'      => 'Pending exams',
    'kpi_exams_pending_hint' => 'Unreported · last 30 days',
    'kpi_guides_waiting'     => 'Pending guides',
    'kpi_receivable'         => 'Receivable',
    'kpi_satisfaction'       => 'Satisfaction',
    'kpi_coming_soon'        => 'Coming soon',
    'kpi_open_list'          => 'View :label list',

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
    'summary_total'          => 'Booked',
    'summary_attended'       => 'Attended',
    'summary_pending'        => 'To be seen',
    'summary_cancelled'      => 'No-shows / cancelled',
    'summary_progress_label' => 'Day progress',
    'summary_progress'       => ':attended of :expected attended',
    'summary_by_shift'       => 'By shift',

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
    'live_label'       => 'Live',
    'live_refreshing'  => 'Refreshing...',
    'last_updated_at'  => 'Updated at',
    'btn_refresh'      => 'Refresh',
    'btn_refresh_hint' => 'Refresh now (includes the month figures)',

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
    'sections_order'  => 'Dashboard sections',

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

    // ── Doctor dashboard (only their own data) ───────────────────────────
    'kpi_my_today'         => 'My appointments today',
    'kpi_my_waiting'       => 'Waiting for you',
    'kpi_my_waiting_hint'  => 'Already arrived',
    'kpi_my_exams_pending' => 'My unreported exams',
    'kpi_ai_waiting'       => 'AI reports to review',
    'kpi_ai_waiting_hint'  => 'Waiting for your approval',
    'kpi_waiting_now'      => 'Waiting now',
    'kpi_waiting_now_hint' => 'Patients who have arrived',

    'section_my_schedule_today'  => 'My schedule today',
    'section_my_day_summary'     => 'My day',
    'section_my_recent_patients' => 'My recent patients',
    'section_pending'            => 'My to-dos',
    'col_last_visit'             => 'Last visit',
    'empty_my_schedules'         => 'You have no appointments today.',
    'shifts_label'               => 'Today\'s schedule shifts',
    'shift_morning'              => 'Morning',
    'shift_afternoon'            => 'Afternoon',
    'shift_evening'              => 'Evening',
    'shift_now'                  => 'Now',
    'shift_empty'                => 'No appointments in this shift.',
    'empty_my_patients'          => 'You have not seen any patients yet.',

    // Next patient
    'next_patient_title'     => 'Next patient',
    'next_patient_arrived'   => 'Arrived at :time',
    'next_patient_waiting'   => 'waiting for :minutes min',
    'next_patient_scheduled' => 'Booked for :time',
    'next_patient_empty'     => 'Nobody is waiting and there are no other appointments booked for today.',
    'btn_start_attendance'   => 'Start appointment',
    'btn_open_patient'       => 'Open record',
    'btn_open_schedule'      => 'See in schedule',

    // Doctor to-dos
    'ai_waiting_title'  => 'AI reports waiting for approval',
    'ai_waiting_empty'  => 'No AI reports waiting for your approval.',
    'ai_waiting_review' => 'Review',
    'unsigned_title'    => 'Unsigned medical records',
    'unsigned_hint'     => 'Your records from the last :days days',
    'unsigned_empty'    => 'None of your records from the last :days days are unsigned.',
    'btn_open_record'   => 'Open',
    'pending_count'     => 'Pending: :count',
    'pending_showing'   => 'Showing :shown of :total.',

    // Doctor user without a doctor record in the clinic
    'doctor_missing_title' => 'Incomplete doctor registration',
    'doctor_missing_text'  => 'Your user is not linked to a doctor record in this clinic yet, so the dashboard does not show schedule, patients or to-dos. Ask the clinic administrator to finish your registration under Doctors.',

    // ══ Dashboard v2 — workstation per role ═══════════════════════════════
    // Header: the role's workstation and primary actions
    'role_doctor'           => 'My practice',
    'role_secretary'        => 'Front desk',
    'role_admin'            => 'Management',
    'role_financial'        => 'Finance',
    'role_user'             => 'Overview',
    'action_new_schedule'   => 'New appointment',
    'action_my_schedule'    => 'My schedule',
    'action_open_bi'        => 'Open BI',
    'action_new_cash_entry' => 'New cash entry',

    // Sections ("Customize" menu)
    'section_next'          => 'Next patient',
    'section_finance'       => 'Cash, receivables and claim denials',
    'section_trends'        => 'Trends',
    'section_confirmations' => 'Confirmations',
    'section_waitlist'      => 'Waiting list',
    'section_birthdays'     => 'Birthdays',

    // Indicators: period and change
    'kpis_month_title'   => 'Month indicators',
    'kpis_month_compare' => 'Month to date, compared with the same period of last month (:period).',
    'kpi_vs'             => 'vs. :period',
    'delta_pp'           => 'pp',
    'delta_no_base'      => 'No data in :period',
    'delta_sr_up'        => 'Up :value compared with :period.',
    'delta_sr_down'      => 'Down :value compared with :period.',
    'delta_sr_flat'      => 'Same as :period.',
    'kpi_today_progress' => ':attended of :expected attended',

    // Doctor indicators (month)
    'kpi_my_month_attended' => 'My appointments this month',
    'kpi_my_noshow_rate'    => 'My no-show rate',
    'kpi_noshow_rate_hint'  => 'No-shows ÷ (attended + no-shows) in the period. Cancelled and upcoming appointments are not counted.',

    // Front desk indicators
    'kpi_longest_wait'    => 'Longest wait: :minutes min',
    'kpi_confirmed_today' => 'Confirmed today',
    'kpi_confirmed_of'    => ':confirmed of :total appointments',
    'kpi_tomorrow'        => 'Appointments tomorrow',
    'kpi_unconfirmed'     => ':count not confirmed',
    'kpi_all_confirmed'   => 'All confirmed',
    'kpi_waitlist'        => 'Waiting list',
    'kpi_waitlist_hint'   => 'Patients waiting for a slot',

    // Management indicators (same definitions as the BI)
    'kpi_occupancy'         => 'Schedule occupancy',
    'kpi_occupancy_hint'    => 'Attended ÷ non-cancelled appointments that already took place in the period (today\'s upcoming appointments are not counted).',
    'kpi_attendance'        => 'Attendance',
    'kpi_attendance_hint'   => 'Attended ÷ (attended + no-shows). Cancelled and pending appointments are not counted.',
    'kpi_noshow_rate'       => 'No-show rate',
    'kpi_new_patients'      => 'New patients',
    'kpi_income'            => 'Revenue received',
    'kpi_receivable_hint'   => "Today's position: pending income entries in the cash book + insurance claims submitted and awaiting payment.",
    'kpi_overdue'           => ':amount overdue',
    'kpi_nothing_overdue'   => 'Nothing overdue',
    'kpi_attended_month'    => 'Appointments attended',
    'kpi_billed'            => 'Billed (insurance)',
    'kpi_billed_hint'       => 'Amount of the claims with service in the period, excluding drafts and cancelled ones.',
    'kpi_paid'              => 'Received from insurers',
    'kpi_glosa'             => 'Denied',
    'kpi_glosa_hint'        => 'Amount denied by insurers on the claims of the period.',
    'kpi_ticket'            => 'Average ticket',
    'kpi_ticket_hint'       => 'Amount received divided by the number of paid claims in the period.',
    'kpi_receipt_rate'      => 'Collection rate',
    'kpi_receipt_rate_hint' => 'Received ÷ billed on the claims of the period.',
    'kpi_patients_hint'     => 'Active records',

    // Day summary: progress
    'summary_in_clinic'         => 'In the clinic',
    'summary_to_come'           => 'Remaining',
    'summary_missed_note_one'   => ':count no-show/cancellation not counted.',
    'summary_missed_note_other' => ':count no-shows/cancellations not counted.',
    'summary_all_missed'        => "All of today's appointments were cancelled or missed.",

    // Today's schedule
    'arrived_at' => 'arrived at :time',
    'live_hint'  => 'Appointments not finished yet',
    'list_more'  => '+ :count in the full list',

    // Waiting room (front desk)
    'waiting_room_title'   => 'Waiting room',
    'waiting_room_empty'   => 'Nobody is waiting right now.',
    'waiting_room_in_care' => ':count in consultation',
    'waiting_room_arrived' => 'arrived at :time',
    'waiting_room_minutes' => ':minutes min',
    'waiting_room_waiting' => 'Waiting for :minutes minutes',

    // Confirmations (front desk)
    'confirm_title'             => 'Confirmations',
    'confirm_subtitle'          => "Today's and tomorrow's appointments",
    'confirm_today'             => 'Today',
    'confirm_tomorrow'          => 'Tomorrow',
    'confirm_open_schedule'     => 'Open schedule',
    'confirm_no_schedules'      => 'No appointments booked.',
    'confirm_of_total'          => 'of :total confirmed',
    'confirm_meter_aria'        => ':confirmed of :total appointments confirmed',
    'confirm_unconfirmed'       => ':count not confirmed',
    'confirm_all_done'          => 'All confirmed',
    'confirm_by_whatsapp'       => ':count via WhatsApp',
    'confirm_shift_confirmed'   => '(:count conf.)',
    'confirm_whatsapp_label'    => 'WhatsApp confirmation of the remaining ones',
    'confirm_wa_awaiting'       => ':count awaiting reply',
    'confirm_wa_queued'         => ':count queued to send',
    'confirm_wa_failed'         => ':count failed to send',
    'confirm_wa_none'           => ':count not sent',
    'confirm_wa_state_awaiting' => 'WhatsApp not answered',
    'confirm_wa_state_queued'   => 'WhatsApp queued',
    'confirm_wa_state_failed'   => 'WhatsApp failed',
    'confirm_call_title'        => 'Call to confirm',
    'confirm_call_aria'         => 'Call :name — :phone',
    'confirm_no_phone'          => 'No phone',
    'confirm_truncated'         => 'Very busy days: the figures consider the first 1,000 appointments.',

    // Waiting list and birthdays (front desk)
    'waitlist_title'       => 'Waiting list',
    'waitlist_open'        => 'Open in schedule',
    'waitlist_empty'       => 'The waiting list is empty.',
    'waitlist_between'     => 'from :from to :until',
    'waitlist_from'        => 'from :date',
    'waitlist_until'       => 'until :date',
    'waitlist_since_today' => 'added today',
    'waitlist_since_one'   => ':count day ago',
    'waitlist_since_other' => ':count days ago',
    'birthdays_title'      => "Today's birthdays",
    'birthdays_empty'      => 'No patient has a birthday today.',
    'birthdays_age'        => 'Turns :age',

    // Appointments per doctor (management)
    'doctors_today_title'      => 'Appointments per doctor',
    'doctors_today_empty'      => 'No appointments today.',
    'doctors_col_progress'     => 'Attended',
    'doctors_col_waiting'      => 'In clinic',
    'doctors_col_waiting_hint' => 'Arrived and waiting (includes dilation and exams)',
    'doctors_col_missed'       => 'No-shows',
    'doctors_progress_sr'      => ':attended of :expected attended',

    // Trends
    'trend_daily_title'   => 'Appointments × no-shows',
    'trend_daily_sub'     => 'Last 30 days',
    'trend_finance_title' => 'Revenue × expenses · 6 months',
    'daily_attended'      => 'Attended',
    'daily_noshow'        => 'No-shows',
    'daily_cancelled'     => 'Cancelled',
    'daily_rate'          => 'No-show rate: :rate%',
    'daily_chart_aria'    => 'Last :days days: :attended appointments attended and :noshow no-shows (no-show rate :rate%).',
    'col_day'             => 'Day',
    'see_data'            => 'View data',
    'hide_data'           => 'Hide data',
    // Texts of the reused BI chart (TrendBarChart)
    'fin_chart' => [
        'trend_chart_aria' => 'From :from to :to: revenue :income, expenses :expense, balance :balance.',
        'col_month'        => 'Month',
        'col_income'       => 'Revenue',
        'col_expense'      => 'Expenses',
        'col_balance'      => 'Balance',
        'monthly_trend'    => 'Revenue × expenses per month',
        'see_data'         => 'View data',
        'hide_data'        => 'Hide data',
    ],
    'covenants_title'        => 'Billed × received per insurer',
    'covenants_sub'          => 'Current month',
    'covenants_billed'       => 'Billed',
    'covenants_paid'         => 'Received',
    'covenants_denied_label' => 'Denied',
    'covenants_rate'         => 'Collection: :rate%',
    'covenants_empty'        => 'No claims billed this month.',
    'covenants_row'          => 'Received :paid',
    'covenants_denied'       => 'denied :denied',
    'covenants_row_aria'     => ':name: billed :billed, received :paid, denied :denied.',

    // Finance: today's cash, receivables and claim denials
    'cash_today_title'           => "Today's cash",
    'cash_today_open'            => 'Open cash book',
    'cash_in'                    => 'Income paid today',
    'cash_out'                   => 'Expenses paid today',
    'cash_balance'               => 'Day balance',
    'cash_receivable_today'      => 'Still to receive today',
    'cash_payable_today'         => 'Still to pay today',
    'cash_projected'             => 'Projected day balance',
    'cash_entries_count'         => "Today's entries",
    'receivables_title'          => 'Receivables',
    'receivables_sub'            => "Today's position",
    'receivables_cash'           => 'Private payments not yet due',
    'receivables_cash_overdue'   => 'Private payments overdue (:count)',
    'receivables_claims'         => 'Open insurance claims (:count)',
    'receivables_claims_overdue' => 'Overdue claims (:count)',
    'glosas_title'               => 'Claim denials to handle',
    'glosas_open'                => 'View denials',
    'glosas_empty'               => 'No pending claim denials.',
    'glosas_amount_hint'         => 'open and under appeal',
    'glosas_overdue'             => 'Appeal deadline passed (:count)',
    'glosas_due_soon'            => 'Deadline within :days days (:count)',
    'glosas_open_count'          => 'Open (:count)',
    'glosas_appealed'            => 'Under appeal (:count)',

    // Shortcuts
    'module_patients'   => 'Patients',
    'module_glosas'     => 'Claim denials',
    'module_bi'         => 'BI',
    'empty_strip_label' => 'Sections with nothing right now',
];
