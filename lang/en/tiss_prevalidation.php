<?php

declare(strict_types=1);

/*
 * TISS pre-validation (App\Domains\Tiss\PreValidation\Rules): message,
 * suggestion and payer hint per rule code, in the user's language.
 */
return [
    'operator_fallback' => 'the payer',
    'guide_type'        => [
        'sadt'         => 'SP-SADT',
        'consultation' => 'consultation',
    ],
    'AUTHORIZATION_REQUIRED' => [
        'message'       => 'Prior authorization number not provided.',
        'suggestion'    => 'This payer requires prior authorization. Enter the number provided by the payer.',
        'operator_hint' => 'Warning: :operator will deny this claim because the procedure requires prior authorization.',
    ],
    'BENEFICIARY_CARD_REQUIRED' => [
        'message'       => 'Beneficiary\'s card number not provided.',
        'suggestion'    => 'Enter the card number exactly as shown on the health plan card.',
        'operator_hint' => 'Warning: :operator will deny this claim because the card number is required.',
    ],
    'BENEFICIARY_NAME_MISSING' => [
        'message'    => 'Beneficiary\'s name not provided.',
        'suggestion' => 'Enter the full name as shown on the health plan card.',
    ],
    'CID_REQUIRED' => [
        'message'       => 'ICD-10 code not provided.',
        'suggestion'    => 'Enter the ICD-10 code for the diagnosis (e.g., H40.1 for glaucoma, H25.9 for cataract).',
        'operator_hint' => 'Warning: :operator will deny this claim because the ICD-10 code is missing.',
    ],
    'CID_FORMAT_INVALID' => [
        'message'       => 'ICD-10 code ":cid" has an invalid format.',
        'suggestion'    => 'The ICD code must follow the pattern: one uppercase letter + 2 digits + optional suffix (e.g., H40.1, Z00.0, A09).',
        'operator_hint' => 'Warning: :operator may reject this claim because of the invalid ICD format.',
    ],
    'DOCTOR_REQUIRED' => [
        'message'       => 'Performing physician not linked to the claim.',
        'suggestion'    => 'Link the physician responsible for the visit. The CRM will be added to the XML automatically.',
        'operator_hint' => 'Warning: :operator requires the performing physician\'s CRM to process the payment.',
    ],
    'EYE_SIDE_RECOMMENDED' => [
        'message'    => 'Procedure ":description" indicates laterality (monocular/binocular), but the eye (OD/OS/OU) was not provided.',
        'suggestion' => 'Enter the eye when billing — it avoids duplicates and makes audits/denials easier.',
    ],
    'ITEMS_REQUIRED' => [
        'message'       => ':type claim has no procedures.',
        'suggestion'    => 'Add at least one procedure with TUSS code, quantity and amount.',
        'operator_hint' => 'Warning: :operator does not pay claims without procedures.',
    ],
    'ITEM_QUANTITY_ZERO' => [
        'message'    => 'TUSS procedure :code: quantity must be greater than zero.',
        'suggestion' => 'Fix the procedure quantity before adding it to a batch.',
    ],
    'ITEM_AMOUNT_ZERO' => [
        'message'    => 'TUSS procedure :code: unit amount is zero or negative.',
        'suggestion' => 'Enter the procedure amount according to the payer\'s price table.',
    ],
    'SADT_EXECUTION_DATE_REQUIRED' => [
        'message'       => 'Execution date is required for SP-SADT claims.',
        'suggestion'    => 'Enter the date the procedures were performed.',
        'operator_hint' => 'Warning: :operator requires the execution date on SP-SADT claims.',
    ],
    'TUSS_CODE_MISSING' => [
        'message'    => 'Procedure without TUSS code.',
        'suggestion' => 'Enter the procedure TUSS code according to ANS Table 22.',
    ],
    'TUSS_CODE_NOT_FOUND' => [
        'message'    => 'TUSS code :code not found in the reference table.',
        'suggestion' => 'Check that the TUSS code is correct or update the code table in the system.',
    ],
    'TUSS_CODE_INACTIVE' => [
        'message'    => 'TUSS code :code is inactive.',
        'suggestion' => 'Replace the TUSS code with a current version or contact support.',
    ],
    'TUSS_CODE_EXPIRED' => [
        'message'    => 'TUSS code :code expired on :until.',
        'suggestion' => 'Use the current replacement TUSS code to avoid a denial for an expired code.',
    ],
];
