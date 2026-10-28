<?php

declare(strict_types=1);

/*
 * Pré-validação TISS (App\Domains\Tiss\PreValidation\Rules): mensagem,
 * sugestão e aviso da operadora por código de regra, no idioma do usuário.
 */
return [
    'operator_fallback' => 'a operadora',
    'guide_type'        => [
        'sadt'              => 'SP-SADT',
        'consultation'      => 'Consulta',
        'inpatient_request' => 'Solicitação de internação',
        'opme_attachment'   => 'Anexo de OPME',
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

    // ── Mensagens avulsas do módulo cirúrgico (solicitação de internação / anexo de OPME) ──
    'OPERATOR_ANS_INVALID' => [
        'message'       => 'Registro ANS da operadora ausente ou inválido (precisa ter 6 dígitos).',
        'suggestion'    => 'Corrija o registro ANS no cadastro do convênio.',
        'operator_hint' => 'Atenção: sem o registro ANS correto a mensagem nem chega a :operator.',
    ],
    'PROVIDER_CODE_INVALID' => [
        'message'    => 'Código do prestador na operadora ausente ou com mais de 14 caracteres.',
        'suggestion' => 'Revise o código da clínica no cadastro da empresa.',
    ],
    'PROFESSIONAL_COUNCIL_REQUIRED' => [
        'message'       => 'Número do CRM do cirurgião não informado.',
        'suggestion'    => 'Preencha o registro (CRM) no cadastro do médico: conselho, número, UF e CBO vão juntos na guia.',
        'operator_hint' => 'Atenção: :operator recusa solicitação sem o CRM do profissional.',
    ],
    'PROFESSIONAL_UF_REQUIRED' => [
        'message'    => 'UF do conselho do cirurgião não identificada.',
        'suggestion' => 'Informe o estado (UF) no cadastro da clínica: a guia usa a UF da clínica como a do conselho.',
    ],
    'HOSPITAL_CODE_REQUIRED' => [
        'message'       => 'Código do hospital na operadora não informado.',
        'suggestion'    => 'Preencha o código na operadora no cadastro do local (Configurações › Cirurgia), com até 14 caracteres.',
        'operator_hint' => 'Atenção: :operator não localiza o hospital solicitado sem o código.',
    ],
    'HOSPITAL_NAME_REQUIRED' => [
        'message'    => 'Nome do hospital solicitado não informado.',
        'suggestion' => 'Escolha o local da cirurgia no pedido.',
    ],
    'HOSPITAL_CNES_RECOMMENDED' => [
        'message'    => 'CNES do hospital ausente ou fora do padrão de 7 dígitos.',
        'suggestion' => 'Informe o CNES no cadastro do local: algumas operadoras o conferem na análise.',
    ],
    'SUGGESTED_DATE_REQUIRED' => [
        'message'       => 'Data sugerida para a internação não informada.',
        'suggestion'    => 'Informe a data sugerida na guia (ou agende a cirurgia e atualize a guia).',
        'operator_hint' => 'Atenção: :operator exige a data sugerida na solicitação de internação.',
    ],
    'CID_TOO_MANY' => [
        'message'    => 'A solicitação aceita no máximo 4 diagnósticos (CID).',
        'suggestion' => 'Reduza os diagnósticos no pedido da cirurgia.',
    ],
    'CLINICAL_INDICATION_REQUIRED' => [
        'message'       => 'Indicação clínica não informada.',
        'suggestion'    => 'Descreva a indicação clínica no pedido da cirurgia.',
        'operator_hint' => 'Atenção: :operator nega solicitação sem indicação clínica.',
    ],
    'CLINICAL_INDICATION_TRUNCATED' => [
        'message'    => 'A indicação clínica passa de 500 caracteres e será cortada na guia.',
        'suggestion' => 'Resuma a indicação para que nada importante fique de fora.',
    ],
    'DAYS_REQUESTED_INVALID' => [
        'message'    => 'Diárias solicitadas fora do intervalo de 1 a 99.',
        'suggestion' => 'Corrija as diárias no pedido da cirurgia.',
    ],
    'OPME_REFERENCED_GUIDE_REQUIRED' => [
        'message'       => 'Número da guia referenciada não informado.',
        'suggestion'    => 'Gere a solicitação de internação (o anexo a referencia sozinho) ou informe o número da guia à qual o anexo se refere.',
        'operator_hint' => 'Atenção: :operator não liga o anexo de OPME a nenhum pedido sem a guia referenciada.',
    ],
    'OPME_PROFESSIONAL_PHONE_REQUIRED' => [
        'message'    => 'Telefone do profissional solicitante não informado (obrigatório no anexo de OPME).',
        'suggestion' => 'Preencha o telefone na guia ou no cadastro do médico (até 11 dígitos, com DDD).',
    ],
    'OPME_JUSTIFICATION_REQUIRED' => [
        'message'       => 'Justificativa técnica da OPME não informada.',
        'suggestion'    => 'Preencha a justificativa técnica nos materiais do pedido ou direto na guia.',
        'operator_hint' => 'Atenção: :operator nega OPME sem justificativa técnica.',
    ],
    'OPME_JUSTIFICATION_TRUNCATED' => [
        'message'    => 'A justificativa técnica passa de 1000 caracteres e será cortada na guia.',
        'suggestion' => 'Resuma a justificativa.',
    ],
    'OPME_MANUFACTURER_OPTION_INVALID' => [
        'message'    => 'OPME ":item": opção de fabricante inválida (1, 2 ou 3).',
        'suggestion' => 'Escolha a opção de fabricante do material (1 = primeira opção).',
    ],
    'OPME_ANVISA_REQUIRED' => [
        'message'       => 'OPME ":item": registro na ANVISA não informado.',
        'suggestion'    => 'Informe o registro ANVISA do material (consta na embalagem e na nota do fabricante).',
        'operator_hint' => 'Atenção: :operator glosa OPME sem registro na ANVISA.',
    ],
    'OPME_QUANTITY_INVALID' => [
        'message'    => 'OPME ":item": quantidade fora do intervalo de 1 a 999.',
        'suggestion' => 'Corrija a quantidade do material.',
    ],
];
