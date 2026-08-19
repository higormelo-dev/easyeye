<?php

return [
    // mock  = não chama a Z-API (dev/teste: loga e devolve id fake)
    // zapi  = integração real com https://developer.z-api.io
    'driver' => env('WHATSAPP_DRIVER', 'mock'),

    'zapi' => [
        'base_url' => env('ZAPI_BASE_URL', 'https://api.z-api.io'),
    ],

    'http' => [
        'timeout_seconds'         => (int) env('WHATSAPP_HTTP_TIMEOUT', 15),
        'connect_timeout_seconds' => (int) env('WHATSAPP_HTTP_CONNECT_TIMEOUT', 5),
    ],

    'queue' => env('WHATSAPP_QUEUE', 'default'),

    // Confirmação: janela padrão de disparo (h antes da consulta) quando a
    // clínica não configurou outro valor em whatsapp_settings.
    'confirmation' => [
        'default_hours_before' => (int) env('WHATSAPP_CONFIRM_HOURS_BEFORE', 24),
        // Resposta do paciente só vale por N dias após o envio.
        'reply_valid_days' => 7,
    ],

    // Pesquisa de satisfação: atraso padrão após o atendimento.
    'survey' => [
        'default_delay_hours' => (int) env('WHATSAPP_SURVEY_DELAY_HOURS', 2),
        'reply_valid_days'    => 14,
        // Não enviar pesquisa de atendimentos mais antigos que isso (evita
        // spam retroativo ao ligar a feature numa clínica com histórico).
        'max_age_days' => 3,
    ],
];
