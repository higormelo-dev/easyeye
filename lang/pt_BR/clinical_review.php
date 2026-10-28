<?php

/*
 * Revisão clínica (Fase 6, ADR 0002): rótulos de estado/decisão/aceite, o e-mail do convite e os
 * textos da página pública do Revisor (`public`). O e-mail sai SEMPRE em português (os Revisores
 * são médicos brasileiros), mas o arquivo em inglês existe para a paridade, para o Manager e para
 * o Revisor que prefira ler a página em inglês.
 */

return [
    'status' => [
        'pending'   => 'Aguardando parecer',
        'submitted' => 'Parecer recebido',
        'accepted'  => 'Aceito',
        'refused'   => 'Aceite recusado',
        'expired'   => 'Expirado',
        'revoked'   => 'Revogado',
    ],

    'decision' => [
        'approved'          => 'Aprovo',
        'changes_requested' => 'Aprovo com ajustes',
        'rejected'          => 'Não aprovo',
    ],

    'acceptance' => [
        'accepted' => 'Identidade conferida',
        'refused'  => 'Identidade não conferida',
    ],

    'mail' => [
        'subject'        => 'EasyEye — convite para revisão clínica de protocolos de retorno',
        'greeting'       => 'Olá, :name.',
        'intro'          => 'A EasyEye pede a sua revisão clínica das regras de retorno de pacientes abaixo, antes de elas serem usadas pelas clínicas.',
        'versions_title' => 'Versões para revisar:',
        'version_line'   => ':protocol — versão :version',
        'action'         => 'Abrir a revisão',
        'expires'        => 'O link é pessoal, vale até :date e não deve ser encaminhado.',
        'pin'            => 'Este convite exige um código de acesso, que a EasyEye enviou a você por outro canal.',
        'privacy'        => 'A página não mostra dados de pacientes. Ao enviar o parecer, seus dados de identificação, o endereço IP e a data e a hora são registrados como prova da validação.',
        'unsolicited'    => 'Se você não esperava este e-mail, pode ignorá-lo: ninguém acessa a revisão sem o link.',
        'salutation'     => 'Equipe EasyEye',
    ],

    'receipt' => [
        'subject'       => 'EasyEye — recibo do seu parecer clínico',
        'intro'         => 'Recebemos o seu parecer em :when. Este é o seu recibo.',
        'decision_line' => ':protocol — versão :version: :decision',
        'code'          => 'Código de verificação do envio: :code',
        'next'          => 'A EasyEye vai conferir o seu CRM/UF e o seu RQE no portal do Conselho Federal de Medicina antes de liberar qualquer regra. Guarde este e-mail.',
        'unrecognized'  => 'Se você não reconhece este envio, responda a este e-mail com urgência.',
    ],

    /*
     * Página PÚBLICA do Revisor Clínico (`/revisao-clinica`). O Revisor lê no idioma do
     * navegador/da escolha dele; o TEXTO OFICIAL da declaração que ele assina é sempre o
     * em português (resources/legal) — a tradução só ajuda a leitura.
     */
    'public' => [
        'layout' => [
            'skip'           => 'Ir para o conteúdo',
            'tagline'        => 'Revisão clínica',
            'language'       => 'Idioma',
            'theme_dark'     => 'Usar tema escuro',
            'theme_light'    => 'Usar tema claro',
            'footer_privacy' => 'Esta página não mostra dados de pacientes.',
        ],

        'show' => [
            'title'         => 'Revisão clínica de protocolos de retorno',
            'greeting'      => 'Olá, :name.',
            'lead'          => 'A EasyEye pede a sua revisão clínica das regras abaixo antes de elas serem usadas pelas clínicas. Leia cada regra, diga se concorda e identifique-se ao final. Você pode parar e voltar pelo mesmo link: o que digitar é salvo sozinho.',
            'valid_until'   => 'Link pessoal, válido até :date. Não o encaminhe a outra pessoa.',
            'privacy_title' => 'Sobre os seus dados.',
            'privacy_body'  => 'Esta página não mostra dados de pacientes. Ao enviar o parecer, o seu nome, CRM/UF, RQE, e-mail, o endereço IP e a data e a hora ficam registrados como prova da validação.',
            'unavailable'   => 'Uma das versões deste convite saiu de revisão e o parecer não pode mais ser enviado. A EasyEye entrará em contato.',
            'errors_title'  => 'Falta corrigir :count item|Faltam corrigir :count itens',
            'review'        => 'Revisar e enviar',
            'back'          => 'Voltar e editar',
            'send'          => 'Confirmar e enviar o parecer',
            'sending'       => 'Enviando…',
            'confirm_title' => 'Confira antes de enviar',
            'confirm_lead'  => 'Leia o resumo abaixo. Depois de enviado, o parecer não pode ser alterado.',
        ],

        'item' => [
            'version'              => 'Versão :version',
            'hash'                 => 'Código :hash',
            'unavailable'          => 'Esta versão não está mais em revisão: não é possível decidir sobre ela.',
            'parent_validated'     => 'Derivada da versão :version, que já foi validada.',
            'parent_not_validated' => 'Derivada da versão :version, que ainda não foi validada.',
            'decision_legend'      => 'Sua decisão sobre :title (versão :version)',
            'decision_hint'        => [
                'approved'          => 'As regras podem ser usadas como estão.',
                'changes_requested' => 'Concordo com a direção, mas peço ajustes (explique abaixo).',
                'rejected'          => 'Não recomendo o uso destas regras.',
            ],
            'comment_required'      => 'Justificativa',
            'comment_optional'      => 'Comentário (opcional)',
            'comment_hint_required' => 'Explique a decisão (mínimo de :min caracteres).',
            'comment_hint_optional' => 'Se quiser, deixe uma observação.',
            'proposed_label'        => 'O que você mudaria (opcional)',
            'proposed_hint'         => 'Por exemplo: "campimetria a cada 9 meses". A EasyEye prepara uma nova versão e envia para você revisar.',
            'field_decision'        => 'Decisão',
            'field_comment'         => 'Justificativa',
            'field_proposed'        => 'Alteração sugerida',
        ],

        'attestation' => [
            'title'                 => 'Sua identificação e declaração',
            'intro'                 => 'Digite os seus dados profissionais. A EasyEye os confere no portal do Conselho Federal de Medicina antes de liberar qualquer regra.',
            'name'                  => 'Nome completo',
            'email'                 => 'E-mail',
            'crm_number'            => 'CRM (número)',
            'crm_uf'                => 'UF do CRM',
            'rqe'                   => 'RQE (número)',
            'specialty'             => 'Especialidade',
            'select'                => 'Selecione',
            'conflict_title'        => 'Conflito de interesses',
            'conflict_hint'         => 'Por exemplo: vínculo financeiro com fabricante, operadora ou concorrente que possa influenciar a sua revisão.',
            'conflict_no'           => 'Não tenho conflito de interesses a declarar',
            'conflict_yes'          => 'Tenho um conflito de interesses a declarar',
            'conflict_description'  => 'Descreva o conflito de interesses',
            'display_title'         => 'Identificação às clínicas',
            'display_hint'          => 'Quando uma clínica adota uma regra validada, ela pode ver quem a validou.',
            'display_yes'           => 'Autorizo mostrar o meu nome e CRM/UF às clínicas',
            'display_no'            => 'Prefiro que a minha identidade não seja mostrada às clínicas',
            'statement_title'       => 'Declaração do Revisor Clínico',
            'statement_translation' => 'Tradução para leitura. O texto oficial que você assina é o em português; o código abaixo identifica exatamente esse texto.',
            'statement_code'        => 'Código da declaração: :code',
            'statement_official'    => 'Ver o texto oficial, em português',
            'agreed'                => 'Li e concordo com a declaração acima',
        ],

        'summary' => [
            'decisions'        => 'Suas decisões',
            'proposed'         => 'Alteração sugerida:',
            'identity'         => 'Sua identificação',
            'crm'              => 'CRM / UF',
            'conflict'         => 'Conflito de interesses',
            'conflict_yes'     => 'Declarado',
            'conflict_no'      => 'Nenhum',
            'display'          => 'Identidade para as clínicas',
            'display_yes'      => 'Autorizada',
            'display_no'       => 'Não autorizada',
            'statement'        => 'Declaração',
            'statement_agreed' => 'Lida e aceita (código :code)',
            'final_title'      => 'Envio único.',
            'final_body'       => 'Ao confirmar, o parecer, a sua identificação, o endereço IP e a data e a hora são gravados e não podem ser alterados.',
        ],

        'draft' => [
            'auto'         => 'O que você digita é salvo sozinho neste servidor.',
            'saving'       => 'Salvando o rascunho…',
            'saved'        => 'Rascunho salvo às :time.',
            'error'        => 'Não foi possível salvar o rascunho agora. O texto continua na tela; tentaremos de novo.',
            'save_now'     => 'Salvar rascunho agora',
            'ended'        => 'O link deixou de valer (sessão encerrada, convite substituído ou expirado). O que você digitou nesta tela não será mais salvo — copie o texto se precisar dele.',
            'ended_action' => 'Abrir a página do link',
            'ended_short'  => 'Rascunho não está mais sendo salvo.',
        ],

        'code' => [
            'title'   => 'Código de acesso',
            'body'    => 'Este convite exige um código de acesso. A EasyEye o enviou a você por outro canal (telefone ou mensagem) — ele não vem no e-mail.',
            'label'   => 'Código de acesso',
            'hint'    => 'Letras e números; maiúsculas e minúsculas valem igual.',
            'submit'  => 'Continuar',
            'invalid' => 'Código incorreto ou temporariamente bloqueado. Confira o código e, se errou várias vezes, aguarde alguns minutos.',
        ],

        'done' => [
            'title'           => 'Parecer enviado',
            'thanks'          => 'Obrigado, :name. Recebemos o seu parecer.',
            'sent_at'         => 'Enviado em',
            'code'            => 'Código de verificação',
            'code_hint'       => 'Identifica este envio. Guarde-o: é a prova de que o parecer foi recebido.',
            'copy'            => 'Copiar',
            'copied'          => 'Copiado',
            'receipt'         => 'Recibo por e-mail',
            'receipt_sent'    => 'Enviado para o e-mail que você declarou.',
            'receipt_pending' => 'Ainda não foi possível enviar. O código acima vale como recibo.',
            'decisions'       => 'Suas decisões',
            'version'         => 'versão :version',
            'next_title'      => 'E agora?',
            'next_body'       => 'A EasyEye confere o seu CRM/UF e o seu RQE no portal do Conselho Federal de Medicina. Só depois dessa conferência as regras aprovadas podem ser liberadas para as clínicas. Se houver pedido de ajuste, você recebe um novo convite.',
            'close'           => 'Você já pode fechar esta página.',
        ],

        'unavailable' => [
            'title'           => 'Este link não está disponível',
            'body'            => 'Não foi possível abrir a revisão por este endereço.',
            'reason_expired'  => 'O prazo do convite pode ter terminado.',
            'reason_replaced' => 'Pode ter sido enviado um link mais novo no lugar deste.',
            'reason_used'     => 'O parecer pode já ter sido enviado.',
            'action'          => 'Procure o e-mail mais recente da EasyEye ou peça um novo convite a quem convidou você.',
        ],

        // Erros que a própria página aponta antes do envio (códigos de resources/js/Support/clinicalReview.js)
        // e motivos que o servidor devolve (ReviewSubmissionException::reason()).
        'errors' => [
            'decision_required'             => 'Escolha uma decisão.',
            'comment_required'              => 'Explique a decisão (mínimo de 10 caracteres).',
            'comment_too_long'              => 'O comentário passou do limite de 3.000 caracteres.',
            'proposed_too_long'             => 'A alteração sugerida passou do limite de 6.000 caracteres.',
            'name_required'                 => 'Informe o nome completo.',
            'email_invalid'                 => 'Informe um e-mail válido.',
            'number_required'               => 'Informe o número.',
            'number_invalid'                => 'Informe apenas o número (pontos e hífen são aceitos).',
            'uf_required'                   => 'Escolha a UF do CRM.',
            'specialty_required'            => 'Informe a especialidade.',
            'choice_required'               => 'Escolha uma das opções.',
            'conflict_description_required' => 'Descreva o conflito de interesses.',
            'agreed_required'               => 'É preciso concordar com a declaração para enviar.',
            'not_open'                      => 'Este convite não aceita mais respostas.',
            'session_expired'               => 'Sua sessão terminou e o parecer NÃO foi enviado. O que você digitou continua na tela: abra de novo o link do e-mail em outra aba e envie por lá (o rascunho salvo volta sozinho).',
            'set_changed'                   => 'As versões deste convite mudaram desde que você abriu a página. Recarregamos o conteúdo: confira e envie de novo.',
            'statement_changed'             => 'A declaração foi atualizada. Leia o texto novo, marque que concorda e envie de novo.',
            'missing_decision'              => 'Falta a decisão de alguma versão.',
            'statement_not_accepted'        => 'É preciso concordar com a declaração para enviar.',
        ],

        // Nomes dos campos nas mensagens de validação do servidor.
        'fields' => [
            'decisions'              => 'decisões',
            'decision'               => 'decisão',
            'comment'                => 'comentário',
            'proposed_changes'       => 'alteração sugerida',
            'name'                   => 'nome completo',
            'email'                  => 'e-mail',
            'crm_number'             => 'CRM',
            'crm_uf'                 => 'UF do CRM',
            'rqe'                    => 'RQE',
            'specialty'              => 'especialidade',
            'has_conflict'           => 'conflito de interesses',
            'conflict_description'   => 'descrição do conflito',
            'allow_identity_display' => 'identificação às clínicas',
            'agreed'                 => 'concordância com a declaração',
            'statement'              => 'declaração',
        ],
    ],
];
