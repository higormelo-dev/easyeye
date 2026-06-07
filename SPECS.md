**Runtime:** PHP 8.4 (ambiente atual) / requisito Composer `^8.2`  
**Framework:** Laravel 12.x  
**Frontend:** Vue 3 (Composition API) + Inertia.js + Vite + Bootstrap 5 + Tailwind CSS  
**Banco:** SQLite (default local/test) com suporte a PostgreSQL/MySQL/MariaDB/SQL Server

---

## 1. Visão Arquitetural
Arquitetura modular em monólito Laravel, orientada a domínio de negócio (clínico, billing, TISS, compliance), com separação por camadas:

1. **HTTP** (`Controllers`, `Requests`, `Middleware`) para orquestração e borda.
2. **Aplicação/Negócio** (`Services`, `Domains`, `Actions`) para regras centrais.
3. **Persistência** (`Models`, migrations, enums, traits) para consistência de dados.
4. **Apresentação** (`Vue 3`, `Inertia.js`, assets Vite) para operação web em formato SPA.

## 2. Multi-Tenancy e ACL

### 2.1 Modelo de Tenant
1. Shared database/shared schema com isolamento lógico por `entity_id`.
2. Entidade ativa armazenada em sessão (`selected_entity_id`).
3. Associação usuário-entidade via pivot `entity_users`.

### 2.2 Regras de Acesso
1. Roles SaaS: `admin`, `financial`, `support`, `user`.
2. Roles Cliente: `admin`, `financial`, `doctor`, `secretary`, `user`.
3. Gates canônicos em `EntityGate` + `AuthServiceProvider`.
4. Middleware contextual: `entity.selected`, `entity.member`, `entity.role`, `partner`.

### 2.3 Stack de Rotas Web
`auth` -> `verified` -> `entity.selected` -> checks de role/gate por rota.

## 3. Mapa de Rotas e Contextos

### 3.1 Volumetria Atual
1. Total de rotas registradas: **367**.
2. Principais blocos: `panel/setting`, `panel/manager`, `panel/patients`, `panel/financial`, `api/integrators`, `portal`.

### 3.2 Contextos
1. **`/panel`**: operação clínica e administrativa da entidade selecionada.
2. **`/panel/manager`**: operação SaaS (entidades, planos, assinaturas, parceiros, gateways, templates globais).
3. **`/api/integrators`**: API externa para integradores de exames.
4. **`/api/billing/webhooks/{gateway}`**: ingestão de eventos de cobrança.
5. **`/portal`**: portal autenticado para parceiros.

## 4. Domínios Funcionais Implementados

### 4.1 Clínico
1. Pacientes, médicos, agenda, lista de espera e eventos.
2. Agenda de recursos (salas/equipamentos) e agenda de médicos com bloqueios.
3. Prontuário, documentações, anexos, PDFs, ações rápidas, CID-10.

### 4.2 Compliance
1. Assinatura de prontuário (`Signable`) com bloqueio pós-assinatura.
2. Versionamento (`Versionable`) e snapshots em `record_versions`.
3. Auditoria (`Auditable`, `HasAuditColumns`) em operações críticas.
4. Logs de acesso a dados sensíveis (`LogsDataAccess`, `data_access_logs`).
5. LGPD: consentimentos, solicitações do titular, termos e aceite.

### 4.3 Financeiro e Faturamento
1. Fluxo de caixa (`financial_cash_entries`, categorias, relatórios).
2. Faturamento individual e em lote (`billing_claims`, `billing_batches`).
3. Exportações de XML/CSV e marcação de status de cobrança.

### 4.4 Billing Multi-Gateway
1. Registry + resolver de gateway por contexto.
2. Fallback rules + circuit breaker.
3. Processamento idempotente de webhooks.
4. Entidades de ciclo financeiro (`invoice`, `payment`, `payment_attempt`, `subscription_change`, `cancellation`, `webhook_event`, etc.).

### 4.5 TISS
1. Domínio dedicado em `app/Domains/Tiss`.
2. Workflows de lote/guia, geração XML, envio e retorno.
3. Jobs assíncronos (`GenerateTissBatchXmlJob`, `SendTissBatchJob`, `ProcessTissReturnJob`).
4. Builders por versão (`V202601`, `V202603`).

### 4.6 Growth/CAC
1. Referral (`referral_codes`, `referral_events`).
2. Partner funnel (`partners`, `partner_leads`, `partner_commissions`).
3. Activation score (`entity_activations`) por observer/evento.

### 4.7 API Integradores
1. Auth com Sanctum + middleware de token/plano/integrador.
2. Endpoints v1 para equipamentos, pacientes, exames, schedules e exam types.
3. Suporte a upload multipart de exames.

