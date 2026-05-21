# CLAUDE.md

Guia operacional para agentes (Claude/Codex) neste repositório.

## Contexto do Projeto

**EasyEye** é SaaS multi-tenant para oftalmologia, rodando em **Laravel 12** com UI em **Vue 3 + Inertia.js + Vite**.

Foco atual sistema:
1. Operação clínica completa (pacientes, médicos, agenda, prontuário).
2. Compliance (CFM/LGPD) com trilha de auditoria.
3. Financeiro + faturamento + domínio TISS.
4. Billing multi-gateway e crescimento (parceiros/referral/ativação).

## Stack Atual

1. Backend: PHP 8.4 runtime, Laravel 12.x.
2. Frontend: **Vue 3 (Composition API + `<script setup>`) + Inertia.js + Bootstrap 5 + Tailwind CSS**.
3. Blade só sobrevive como entry-point Inertia (`app/panel-app/guest-app/portal-app`) e templates server-side de PDF (`resources/views/pdf/`).
4. Banco: PostgreSQL via `host.docker.internal` (rodando no host); suporte SQLite/MySQL/MariaDB/SQL Server.
5. Filas/cache: queue default `database`; Redis em container `easyeye_redis`.
6. Observabilidade: Sentry habilitado em `production` e `testing`.
7. Rich-text editor: TinyMCE 8 via wrapper Vue `Components/Panel/TinyMceEditor.vue`.

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

1. Entry-points Inertia: `resources/views/{app,panel-app,guest-app,portal-app}.blade.php`. Cada controller usa `Inertia::render()` apontando para `resources/js/Pages/...`.
2. Layouts Vue: `resources/js/Layouts/{AppLayout,GuestLayout,PortalLayout}.vue`.
3. Páginas por área: `resources/js/Pages/{Site,Auth,Panel,Portal}/...`.
4. Componentes compartilhados: `resources/js/Components/Panel/...` (PageHeader, ActionIconButton, OffcanvasPanel, TinyMceEditor, TablePagination, etc.).
5. Estado de formulário: sempre `useForm` do `@inertiajs/vue3`. Multipart precisa `forceFormData: true`.
6. Navegação: `<Link>` para SPA, `router.get/post` para programático, `window.location` apenas para asset/PDF externo.
7. Bundles Vite: `vendor.js` (jQuery + Bootstrap plugins legados), `panel.js` (Inertia app painel), `site.js` (landing + auth + portal).
8. Rich text: usar `<TinyMceEditor v-model="content" />` — wrapper Vue do TinyMCE 8.
9. SCSS modular em `resources/css/system/_*.scss` e `system.scss` como entry.
10. **Não usar Alpine.js, Yajra DataTables ou Blade fora de PDFs/entry-points** — removidos do projeto.

## Convenções de Implementação

1. Evitar lógica de negócio complexa em controller.
2. Em fluxos críticos, usar transação explícita no service.
3. Preservar `entity_id` em queries e policies.
4. Usar enums/gates existentes antes de criar novos status/strings.
5. Em mudanças de prontuário/compliance, validar impacto em assinatura/versionamento/auditoria.
6. Toda nova tela do painel: `Inertia::render('Panel/<Modulo>/<Tela>', $props)` + página Vue equivalente.

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
2. Docker via Snap tem bind-mount estagnado: edits no host podem não propagar para o container. Fix: `docker compose rm -f -s app node ssr && docker compose up -d`. Migrar para Docker Engine nativo resolve em definitivo.
3. Multi-tenancy por sessão: jobs/CLI precisam resolver entidade explicitamente quando necessário.
4. Ajustes em billing podem afetar webhook idempotency e retry/circuit breaker.
5. Ajustes em templates clínicos afetam emissão de documentação e histórico de versões.
6. PostgreSQL aceita conexões da subnet Docker `172.16.0.0/12` (`pg_hba.conf`). Mudanças na rede podem exigir liberação adicional.
7. Redis em container (`easyeye_redis`) — `.env` deve apontar `REDIS_HOST=redis`.
