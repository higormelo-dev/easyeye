<?php

/*
 * Identidades GLOBAIS compartilhadas entre clínicas: cadastro de pessoa
 * (People — paciente/médico) e login (User). Uma clínica não vincula nem
 * reescreve sozinha o que também pertence a outra.
 */
return [
    'person_shared_readonly' => 'Os dados pessoais deste cadastro também são usados por outra clínica e não podem ser alterados por aqui. Desfaça as alterações nos dados pessoais para salvar o restante.',
    'user_shared_readonly'   => 'Este usuário também tem acesso a outra clínica ou ao portal de parceiros: o nome e o e-mail de login só podem ser alterados por ele mesmo, em "Meu perfil".',

    'import' => [
        'cpf_linked_elsewhere' => 'CPF já cadastrado em outra clínica. Por segurança (LGPD), a importação não vincula cadastros de outras clínicas — linha não importada.',
        'email_in_use'         => 'E-mail já cadastrado em outra conta de acesso. Por segurança, a importação só aceita e-mails novos ou de médicos já cadastrados nesta clínica.',
        'row_failed'           => 'Não foi possível gravar esta linha por um erro interno. Revise os dados e importe a linha novamente; se o erro persistir, contate o suporte.',
        'failed'               => 'A importação foi interrompida por um erro interno. Tente novamente; se o erro persistir, contate o suporte.',
    ],
];
