# Termos de Uso do EasyEye

A página `/termos` utiliza a versão vigente de `terms_of_service` em
`term_versions`, pelo mesmo controller e componente da Política de Privacidade.
O texto inicial fica em `resources/legal/terms-of-use-1.0.txt`, com vigência em
27/09/2026. Títulos numerados e listas são exibidos pelo componente existente,
com índice de navegação e conteúdo escapado.

## Publicação

O `DatabaseSeeder` inclui os termos para novas instalações. Em um ambiente
existente, publique somente o documento com:

```sh
php artisan db:seed --class=TermsOfUseSeeder
```

A identificação da empresa vem de `config/legal.php`, compartilhada com a
política. Os canais contratuais usam `legal.terms_email` e `legal.terms_phone`;
o suporte técnico utiliza `mail.support_address`. Os valores são incorporados
ao documento no momento da publicação.

O seeder não altera documentos existentes, inclusive termos inativos ou futuros,
nem modifica a política, registros de aceite, planos, cobranças ou assinaturas.
Alterações de texto ou configuração posteriores exigem uma nova versão do
documento; não devem reescrever o conteúdo já publicado. Em produção, siga o
procedimento de deploy e utilize `--force` somente no ambiente pretendido.

Estes termos ficam disponíveis aos consumidores de `TermsService::REQUIRED_TYPES`.
A publicação não implementa um novo fluxo de aceite e não registra concordância
em nome de usuários.

## Adequação ao produto

O texto foi redigido a partir dos fluxos existentes e das informações da empresa
fornecidas pelo responsável pelo projeto:

- `SubscriptionService`, `TrialService` e `BillingCancellationService`: vigência,
  avaliação, cancelamento e transição de acesso dependem da configuração e da
  contratação. O texto não presume acesso até o fim de todo período pré-pago,
  nem substitui a análise dos direitos relativos a períodos não prestados.
- `AiCreditWalletService`: franquia do plano vence ao fim do ciclo, recargas
  acumulam e a franquia disponível é consumida primeiro.
- Faturamento TISS, integrações e IA: recursos de apoio sujeitos a conferência,
  sem promessa de pagamento de convênios ou resultado clínico.
- Portal do paciente e `LgpdService`: direitos sobre dados próprios são distintos
  de uma exportação integral da organização.

O documento não fixa novos preços, percentuais de disponibilidade, horários de
suporte, multas, fidelidade, índice de reajuste ou prazo automático de exclusão.
Essas condições devem estar definidas na oferta ou no contrato específico e
validadas juridicamente, junto com renovação, restituições, créditos remanescentes
e transição de dados. A redação preserva direitos legais obrigatórios, inclusive
os de consumidores quando a relação estiver sujeita ao CDC.

Recomenda-se revisão jurídica antes da adoção em produção para confirmar a
correspondência com a operação comercial e os contratos efetivamente praticados.

## Referências consultadas em 27/09/2026

Redação própria para o EasyEye, usando os documentos como referência de temas e
organização, sem copiar cláusulas nem incorporar condições de outros fornecedores:

- [GestãoDS — Termos de Uso](https://www.gestaods.com.br/termos-de-uso/): licença, deveres das partes, planos, cancelamento e proteção de dados.
- [Ninsaúde — Termos de uso](https://www.ninsaude.com/pt-br/termos-de-uso/): objeto do serviço, perfis de acesso, contratação, manutenção e encerramento.
- [iClinic — página indicada](https://suporte.iclinic.com.br/pt-br/iclinic-termos-de-uso): o documento incorporado via OneTrust retornou HTTP 404 (`ResourceNotFound`). O endereço alternativo `iclinic.com.br/termos/` redirecionou para essa mesma página.
- [iClinic — Termos do AgendarConsulta](https://suporte.iclinic.com.br/pt-br/termos-e-condicoes-de-uso-do-agendarconsulta): referência complementar acessível da mesma empresa, com distinção entre contratante profissional e paciente e condições de serviços integrados. Seus recursos e condições comerciais não foram atribuídos ao EasyEye.
- [Código de Defesa do Consumidor](https://www.planalto.gov.br/ccivil_03/leis/l8078compilado.htm): informação contratual, arrependimento e limites de cláusulas de responsabilidade, quando aplicável.
- [Lei do Software](https://www.planalto.gov.br/ccivil_03/leis/l9609.htm): licença e direitos sobre programas de computador.
- [LGPD](https://www.planalto.gov.br/ccivil_03/_ato2015-2018/2018/lei/l13709compilado.htm): responsabilidades, finalidade e direitos dos titulares.
