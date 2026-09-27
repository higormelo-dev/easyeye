# Política de Privacidade do EasyEye

A página `/privacidade` lê exclusivamente a versão vigente de `term_versions`.
O texto inicial fica em `resources/legal/privacy-policy-1.0.txt`, com vigência
em 27/09/2026. A identificação pública da empresa e o canal de privacidade
ficam em `config/legal.php` e são incorporados ao texto na publicação.

## Publicação

Em instalações novas, o `DatabaseSeeder` inclui a política. Para uma instalação
existente sem política cadastrada, execute:

```sh
php artisan db:seed --class=PrivacyPolicySeeder
```

O seeder preserva qualquer política existente, inclusive inativa ou com vigência
futura. Reexecutá-lo não altera conteúdo, datas, estado ou identificadores. Não
é necessário executar os demais seeders. Em produção, use o procedimento de
deploy do projeto e a opção `--force` apenas no ambiente pretendido.

Mudanças posteriores no arquivo ou na configuração não modificam uma versão
publicada. Publique uma nova `TermVersion` para alterações de conteúdo, mantendo
o histórico e os vínculos de aceite existentes. O documento é compartilhado com
os consumidores de `TermsService::REQUIRED_TYPES`; não há uma cópia independente
para o site. Esta implementação não cria nem registra aceites em nome de usuários.

## Escopo e validação

O texto considera os fluxos presentes no projeto: formulário comercial por
e-mail, contas e permissões por clínica, prontuários, imagens, portal do paciente,
faturamento, WhatsApp, integradores e provedores de IA. Google Fonts aparece porque
é carregado pelo layout público. Os filtros de identificadores da IA não são
descritos como anonimização completa.

Razão social, CNPJ, endereço, e-mail e telefone foram fornecidos pelo responsável
pelo projeto. Antes da utilização definitiva, a revisão jurídica deve confirmar
a adequação do texto à operação real, especialmente fornecedores em produção,
países e mecanismos de transferência internacional, prazos de guarda e contato
público do encarregado, quando aplicável. Esses elementos não podem ser comprovados
apenas pelo código. A política não atesta certificações nem promete retenção zero
ou ausência de uso de dados pelos fornecedores de IA.

## Referências consultadas em 27/09/2026

Redação própria para o EasyEye. As políticas comerciais serviram de referência
de organização e temas, sem reprodução de cláusulas, dados empresariais ou
compromissos de outros fornecedores:

- [Ninsaúde — Aviso de privacidade](https://www.ninsaude.com/pt-br/aviso-de-privacidade/): site, finalidades, cookies, direitos e contato.
- [Mezzow — Política de Privacidade](https://www.mezzow.com.br/privacidade.html): distinção entre contatos comerciais e dados clínicos.
- [iClinic — página indicada](https://suporte.iclinic.com.br/pt-br/politica-de-privacidade-iclinic): o documento incorporado via OneTrust retornou `ResourceNotFound`. Como referência complementar da mesma empresa, foi consultada a [política do AgendarConsulta](https://suporte.iclinic.com.br/pt-br/politica-de-privacidade-do-agendarconsulta), especialmente a distinção de responsabilidades sobre prontuários, permissões e pedidos de titulares.
- [LGPD — texto oficial](https://www.planalto.gov.br/ccivil_03/_ato2015-2018/2018/lei/l13709compilado.htm): bases legais, dados sensíveis, direitos e responsabilidades.
- [ANPD — transferências internacionais](https://www.gov.br/anpd/pt-br/assuntos/assuntos-internacionais/transferencia-internacional-de-dados): regulamentação e mecanismos aplicáveis.
- [Enunciado CD/ANPD nº 1/2023](https://bibliotecadigital.mj.gov.br/bitstream/1/10215/2/Enunciado_ANPD_2023_1.html): bases legais e melhor interesse de crianças e adolescentes.
