<?php

declare(strict_types=1);

// Planos de saúde (produtos registrados na ANS) — compartilhado entre o
// manager, as configurações da clínica e o cadastro do paciente.
return [
    'plan'               => 'Plano',
    'plans'              => 'Planos',
    'name_with_code'     => ':name (Reg. ANS :code)',
    'name_with_old_code' => ':name (cód. ANS :code)',

    // Situação do produto na ANS
    'status_active'      => 'Ativo',
    'status_suspended'   => 'Comercialização suspensa',
    'status_cancelled'   => 'Cancelado na ANS',
    'status_transferred' => 'Transferido para outra operadora',
    'status_inactive'    => 'Inativo',

    // Vigência do plano (VIGENCIA_PLANO da ANS)
    'regulation_A' => 'Anterior à Lei 9.656/98 (não regulamentado)',
    'regulation_P' => 'Regulamentado (Lei 9.656/98)',

    // Campos
    'field_name'              => 'Nome do plano',
    'field_ans_code'          => 'Registro do produto na ANS',
    'field_ans_code_hint'     => 'Número impresso na carteirinha (opcional).',
    'field_covenant'          => 'Convênio',
    'field_contracting'       => 'Contratação',
    'field_segmentation'      => 'Segmentação',
    'field_coverage_area'     => 'Abrangência',
    'field_accommodation'     => 'Acomodação',
    'field_moderating_factor' => 'Coparticipação / franquia',
    'field_regulation'        => 'Vigência',
    'field_status'            => 'Situação na ANS',
    'field_status_at'         => 'Situação desde',
    'field_registered_at'     => 'Registrado na ANS em',
    'field_active'            => 'Disponível para escolha',

    'saved'           => 'Plano salvo.',
    'deleted'         => 'Plano excluído.',
    'name_taken'      => 'Já existe um plano com este nome neste convênio.',
    'ans_readonly'    => 'Planos da ANS são atualizados pela sincronização e não podem ser alterados.',
    'in_use'          => 'Este plano está no cadastro de pacientes. Desative em vez de excluir.',
    'covenant_locked' => 'O convênio do plano não pode ser alterado. Cadastre um novo plano no outro convênio.',
    'settings_title'  => 'Planos dos convênios',
    'settings_tab'    => 'Planos',
    'invalid'         => 'Plano não encontrado.',
    'wrong_covenant'  => 'Este plano não é do convênio escolhido.',
    'unavailable'     => 'Este plano não está mais disponível (cancelado na ANS ou desativado). Escolha outro.',
    'particular'      => 'Particular não tem planos.',
];
