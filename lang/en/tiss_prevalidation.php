<?php

declare(strict_types=1);

/*
 * TISS pre-validation (App\Domains\Tiss\PreValidation\Rules): message,
 * suggestion and payer hint per rule code, in the user's language.
 */
return [
    'operator_fallback' => 'the payer',
    'guide_type'        => [
        'sadt'              => 'SP-SADT',
        'consultation'      => 'consultation',
        'inpatient_request' => 'admission request',
        'opme_attachment'   => 'OPME attachment',
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

    // ── Standalone surgical messages (inpatient request / OPME attachment) ──
    'OPERATOR_ANS_INVALID' => [
        'message'       => 'Payer ANS registry missing or invalid (it must have 6 digits).',
        'suggestion'    => 'Fix the ANS registry in the insurance record.',
        'operator_hint' => 'Warning: without the correct ANS registry the message does not even reach :operator.',
    ],
    'PROVIDER_CODE_INVALID' => [
        'message'    => 'Provider code at the payer missing or longer than 14 characters.',
        'suggestion' => 'Review the clinic code in the company record.',
    ],
    'PROFESSIONAL_COUNCIL_REQUIRED' => [
        'message'       => 'Surgeon\'s medical council (CRM) number not provided.',
        'suggestion'    => 'Fill in the registry (CRM) in the doctor record: council, number, state and CBO go together in the form.',
        'operator_hint' => 'Warning: :operator rejects a request without the professional\'s CRM.',
    ],
    'PROFESSIONAL_UF_REQUIRED' => [
        'message'    => 'Surgeon\'s council state could not be identified.',
        'suggestion' => 'Fill in the state in the clinic record: the form uses the clinic\'s state as the council\'s.',
    ],
    'HOSPITAL_CODE_REQUIRED' => [
        'message'       => 'Hospital code at the payer not provided.',
        'suggestion'    => 'Fill in the payer code in the location record (Settings › Surgery), up to 14 characters.',
        'operator_hint' => 'Warning: :operator cannot locate the requested hospital without the code.',
    ],
    'HOSPITAL_NAME_REQUIRED' => [
        'message'    => 'Requested hospital name not provided.',
        'suggestion' => 'Choose the surgery location in the request.',
    ],
    'HOSPITAL_CNES_RECOMMENDED' => [
        'message'    => 'Hospital CNES missing or not 7 digits.',
        'suggestion' => 'Fill in the CNES in the location record: some payers check it during review.',
    ],
    'SUGGESTED_DATE_REQUIRED' => [
        'message'       => 'Suggested admission date not provided.',
        'suggestion'    => 'Enter the suggested date on the form (or schedule the surgery and refresh the form).',
        'operator_hint' => 'Warning: :operator requires the suggested date on the admission request.',
    ],
    'CID_TOO_MANY' => [
        'message'    => 'The request accepts at most 4 diagnoses (ICD).',
        'suggestion' => 'Reduce the diagnoses in the surgery request.',
    ],
    'CLINICAL_INDICATION_REQUIRED' => [
        'message'       => 'Clinical indication not provided.',
        'suggestion'    => 'Describe the clinical indication in the surgery request.',
        'operator_hint' => 'Warning: :operator denies a request without a clinical indication.',
    ],
    'CLINICAL_INDICATION_TRUNCATED' => [
        'message'    => 'The clinical indication is over 500 characters and will be cut on the form.',
        'suggestion' => 'Summarize the indication so nothing important is lost.',
    ],
    'DAYS_REQUESTED_INVALID' => [
        'message'    => 'Requested days outside the 1 to 99 range.',
        'suggestion' => 'Fix the days in the surgery request.',
    ],
    'OPME_REFERENCED_GUIDE_REQUIRED' => [
        'message'       => 'Referenced guide number not provided.',
        'suggestion'    => 'Generate the admission request (the attachment references it on its own) or enter the number of the guide the attachment refers to.',
        'operator_hint' => 'Warning: :operator cannot tie the OPME attachment to any request without the referenced guide.',
    ],
    'OPME_PROFESSIONAL_PHONE_REQUIRED' => [
        'message'    => 'Requesting professional\'s phone not provided (required on the OPME attachment).',
        'suggestion' => 'Fill in the phone on the form or in the doctor record (up to 11 digits, with area code).',
    ],
    'OPME_JUSTIFICATION_REQUIRED' => [
        'message'       => 'OPME technical justification not provided.',
        'suggestion'    => 'Fill in the technical justification in the request materials or directly on the form.',
        'operator_hint' => 'Warning: :operator denies OPME without a technical justification.',
    ],
    'OPME_JUSTIFICATION_TRUNCATED' => [
        'message'    => 'The technical justification is over 1000 characters and will be cut on the form.',
        'suggestion' => 'Summarize the justification.',
    ],
    'OPME_MANUFACTURER_OPTION_INVALID' => [
        'message'    => 'OPME ":item": invalid manufacturer option (1, 2 or 3).',
        'suggestion' => 'Choose the material\'s manufacturer option (1 = first option).',
    ],
    'OPME_ANVISA_REQUIRED' => [
        'message'       => 'OPME ":item": ANVISA registration not provided.',
        'suggestion'    => 'Enter the material\'s ANVISA registration (on the packaging and the manufacturer\'s invoice).',
        'operator_hint' => 'Warning: :operator denies OPME without an ANVISA registration.',
    ],
    'OPME_QUANTITY_INVALID' => [
        'message'    => 'OPME ":item": quantity outside the 1 to 999 range.',
        'suggestion' => 'Fix the material quantity.',
    ],
];
