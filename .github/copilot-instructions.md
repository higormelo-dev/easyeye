# Medicare — Copilot Instructions

Plataforma multi-tenant SaaS de oftalmologia. Laravel 12 + Blade + Alpine.js + Bootstrap 5.
Veja [CLAUDE.md](../CLAUDE.md) para arquitetura completa, tabelas, enums, observers e traits.

## Idioma e Localização

- Código (variáveis, métodos, classes): **inglês**
- UI (labels, mensagens, validação): **pt_BR** — usar `__()` / `trans()` com `lang/pt_BR.json`
- Commits e PR descriptions: português é aceito

## Build & Test

```bash
composer dev          # Server + Queue + Pail + Vite (concurrently)
npm run dev           # Vite dev server isolado
./vendor/bin/pint     # Fix code style (PSR-12 + Laravel preset)
./vendor/bin/pest     # Rodar todos os testes
php artisan migrate   # Migrations
php artisan db:seed   # Seeders (DataFakers só em local/testing)
```

Banco de testes: **PostgreSQL** (`medicare_test`), configurado em `phpunit.xml`.

## Código — Regras Críticas

### Controllers Magros

Toda business logic vai em `app/Services/`. Controllers apenas validam request, chamam service e retornam response/view.

### Multi-Tenancy via Sessão

- A entidade ativa vem da sessão (`selected_entity_id`). Nunca assumir entidade fixa.
- Toda query clínica deve ser scoped pela entidade selecionada.
- Middleware stack em `/panel/`: `auth → verified → entity.selected → entity.role → check.subscription`.

### Enums PHP 8.1

Usar backed enums de `app/Enums/` para status, tipos e gates. Nunca strings mágicas.

### Observers São Silenciosos

Observers em `app/Observers/` capturam `\Throwable` e logam sem propagar. Manter esse padrão — operação principal nunca falha por tracking.

### Singletons no Container

`FeatureGateService`, `AuditService`, `VersionService`, `ActivationService`, `ReferralService`, `PartnerService` são singletons. Injetar via construtor, nunca instanciar manualmente.

### Soft Deletes

`User`, `Entity`, `EntityUser`, `Partner`, integrations e equipamentos têm soft delete. Controllers incluem `restore()`. Respeitar o padrão.

### Códigos Sequenciais

Entidades (`ENT-`), pacientes (`PAC-`) e agendamentos (`SDL-`) recebem códigos auto-gerados em `booted()`. Não gerar manualmente.

### Compliance (CFM / LGPD)

- Prontuários assinados (`is_locked = true`) são imutáveis — `LockedMedicalRecordException`.
- Trait `Auditable` em quase todo model → `AuditService`. Não remover.
- Trait `LogsDataAccess` nos controllers clínicos → sempre chamar `logAccess()`.
- `ConsentService` para consentimento, `LgpdService` para direitos do titular.
- `TermsService` + middleware `terms.accepted` bloqueia painel sem aceite.

## Frontend

### Stack

Bootstrap 5 + Tailwind CSS + Alpine.js + jQuery (DataTables, plugins legacy).
Vite com ~38 entry points modulares (por página). Não criar bundles monolíticos.

### Alpine.js

- Componentes em `resources/js/components/` como factory functions.
- Registrados em `app.js` via `Alpine.data('nome', factory)`.
- Usados inline no Blade: `x-data="nome(params)"`.

### DataTables (Yajra)

- DataTable classes em `app/DataTables/` estendem `BaseDataTable`.
- jQuery global via `resources/js/jquery-global.js` + script síncrono no layout.
- Usar o padrão existente com `yajra/laravel-datatables`.

### Blade Components

Componentes reutilizáveis em `resources/views/components/` (modals, inputs, buttons).
Props-driven com `@props`. Slots para conteúdo flexível.

## API (Integradores)

- Auth via Sanctum token. Middleware: `token.precheck → auth:sanctum → auth_with_integrator`.
- Quota mensal via `UsageMeterService` + middleware `api.plan`.
- Rotas versionadas: `/api/integrators/v1/{resource}`.

## Testes

- **Pest 4** em `tests/Feature/` e `tests/Unit/`.
- Factories em `database/factories/` (15 factories).
- Áreas cobertas: ACL, Auth, API, Controllers, Subscriptions, Models, Enums.
- Rodar teste específico: `./vendor/bin/pest --filter "test name"`.
