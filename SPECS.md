# Technical Specifications Document (SPECS)
**Produto:** EasyEye (Medicare)
**Arquitetura:** Laravel 11 (PHP 8.4) + React 19 + Inertia.js + Integrador Java Local

---

## 1. Stack Tecnológico e Infraestrutura
- **Backend Framework:** Laravel 11.x
- **Linguagem:** PHP 8.4
- **Banco de Dados (Dev):** SQLite (Suporte robusto a MySQL/PostgreSQL para Prod).
- **Cache, Sessões e Filas:** Redis
- **Frontend:** React 19, Inertia.js (v3), Vite, Bootstrap 5, template Preclinic. **100% React/Inertia** — Phase 4 concluída em 2026-03-31. 18 pacotes legados removidos (jQuery ×4, DataTables ×4, Alpine.js, morris.js, raphael, Gulp ×5, Tailwind CSS pipeline ×4). Blade residual: apenas `app.blade.php` (Inertia root) e `welcome.blade.php` (landing pública).
- **Build:** Vite com 2 entradas — `resources/css/app.css` + `resources/js/app.jsx` (reduzido de 23 entradas na Phase 4). Ver mapa completo antes/depois em `MIGRATION_PLAN.md` §9.
- **Serviço de Background:** Laravel Queue Workers nativos para assincronicidade (envio de e-mails, comissões de parceiros, webhooks).

## 2. Arquitetura de Multi-Tenancy
O sistema opera através de **Session-based Multi-tenancy** garantindo total isolamento visual e operacional.
1. O banco guarda todos os dados numa mesma estrutura, porém cada tabela-chave (Patients, Schedules, Exams) possui a coluna `entity_id`.
2. A entidade controladora do SaaS em si (`ENT-0000000001`) tem regras de bypass (Manager routes).
3. O Middleware `EnsureEntitySelected` intercepta o request, injeta a entidade logada na sessão e obriga que as buscas via repositório/models (nos Services) venham com escopo (Tenant Guard).

## 3. Padrões de Design de Código

### Backend
- **Service Layer Pattern:** Nenhum controlador ("Thin Controller") detém lógica de negócios. Todos os fluxos pesados ficam dentro de `app/Services/` (Ex: `PatientExamService::createFromScheduleIdentifier()`).
- **Repositories (via Model queries):** O Eloquent ORM é manipulado estritamente pelas classes de Service para acesso e persistência.
- **Observer Pattern Silencioso:** Acoplamento fraco para efeitos colaterais. Auditorias (AuditContext), Criação de Trial Automático e Tracking de Ativação (CAC/Growth) operam via Observers do Eloquent (`creating/created`). Exceções não críticas nos observers são controladas (Logs de erro silenciados) para não quebrar a transação de negócio primária.
- **Action/Job Pattern:** Ações assíncronas encapsuladas sob Jobs quando necessário.

### Frontend Inertia (padrão único)
- **Frontend Inertia Pattern:** Todos os controllers do painel retornam `Inertia::render('Module/Page', [props])`. Shared data global via `HandleInertiaRequests::share()` injeta `auth`, `entity`, `flash`, `isClient`, `userRule`, `locale`, `appName` em todas as páginas React.
- **BaseSettingController Pattern:** Controlador abstrato em `App\Http\Controllers\Setting\` que centraliza a lógica Inertia para todos os 12 tipos de configuração. Cada subclasse declara apenas `$inertiaPage` (nome da página React) e `$resourceClass` (API Resource). O método `index()` renderiza `Settings/{$inertiaPage}` com todos os registros da entidade.
- **Auth Pages Pattern:** Todos os controllers de Auth (`AuthenticatedSessionController`, `RegisteredUserController`, `PasswordResetLinkController`, `NewPasswordController`, `AuthenticatedEntityController`) retornam `Inertia::render('Auth/PageName')`. `RegisteredUserController::store()` usa dual-path: `wantsJson()` → JSON `{redirect}` (compatibilidade com testes); browser/Inertia → `redirect()`.
- **Settings SettingsCrud Pattern:** `SettingsCrud.jsx` é o componente genérico para todos os settings — lista registros, abre modal inline para criar/editar (só campos `name` e `active`), confirm dialog para excluir/restaurar. Para settings com campos extras (`Covenants`, `SurgeryTypes`, `Resources`), os FormRequests aplicam defaults via `prepareForValidation()`.
- **Profile Update Pattern:** `ProfileController::update()` aceita PATCH via method-spoofing (`_method: 'patch'` no FormData do `useForm`) para suportar upload de foto. `PasswordController::update()` usa `validate()` simples (não `validateWithBag`) e redireciona para `panel.profile.edit` com `flash.success`.
- **Reports Filter Pattern:** Relatórios enviam filtros via `router.get()` do Inertia (GET params, `preserveState: true`). Controller retorna `results: null` sem filtros ativos; retorna array serializado quando filtrado. Filtro de médico é ocultado pelo frontend quando `userRule === 'doctor'`.
- **Subscription Expired Pattern:** `SubscriptionExpiredController` serializa `FeatureKey::cases()` chamando `label()` e `isBoolean()` no servidor, enviando planos como array JSON puro ao React. Redireciona para dashboard se assinatura ativa.
- **Global UX Components:** `Toast.jsx` e `ConfirmDialog.jsx` padronizam notificações e confirmações. `CardGrid.jsx` e `DataTable.jsx` padronizam listagens.

### Convenção de flash em redirects
```php
// ✅ Correto — lido pelo Toast.jsx e FlashMessages.jsx
return redirect()->route('...')->with('success', $message);
return redirect()->route('...')->with('error', $message);
return redirect()->route('...')->with('warning', $message);

