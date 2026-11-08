<?php

/*
 * Credenciais TISS da clínica (Financeiro > Credenciais TISS): acesso ao webservice de cada operadora, por
 * ambiente. Também usado nas mensagens de bloqueio do envio (tiss_send.credential_incomplete).
 */
return [
    'title'      => 'Credenciais TISS',
    'subtitle'   => 'Acesso ao webservice de cada operadora, separado por ambiente. Senhas, tokens e certificados ficam cifrados e nunca voltam para a tela.',
    'new'        => 'Nova credencial',
    'empty'      => 'Nenhuma credencial cadastrada. Comece pela de homologação e teste a conexão antes de cadastrar a de produção.',
    'admin_only' => 'Só administradores da clínica veem e alteram esta tela. Cada alteração e cada teste ficam na auditoria, sem os segredos.',

    'transport' => [
        'simulated' => 'Envio em modo simulação (TISS_TRANSPORT_DRIVER=:driver): nada sai para as operadoras e o protocolo gerado começa com SIMULADO-, que não vale como aceite. O suporte liga o envio real depois de validar uma operadora em homologação.',
        'real'      => 'Envio real ligado: os lotes saem com a credencial do ambiente :environment.',
    ],

    'environments' => [
        'sandbox'    => 'Homologação',
        'production' => 'Produção',
    ],

    'missing' => [
        'endpoint' => 'endereço do webservice',
        'review'   => 'revisão de um administrador (a credencial foi criada sozinha pelo faturamento)',
    ],

    'columns' => [
        'operator'       => 'Operadora',
        'environment'    => 'Ambiente',
        'endpoint'       => 'Endereço de envio',
        'authentication' => 'Autenticação',
        'status'         => 'Situação',
        'last_test'      => 'Último teste',
        'actions'        => 'Ações',
    ],

    'status' => [
        'ready'       => 'Pronta',
        'incomplete'  => 'Incompleta: falta :missing',
        'inactive'    => 'Inativa',
        'placeholder' => 'Criada pelo faturamento, ainda sem dados',
        'ans'         => 'ANS :code',
        'default_url' => 'padrão da operadora',
    ],

    'auth' => [
        'login'       => 'Usuário e senha',
        'token'       => 'Token',
        'certificate' => 'Certificado',
        'valid_to'    => 'válido até :date',
        'expired'     => 'vencido em :date',
        'no_auth'     => 'Sem autenticação no webservice: confirme com a operadora se ela libera só por IP',
    ],

    'form' => [
        'title_new'                 => 'Nova credencial TISS',
        'title_edit'                => 'Editar credencial TISS',
        'covenant'                  => 'Convênio (operadora)',
        'covenant_hint'             => 'Lista só convênios com registro ANS. A credencial vale para a operadora do convênio.',
        'covenant_placeholder'      => 'Busque pelo nome ou registro ANS',
        'environment'               => 'Ambiente',
        'environment_hint'          => 'Homologação e produção têm credenciais separadas. O envio usa a do ambiente configurado no servidor.',
        'endpoint_submission'       => 'Endereço de envio dos lotes',
        'endpoint_submission_hint'  => 'URL do webservice TISS informada pela operadora. Em produção, só https. Vazio: usa o endereço padrão da operadora, se houver.',
        'endpoint_status'           => 'Endereço para o teste de conexão (opcional)',
        'endpoint_status_hint'      => 'Vazio: o teste usa o endereço de envio.',
        'username'                  => 'Usuário',
        'password'                  => 'Senha',
        'token'                     => 'Token de acesso',
        'secret_saved'              => 'Salvo. Deixe em branco para manter.',
        'secret_not_saved'          => 'Nada salvo.',
        'remove_password'           => 'Apagar a senha salva',
        'remove_token'              => 'Apagar o token salvo',
        'certificate'               => 'Certificado digital do cliente',
        'certificate_hint'          => 'Só se a operadora exigir. Arquivo .pfx ou .p12 (com a chave) ou .pem, .crt ou .cer com a chave à parte. Fica em armazenamento privado, fora da pasta pública.',
        'certificate_saved'         => 'Certificado salvo: :subject (válido até :date). Envie outro arquivo para trocar.',
        'private_key'               => 'Chave privada (.pem ou .key)',
        'private_key_hint'          => 'Só para certificado .pem, .crt ou .cer sem a chave no mesmo arquivo.',
        'certificate_password'      => 'Senha do certificado',
        'certificate_password_hint' => 'Usada para abrir o certificado e a chave. Deixe em branco para manter a salva.',
        'remove_certificate'        => 'Apagar o certificado salvo',
        'ssl_enabled'               => 'Verificar o certificado do servidor da operadora (recomendado)',
        'active'                    => 'Ativa: usada no envio',
        'save'                      => 'Salvar',
        'saving'                    => 'Salvando…',
        'cancel'                    => 'Cancelar',
        'close'                     => 'Fechar',
        'discard_confirm'           => 'Descartar as alterações desta credencial?',
    ],

    'actions' => [
        'test'           => 'Testar conexão',
        'testing'        => 'Testando…',
        'edit'           => 'Editar',
        'delete'         => 'Remover',
        'confirm_delete' => 'Remover a credencial de :operator (:environment)? Os arquivos de certificado também são apagados. Com o envio real ligado, essa operadora fica sem envio nesse ambiente até cadastrar outra.',
    ],

    'test' => [
        'note'    => 'O teste faz uma consulta simples ao endereço, sem enviar lote nem dado de paciente. Ele confirma rede, TLS e autenticação; o aceite dos lotes só se confirma com um envio em homologação.',
        'never'   => 'Nunca testada',
        'at'      => ':result em :date',
        'results' => [
            'reachable'        => 'Conexão OK (HTTP :status, :ms ms)',
            'responded'        => 'O servidor respondeu HTTP :status: rede e TLS OK, mas esse endereço não atende consulta simples (comum em SOAP)',
            'auth_rejected'    => 'Autenticação recusada (HTTP :status). Confira usuário, senha, token ou certificado',
            'server_error'     => 'O servidor da operadora respondeu com erro (HTTP :status)',
            'unreachable'      => 'Sem conexão: :reason',
            'blocked'          => 'Endereço recusado: :reason',
            'missing_endpoint' => 'Sem endereço: cadastre o endereço de envio',
            'redirect'         => 'O servidor redirecionou (HTTP :status). O envio não segue redirecionamento: cadastre o endereço final',
            'failed'           => 'Não foi possível montar a conexão com os dados salvos (certificado ou segredo ilegível). Salve-os de novo',
        ],
        'reasons' => [
            'timeout'      => 'tempo esgotado',
            'dns'          => 'endereço não encontrado (DNS)',
            'refused'      => 'conexão recusada',
            'tls'          => 'falha no TLS ou no certificado',
            'other'        => 'erro de rede',
            'invalid'      => 'use http ou https, sem usuário e senha no endereço',
            'private'      => 'aponta para a rede interna',
            'unresolvable' => 'o nome não resolve para nenhum IP',
        ],
    ],

    'errors' => [
        'covenant_not_found'       => 'Convênio não encontrado nesta clínica.',
        'covenant_without_ans'     => 'Este convênio não tem registro ANS, então não passa pelo TISS.',
        'duplicate'                => 'Já existe uma credencial desta operadora neste ambiente. Edite a existente.',
        'certificate_unreadable'   => 'Não foi possível abrir o certificado. Confira o arquivo e a senha.',
        'private_key_required'     => 'Envie a chave privada do certificado (ou use um arquivo .pfx ou .p12).',
        'private_key_mismatch'     => 'A chave privada não abre com esta senha ou não corresponde ao certificado.',
        'endpoint_invalid'         => 'Endereço inválido: use http ou https, sem usuário e senha na URL.',
        'endpoint_private'         => 'Endereço recusado: ele aponta para a rede interna do servidor.',
        'endpoint_unresolvable'    => 'Endereço recusado: o nome não resolve para nenhum IP.',
        'https_required'           => 'Em produção o endereço precisa ser https.',
        'secret_reentry'           => 'O endereço mudou: digite de novo (ou marque para apagar). O valor salvo não é enviado a um endereço novo sem confirmação.',
        'certificate_missing'      => 'O certificado salvo não foi encontrado no servidor. Envie o arquivo de novo em Financeiro > Credenciais TISS.',
        'certificate_legacy'       => 'Certificado em formato antigo (criptografia RC2 ou 3DES), que o servidor não abre. Exporte de novo em formato moderno (AES-256) ou peça o arquivo atualizado à certificadora.',
        'certificate_without_key'  => 'O arquivo não traz a chave privada do certificado. Exporte o .pfx com a chave.',
        'certificate_store_failed' => 'Não foi possível gravar o certificado no servidor. Tente de novo.',
    ],

    'flash' => [
        'created' => 'Credencial TISS salva.',
        'updated' => 'Credencial TISS atualizada.',
        'deleted' => 'Credencial TISS removida.',
    ],
];
