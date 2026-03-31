# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

**Medicare** is a multi-tenant SaaS platform for managing ophthalmology clinics, built with Laravel 11 + Blade + Alpine.js.

## Common Commands

```bash
# Development
composer dev          # Start all dev services (server, queue, logs, vite) concurrently
npm run dev           # Vite dev server only
npm run build         # Production asset build

# Database
php artisan migrate
php artisan db:seed

# Code quality
./vendor/bin/pint     # Fix code style (Laravel Pint / PSR-12 + Laravel preset)
./vendor/bin/pest     # Run all tests
./vendor/bin/pest tests/Feature/SubscriptionTest.php  # Run single test file
./vendor/bin/pest --filter "test name"                # Run single test by name
```

## Architecture

### Multi-Tenancy Model

The platform uses a **session-based multi-tenancy** pattern:
- One SaaS owner entity (`ENT-0000000001`) manages all client entities
- Users belong to one or more entities via `entity_users` pivot (with roles)
- The active entity is stored in the session after entity selection
- `EnsureEntitySelected` middleware gates all `/panel/` routes

Key entity-scoped middleware stack: `auth → verified → EnsureEntitySelected → EnsureEntityRole`

### Route Groups

| Prefix | File | Purpose |
|--------|------|---------|
| `/panel/` | `web.php` | Authenticated clinic management |
| `/panel/manager/` | `manager.php` | SaaS owner admin |
| `/api/integrators/` | `api.php` | External equipment integrator API |
| `/tv/` | `web.php` | Waiting room TV display (public) |
| `/auth/` | `auth.php` | Authentication |

### Service Layer

All business logic lives in `app/Services/`. Controllers are thin — they delegate to services:

**Core**
- `SubscriptionService` — Trial/active/expired subscription lifecycle
- `FeatureGateService` — Plan-based feature access (singleton, checks `subscription_settings`)
- `EntityRoleService` / `EntityUserService` — RBAC within entities
- `PatientService`, `ScheduleService` — Core clinical domain logic
- `UsageMeterService` — Track API usage quotas per billing period
- `TrialService` — Trial creation and expiry handling

**CFM / LGPD Compliance**
- `MedicalRecordSignatureService` — Assinatura eletrônica de prontuários (CFM Res. 2.227/2018); `sign()`, `canEdit()`, `verifyIntegrity()`
- `DataAccessLogService` — Registra toda leitura de dados sensíveis; chamado via trait `LogsDataAccess` nos controllers
- `ConsentService` — Coleta, revogação e validação de consentimento do paciente (LGPD Art. 7/11); `grant()`, `revoke()`, `grantRequired()`
- `LgpdService` — Solicitações de direitos do titular (LGPD Art. 18); `openRequest()`, `complete()`, `exportPatientData()`
- `TermsService` — Versionamento e aceite de Política de Privacidade / Termos de Uso (LGPD Art. 8)

**CAC (Custo de Aquisição de Clientes)**
- `ReferralService` — Programa de indicação peer-to-peer; `generate()`, `recordTrialStarted()`, `recordConversion()`
- `PartnerService` — Programa de parceiros/revendedores; `create()`, `registerLead()`, `generateCommission()`, `metrics()`
- `ActivationService` — Rastreamento de marcos de ativação do trial; `complete()`, `getScore()`, `getProgress()`, `lowActivationTrials()`

### Key Enums

State management uses PHP 8.1 backed enums in `app/Enums/`:

**Core**
- `SubscriptionStatus` — `Trial`, `Active`, `Cancelled`, `Expired`
- `ScheduleSituation` — `Scheduled`, `InProgress`, `Completed`, `Cancelled`
- `FeatureKey` — Feature flag identifiers for plan gating
- `EntityGate` — Permission gate identifiers
- `BillingCycle` — `Monthly`, `Yearly`, `Lifetime`
- `ExamCategory` — Exam classification types

