# CLAUDE.md

Guia operacional para agentes (Claude/Codex) neste repositório.

## Contexto do Projeto

**EasyEye** é SaaS multi-tenant para oftalmologia, rodando em **Laravel 12** com UI em **Blade + Alpine.js + Vite**.

Foco atual sistema:
1. Operação clínica completa (pacientes, médicos, agenda, prontuário).
2. Compliance (CFM/LGPD) com trilha de auditoria.
3. Financeiro + faturamento + domínio TISS.
4. Billing multi-gateway e crescimento (parceiros/referral/ativação).

## Stack Atual

1. Backend: PHP 8.4 runtime, Laravel 12.x.
2. Frontend: Blade, Alpine.js, Bootstrap 5, Tailwind CSS, DataTables.
3. Banco: SQLite default local/test; suporte MySQL/MariaDB/PostgreSQL/SQL Server.
4. Filas/cache: queue default `database`; Redis disponível e usado em fluxos específicos.
5. Observabilidade: Sentry habilitado em `production` e `testing`.

## Comandos Comuns

```bash
# Desenvolvimento
composer dev          # server + queue + logs(pail) + vite
npm run dev
npm run build

# Banco
php artisan migrate
php artisan db:seed

# Rotas
php artisan route:list
php artisan route:list --json

# Qualidade
./vendor/bin/pint
./vendor/bin/pest
./vendor/bin/pest --filter "nome do teste"
```

## Arquitetura por Camadas

1. Controllers orquestram fluxo HTTP e delegam regra para services.
2. Services concentram regra de negócio e transações.
3. Models + Traits concentram persistência, auditoria, versionamento e assinatura.
4. Enums removem magic strings de status/roles/gates/feature keys.

Pasta-chave de negócio:
1. `app/Services`
2. `app/Domains/Tiss`
3. `app/Services/Billing`
4. `app/Http/Controllers/{Manager,Financial,Api,Partner,Setting}`

## Multi-Tenancy e ACL

### Tenant
1. Isolamento lógico por `entity_id`.
2. Entidade ativa vem da sessão (`selected_entity_id`).
3. Usuário pode participar de múltiplas entidades via `entity_users`.

### ACL
1. Gates em `AuthServiceProvider` usando `EntityGate`.
2. Roles SaaS em `SaasRule`; roles cliente em `ClientRule`.
3. Middleware principal:
- `entity.selected`
- `entity.member`
- `entity.role`
- `partner`
- `check.subscription`
- `feature`

## Mapa de Rotas

1. `routes/web.php`: painel principal (`/panel`), clínico, financeiro, compliance, settings.
2. `routes/manager.php`: painel SaaS manager (`/panel/manager`).
3. `routes/api.php`: integradores (`/api/integrators/*`) e webhook billing.
4. `routes/portal.php`: portal parceiro (`/portal/*`).
5. `routes/auth.php`: autenticação e seleção de entidade.

Estado atual: ~367 rotas registradas.

## Domínios Importantes

### Clínico
1. Pacientes, médicos, agenda, blocks, waiting list, schedule events.
2. Prontuário com documentação, anexos, PDFs e ações rápidas.
3. Catálogos clínicos configuráveis (`panel/setting/*`).

### Compliance
1. `Signable`: trava prontuário após assinatura.
2. `Versionable`: snapshot antes de update.
3. `Auditable` + `HasAuditColumns`: trilha CUD.
4. `LogsDataAccess`: rastreio de leitura sensível.
5. Tabelas: `audit_logs`, `record_versions`, `data_access_logs`, `patient_consents`, `lgpd_requests`, `term_versions`, `user_term_acceptances`.

### Financeiro/Billing
1. Fluxo de caixa, faturamento individual/lote, claims e relatórios.
2. Billing multi-gateway com fallback + circuit breaker + webhook idempotente.
3. Gateways suportados: Asaas, InfinitePay, Mercado Pago, Pagar.me, Stripe BR, PagBank.

### TISS
1. Domínio dedicado em `app/Domains/Tiss`.
2. Workflow de guia/lote, geração XML por versão, envio e processamento de retorno.
3. Jobs: `GenerateTissBatchXmlJob`, `SendTissBatchJob`, `ProcessTissReturnJob`.

### Growth
1. Referral codes/events.
2. Parceiros, leads, comissões.
3. Activation score por eventos de domínio.

## Providers e Observers

1. `SubscriptionServiceProvider`: observer de entidade + gates de assinatura/feature.
2. `BillingServiceProvider`: registro de gateways, default gateway e circuit breaker.
3. `TissServiceProvider`: transporte mock/http para TISS.
4. Observers ativos:
- `EntityObserver`
- `ActivationObserver`
- `SubscriptionObserver`

## Frontend e Padrões UI

1. Layout principal: `resources/views/layouts/app.blade.php`.
2. Alpine components registrados em `resources/js/app.js`.
3. Vendor JS em `resources/js/vendor.js` (ordem importa para DataTables/plugins).
4. DataTables server-side com Yajra em múltiplos módulos (`app/DataTables/*`).

## Convenções de Implementação

1. Evitar lógica de negócio complexa em controller.
2. Em fluxos críticos, usar transação explícita no service.
3. Preservar `entity_id` em queries e policies.
4. Usar enums/gates existentes antes de criar novos status/strings.
5. Em mudanças de prontuário/compliance, validar impacto em assinatura/versionamento/auditoria.

## Testes Relevantes para Regressão

Executar ao mexer em ACL/subscription/billing/TISS/compliance:

```bash
./vendor/bin/pest tests/Feature/ACL
./vendor/bin/pest tests/Feature/Subscriptions
./vendor/bin/pest tests/Feature/Billing
./vendor/bin/pest tests/Unit/Tiss
./vendor/bin/pest tests/Feature/AuditLogTest.php tests/Feature/RecordVersionTest.php
```

## Pontos de Atenção

1. `route:list --columns` não existe no Laravel 12 deste projeto.
2. Muitas telas dependem de DataTables + jQuery global; cuidado ao alterar ordem de scripts.
3. Multi-tenancy por sessão: jobs/CLI precisam resolver entidade explicitamente quando necessário.
4. Ajustes em billing podem afetar webhook idempotency e retry/circuit breaker.
5. Ajustes em templates clínicos afetam emissão de documentação e histórico de versões.
