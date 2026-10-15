<?php

return [
    // Patient consent types (LGPD art. 7 and 11). `description` is the text the data subject authorises.
    'types' => [
        'data_collection' => [
            'label'       => 'Data Collection and Storage',
            'description' => 'I authorise the collection, storage and use of my personal data for clinical care.',
        ],
        'sensitive_data' => [
            'label'       => 'Health Data Processing',
            'description' => 'I authorise the processing of my health data (diagnoses, exams, prescriptions) for medical care.',
        ],
        'data_sharing' => [
            'label'       => 'Sharing with Third Parties',
            'description' => 'I authorise sharing my data with insurers and operators for billing.',
        ],
        'minor_guardian' => [
            'label'       => 'Representation of a Minor',
            'description' => 'As legal guardian, I authorise the processing of the data of the minor under my responsibility.',
        ],
        'image_and_voice' => [
            'label'       => 'Use of Image and Voice',
            'description' => 'I authorise the use of images and voice recordings for clinical documentation.',
        ],
        'research' => [
            'label'       => 'Research Participation',
            'description' => 'I authorise the anonymised use of my data for scientific research.',
        ],
        'care_reminders' => [
            'label'       => 'Follow-up reminders by WhatsApp',
            'description' => 'I authorise the clinic to send me follow-up reminders by WhatsApp, without stating the reason for the follow-up. I can stop receiving them at any time by replying STOP.',
        ],
    ],

    // Revocation record (patient_consents.revocation_reason).
    'revoked' => [
        'whatsapp_opt_out' => 'Patient replied STOP on WhatsApp',
        'provider_opt_out' => 'WhatsApp reported that the patient does not want messages from this number',
        'patient_form'     => 'Unchecked in the patient record',
        'replaced'         => 'Replaced by a new consent',
    ],
];
