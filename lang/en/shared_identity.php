<?php

/*
 * GLOBAL identities shared across clinics: person record (People —
 * patient/doctor) and login (User). A clinic never links nor rewrites on its
 * own what also belongs to another clinic.
 */
return [
    'person_shared_readonly' => 'The personal data of this record is also used by another clinic and cannot be changed here. Undo the changes to the personal data to save the rest.',
    'user_shared_readonly'   => 'This user also has access to another clinic or to the partner portal: the login name and e-mail can only be changed by the user, under "My profile".',

    'import' => [
        'cpf_linked_elsewhere' => 'CPF already registered at another clinic. For security (LGPD), the import does not link records from other clinics — row not imported.',
        'email_in_use'         => 'E-mail already registered to another login. For security, the import only accepts new e-mails or doctors already registered at this clinic.',
        'row_failed'           => 'This row could not be saved due to an internal error. Review the data and import the row again; if the error persists, contact support.',
        'failed'               => 'The import was interrupted by an internal error. Try again; if the error persists, contact support.',
    ],
];
