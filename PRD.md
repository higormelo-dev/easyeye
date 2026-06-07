**Versão:** 3.0  
**Data:** Junho de 2026 (Migração SPA)  
**Status:** Produção ativa (escopo implementado via Vue 3 + Inertia.js)

---

## 1. Visão do Produto
O **EasyEye** é uma plataforma SaaS multi-tenant (com interface SPA ultra-rápida) para clínicas de oftalmologia, com foco em operação clínica diária, conformidade regulatória (CFM/LGPD), integração de exames e gestão financeira/faturamento.

Produto já cobre operação de ponta a ponta do tenant clínico e operação SaaS do provedor (manager), com camada de parceiros e billing multi-gateway.

## 2. Objetivos de Negócio (Implementados)
1. **Centralizar operação clínica** em um único sistema (paciente, médico, agenda, prontuário e documentos).
2. **Reduzir risco regulatório** com trilha de auditoria, versionamento e assinatura de prontuário.
3. **Escalar modelo SaaS** com planos, assinatura, trial, quotas e automações de aquisição (referral/parceiros).
4. **Conectar ecossistema externo** via API para integradores de exames e webhooks financeiros.

## 3. Perfis de Usuário Atendidos
1. **SaaS (entidade não cliente):** admin, financeiro, suporte, usuário comum.
2. **Clínica (entidade cliente):** admin, financeiro, médico, secretária, usuário comum.
3. **Parceiro comercial:** acesso ao portal de leads/comissões.
4. **Integração externa:** integradores autenticados por token/Sanctum.

## 4. Escopo Funcional Implementado

### 4.1 Acesso, Identidade e Contexto de Entidade
1. Registro, login, recuperação de senha, verificação de e-mail.
2. Seleção de entidade ativa para usuários multi-entidade.
3. Impersonação controlada no painel manager.
4. Internacionalização com locale por entidade e por usuário.

### 4.2 Núcleo Clínico
1. Gestão de pacientes e médicos.
2. Agenda clínica com slots, bloqueios, reagendamento e atualização de situação/humor.
3. Lista de espera e eventos de agenda não clínicos.
4. Agenda de recursos (salas/equipamentos) com horários e bloqueios.
5. Gestão de avisos internos para comunicação entre equipes.

### 4.3 Prontuário e Documentação
1. Prontuário oftalmológico com CRUD completo por paciente.
2. Documentações anexas e arquivos de prontuário.
3. Emissão de PDF de prontuário, tonometria e receitas.
4. Ações rápidas de documentação e preview por template.
5. Busca CID-10 e cálculo auxiliar de presbiopia.
6. Prescrição de medicamentos com catálogo de apresentações e posologias.
7. Solicitação de procedimentos e workflow de agendamento cirúrgico.
8. Importação em lote de pacientes com mapeamento de campos.

### 4.4 Compliance Clínico e LGPD
1. Assinatura de prontuário com hash e bloqueio de edição.
2. Versionamento de registros clínicos (snapshots).
3. Auditoria de alterações de dados (CUD).
4. Log de acesso a dados sensíveis.
5. Consentimentos de paciente (coleta/revogação/base legal).
6. Solicitações LGPD (abertura, processamento, conclusão/rejeição).
7. Versionamento de termos e aceite por usuário.
8. Relatórios de compliance (auditoria e acesso a dados).

### 4.5 Configurações Clínicas (Tenant)
1. CRUD de tipologias e catálogos clínicos:
- convênios
- tipos de pele
- tipos de íris
- tipos de consulta
- tipos de adição
- tipos cirúrgicos
- tipos de cover test
- tipos de visão de cores
- tipos de acuidade visual
- lentes
- convergência de ponto próximo
- recursos clínicos
2. Configuração de templates/documentos da clínica (adotar/reimportar versão global).
3. Gestão de credenciais de gateway por tenant.

### 4.6 Financeiro e Faturamento
1. Fluxo de caixa com categorias e lançamentos.
2. Faturamento individual e em lote.
3. Submissão de lote e exportação de XML.
4. Gestão de glosa/pagamento de claims.
5. Relatórios financeiros (fluxo de caixa e convênios) com exportação CSV.

### 4.7 Domínio TISS
1. Estruturas e workflow de lotes/guias TISS.
2. Geração de XML por versão (builders dedicados).
3. Envio/processamento assíncrono via jobs.
4. Recebimento e parsing de retorno TISS, incluindo glosa e recurso.

### 4.8 SaaS Manager
1. Gestão de entidades cliente.
2. Gestão de planos e features de plano.
3. Gestão de assinaturas (trial, ativação, cancelamento, bloqueio, settings).
4. Gestão de parceiros, leads e comissões.
5. Gestão de integradores por entidade/usuário integrador/equipamento.
6. Gestão de gateways globais e regra de acesso por entidade.
7. Gestão de templates globais de documentação (publish/archive).

### 4.9 Billing Multi-Gateway
1. Orquestração de assinatura com gateway resolver.
2. Fallback de gateway por regras.
3. Circuit breaker por gateway.
4. Ingestão/processamento idempotente de webhook.
5. Trilha financeira de invoice/payment/attempt/event/cancellation/retry.
6. Suporte configurado para gateways:
- Asaas
- InfinitePay
- Mercado Pago
- Pagar.me
- Stripe BR
- PagBank

### 4.10 Crescimento (CAC)
1. Programa de indicação (referral codes/events/rewards).
2. Programa de parceiros (partner leads/commissions).
3. Activation score por entidade via marcos de ativação.
4. Portal do parceiro para dashboard, leads e comissões.

### 4.11 API de Integradores
1. Autenticação `signin/signout/check-token`.
2. API v1 para equipamentos, pacientes, exames, tipos de exame e agendas.
3. Upload e atualização multipart de exames.
4. Proteções de API: precheck, expiração de token, validação de plano e auth com integrador.

## 5. Jornada Principal (Estado Atual)
1. Usuário autentica e seleciona entidade.
2. Equipe clínica opera agenda/pacientes/prontuários.
3. Documentação clínica é emitida com rastreabilidade.
4. Financeiro da clínica processa caixa e faturamento.
5. SaaS manager monitora entidades, planos, assinatura e billing.
6. Parceiros alimentam funil de aquisição pelo portal.

## 6. Requisitos Não Funcionais (Implementados)
1. **Isolamento lógico multi-tenant** por `entity_id` + contexto de sessão.
2. **ACL contextual** por gate e middleware (`entity.role`, `entity.member`, etc.).
3. **Soft delete e restore** em entidades críticas.
4. **Auditoria e rastreabilidade** em camadas de modelo e serviço.
5. **Operação assíncrona** com filas para workflows de billing/TISS.
6. **Observabilidade de erros** com integração Sentry por ambiente.

## 7. Escopo Fora do Produto Atual
1. Aplicativo mobile nativo médico/paciente.
2. Telemedicina síncrona no próprio produto.
3. Motor de IA de apoio clínico em produção.
4. Integrações hospitalares HL7/FHIR completas.

## 8. Indicadores Operacionais Relevantes
1. Volume de atendimentos/agenda por entidade.
2. Conversão de trial para assinatura ativa.
3. Uso de features limitadas por plano.
4. Taxa de sucesso de cobrança por gateway.
5. Ciclo de faturamento TISS e índice de glosa.
6. Evolução de activation score/referral/parceiros.
