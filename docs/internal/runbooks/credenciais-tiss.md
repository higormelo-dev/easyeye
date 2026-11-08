# Credenciais TISS por clínica e operadora

Origem: 08/11/2026. O transporte real (`TISS_TRANSPORT_DRIVER=http`) precisa do endereço e do acesso ao webservice de cada operadora, por clínica. Antes desta tela, não havia como cadastrá-los sem banco ou terminal, e o servidor tinha 0 credenciais.

## Onde

Painel da clínica > **Financeiro > Credenciais TISS** (`/panel/setting/tiss-credentials`). Só o **administrador da clínica** vê o item e abre a tela: a rota exige `entity.role:admin` e o gate `entity.manage-tiss-credentials`, que não vale para a empresa do SaaS. Cada clínica vê só as próprias credenciais.

## O que se cadastra

Há uma credencial por **operadora + ambiente**. Homologação (`sandbox`) e produção são linhas separadas. O envio usa a credencial do ambiente configurado no servidor (`TISS_TRANSPORT_ENVIRONMENT`, padrão `production`).

| Campo | Observação |
|---|---|
| Convênio (operadora) | Só convênios com registro ANS. A operadora é criada pelo ANS na primeira vez. Operadora e ambiente não mudam depois do cadastro. |
| Endereço de envio | URL do webservice. Em produção, só `https`. Vazio usa o endereço padrão da operadora + `/tiss`, se existir. |
| Endereço do teste | Opcional. Vazio usa o endereço de envio. |
| Usuário e senha, token | A senha e o token são cifrados (`Crypt`) e nunca voltam para a tela. Campo vazio mantém o valor salvo; "apagar" remove, mesmo que algo tenha sido digitado no campo. Ao trocar o endereço de envio ou de teste, a senha e o token salvos precisam ser digitados de novo: senão bastaria apontar o endereço para outro servidor e testar a conexão para recebê-los. |
| Certificado do cliente | `.pfx` ou `.p12` (com a chave), ou `.pem`, `.crt` ou `.cer` com a chave à parte. É lido com a senha **antes** de gravar: arquivo ou senha errados, `.pfx` sem a chave ou em formato antigo (RC2/3DES, que o OpenSSL 3 não abre) dão erro próprio e nada é salvo. Fica no disco privado `tiss-certificates` (`storage/app/private/tiss-certificates/{clínica}/{credencial}/`, arquivos 0600). O titular e a validade aparecem na tela. A senha do certificado nunca vai para a sessão, nem quando o formulário volta com erro. |

A credencial provisória que o primeiro faturamento cria sozinha aparece na lista como "Criada pelo faturamento, ainda sem dados". O cadastro da mesma operadora e ambiente reaproveita essa linha, sem duplicar.

## Bloqueio do envio

Com o transporte real, o lote e a guia cirúrgica avulsa **não saem**, e nada é reservado nem gravado, quando a credencial do ambiente:

- não tem endereço do webservice (nem o padrão da operadora); ou
- ainda é a provisória que nenhum administrador revisou.

A mensagem diz a operadora, o ambiente e o que falta. O job da fila falha na hora, sem retentativa; o lote que ainda não estava em envio vai para **erro**.

Destino recusado pela trava (abaixo) ou certificado salvo que sumiu do disco também não saem: a transmissão termina como **não enviada** (erro definitivo), nunca como entrega em dúvida, porque nada chegou a conectar.

A falta de autenticação **não bloqueia**: ela depende da operadora (usuário e senha, token, certificado ou só liberação por IP). A tela avisa "Sem autenticação no webservice", e o teste de conexão mostra a recusa (401/403).

O `loginSenhaPrestador` dentro do XML TISS ainda **não** é preenchido. Se a operadora autenticar assim, isso precisa ser implementado antes.

## Teste de conexão

O botão **Testar conexão** (ou `php artisan tiss:credentials --test=<id>`) faz um GET simples no endereço, com a mesma autenticação, TLS e certificado do envio. Não envia lote nem dado de paciente.

| Resultado | Significa |
|---|---|
| Conexão OK / respondeu HTTP 4xx (405, 404) | A rede e o TLS funcionaram. SOAP costuma recusar GET, e isso é normal. |
| Redirecionou (3xx) | O envio não segue redirecionamento: cadastre o endereço final. |
| Autenticação recusada (401/403) | Confira usuário, senha, token ou certificado. |
| Erro do servidor (5xx), sem conexão, endereço recusado | Veja o motivo na tela. |

O teste **não prova** que a operadora aceita os lotes: isso só o envio em homologação mostra. O resultado guarda só códigos (resultado, status HTTP, tempo e motivo), nunca o corpo da resposta. Cada teste e cada alteração entram na auditoria (`tiss.credential.*`), sem segredo.

## Destino protegido (SSRF)

O endereço vem do administrador da clínica. O servidor só chama hosts que resolvem para IP público. O IP verificado fica fixado na conexão e o redirecionamento não é seguido. A trava vale para o envio e para o teste, em **todo ambiente**, inclusive no servidor de teste, que também é público.

Para usar um simulador de operadora na própria máquina, ponha `TISS_ALLOW_PRIVATE_ENDPOINTS=true` no `.env` local. Os testes automatizados ligam essa chave no `phpunit.xml`. Em produção ela é ignorada.

## Mock continua até validar uma operadora

Enquanto `TISS_TRANSPORT_DRIVER` não for `http`, a tela mostra o aviso de simulação. No mock:

- o protocolo gerado começa com `SIMULADO-`;
- a resposta diz "Aceito (SIMULADO)" e traz a observação de que nada foi enviado;
- o faturamento e a guia cirúrgica avisam "processado em modo simulação".

Em produção, o mock continua bloqueado ([alertas de configuração](alertas-config-producao.md)).

Para ligar o envio real numa clínica:

1. Cadastre a credencial de **homologação** e teste a conexão.
2. Com `TISS_TRANSPORT_ENVIRONMENT=sandbox` e `TISS_TRANSPORT_DRIVER=http` num ambiente de homologação, envie um lote de teste e confira o protocolo devolvido pela operadora.
3. Só depois cadastre a credencial de produção e troque o `.env` de produção (`php artisan config:cache`).

## Manutenção

`php artisan tiss:credentials [--entity=<id>]` lista, sem segredos, as credenciais de cada clínica: endereço (host), autenticação configurada, o que falta e o último teste. O cadastro e a troca de segredo ficam só na tela, porque no terminal a senha iria para o histórico do shell.

Código: `TissCredentialsController`, `TissCredentialManager`, `TissCredentialConnectionTester` (`app/Domains/Tiss/Services/Credentials`), `TissHttpClientFactory`, `TissEndpointGuard`, `TissCredentialReadiness` (`app/Domains/Tiss/Services/Transport`).

Testes: `tests/Feature/Tiss/TissCredentialsScreenTest.php`, `tests/Feature/Tiss/TissCredentialReadinessTest.php` e `tests/JavaScript/Panel/Settings/TissCredentials.test.js`.
