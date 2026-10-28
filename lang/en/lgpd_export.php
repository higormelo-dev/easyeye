<?php

// Scope statement of the data subject export (LGPD art. 19, II).
return [
    'scope' => [
        'clinic_only'  => 'Data processed by this clinic. Each clinic is an independent controller: data held by other clinics must be requested from each one.',
        'not_included' => [
            'binary_files'              => 'Files (exam images, attachments, photo) appear as metadata only; their content can be downloaded in the Portal when shared, or requested from the clinic.',
            'internal_instructions'     => 'Internal instructions sent to the artificial intelligence (system prompt and safety rules) are not personal data and are protected as trade secrets (LGPD art. 19).',
            'technical_records'         => 'Technical records that only repeat data already in this file (TISS guide XML/payload, security audit logs).',
            'professional_compensation' => "Payouts and professionals' compensation belong to the relationship between the clinic and the professional, not to the patient.",
            'deleted_records'           => 'Deleted records are retained only to meet legal obligations and can be requested from the clinic.',
            'other_clinics'             => 'Links between your Portal account and other clinics are not included in this file.',
        ],
    ],
];
