<?php

/*
 * Convite de retorno por WhatsApp (Fase 6, ADR 0002 item 9): rótulos de origem e de resposta, e os motivos pelos
 * quais um convite não sai (InviteBlocker). O texto da mensagem em si está em `whatsapp.php` (templates.care_recall).
 */

return [
    'origin' => [
        'auto'   => 'Automático',
        'manual' => 'Enviado pela equipe',
    ],

    'response' => [
        'wants_to_schedule' => 'Pediu para agendar',
        'already_scheduled' => 'Disse que já agendou',
    ],

    'blockers' => [
        'paused_global'       => 'Os convites estão pausados pelo EasyEye.',
        'feature_off'         => 'O plano da clínica não inclui o retorno de pacientes: nenhum convite sai.',
        'clinic_not_enabled'  => 'O EasyEye ainda não liberou o convite por WhatsApp para esta clínica.',
        'no_sender'           => 'O WhatsApp da clínica não está ativo (número do EasyEye ou próprio).',
        'access_blocked'      => 'O acesso da clínica está bloqueado (assinatura): nenhuma mensagem automática sai.',
        'clinic_switch_off'   => 'O convite por WhatsApp está desligado nas configurações dos retornos.',
        'patient_unavailable' => 'O paciente está inativo ou foi removido.',
        'contact_blocked'     => 'O paciente está marcado como "não contatar".',
        'no_consent'          => 'O paciente não autorizou lembretes de retorno por WhatsApp.',
        'no_whatsapp'         => 'O celular do paciente não está marcado como WhatsApp.',
        'invalid_phone'       => 'O celular do paciente não é válido para WhatsApp.',
        'opted_out'           => 'O paciente respondeu SAIR e não quer receber mensagens por este número.',
        'nothing_to_invite'   => 'Não há retorno a convidar: foi agendado, adiado, dispensado, ainda não está na janela de convite ou a regra não está em uso.',
        'outside_window'      => 'Fora da janela de horário dos convites.',
        'too_soon'            => 'O paciente recebeu um convite há pouco tempo.',
        'daily_cap'           => 'O limite diário de convites da clínica foi atingido.',
    ],

    'refused' => 'O convite não pôde sair. :reasons',

    'errors' => [
        'not_found'     => 'Este retorno não existe mais ou não é desta clínica.',
        'blocked'       => 'O convite não pôde sair.',
        'already_today' => 'Este paciente já recebeu um convite hoje.',
    ],
];
