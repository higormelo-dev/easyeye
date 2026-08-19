<?php

return [
    'title'            => 'WhatsApp (Z-API)',
    'subtitle'         => 'Conecte a instância Z-API da sua clínica para confirmar consultas e enviar pesquisas de satisfação pelo seu próprio número de WhatsApp.',
    'manager_subtitle' => 'Configure a instância Z-API (número de WhatsApp próprio) de cada clínica — confirmação de consulta e pesquisa de satisfação. As credenciais pertencem à conta Z-API da empresa e nunca são visíveis para a clínica.',
    'saved'            => 'Configurações do WhatsApp salvas.',

    'manager' => [
        'clinics'             => 'clínicas',
        'configured'          => 'configuradas',
        'active'              => 'ativas',
        'clinic'              => 'Clínica',
        'status'              => 'Status',
        'status_active'       => 'Ativo',
        'status_inactive'     => 'Configurado (inativo)',
        'status_unconfigured' => 'Não configurado',
        'configure'           => 'Configurar',
    ],

    'no_credentials' => 'Configure as credenciais da Z-API primeiro.',

    'credentials' => [
        'title'          => 'Credenciais da instância Z-API',
        'hint'           => 'Crie sua instância em z-api.io, conecte seu número de WhatsApp pelo QR Code no painel deles e cole aqui os 3 dados da instância. Os tokens são armazenados criptografados e nunca são exibidos de volta.',
        'instance_id'    => 'ID da instância',
        'instance_token' => 'Token da instância',
        'client_token'   => 'Client-Token (token de segurança da conta)',
        'configured'     => 'Credenciais configuradas',
        'not_configured' => 'Credenciais pendentes',
        'replace_hint'   => 'Preencha os 3 campos para substituir as credenciais atuais. Deixe em branco para manter.',
    ],

    'connection' => [
        'test'         => 'Testar conexão',
        'testing'      => 'Testando...',
        'connected'    => 'Instância conectada ao WhatsApp! ✅',
        'disconnected' => 'Instância encontrada, mas o WhatsApp NÃO está conectado — escaneie o QR Code no painel da Z-API.',
    ],

    'webhook' => [
        'title'       => 'Webhook de respostas',
        'hint'        => 'Configurado automaticamente na sua instância ao salvar as credenciais — é por ele que as respostas dos pacientes (confirmação e nota da pesquisa) chegam ao EasyEye.',
        'warn_failed' => 'Não foi possível configurar o webhook automaticamente (instância desconectada?). Cole a URL abaixo no campo "Ao receber" do painel Z-API.',
    ],

    'toggles' => [
        'active'                    => 'Integração ativa',
        'confirmation_enabled'      => 'Confirmação de consulta',
        'confirmation_hours_before' => 'Enviar quantas horas antes da consulta',
        'confirmation_hint'         => 'O paciente responde 1 para confirmar ou 2 para cancelar — a agenda é atualizada automaticamente.',
        'survey_enabled'            => 'Pesquisa de satisfação',
        'survey_delay_hours'        => 'Enviar quantas horas após o atendimento',
        'survey_hint'               => 'O paciente responde com uma nota de 1 a 5, registrada na trilha da consulta.',
    ],

    'stats' => [
        'title'                  => 'Últimos 30 dias',
        'confirmations_sent'     => 'Confirmações enviadas',
        'confirmations_answered' => 'Confirmações respondidas',
        'surveys_sent'           => 'Pesquisas enviadas',
        'surveys_answered'       => 'Pesquisas respondidas',
        'survey_average'         => 'Nota média',
        'failed'                 => 'Falhas de envio',
    ],

    'save' => 'Salvar configurações',
];