**CFM / LGPD**
- `ConsentType` — `DataCollection`, `SensitiveData`, `DataSharing`, `MinorGuardian`, `ImageAndVoice`, `ResearchParticipation`
- `LegalBasis` — Base legal LGPD para cada operação de tratamento (`Consent`, `HealthProtection`, `LegalObligation`…)
- `LgpdRequestType` — 8 tipos de direitos do titular (Art. 18): `Access`, `Correction`, `Deletion`, `Portability`…
- `LgpdRequestStatus` — `Pending`, `InProgress`, `Completed`, `Rejected`, `Cancelled`
- `DataAccessPurpose` — Finalidade do acesso a dados sensíveis (`PatientCare`, `EmergencyAccess`…)

**CAC**
- `ActivationStep` — 7 marcos de ativação do trial com pesos (soma=100): `FirstDoctorAdded`, `FirstPatientAdded`, `FirstScheduleCreated`…
- `ReferralRewardType` — `TrialExtension`, `DiscountPercentage`
- `ReferralEventType` — `TrialStarted`, `PlanActivated`
- `PartnerType` — `Distributor`, `Association`, `Consultant`, `Agency` (cada um com `defaultCommissionRate()`)
- `PartnerLeadStatus` — `New`, `Contacted`, `Trial`, `Converted`, `Lost`
- `CommissionStatus` — `Pending`, `Paid`, `Cancelled`

### Observers (`app/Observers/`)

| Observer | Modelos Observados | Responsabilidade |
|---|---|---|
| `EntityObserver` | `Entity::created` | Inicia trial automático via `TrialService` |
| `ActivationObserver` | `Doctor`, `Patient`, `Schedule`, `MedicalRecord`, `EntityUser`, `EntityIntegrator`, `Entity` | Registra marcos de ativação via `ActivationService` |
| `SubscriptionObserver` | `Subscription::created/updated` | Dispara evento de indicação no trial; gera comissão de parceiro e aplica reward de indicação na conversão |

Todos os observers são registrados em `AppServiceProvider::boot()`.

### Traits (`app/Traits/`)

| Trait | Aplicação | Comportamento |
|---|---|---|
| `Auditable` | Maioria dos models | Hook `created/updated/deleted/restored` → `AuditService` |
| `HasAuditColumns` | Models com `created_by/updated_by/deleted_by` | Auto-preenche a partir do `AuditContext` |
| `Versionable` | `MedicalRecord` | Snapshot completo antes de cada `update` → `record_versions` |
| `Signable` | `MedicalRecord` | Assinatura eletrônica CFM: gera hash SHA-256, bloqueia edição após assinatura (`is_locked`). Lança `LockedMedicalRecordException` em `updating`/`deleting` |
| `LogsDataAccess` | Controllers clínicos | Adiciona `$this->logAccess($model, DataAccessPurpose::PatientCare)` para registrar leitura de dados sensíveis |

### Middleware (`app/Http/Middleware/`)

| Alias | Classe | Função |
|---|---|---|
| `entity.selected` | `EnsureEntitySelected` | Garante entidade na sessão |
| `entity.member` | `EnsureUserBelongsToEntity` | Valida acesso do usuário à entidade |
| `entity.role` | `EnsureEntityRole` | Verifica papel do usuário |
| `check.subscription` | `CheckSubscription` | Bloqueia acesso sem assinatura ativa |
| `feature` | `CheckFeature` | Valida acesso a feature pelo plano |
| `terms.accepted` | `RequireTermsAcceptance` | Bloqueia painel enquanto há documentos legais pendentes de aceite (LGPD Art. 8) |
| `auth_with_integrator` | `ApiAuthenticateWithIntegrator` | Auth via Sanctum token para integradores |

### CFM / LGPD — Resumo das tabelas

