<?php

// Data subject requests (LGPD Arts. 18–19) — Panel/Compliance/LgpdRequests.
return [
    'page_title'            => 'Data subject requests (LGPD)',
    'subtitle'              => 'Record requests received by email, phone or in person and answer them on time. Full confirmation and access: within 15 days of the request (LGPD Art. 19, II).',
    'breadcrumb_dashboard'  => 'Dashboard',
    'breadcrumb_compliance' => 'Compliance & Audit',
    'btn_new'               => 'Record request',
    'open_count'            => 'Open: :count',
    'overdue_count'         => 'Overdue: :count',
    'empty'                 => 'No requests recorded. Requests from the patient portal show up here automatically.',
    'overdue'               => 'Overdue',
    'no_patient'            => 'No linked patient',
    'responded'             => 'Answered on :date by :name',
    'showing_from'          => 'Showing',
    'showing_of'            => 'of',
    'showing_suffix'        => 'requests',
    'pagination_label'      => 'Requests pagination',
    'previous'              => 'Previous',
    'next'                  => 'Next',

    'columns' => [
        'requested_at' => 'Received on',
        'requester'    => 'Data subject',
        'type'         => 'Right',
        'deadline'     => 'Deadline',
        'status'       => 'Status',
        'actions'      => 'Actions',
    ],

    'actions' => [
        'start'    => 'Start review',
        'complete' => 'Complete',
        'reject'   => 'Refuse',
        'export'   => 'Download patient data',
    ],

    'form' => [
        'title'                   => 'Record data subject request',
        'requester_name'          => 'Requester name',
        'requester_email'         => 'Reply email',
        'requester_document'      => 'CPF',
        'requester_document_hint' => 'Optional. Helps confirm identity and find the record.',
        'request_type'            => 'Right requested',
        'requested_at'            => 'Received on',
        'requested_at_hint'       => 'The deadline counts from the date the request arrived.',
        'patient'                 => 'Patient',
        'patient_placeholder'     => 'Search by name, CPF or code',
        'patient_hint'            => 'Optional. Link it to download the patient data.',
        'description'             => 'What was requested',
        'description_hint'        => 'Summarise the request and how it arrived. Do not paste records or passwords.',
        'optional'                => '(optional)',
        'save'                    => 'Record',
        'cancel'                  => 'Cancel',
        'discard_confirm'         => 'Discard what you entered?',
    ],

    'respond' => [
        'complete_title' => 'Complete request',
        'reject_title'   => 'Refuse request',
        'complete_label' => 'Answer given to the data subject',
        'reject_label'   => 'Reason for refusal',
        'complete_hint'  => 'What was done and how the data was delivered. It is kept as proof of the response.',
        'reject_hint'    => 'E.g.: identity not confirmed; data that must be kept by law (state which).',
        'confirm'        => 'Confirm',
        'cancel'         => 'Cancel',
    ],

    'types' => [
        'access'         => 'Confirmation and access (Art. 18, I and II)',
        'correction'     => 'Correction (Art. 18, III)',
        'anonymization'  => 'Anonymisation, blocking or deletion of unnecessary data (Art. 18, IV)',
        'portability'    => 'Portability (Art. 18, V)',
        'deletion'       => 'Deletion of data processed with consent (Art. 18, VI)',
        'information'    => 'Information about sharing (Art. 18, VII)',
        'revoke_consent' => 'Withdrawal of consent (Art. 18, IX)',
        'opposition'     => 'Objection to processing (Art. 18, § 2)',
    ],

    'statuses' => [
        'pending'     => 'Pending',
        'in_progress' => 'Under review',
        'completed'   => 'Completed',
        'rejected'    => 'Refused',
        'cancelled'   => 'Cancelled',
    ],

    'attributes' => [
        'requester_name'     => 'requester name',
        'requester_email'    => 'reply email',
        'requester_document' => 'CPF',
        'request_type'       => 'right requested',
        'requested_at'       => 'date received',
        'patient_id'         => 'patient',
        'description'        => 'request description',
        'action'             => 'action',
        'text'               => 'answer text',
    ],

    'flash' => [
        'created'  => 'Request recorded. Deadline: :date.',
        'start'    => 'Request under review.',
        'complete' => 'Request completed.',
        'reject'   => 'Request refused.',
    ],

    'errors' => [
        'not_open'    => 'This request is already closed.',
        'not_pending' => 'Only a pending request can be put under review.',
    ],

    'compliance_card' => [
        'title' => 'Data subject requests (LGPD)',
        'text'  => 'Access, correction, deletion and the other Art. 18 rights, with deadline and answer recorded.',
        'link'  => 'Open requests',
    ],
];
