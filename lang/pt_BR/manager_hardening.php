<?php

declare(strict_types=1);

return [
    // FormRequest reason
    'reason_required' => 'A justificativa é obrigatória para esta ação.',
    'reason_min'      => 'A justificativa precisa ter pelo menos :min caracteres.',
    'reason_max'      => 'A justificativa não pode ultrapassar :max caracteres.',

    // Modal de confirmação destrutiva (Vue)
    'modal_title'        => 'Confirmar ação',
    'modal_warning'      => 'Esta ação é registrada no log de auditoria e não pode ser desfeita silenciosamente.',
    'modal_reason_label' => 'Justificativa (LGPD/CFM)',
    'modal_reason_hint'  => 'Descreva por que esta ação está sendo executada. Mínimo 20 caracteres. Será registrado no audit trail.',
    'modal_reason_placeholder' => 'Ex.: Solicitação do cliente via ticket #1234 — cancelamento por inadimplência após 3 tentativas de retry.',
    'modal_cancel'       => 'Cancelar',
    'modal_confirm'      => 'Confirmar',
    'modal_counter'      => ':current / :min mínimo',

    // 2FA (preparação)
    'two_factor_required' => 'Autenticação em dois fatores é obrigatória para administradores SaaS.',
    'two_factor_required_by_entity' => 'A empresa ":entity" exige autenticação em dois fatores para todos os usuários.',
    'two_factor_invalid'  => 'Código de verificação inválido ou expirado.',

    // Settings de 2FA por empresa (admin clínica/SaaS configura)
    'entity_2fa_section'        => 'Autenticação em dois fatores',
    'entity_2fa_label'          => 'Exigir 2FA para todos os usuários desta empresa',
    'entity_2fa_hint'           => 'Quando ativado, todos os usuários (incluindo você) precisarão configurar e usar um aplicativo autenticador (Google Authenticator, Authy, 1Password etc.) para acessar o sistema.',
    'entity_2fa_warning'        => 'Se você ativar e ainda não configurou 2FA, será redirecionado para configurar antes de continuar usando o sistema.',
    'entity_2fa_enabled_at'     => 'Ativado em :date por :user',
    'entity_2fa_btn_enable'     => 'Ativar 2FA obrigatório',
    'entity_2fa_btn_disable'    => 'Desativar 2FA obrigatório',
    'entity_2fa_enabled'        => 'Autenticação em dois fatores ativada para todos os usuários da empresa.',
    'entity_2fa_disabled'       => 'Exigência de 2FA desativada. Usuários podem usar 2FA opcionalmente.',
    'entity_2fa_reason_enable'  => 'Justifique por que está ATIVANDO 2FA obrigatório (auditoria LGPD).',
    'entity_2fa_reason_disable' => 'Justifique por que está DESATIVANDO 2FA obrigatório (auditoria LGPD).',
];
