<?php

declare(strict_types=1);

/**
 * Rótulos dos enums do módulo cirúrgico (App\Enums\Surgery\*). Chave =
 * nome do enum em snake_case → valor do case.
 */
return [
    'surgical_case_status' => [
        'draft'                  => 'Rascunho',
        'awaiting_authorization' => 'Aguardando autorização',
        'authorized'             => 'Autorizada',
        'partially_authorized'   => 'Parcialmente autorizada',
        'denied'                 => 'Negada',
        'scheduled'              => 'Agendada',
        'performed'              => 'Realizada',
        'discharged'             => 'Alta dada',
        'cancelled'              => 'Cancelada',
        'suspended'              => 'Suspensa',
    ],
    'surgery_eye' => [
        'od' => 'Olho direito (OD)',
        'oe' => 'Olho esquerdo (OE)',
        'ao' => 'Ambos os olhos (AO)',
    ],
    'surgery_eye_short' => [
        'od' => 'OD',
        'oe' => 'OE',
        'ao' => 'AO',
    ],
    'anesthesia_type' => [
        'topical'            => 'Tópica',
        'topical_sedation'   => 'Tópica com sedação',
        'peribulbar'         => 'Peribulbar',
        'retrobulbar'        => 'Retrobulbar',
        'sub_tenon'          => 'Sub-tenoniana',
        'local_infiltration' => 'Infiltração local',
        'general'            => 'Geral',
    ],
    'surgery_regime' => [
        'ambulatory'   => 'Ambulatorial',
        'day_hospital' => 'Hospital-dia',
        'inpatient'    => 'Internação',
    ],
    'surgery_urgency' => [
        'elective' => 'Eletiva',
        'urgent'   => 'Urgência',
    ],
    'surgery_facility_type' => [
        'own_clinic'      => 'Clínica própria',
        'hospital'        => 'Hospital',
        'surgical_center' => 'Centro cirúrgico',
    ],
    'authorization_status' => [
        'not_requested'        => 'Não solicitada',
        'pending'              => 'Aguardando resposta',
        'authorized'           => 'Autorizada',
        'partially_authorized' => 'Parcialmente autorizada',
        'denied'               => 'Negada',
        'expired'              => 'Vencida',
        'waived'               => 'Dispensada',
    ],
    'authorization_event_type' => [
        'requested'            => 'Pedido enviado',
        'submitted'            => 'Enviada à operadora',
        'authorized'           => 'Autorizada',
        'partially_authorized' => 'Parcialmente autorizada',
        'denied'               => 'Negada',
        'appealed'             => 'Recurso enviado',
        'expired'              => 'Autorização vencida',
        'waived'               => 'Autorização dispensada',
        'cancelled'            => 'Pedido retirado',
        'note'                 => 'Anotação',
    ],
    'surgery_team_role' => [
        'surgeon'           => 'Cirurgião',
        'assistant'         => 'Auxiliar',
        'anesthesiologist'  => 'Anestesista',
        'scrub_nurse'       => 'Instrumentador(a)',
        'circulating_nurse' => 'Circulante',
        'technician'        => 'Técnico(a)',
    ],
    'surgery_document_type' => [
        'consent'          => 'Termo de consentimento (TCLE)',
        'pre_op_guidance'  => 'Orientações pré-operatórias',
        'implant_record'   => 'Registro do implante',
        'post_op_guidance' => 'Orientações pós-operatórias',
        'discharge'        => 'Alta cirúrgica',
    ],
];
