<?php

return [
    // Tipos de consentimento do paciente (LGPD art. 7 e 11). `description` é o texto que o titular autoriza.
    'types' => [
        'data_collection' => [
            'label'       => 'Coleta e Armazenamento de Dados',
            'description' => 'Autorizo a coleta, armazenamento e uso dos meus dados pessoais para fins de atendimento clínico.',
        ],
        'sensitive_data' => [
            'label'       => 'Tratamento de Dados de Saúde',
            'description' => 'Autorizo o tratamento dos meus dados de saúde (diagnósticos, exames, prescrições) para fins de atendimento médico.',
        ],
        'data_sharing' => [
            'label'       => 'Compartilhamento com Terceiros',
            'description' => 'Autorizo o compartilhamento dos meus dados com convênios e operadoras para fins de faturamento.',
        ],
        'minor_guardian' => [
            'label'       => 'Representação de Menor de Idade',
            'description' => 'Na qualidade de responsável legal, autorizo o tratamento dos dados do menor sob minha responsabilidade.',
        ],
        'image_and_voice' => [
            'label'       => 'Uso de Imagem e Voz',
            'description' => 'Autorizo o uso de imagens e registros de voz para fins de documentação clínica.',
        ],
        'research' => [
            'label'       => 'Participação em Pesquisa',
            'description' => 'Autorizo a utilização anonimizada dos meus dados para fins de pesquisa científica.',
        ],
        'care_reminders' => [
            'label'       => 'Lembretes de retorno por WhatsApp',
            'description' => 'Autorizo a clínica a me enviar lembretes de retorno e acompanhamento por WhatsApp, sem citar o motivo do acompanhamento. Posso deixar de receber a qualquer momento respondendo SAIR.',
        ],
    ],

    // Registro de revogação (patient_consents.revocation_reason).
    'revoked' => [
        'whatsapp_opt_out' => 'Paciente respondeu SAIR no WhatsApp',
        'provider_opt_out' => 'O WhatsApp informou que o paciente não quer receber mensagens deste número',
        'patient_form'     => 'Desmarcado no cadastro do paciente',
        'replaced'         => 'Substituído por novo consentimento',
    ],
];