| Tabela | Finalidade | Base Legal |
|---|---|---|
| `audit_logs` | Histórico de CREATE/UPDATE/DELETE em todos os models | CFM + LGPD Art. 37 |
| `record_versions` | Snapshots imutáveis do `MedicalRecord` antes de edições | CFM Res. 2.227/2018 |
| `data_access_logs` | Log de leitura de dados sensíveis (prontuários, exames) | CFM Res. 2.227/2018 + LGPD Art. 37 |
| `medical_records` (campos de assinatura) | `signed_by`, `signed_at`, `signature_hash`, `is_locked` | CFM Res. 2.227/2018 |
| `patient_consents` | Consentimentos coletados com canal, versão do documento e base legal | LGPD Art. 7/8/11 |
| `lgpd_requests` | Solicitações de direitos do titular com prazo automático de 15 dias | LGPD Art. 18/23 |
| `term_versions` | Versões de Política de Privacidade e Termos de Uso | LGPD Art. 8 |
| `user_term_acceptances` | Prova auditável de aceite por usuário: IP, user agent, timestamp, versão | LGPD Art. 8 §5 |

### CAC — Resumo das tabelas

| Tabela | Finalidade |
|---|---|
| `partners` | Parceiros/revendedores externos com token de atribuição UTM |
| `partner_leads` | Leads trazidos por parceiros com funil de status |
| `partner_commissions` | Comissões geradas automaticamente na conversão de plano |
| `referral_codes` | Códigos de indicação gerados por clínicas ativas |
| `referral_events` | Funil de indicação: `trial_started` → `plan_activated` |
| `entity_activations` | Marcos de ativação do trial por clínica (score 0–100) |
| `entities.partner_id` | Atribuição de canal: qual parceiro trouxe esta clínica |
| `entities.referral_code_id` | Atribuição de indicação: qual código foi usado no cadastro |

### Code Patterns

**Auto-generated codes**: Entities, patients, and schedules get sequential human-readable codes (`ENT-0000000001`, `PAC-0000000001`, `SDL-0000000001`) via model `booted()` hooks.

**Presenter pattern**: `SchedulePresenter` and `PatientPresenter` (via `laracasts/presenter`) handle display formatting in views.

**Soft deletes**: `User`, `Entity`, `EntityUser`, `Partner`, integrations, and equipment support soft delete + restore. Controllers include `restore()` methods and routes like `/setting/{resource}/restore`.

**Field normalization**: Names and addresses are auto-uppercased; phone/registration fields strip non-numeric characters.

**API authentication**: External integrators use Sanctum tokens (`ApiAuthenticateWithIntegrator` middleware) with monthly quota limits tracked via `UsageMeterService`.

**Audit context**: `AuditContext::userId()` resolves the authenticated user for audit columns. Used by `AuditService`, `VersionService`, and `HasAuditColumns` trait.

**Idempotência nos observers**: `EntityActivation` usa `firstOrCreate` — chamar `complete()` múltiplas vezes para o mesmo step é seguro. `ReferralEvent` também tem unique constraint por código+clínica+tipo.

**Observer silencioso**: Todos os observers de compliance e CAC capturam `\Throwable` e fazem `Log::warning/error` sem propagar a exceção — a operação principal nunca é bloqueada por um erro de rastreamento.

### Frontend Stack

- **Blade** templates with **Alpine.js** for reactivity
- **Bootstrap 5** + **Tailwind CSS** (both present)
- **Vite** bundles: `resources/js/vendor.js` (jQuery + libs), `resources/js/app.js` (global), plus per-module files in `resources/js/system/`
- Key JS libraries: DataTables, FlatPickr, SweetAlert2, jQuery toast, QRCode (TV pairing)

### Testing

Tests use **Pest 4** in `tests/Feature/` and `tests/Unit/`. Key test areas: subscriptions, ACL/roles, feature gates, usage metering, API integrator flows, exam limits, TV pairing.

## Environment

Default locale is `pt_BR` (Brazilian Portuguese). Default DB is SQLite. The `.env.example` documents all required variables.

Docker setup (`docker-compose.yml`): PHP 8.4 FPM + Nginx (port 8080) + Redis.