// ✅ Reservado — ForgotPassword (link enviado)
return back()->with('status', 'verification-link-sent');
```

## 4. Integração Cloud <-> Hardware (Arquitetura)
O ponto nevrálgico técnico da aplicação é a API de integração (`/api/integrators/*`) que conversa com o Desktop App em Java alocado na rede local da clínica.
1. **Autenticação Segura API (Machine-to-Machine):** Baseada em Laravel Sanctum (`PersonalAccessToken`). O device loga com email, senha e um HWID (código do integrador) recebendo um Bearer Token com *abilities* específicas (`integrator_id:X`). O token é estendido automaticamente (`check-token`) se estiver próximo de vencer.
2. **Rate Limiting Comercial:** O `FeatureGateService` inspeciona quotas da conta (ex: plano permite 500 exames locais subindo para as nuvens). Se a cota acabar, estorna um HTTP 403.
3. **Payload Mapping Inteligente Automático:**
   * O App Java captura um JPEG de equipamento: `PAC-000002-od-2026.jpeg`.
   * Envia multipart para a API (`POST /exams`).
   * **Fluxo de Binding (Service Lógico):** Se enviado "PAC-0000000002" como `patient_identifier`, o backend faz match com aquele paciente dentro do tenant atual, procura agressivamente pelo agendamento mais recente daquele paciente com o dia vigente, e os linca (vinculando automaticamente `schedule_id` e `doctor_id`). Caso não ache agenda, salva apenas linkado ao perfil clínico global do PAC.

## 5. Compliance, LGPD e Resoluções CFM
A infraestrutura garante imunidade a nível jurídico no trato de PHI (Protected Health Information).
- **Imutabilidade e Snapshots (Trait Versionable):** Qualquer edição sensível num Prontuário (`MedicalRecord`) clona o registro integral anterior em `record_versions`.
- **Assinatura Eletrônica CFM Válida:** O `MedicalRecord` possui o Trait `Signable`. Ao assinar, aplica-se uma macro-função que converte os dados do registro em string, realiza HMAC SHA-256 (`signature_hash`) usando a chave privada da env local ou token do user e tranca o model (`is_locked = true`). Atualizar o model dispara exceções rígidas.
- **Trait LogsDataAccess:** Instanciado no backend de relatórios e acessos, toda query que expõe dados sensíveis injeta e persiste uma linha em `data_access_logs` gravando quem visualizou, a que horas e finalidade da leitura.

## 6. Base de Dados: Estrutura Resumida
- **Auth/Tenant:** `users`, `entities` (Clínicas), `entity_users` (Pivot Papéis).
- **Core Clínico:** `patients`, `people`, `schedules` (Agendamentos), `medical_records` (Prontuários).
- **Integração:** `entity_integrators` (Softwares Logados), `entity_integrator_equipments` (Máquinas Físicas - IPs/MACs), `patient_exams` (Uploads).
- **Growth/CAC:** `partners`, `referral_codes`, `entity_activations`.
- **Compliance:** `data_access_logs`, `patient_consents`, `term_versions`.

## 7. Arquitetura Frontend React/Inertia (Phase 4 — Estado Atual)

### Estrutura de diretórios React (37 páginas | 0 Blade no painel)
```
resources/js/
├── app.jsx                          ← Entry point Inertia (resolvePageComponent)
├── Layouts/
│   ├── AuthenticatedLayout.jsx      ← Sidebar + Header (menu condicional por isClient)
│   └── GuestLayout.jsx              ← Wrapper auth (logo + card centralizado)
├── Components/
│   └── UI/
│       ├── SettingsCrud.jsx         ← CRUD genérico com modal inline
│       ├── Toast.jsx                ← Notificações flash automáticas
│       ├── FlashMessages.jsx        ← Flash inline em páginas
│       ├── ConfirmDialog.jsx        ← Confirmação de exclusão
│       ├── CardGrid.jsx             ← Listagem em cards com paginação AJAX
│       ├── DataTable.jsx            ← Tabela server-side (Manager)
│       ├── Modal.jsx, Badge.jsx, Pagination.jsx
│       ├── PageHeader.jsx, FormInput.jsx, PersonForm.jsx
├── Pages/
│   ├── Auth/                        ← Login, Register, ForgotPassword, ResetPassword, SelectEntity (5)
│   ├── Dashboard/Index.jsx          (1)
│   ├── Settings/                    ← SkinTypes, IrisTypes, VisitTypes, AdditionTypes,
│   │                                   ColorVisionTypes, CoverTestTypes, VisualAcuityTypes,
│   │                                   SurgeryTypes, Lenses, NearPointConvergences,
│   │                                   Covenants, Resources (12)
│   ├── Doctors/                     ← Index.jsx, Show.jsx, WorkSchedule.jsx (3)
│   ├── Patients/                    ← Index.jsx, Show.jsx (2)
│   ├── Users/                       ← Index.jsx, Show.jsx (2)
│   ├── Schedules/                   ← Index.jsx, Show.jsx (2)
│   ├── MedicalRecords/              ← Index.jsx, Create.jsx (2)
│   ├── Reports/                     ← Index.jsx, Schedules.jsx, Absenteeism.jsx (3) ← Phase 4
│   ├── Profile/                     ← Edit.jsx (1) ← Phase 4
│   ├── Subscription/                ← Expired.jsx (1) ← Phase 4
│   └── Manager/                     ← Dashboard.jsx, Entities/Index.jsx, Entities/Show.jsx,
│                                       Subscriptions/Index.jsx, Plans/Index.jsx (5)
```

### Shared props (HandleInertiaRequests)
| Prop | Tipo | Fonte | Uso |
|------|------|-------|-----|
| `auth.user` | `{id, name, email}` | `$request->user()` | Avatar, saudação, forms de perfil |
| `entity` | `{id, name, code}` ou `null` | `session('entity')` | Cabeçalho da clínica |
| `flash.success` | `string\|null` | `session('success')` | Toast verde pós-action |
| `flash.error` | `string\|null` | `session('error')` | Toast vermelho |
| `flash.warning` | `string\|null` | `session('warning')` | Toast amarelo |
| `flash.status` | `string\|null` | `session('status')` | ForgotPassword: link enviado |
| `isClient` | `bool` | `session('selected_entity_is_client')` | Menu condicional sidebar |
| `userRule` | `string` | `session('selected_entity_user_rule')` | RBAC no frontend (ex: ocultar filtros) |
| `locale` | `string` | `app()->getLocale()` | i18n |
| `appName` | `string` | `config('app.name')` | Títulos de página |

### Convenção de useForm com upload de arquivo
```jsx
// ✅ Correto para PATCH com arquivo (method spoofing via FormData)
const form = useForm({ _method: 'patch', name: '', email: '', photo: null });
form.post('/panel/profile', { forceFormData: true });

// ✅ Correto para PUT sem arquivo
const form = useForm({ current_password: '', password: '', password_confirmation: '' });
form.put('/auth/password');
```

### Convenção de filtros nos relatórios
```jsx
// ✅ Aplicar filtros via Inertia GET (mantém estado)
router.get('/panel/reports/schedules', params, { preserveState: true });

// ✅ Limpar filtros
router.get('/panel/reports/schedules', {});
```

## 8. Próximos Passos de Arquitetura (Phase 5 em diante)
- **Phase 5 — Apps Mobile (React Native / Expo):** App do Médico e App do Paciente em monorepo Turborepo. Pacote `@easyeye/api` compartilhado entre web e mobile para chamadas Axios ao backend Laravel.
- **Object Storage (S3/R2):** Atualizar o Filesystem do `PatientExamService` para garantir que blobs de imagens grandes (ex: OCT de retina) vão para provedores focados em *Cold Storage*.
- **WebSocket (Pusher/Reverb):** Emissão de eventos em tempo real para as estações da recepção (Broadcasting) avisando quando o integrador fez push de um exame, ou na atualização de fila.
- **LLM API Clients:** Classes dedicadas para wrap das Requests ao OpenAI (ou Claude) nas features de resumo de prontuário e speech-to-text.
