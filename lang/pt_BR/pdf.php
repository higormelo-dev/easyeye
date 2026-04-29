<?php

/**
 * Strings de PDFs clínicos — paridade total i18n.
 * Usado em resources/views/pdf/*.blade.php.
 */
return [
    // ── Títulos ──────────────────────────────────────────────────────────
    'title' => [
        'tonometry'      => 'Laudo de Tonômetria',
        'medical_record' => 'Prontuário',
        'documentation'  => 'Documentação',
    ],

    // ── Identificação do paciente ────────────────────────────────────────
    'patient'       => 'Paciente',
    'birth'         => 'Nascimento',
    'birth_date'    => 'Data de Nascimento',
    'name'          => 'Nome',
    'code'          => 'Código',
    'doctor'        => 'Médico responsável',
    'covenant'      => 'Convênio',
    'date'          => 'Data',
    'address'       => 'Endereço',

    // ── Exame ocular ─────────────────────────────────────────────────────
    'right_eye'     => 'Olho Direito',
    'left_eye'      => 'Olho Esquerdo',
    'eye'           => 'Olho',
    'spherical'     => 'Esférico',
    'cylindrical'   => 'Cilíndrico',
    'axis'          => 'Eixo',
    'addition'      => 'Adição',

    // ── Anamnese ─────────────────────────────────────────────────────────
    'section_anamnesis'         => 'Anamnese',
    'main_complaint'            => 'Queixa principal',
    'ocular_surgical_history'   => 'Histórico cirúrgico ocular',
    'medications_in_use'        => 'Medicamentos em uso',
    'ocular_motility'           => 'Motilidade ocular',
    'time_label'                => 'Horário',

    // ── Refração / Achados ───────────────────────────────────────────────
    'section_refraction'        => 'Refração',
    'observations_general'      => 'Observações gerais',
    'observations_lenses'       => 'Observações sobre lentes',

    // ── Diagnóstico / Conduta ────────────────────────────────────────────
    'description'               => 'Descrição',
    'clinical_conduct'          => 'Conduta clínica',
    'follow_up'                 => 'Retorno em',

    // ── Rodapé / Assinatura ──────────────────────────────────────────────
    'issued_at'                 => 'Emitido em',
    'generated_at'              => 'Gerado em',
    'signed_at'                 => 'Assinado eletronicamente em',
    'signature_hash'            => 'Hash',

    // ── Seções (medical_record) ──────────────────────────────────────────
    'section_patient_data'      => 'Dados do Paciente',
    'section_physical_exam'     => 'Exame Físico',
    'section_clinical_findings' => 'Achados Clínicos',
    'section_diagnosis_conduct' => 'Diagnóstico & Conduta',

    // ── Anamnese — flags ─────────────────────────────────────────────────
    'crm'                       => 'CRM',
    'hda'                       => 'HDA',
    'diabetic'                  => 'Diabético',
    'hypertensive'              => 'Hipertenso',
    'glaucomatous'              => 'Glaucomatoso',
    'yes'                       => 'Sim',
    'no'                        => 'Não',
    'family_history_short'      => '(HF)',
    'private_payment'           => 'Particular',

    // ── Exame físico (labels) ────────────────────────────────────────────
    'av_without_od'             => 'AV sem correção OD',
    'av_without_oe'             => 'AV sem correção OE',
    'visual_acuity'             => 'Acuidade Visual',
    'near_point_convergence'    => 'PPC',
    'cover_test'                => 'Cover Test',
    'color_vision'              => 'Visão Cromática',
    'tonometry_od'              => 'Tonometria OD',
    'tonometry_oe'              => 'Tonometria OE',
    'pachymetry_od'             => 'Paquimetria OD',
    'pachymetry_oe'             => 'Paquimetria OE',
    'gonioscopy_od'             => 'Gonioscopia OD',
    'gonioscopy_oe'             => 'Gonioscopia OE',

    // ── Refração ─────────────────────────────────────────────────────────
    'av_sc_short'               => 'AV s/c',
    'av_cc_short'               => 'AV c/c',
    'dynamic'                   => 'Dinâmica',
    'static'                    => 'Estática',
    'lens_away'                 => 'Lente Longe',
    'lens_near'                 => 'Lente Perto',

    // ── Achados clínicos ─────────────────────────────────────────────────
    'biomicroscopy_od'          => 'Biomicroscopia OD',
    'biomicroscopy_oe'          => 'Biomicroscopia OE',
    'fundoscopy_od'             => 'Fundoscopia OD',
    'fundoscopy_oe'             => 'Fundoscopia OE',

    // ── Diagnóstico ──────────────────────────────────────────────────────
    'cid10'                     => 'CID-10',
    'days'                      => 'dias',
];
