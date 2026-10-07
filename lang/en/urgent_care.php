<?php

declare(strict_types=1);

/*
 * Urgent care (Phase 1 / B1) — same-day walk-in queue.
 * Panel/UrgentCare/Index page (`t` prop), UrgentCare* enums and the
 * UrgentCareController/UrgentCareService messages.
 */
return [
    'title'    => 'Urgent care',
    'subtitle' => 'Walk-in patients, by priority and arrival order.',

    // Top summary
    'summary_queued'    => 'In queue',
    'summary_immediate' => 'Immediate',
    'summary_longest'   => 'Longest wait',
    'live_region'       => ':count in the urgent care queue',
    'updated_at'        => 'Updated at :time',
    'refresh'           => 'Refresh',

    // Queue
    'add'              => 'Add patient',
    'queue_title'      => 'Queue now',
    'queue_label'      => 'Urgent care queue, in order of care',
    'queue_empty'      => 'Nobody in the urgent care queue.',
    'queue_empty_hint' => 'Walk-in patients are added here with the "Add patient" button.',
    'closed_title'     => 'Closed today',
    'closed_empty'     => 'Nothing closed today.',
    'position'         => '#:n',
    'age_years'        => ':age years',
    'arrived_at'       => 'Arrived at :time',
    'waiting_for'      => 'Waiting for :time',
    'called_at'        => 'Called at :time',
    'called_times'     => ':count calls',
    'attended_at'      => 'Seen at :time by :doctor',
    'closed_at'        => 'At :time — :reason',
    'no_doctor'        => 'Any doctor',
    'no_covenant'      => 'No health plan',
    'minutes_short'    => ':m min',
    'hours_short'      => ':h h :m min',
    'doctor_label'     => 'Doctor',
    'covenant_label'   => 'Health plan',
    'phone_label'      => 'Phone',

    // Status
    'status_waiting' => 'Waiting',
    'status_called'  => 'Called',
    'status_in_care' => 'In care',
    'status_left'    => 'Left',
    'status_removed' => 'Removed',

    // Priority (Brazilian Law 10,048/2000 and Elderly Statute)
    'priority_immediate'      => 'Immediate',
    'priority_immediate_hint' => 'Sight at risk now: goes ahead of everyone (chemical burn, trauma, sudden vision loss).',
    'priority_legal'          => 'Priority by law',
    'priority_legal_hint'     => 'Law 10,048/2000: aged 60 or over, pregnant or breastfeeding, with a baby in arms, people with disabilities or reduced mobility and obese people.',
    'priority_normal'         => 'Normal',
    'priority_normal_hint'    => 'Arrival order.',
    'priority_suggested'      => 'Priority suggested by the reason. You can change it.',
    'priority_suggested_age'  => 'Patient aged :age: priority by law suggested.',
    'chemical_burn_alert'     => 'Chemical burn: tell the doctor now. Eye irrigation must start immediately.',

    // Reasons (quick ophthalmic list)
    'reason_ocular_trauma'      => 'Eye trauma',
    'reason_foreign_body'       => 'Foreign body',
    'reason_chemical_burn'      => 'Chemical burn',
    'reason_red_eye'            => 'Red eye',
    'reason_eye_pain'           => 'Eye pain',
    'reason_sudden_vision_loss' => 'Sudden vision loss',
    'reason_post_op_complaint'  => 'Post-op complaint',
    'reason_other'              => 'Other',
    'reason_details_ph'         => 'Describe the complaint',
    'reason_details_required'   => 'Describe the complaint (required for "Other").',

    // Form
    'add_title'             => 'Add to urgent care',
    'edit_title'            => 'Edit queue item',
    'field_patient'         => 'Patient',
    'field_reason'          => 'Reason',
    'field_reason_details'  => 'Complaint description',
    'field_priority'        => 'Priority',
    'field_doctor'          => 'Preferred doctor',
    'field_covenant'        => 'Health plan',
    'field_close_status'    => 'Status',
    'field_close_reason'    => 'Reason',
    'doctor_optional'       => 'Optional — without a doctor, any doctor in the clinic can see the patient.',
    'covenant_hint'         => 'Active health plans only. Comes from the patient record; change it if needed.',
    'patient_search_ph'     => 'Name, ID number, phone or PAC code',
    'patient_search_min'    => 'Type at least 2 letters.',
    'patient_none'          => 'No patient found.',
    'patient_new'           => 'Register patient',
    'patient_change'        => 'Change patient',
    'patient_already'       => 'Already in the queue',
    'quick_title'           => 'Quick registration',
    'quick_name'            => 'Full name',
    'quick_cellphone'       => 'Mobile phone',
    'quick_birth_date'      => 'Date of birth',
    'quick_birth_hint'      => 'Helps avoid duplicate records and suggests the priority by age.',
    'quick_save'            => 'Register and select',
    'quick_homonym_confirm' => 'I confirm this is a different patient',
    'save'                  => 'Save',
    'saving'                => 'Saving…',
    'cancel'                => 'Cancel',
    'close'                 => 'Close',

    // Actions
    'action_call'         => 'Call',
    'action_call_again'   => 'Call again',
    'action_attend'       => 'See patient',
    'action_edit'         => 'Edit',
    'action_left'         => 'Left',
    'action_remove'       => 'Remove from queue',
    'action_more'         => 'More actions',
    'action_open_record'  => 'Open medical record',
    'attend_hint'         => 'Creates the urgent appointment now and opens the medical record.',
    'attend_doctor_only'  => 'Only the doctor (with an active doctor record) can see the patient.',
    'attend_staff_hint'   => 'The doctor sees the patient from their own Urgent care screen.',
    'call_hint'           => 'Shows the patient name on the waiting room call panel.',
    'close_left_title'    => 'Patient left',
    'close_removed_title' => 'Remove from queue',
    'close_reason_ph'     => 'E.g.: left without notice, referred to hospital, added by mistake',
    'close_confirm'       => 'Confirm',

    // Messages
    'added'            => ':name joined the urgent care queue.',
    'updated'          => 'Queue item updated.',
    'called'           => 'Patient called on the waiting room panel.',
    'closed_left'      => 'Departure recorded.',
    'closed_removed'   => 'Patient removed from the queue.',
    'already_queued'   => 'This patient is already in today\'s queue. Edit the existing item.',
    'no_longer_queued' => 'This patient is no longer in the queue (:status). The list was refreshed.',
    'not_yours'        => 'This queue item belongs to another doctor.',
    'invalid_doctor'   => 'Choose an active doctor of this clinic.',

    // Appointment created by "See patient"
    'schedule_note' => 'Urgent care — :reason',
    'badge'         => 'Urgent care',
];
