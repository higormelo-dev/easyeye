<?php

/**
 * Clinical PDF strings — full i18n parity.
 * Used by resources/views/pdf/*.blade.php.
 */
return [
    // ── Titles ───────────────────────────────────────────────────────────
    'title' => [
        'tonometry'      => 'Tonometry Report',
        'medical_record' => 'Medical Record',
        'documentation'  => 'Documentation',
    ],

    // ── Patient identification ───────────────────────────────────────────
    'patient'    => 'Patient',
    'birth'      => 'Birth',
    'birth_date' => 'Date of Birth',
    'gender'     => 'Gender',
    'age'        => 'Age',
    'age_years'  => ':years years',
    'name'       => 'Name',
    'code'       => 'Code',
    'doctor'     => 'Attending physician',
    'covenant'   => 'Insurance',
    'date'       => 'Date',
    'address'    => 'Address',

    // ── Eye exam ─────────────────────────────────────────────────────────
    'right_eye'   => 'Right Eye',
    'left_eye'    => 'Left Eye',
    'eye'         => 'Eye',
    'spherical'   => 'Spherical',
    'cylindrical' => 'Cylindrical',
    'axis'        => 'Axis',
    'addition'    => 'Addition',

    // ── Anamnesis ────────────────────────────────────────────────────────
    'section_anamnesis'       => 'Anamnesis',
    'main_complaint'          => 'Main complaint',
    'ocular_surgical_history' => 'Ocular surgical history',
    'medications_in_use'      => 'Medications in use',
    'ocular_motility'         => 'Ocular motility',
    'time_label'              => 'Time',

    // ── Refraction / Findings ────────────────────────────────────────────
    'section_refraction'   => 'Refraction',
    'observations_general' => 'General observations',
    'observations_lenses'  => 'Observations on lenses',

    // ── Diagnosis / Conduct ──────────────────────────────────────────────
    'description'      => 'Description',
    'clinical_conduct' => 'Clinical conduct',
    'follow_up'        => 'Follow-up in',

    // ── Footer / Signature ───────────────────────────────────────────────
    'issued_at'      => 'Issued on',
    'generated_at'   => 'Generated on',
    'signed_at'      => 'Electronically signed on',
    'signature_hash' => 'Hash',

    // ── Sections (medical_record) ────────────────────────────────────────
    'section_patient_data'      => 'Patient Data',
    'section_physical_exam'     => 'Physical Examination',
    'section_clinical_findings' => 'Clinical Findings',
    'section_diagnosis_conduct' => 'Diagnosis & Conduct',

    // ── Anamnesis flags ──────────────────────────────────────────────────
    'crm'                  => 'License #',
    'hda'                  => 'HPI',
    'diabetic'             => 'Diabetic',
    'hypertensive'         => 'Hypertensive',
    'glaucomatous'         => 'Glaucomatous',
    'yes'                  => 'Yes',
    'no'                   => 'No',
    'family_history_short' => '(FH)',
    'private_payment'      => 'Private',

    // ── Physical exam labels ─────────────────────────────────────────────
    'av_without_od'          => 'VA w/o correction OD',
    'av_without_oe'          => 'VA w/o correction OE',
    'visual_acuity'          => 'Visual Acuity',
    'near_point_convergence' => 'NPC',
    'cover_test'             => 'Cover Test',
    'color_vision'           => 'Color Vision',
    'tonometry_od'           => 'Tonometry OD',
    'tonometry_oe'           => 'Tonometry OE',
    'pachymetry_od'          => 'Pachymetry OD',
    'pachymetry_oe'          => 'Pachymetry OE',
    'gonioscopy_od'          => 'Gonioscopy OD',
    'gonioscopy_oe'          => 'Gonioscopy OE',

    // ── Refraction ───────────────────────────────────────────────────────
    'av_sc_short' => 'VA w/o',
    'av_cc_short' => 'VA w/',
    'dynamic'     => 'Dynamic',
    'static'      => 'Static',
    'lens_away'   => 'Distance lens',
    'lens_near'   => 'Near lens',

    // ── Clinical findings ────────────────────────────────────────────────
    'biomicroscopy_od' => 'Biomicroscopy OD',
    'biomicroscopy_oe' => 'Biomicroscopy OE',
    'fundoscopy_od'    => 'Fundoscopy OD',
    'fundoscopy_oe'    => 'Fundoscopy OE',

    // ── Diagnosis ────────────────────────────────────────────────────────
    'cid10' => 'ICD-10',
    'days'  => 'days',
];
