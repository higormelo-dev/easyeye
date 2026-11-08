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

## Versão 1.1 (pronta, não publicada)

O texto oficial está em `resources/legal/privacy-policy-1.1.txt`, e a tradução em
`resources/legal/privacy-policy-1.1.en.txt`. Mudanças em relação à 1.0: identidade e contato
do Encarregado, fontes servidas pelo próprio EasyEye (sem Google Fonts), lista atual de
provedores de IA, categorias de transferência internacional e o atalho
`/privacidade#direitos-titular`. A proposta e o checkpoint estão em
`.lgpd/policies/privacy-policy-v1.1-proposta.md`.

Para publicar, depois de designar o Encarregado e configurar `LEGAL_ENCARREGADO_NAME` (e,
se for diferente do canal de privacidade, `LEGAL_ENCARREGADO_EMAIL`/`_PHONE`) e rodar
`config:cache`:

```sh
php artisan legal:publish-privacy-policy 1.1 --by=email@do-responsavel --summary="Resumo das mudanças"
```

O comando recusa publicar sem Encarregado, sem autor ou sem resumo, nunca altera uma
versão já publicada e grava a tradução na mesma versão. Publicar desativa a 1.0: o site e
o cadastro passam a mostrar a 1.1. **O painel não pede novo aceite a quem já tem conta**: o
middleware `terms.accepted` existe, mas não está em nenhuma rota, e a rota `terms.accept`
não existe. Comunique a mudança aos usuários e às clínicas (política, seção 14). Testes:
`tests/Feature/Site/PrivacyPolicyV11PublicationTest.php`.

## Tradução para o inglês

O texto oficial é o em português (`term_versions.content`): é ele que vale e que
os usuários aceitam. A tradução de cortesia fica em
`resources/legal/privacy-policy-1.0.en.txt` e é gravada em `term_versions.translations.en`
da mesma versão pelo `LegalDocumentTranslationsSeeder`, que não altera texto
oficial, datas, estado ou identificadores nem sobrescreve uma tradução publicada.
Em inglês, a página mostra a tradução com aviso de que o português prevalece e
link para o original (`?original=1`); sem tradução, mostra o original e avisa.

Em uma instalação existente: `php artisan migrate` e
`php artisan db:seed --class=LegalDocumentTranslationsSeeder`. Uma nova versão do
documento precisa do próprio arquivo `.en.txt` para ganhar tradução; a revisão
jurídica vale também para o texto em inglês.

## Escopo e validação

O texto considera os fluxos presentes no projeto: formulário comercial por
e-mail, contas e permissões por clínica, prontuários, imagens, portal do paciente,
faturamento, WhatsApp, integradores e provedores de IA. Google Fonts aparece porque
era carregado pelo layout público; desde 07/11/2026 a Inter é servida pelo próprio
EasyEye (`public/fonts/`), e a próxima versão deve retirar essa menção
(`.lgpd/policies/`). Os filtros de identificadores da IA não são
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
