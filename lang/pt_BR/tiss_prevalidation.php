<?php

declare(strict_types=1);

/*
 * Pré-validação TISS (App\Domains\Tiss\PreValidation\Rules): mensagem,
 * sugestão e aviso da operadora por código de regra, no idioma do usuário.
 */
return [
    'operator_fallback' => 'a operadora',
    'guide_type'        => [
        'sadt'         => 'SP-SADT',
        'consultation' => 'Consulta',
    ],
    'AUTHORIZATION_REQUIRED' => [
        'message'       => 'Número de autorização prévia não informado.',
        'suggestion'    => 'Este convênio exige autorização prévia. Informe o número fornecido pela operadora.',
        'operator_hint' => 'Atenção: :operator vai glosar esta guia pois o procedimento exige autorização prévia.',
    ],
    'BENEFICIARY_CARD_REQUIRED' => [
        'message'       => 'Número da carteirinha do beneficiário não informado.',
        'suggestion'    => 'Informe o número da carteirinha exatamente como consta no cartão do plano de saúde.',
        'operator_hint' => 'Atenção: :operator vai glosar esta guia pois o número da carteirinha é obrigatório.',
    ],
    'BENEFICIARY_NAME_MISSING' => [
        'message'    => 'Nome do beneficiário não informado.',
        'suggestion' => 'Informe o nome completo conforme consta no cartão do plano de saúde.',
    ],
    'CID_REQUIRED' => [
        'message'       => 'Código CID-10 não informado.',
        'suggestion'    => 'Informe o CID-10 correspondente ao diagnóstico (ex.: H40.1 para glaucoma, H25.9 para catarata).',
        'operator_hint' => 'Atenção: :operator vai glosar esta guia pois falta o CID-10.',
    ],
    'CID_FORMAT_INVALID' => [
        'message'       => 'Código CID-10 ":cid" está em formato inválido.',
        'suggestion'    => 'O código CID deve seguir o padrão: uma letra maiúscula + 2 dígitos + sufixo opcional (ex.: H40.1, Z00.0, A09).',
        'operator_hint' => 'Atenção: :operator pode rejeitar esta guia por formato de CID inválido.',
    ],
    'DOCTOR_REQUIRED' => [
        'message'       => 'Médico executante não vinculado à guia.',
        'suggestion'    => 'Associe o médico responsável pelo atendimento. O CRM será incluído automaticamente no XML.',
        'operator_hint' => 'Atenção: :operator exige o CRM do médico executante para processar o pagamento.',
    ],
    'EYE_SIDE_RECOMMENDED' => [
        'message'    => 'Procedimento ":description" indica lateralidade (monocular/binocular), mas o olho (OD/OE/AO) não foi informado.',
        'suggestion' => 'Informe o olho no faturamento — evita duplicidade e facilita auditoria/glosa.',
    ],
    'ITEMS_REQUIRED' => [
        'message'       => 'Guia de :type sem procedimentos cadastrados.',
        'suggestion'    => 'Adicione pelo menos um procedimento com código TUSS, quantidade e valor.',
        'operator_hint' => 'Atenção: :operator não paga guias sem procedimentos informados.',
    ],
    'ITEM_QUANTITY_ZERO' => [
        'message'    => 'Procedimento TUSS :code: quantidade deve ser maior que zero.',
        'suggestion' => 'Corrija a quantidade do procedimento antes de incluir no lote.',
    ],
    'ITEM_AMOUNT_ZERO' => [
        'message'    => 'Procedimento TUSS :code: valor unitário é zero ou negativo.',
        'suggestion' => 'Informe o valor do procedimento conforme a tabela do convênio.',
    ],
    'SADT_EXECUTION_DATE_REQUIRED' => [
        'message'       => 'Data de execução é obrigatória para guias SP-SADT.',
        'suggestion'    => 'Informe a data em que os procedimentos foram realizados.',
        'operator_hint' => 'Atenção: :operator exige a data de execução em guias SP-SADT.',
    ],
    'TUSS_CODE_MISSING' => [
        'message'    => 'Procedimento sem código TUSS.',
        'suggestion' => 'Informe o código TUSS do procedimento conforme a Tabela 22 da ANS.',
    ],
    'TUSS_CODE_NOT_FOUND' => [
        'message'    => 'Código TUSS :code não encontrado na tabela de referência.',
        'suggestion' => 'Verifique se o código TUSS está correto ou atualize a tabela de códigos no sistema.',
    ],
    'TUSS_CODE_INACTIVE' => [
        'message'    => 'Código TUSS :code está inativo.',
        'suggestion' => 'Substitua o código TUSS por uma versão vigente ou contate o suporte.',
    ],
    'TUSS_CODE_EXPIRED' => [
        'message'    => 'Código TUSS :code expirou em :until.',
        'suggestion' => 'Utilize o código TUSS substituto vigente para evitar glosa por código expirado.',
    ],
];
