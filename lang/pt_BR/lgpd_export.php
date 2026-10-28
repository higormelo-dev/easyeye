<?php

// Declaração de escopo da exportação de dados do titular (LGPD art. 19, II).
return [
    'scope' => [
        'clinic_only'  => 'Dados tratados por esta clínica. Cada clínica é um controlador independente: dados de outras clínicas devem ser pedidos a cada uma.',
        'not_included' => [
            'binary_files'              => 'Arquivos (imagens de exame, anexos, foto) aparecem só como metadado; o conteúdo pode ser baixado no Portal, quando liberado, ou solicitado à clínica.',
            'internal_instructions'     => 'Instruções internas enviadas à inteligência artificial (prompt de sistema e regras de segurança) não são dado pessoal e são protegidas por segredo comercial (LGPD art. 19).',
            'technical_records'         => 'Registros técnicos que só repetem dados já presentes no arquivo (XML/payload das guias TISS, logs de auditoria de segurança).',
            'professional_compensation' => 'Repasses e remuneração dos profissionais são dados da relação entre a clínica e o profissional, não do paciente.',
            'deleted_records'           => 'Registros excluídos ficam retidos apenas para cumprimento de obrigação legal e podem ser solicitados à clínica.',
            'other_clinics'             => 'Vínculos da sua conta do Portal com outras clínicas não aparecem neste arquivo.',
        ],
    ],
];