## 5. Camada de Serviços

### 5.1 Serviços Core
1. `SubscriptionService`, `TrialService`, `FeatureGateService`, `UsageMeterService`.
2. `PatientService`, `DoctorService`, `ScheduleService`, `MedicalRecordService`.
3. `ReportSettingService`, `TemplateVariableResolver`.
4. `MedicationPrescriptionService`, `ProcedureSolicitationService`, `SurgerySchedulingDocService`.
5. `PatientImportService`, `DayExtensionService`.

### 5.2 Serviços de Compliance
1. `MedicalRecordSignatureService`, `DataAccessLogService`, `AuditService`, `VersionService`.
2. `ConsentService`, `LgpdService`, `TermsService`.

### 5.3 Serviços de Billing/TISS
1. `BillingSubscriptionOrchestrator`, `ProcessWebhookEventService`, `WebhookIngestionService`.
2. `GatewayResolver`, `FallbackGatewayService`, `CircuitBreakerService`, `GatewayCredentialResolver`.
3. `TissWorkflowService` e serviços de transporte/retorno/xml.

## 6. Observers, Traits e Providers

### 6.1 Observers
1. `EntityObserver`: auto-start de trial em criação de entidade.
2. `ActivationObserver`: marcos de ativação por eventos de domínio.
3. `SubscriptionObserver`: efeitos de referral/parceiros na assinatura.

### 6.2 Traits Transversais
1. `Auditable`, `HasAuditColumns`, `Versionable`, `Signable`, `LogsDataAccess`, `HasEntityRoles`.

### 6.3 Service Providers
1. `AppServiceProvider`, `AuthServiceProvider`, `SubscriptionServiceProvider`.
2. `BillingServiceProvider` (gateways/circuit-breaker/default gateway).
3. `TissServiceProvider` (driver mock/http para transporte TISS).

## 7. Banco de Dados

### 7.1 Estratégia
1. Migrations orientadas a módulos.
2. UUID para entidades principais e códigos legíveis (`ENT-`, `PAC-`, etc.).
3. Soft delete em recursos administrativos e cadastros críticos.

### 7.2 Blocos de Tabelas
1. Core multi-tenant: entidades, usuários, vínculos e integrações.
2. Clínico: pacientes, médicos, agenda, exames, prontuário e documentação.
3. Compliance: audit logs, versões, data access, consentimentos, LGPD, termos.
4. Financeiro/Billing: claims/lotes, cash flow, gateways, invoices, payments, webhooks.
5. TISS: operadores, credenciais, guias, lotes, XML, retornos, glosas.
6. Growth: partners, referrals, leads, commissions, activation.

## 8. Frontend (Vue 3 + Inertia.js + Vite)

### 8.1 Estrutura
1. Entry-points Inertia: `resources/views/{app,panel-app,guest-app,portal-app}.blade.php`.
2. Layouts Vue: `resources/js/Layouts/{AppLayout,GuestLayout,PortalLayout}.vue`.
3. JS app em `resources/js/app.js` e páginas em `resources/js/Pages/*`.
4. SCSS modular em `resources/css/system/_*.scss` e `system.scss` como entry, junto com Tailwind.

### 8.2 Observações Técnicas
1. Utilização extensiva de Vue 3 Composition API (`<script setup>`).
2. Componentes compartilhados centralizados em `resources/js/Components/Panel/` (ex: TinyMceEditor, TablePagination).
3. Gerenciamento de formulários feito exclusivamente via `useForm` do `@inertiajs/vue3`.
4. Alpine.js, Yajra DataTables e jQuery (na lógica de negócio) foram removidos em prol do Inertia.

## 9. Qualidade, Testes e Operação

### 9.1 Testes
1. Stack de testes com Pest 4.
2. Cobertura relevante em ACL, auth, subscriptions, billing/webhook, TISS parser/XML, traits.

### 9.2 Code Quality
1. Laravel Pint para padronização.
2. Estrutura de requests/validators por contexto de domínio.

### 9.3 Execução Local
1. `composer dev` para servidor, queue listener, logs e Vite concorrentes.
2. Docker Compose com app/nginx/queue/redis.
3. Queue default em `database` no `.env.example`.

## 10. Riscos Técnicos Atuais
1. Transição recente para Vue/Inertia requer vigilância para garantir que componentes legados do Bootstrap não quebrem a reatividade.
2. Alta superfície de domínio (clínico + billing + TISS) exige disciplina de regressão em releases.
3. Multi-tenancy por sessão requer atenção especial em jobs, logs e autorizações fora de request web.
