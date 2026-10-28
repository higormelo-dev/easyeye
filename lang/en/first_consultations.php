<?php

declare(strict_types=1);

/*
 * First visit report (Phase 1 / B3) — Panel/Reports/FirstConsultations page
 * and export.
 */
return [
    'title'            => 'First visit report',
    'subtitle'         => 'Patients whose first attended visit at the clinic was in the period',
    'subtitle_mine'    => 'Patients whose first attended visit at the clinic was with you in the period',
    'from_label'       => 'From',
    'until_label'      => 'To',
    'doctor_label'     => 'Doctor',
    'doctor_all'       => 'All doctors',
    'filter'           => 'Filter',
    'export_csv'       => 'Export CSV',
    'export_xlsx'      => 'Export Excel',
    'period'           => 'Period: :from to :until',
    'total'            => 'New patients',
    'by_doctor'        => 'By doctor',
    'col_date'         => 'Date',
    'col_patient'      => 'Patient',
    'col_patient_code' => 'Code',
    'col_doctor'       => 'Doctor',
    'col_covenant'     => 'Health plan',
    'col_type'         => 'Type',
    'no_covenant'      => 'Private / no health plan',
    'urgent_badge'     => 'Urgent care',
    'empty'            => 'No first visit in the period.',
    'how_it_counts'    => 'Counts each patient\'s first "Attended" visit at the clinic (includes imported history and urgent care).',
    'period_too_long'  => 'Choose a period of up to :days days.',
    'sheet_name'       => 'First visits',
    'datetime_format'  => 'm/d/Y H:i',
];
