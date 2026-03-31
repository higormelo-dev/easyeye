# 🚀 Plano de Migração: Blade → React (Inertia.js)
**Projeto:** EasyEye (Medicare)
**De:** Laravel 11 + Blade + Alpine.js + jQuery + Bootstrap 5
**Para:** Laravel 11 + React 19 + Inertia.js + Preclinic Template
**Estimativa:** 8–12 semanas (trabalho incremental, sistema nunca para)

---

## Índice
1. [Por que Migrar](#1-por-que-migrar)
2. [Estratégia: Migração Incremental (Híbrida)](#2-estratégia-migração-incremental-híbrida)
3. [Fase 0 — Preparação do Ambiente](#3-fase-0--preparação-do-ambiente)
4. [Fase 1 — Infraestrutura Inertia + React](#4-fase-1--infraestrutura-inertia--react)
5. [Fase 2 — Importar o Template Preclinic React](#5-fase-2--importar-o-template-preclinic-react)
6. [Fase 3 — Migrar Telas (Ordem de Prioridade)](#6-fase-3--migrar-telas-ordem-de-prioridade)
7. [Fase 4 — Eliminar Dependências Legadas](#7-fase-4--eliminar-dependências-legadas)
8. [Fase 5 — Apps Mobile (React Native / Expo)](#8-fase-5--apps-mobile-react-native--expo)
9. [Mapa de Dependências (Antes vs Depois)](#9-mapa-de-dependências-antes-vs-depois)
10. [Riscos e Mitigações](#10-riscos-e-mitigações)

---

## 1. Por que Migrar

| Critério | Blade + Alpine + jQuery | React + Inertia |
|---|---|---|
| Reatividade | Limitada (Alpine é leve mas sem estado global) | Total (useState, useContext, Zustand) |
| Reutilização de código | Baixíssima (cada .blade.php é isolado) | Altíssima (componentes importáveis) |
| Ecossistema Mobile | Nenhum | React Native compartilha lógica |
| Bundle Size | Pesado (jQuery + DataTables + múltiplos plugins) | Leve (React + componentes sob demanda) |
| DX (Developer Experience) | Lento (F5 manual, sem HMR real no Blade) | Instantâneo (Vite HMR, Fast Refresh) |
| Contratação de Devs | Difícil (Blade + Alpine é nicho) | Fácil (React é a skill #1 do mercado) |

---

## 2. Estratégia: Migração Incremental (Híbrida)

> **Regra de Ouro:** O sistema NUNCA para. Não existe um "big bang" onde tudo muda de uma vez.

O Laravel + Inertia suporta **modo híbrido**: rotas Blade e rotas Inertia/React coexistem no mesmo projeto. Isso significa que você pode migrar **1 tela por semana** enquanto o restante do sistema continua funcionando em Blade normalmente.

**Fluxo de coexistência:**
```
Rota /panel/patients       → Inertia::render('Patients/Index')    ← Nova (React)
Rota /panel/schedules      → view('system.schedules.index')       ← Antiga (Blade) - migra depois
Rota /panel/doctors        → view('system.doctors.index')         ← Antiga (Blade) - migra depois
```

---

## 3. Fase 0 — Preparação do Ambiente
**Duração:** 1 dia
**Objetivo:** Criar uma branch de migração e garantir backup.

### Tarefas
- [ ] Criar branch `feature/react-migration` a partir de `desenv`
- [ ] Fazer backup completo do banco de dados de produção
- [ ] Documentar todas as rotas web existentes:
  ```bash
  php artisan route:list --path=panel --columns=method,uri,name,action > routes_snapshot.txt
  ```

---

## 4. Fase 1 — Infraestrutura Inertia + React
**Duração:** 2–3 dias
**Objetivo:** Instalar Inertia.js, React e configurar o Vite para compilar JSX.

### 1.1 Instalar dependências do Backend
```bash
composer require inertiajs/inertia-laravel
```

### 1.2 Publicar o middleware do Inertia
```bash
php artisan inertia:middleware
```
Depois, registrar o middleware `HandleInertiaRequests` no `bootstrap/app.php`:
```php
->withMiddleware(function (Middleware $middleware) {
    $middleware->web(append: [
        \App\Http\Middleware\HandleInertiaRequests::class,
    ]);
})
```

### 1.3 Criar o template raiz do Inertia
Criar o arquivo `resources/views/app.blade.php` (Inertia root template):
```blade
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title inertia>{{ config('app.name', 'EasyEye') }}</title>
    @viteReactRefresh
    @vite(['resources/css/app.css', 'resources/js/app.jsx'])
    @inertiaHead
</head>
<body>
    @inertia
</body>
</html>
```

> **IMPORTANTE:** Este arquivo é **separado** do `resources/views/layouts/app.blade.php` atual. O Blade layout existente continua funcionando para as rotas legadas. O Inertia usa este novo `app.blade.php` na raiz de `resources/views/`.

### 1.4 Instalar dependências do Frontend
```bash
npm install react react-dom @inertiajs/react @vitejs/plugin-react
```

### 1.5 Atualizar o `vite.config.js`
```js
import path from 'path';
import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import react from '@vitejs/plugin-react';

export default defineConfig({
    plugins: [
        laravel({
            input: [
                // === Legado (Blade) — manter até migração completa ===
                'resources/css/vendor.css',
                'resources/css/app.css',
                'resources/css/dashboard.css',
                'resources/js/vendor.js',
                'resources/js/app.js',
                'resources/js/system/auxiliary_functions.js',
                'resources/js/auth/login.js',
                'resources/js/auth/register.js',
                'resources/js/auth/reset-password.js',
                'resources/js/system/patients.js',
                'resources/js/system/doctors.js',
                'resources/js/system/users.js',
                'resources/js/system/schedules.js',
                'resources/js/system/setting.js',
                'resources/js/system/skintypes.js',
                'resources/js/system/iristypes.js',
                'resources/js/system/visittypes.js',
                'resources/js/system/additiontypes.js',
                'resources/js/system/colorvisiontypes.js',
                'resources/js/system/nearpointconvergences.js',
                'resources/js/system/covenants.js',
                'resources/js/system/lenses.js',
                'resources/js/system/surgerytypes.js',
                'resources/js/system/covertesttypes.js',
                'resources/js/system/visualacuitytypes.js',
                'resources/js/auth/password-toggle.js',

                // === React (Inertia) — novo ===
                'resources/js/app.jsx',
            ],
            refresh: true,
        }),
        react(),
    ],
    resolve: {
        alias: {
            '@': path.resolve('./resources/js'),
            jquery: path.resolve('./resources/js/jquery-global.js'),
        },
    },
});
```

### 1.6 Criar o entry point React
Criar `resources/js/app.jsx`:
```jsx
import { createInertiaApp } from '@inertiajs/react';
import { createRoot } from 'react-dom/client';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';

createInertiaApp({
    title: (title) => title ? `${title} — EasyEye` : 'EasyEye',
    resolve: (name) =>
        resolvePageComponent(
            `./Pages/${name}.jsx`,
            import.meta.glob('./Pages/**/*.jsx'),
        ),
    setup({ el, App, props }) {
        createRoot(el).render(<App {...props} />);
    },
    progress: {
        color: '#4B5563',
        showSpinner: true,
    },
});
```

### 1.7 Criar uma página de teste
Criar `resources/js/Pages/Test.jsx`:
```jsx
export default function Test({ message }) {
    return (
        <div style={{ padding: 40 }}>
            <h1>🎉 React + Inertia funcionando!</h1>
            <p>{message}</p>
        </div>
    );
}
```

Adicionar uma rota temporária em `web.php`:
```php
use Inertia\Inertia;

Route::get('/react-test', fn () => Inertia::render('Test', [
    'message' => 'O Laravel está mandando dados para o React!'
]))->name('react.test');
```

**Validação:** Rodar `npm run dev`, acessar `/react-test` e ver o React renderizando com dados do Laravel.

---

## 5. Fase 2 — Importar o Template Preclinic React
**Duração:** 3–5 dias
**Objetivo:** Extrair os componentes e assets do template Preclinic React para dentro do projeto Laravel.

### 2.1 Descompactar o template
Descompactar `react.zip` numa pasta temporária fora do projeto.

### 2.2 Mapear a estrutura do Preclinic React
Normalmente a estrutura do Preclinic React será algo como:
```
preclinic-react/
├── public/
│   └── assets/         ← Ícones, imagens, SVGs
├── src/
│   ├── components/     ← Sidebar, Header, Footer, Cards, DataTable
│   ├── pages/          ← Dashboard, Patients, Doctors, Appointments
│   ├── layouts/        ← MainLayout (wrapper com sidebar + header)
│   ├── assets/         ← CSS/SCSS globais do template
│   └── App.jsx         ← Router (ignorar — Inertia substitui)
└── package.json        ← Ver quais libs o template usa
```

### 2.3 Copiar para dentro do Laravel
```
resources/
└── js/
    ├── app.jsx                          ← Entry point Inertia (já criado)
    ├── Components/                      ← Componentes do Preclinic
    │   ├── Layout/
    │   │   ├── Sidebar.jsx
    │   │   ├── Header.jsx
    │   │   ├── Footer.jsx
    │   │   └── MainLayout.jsx           ← Wrapper principal
    │   ├── UI/
    │   │   ├── Card.jsx
    │   │   ├── Button.jsx
    │   │   ├── Modal.jsx
    │   │   ├── DataTable.jsx
    │   │   ├── Badge.jsx
    │   │   └── Alert.jsx
    │   └── Form/
    │       ├── Input.jsx
    │       ├── Select.jsx
    │       ├── DatePicker.jsx
    │       └── FileUpload.jsx
    ├── Hooks/                            ← Custom hooks reutilizáveis
    │   ├── useAuth.js
    │   ├── useEntity.js
    │   └── useToast.js
    ├── Pages/                            ← Páginas Inertia (1 arquivo = 1 rota)
    │   ├── Dashboard/
    │   │   └── Index.jsx
    │   ├── Patients/
    │   │   ├── Index.jsx
    │   │   ├── Show.jsx
    │   │   └── Create.jsx
    │   ├── Schedules/
    │   │   ├── Index.jsx
    │   │   └── Create.jsx
    │   ├── Doctors/
    │   │   ├── Index.jsx
    │   │   └── Create.jsx
    │   └── Settings/
    │       └── Index.jsx
    └── Layouts/
        └── AuthenticatedLayout.jsx       ← Layout com sidebar + header + guards
```

### 2.4 Adaptar o Layout Principal (MainLayout → AuthenticatedLayout)
O layout do Preclinic provavelmente usa React Router (`<BrowserRouter>`). Você vai **remover** isso e trocar pelo padrão Inertia:

```jsx
// resources/js/Layouts/AuthenticatedLayout.jsx
import Sidebar from '@/Components/Layout/Sidebar';
import Header from '@/Components/Layout/Header';
import { usePage } from '@inertiajs/react';

export default function AuthenticatedLayout({ children }) {
    const { auth, entity } = usePage().props;

    return (
        <div className="main-wrapper">
            <Sidebar user={auth.user} entity={entity} />
            <div className="page-wrapper">
                <Header user={auth.user} />
                <div className="page-content">
                    {children}
                </div>
            </div>
        </div>
    );
}
```

### 2.5 Copiar CSS/SCSS do Preclinic
Copiar os arquivos de estilo do Preclinic para `resources/css/preclinic/` e importar no `app.jsx` ou no `app.blade.php` de Inertia.

### 2.6 Configurar shared data (dados globais)
No `HandleInertiaRequests.php`, compartilhar os dados que toda página React precisa:

```php
// app/Http/Middleware/HandleInertiaRequests.php
public function share(Request $request): array
{
    return [
        ...parent::share($request),
        'auth' => [
            'user' => $request->user()?->only('id', 'name', 'email'),
        ],
        'entity' => session('entity'),
        'flash' => [
            'success' => fn () => $request->session()->get('success'),
            'error'   => fn () => $request->session()->get('error'),
        ],
    ];
}
```

---

## 6. Fase 3 — Migrar Telas (Ordem de Prioridade)
**Duração:** 4–6 semanas (1–2 telas por semana)
**Regra:** Migrar sempre da tela MENOS complexa para a MAIS complexa.

### Ordem recomendada de migração

| # | Módulo | Views Blade | Complexidade | Semana |
|---|---|---|---|---|
| 1 | Dashboard | `dashboard.blade.php` | 🟢 Baixa | Semana 1 |
| 2 | Settings (Tipos: Skin, Iris, Visit, etc.) | `setting/*.blade.php` | 🟢 Baixa (CRUDs simples) | Semana 2 |
| 3 | Médicos | `doctors/*.blade.php` | 🟡 Média | Semana 3 |
| 4 | Pacientes | `patients/*.blade.php` | 🟡 Média | Semana 4 |
| 5 | Usuários / ACL | `users/*.blade.php` | 🟡 Média | Semana 5 |
| 6 | Agendamentos | `schedules/*.blade.php` | 🔴 Alta (DataTables, calendário) | Semana 6–7 |
| 7 | Prontuários | `medical_records/*.blade.php` | 🔴 Alta (formulário complexo) | Semana 8–9 |
| 8 | Auth (Login/Register) | `auth/*.blade.php` | 🟡 Média | Semana 10 |
| 9 | Manager (SaaS Admin) | `manager/*.blade.php` | 🟡 Média | Semana 11 |
| 10 | TV / Sala de Espera | `tv/*.blade.php` | 🟢 Baixa | Semana 12 |

### Como migrar cada tela (Passo-a-passo do Controller)

**Antes (Blade):**
```php
// PatientController.php
public function index() {
    $patients = Patient::where('entity_id', session('entity')->id)
        ->with('person')
        ->paginate(10);

    return view('system.patients.index', compact('patients'));
}
```

**Depois (Inertia):**
```php
use Inertia\Inertia;

public function index() {
    $patients = Patient::where('entity_id', session('entity')->id)
        ->with('person')
        ->paginate(10);

    return Inertia::render('Patients/Index', [
        'patients' => $patients,
        'filters'  => request()->only(['search', 'status']),
    ]);
}
```

**A página React correspondente:**
```jsx
// resources/js/Pages/Patients/Index.jsx
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import DataTable from '@/Components/UI/DataTable';
import { Head, Link, router } from '@inertiajs/react';

export default function PatientsIndex({ patients, filters }) {
    return (
        <AuthenticatedLayout>
            <Head title="Pacientes" />
            <div className="card">
                <div className="card-header">
                    <h4>Pacientes</h4>
                    <Link href="/panel/patients/create" className="btn btn-primary">
                        Novo Paciente
                    </Link>
                </div>
                <div className="card-body">
                    <DataTable
                        data={patients.data}
                        columns={['code', 'person.full_name', 'active']}
                        pagination={patients}
                    />
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
```

---

## 7. Fase 4 — Eliminar Dependências Legadas
**Duração:** 1 semana (após todas as telas migradas)
**Objetivo:** Remover jQuery, Alpine.js, DataTables e plugins antigos.

### Dependências a REMOVER do `package.json` após migração completa
```json
// REMOVE (devDependencies):
"alpinejs"

// REMOVE (dependencies):
"jquery",
"jquery-asColorPicker",
"jquery-sparkline",
"jquery-toast-plugin",
"datatables.net",
"datatables.net-bs5",
"datatables.net-responsive",
"datatables.net-responsive-bs5",
"morris.js",
"raphael"
```

### Dependências a MANTER
```json
"bootstrap"          // O Preclinic React usa Bootstrap por baixo
"flatpickr"          // Pode ser usado via wrapper React
"sweetalert2"        // Tem versão React (sweetalert2-react-content)
"feather-icons"      // Pode migrar para react-feather
"@fortawesome/fontawesome-free"
"inputmask"
"simplebar"
"qrcode"             // TV pairing
```

### Substituições React recomendadas
| Legado | Substituição React |
|---|---|
| `jquery` + DataTables | `@tanstack/react-table` (TanStack Table v8) |
| `alpine.js` | React state nativo (`useState`, `useEffect`) |
| `flatpickr` | `react-flatpickr` (wrapper oficial) |
| `jquery-toast-plugin` | `react-hot-toast` ou `sonner` |
| `sweetalert2` | `sweetalert2-react-content` |
| `feather-icons` | `react-feather` |
| `morris.js` + `raphael` | `recharts` ou `chart.js` + `react-chartjs-2` |

---

## 8. Fase 5 — Apps Mobile (React Native / Expo)
**Duração:** Pós-migração web (projeto separado)
**Objetivo:** Criar os apps Paciente e Médico reaproveitando lógica JavaScript.

### Estrutura Monorepo (Turborepo)
```
easyeye-apps/                      ← Novo repositório
├── turbo.json
├── packages/
│   ├── api/                       ← Shared: chamadas Axios/Fetch para Laravel
│   │   ├── src/
│   │   │   ├── client.ts          ← Axios instance com baseURL e Bearer
│   │   │   ├── patients.ts        ← getPatients(), createPatient()
│   │   │   ├── schedules.ts       ← getSchedules(), getSchedulesByDate()
│   │   │   └── exams.ts           ← uploadExam(), getExams()
│   │   └── package.json
│   └── shared/                    ← Shared: formatadores, enums, tipos
│       ├── src/
│       │   ├── formatDate.ts
│       │   ├── formatCode.ts      ← PAC-XXX, SDL-XXX
│       │   └── types.ts           ← Patient, Schedule, Exam interfaces
│       └── package.json
├── apps/
│   ├── patient-app/               ← Expo (React Native) — App do Paciente
│   │   ├── app/                   ← Expo Router (file-based routing)
│   │   │   ├── (tabs)/
│   │   │   │   ├── appointments.tsx
│   │   │   │   ├── exams.tsx
│   │   │   │   └── profile.tsx
│   │   │   └── login.tsx
│   │   └── package.json
│   └── doctor-app/                ← Expo (React Native) — App do Médico
│       ├── app/
│       │   ├── (tabs)/
│       │   │   ├── schedule.tsx
│       │   │   ├── patients.tsx
│       │   │   ├── exams.tsx
│       │   │   └── settings.tsx
│       │   └── login.tsx
│       └── package.json
└── package.json
```

O pacote `@easyeye/api` é usado tanto no Web (Inertia) quanto nos apps. Se você mudar um endpoint da API Laravel, a atualização propaga para todos os clientes de uma vez.

---

## 9. Mapa de Dependências (Antes vs Depois)

> **Status:** Phase 4 concluída em 2026-03-31. O estado "DEPOIS" abaixo é a realidade atual do projeto.

---

### 9.1 Arquitetura de Camadas

#### ANTES (Stack Original — Phases 0–3 início)
```
┌──────────────────────────────────────────────────────────────────┐
│                        Browser (Web)                             │
│                                                                  │
│  ┌────────────────────────────────────────────────────────────┐  │
│  │  Blade Templates (.blade.php) — HTML renderizado no server │  │
│  │  Alpine.js — reatividade inline (x-data, x-show, x-model) │  │
│  │  jQuery — DOM, AJAX, eventos                               │  │
│  │  DataTables (4 pkgs) — tabelas com sort/filter/pagination  │  │
│  │  Bootstrap 5 + Tailwind CSS — dois sistemas de estilo      │  │
│  │  morris.js + raphael — gráficos SVG legados                │  │
│  │  18+ arquivos JS em /resources/js/system/                  │  │
│  └────────────────────────────────────────────────────────────┘  │
└──────────────────────────┬───────────────────────────────────────┘
                           │ Full Page Reload (HTTP GET/POST)
                           │ (toda navegação recarrega a página inteira)
┌──────────────────────────▼───────────────────────────────────────┐
│                  Laravel 11 (Backend)                            │
│                                                                  │
│  Controllers → return view('system.module.page', compact(...))   │
│  Blade Templates → HTML gerado no servidor e enviado completo    │
│  Session Flash → back()->with('success', ...) + @if($flash)      │
└──────────────────────────────────────────────────────────────────┘
```

#### DEPOIS (Stack Atual — Phase 4 Completa, 2026-03-31)
```
┌──────────────────────────────────────────────────────────────────┐
│                        Browser (Web)                             │
│                                                                  │
│  ┌────────────────────────────────────────────────────────────┐  │
│  │  React 19 — componentes com estado (useState, useEffect)   │  │
│  │  Inertia.js v3 — SPA-like sem CORS, sem API REST própria   │  │
│  │  Preclinic Template — Bootstrap 5 (sidebar + dark mode)    │  │
│  │  AuthenticatedLayout.jsx — wrapper universal do painel     │  │
│  │  GuestLayout.jsx — wrapper para páginas de auth            │  │
│  │  1 entry point: resources/js/app.jsx (Vite)                │  │
│  └────────────────────────────────────────────────────────────┘  │
└──────────────────────────┬───────────────────────────────────────┘
                           │ Inertia XHR (navegação SPA-like)
                           │ JSON parcial — só props mudam por visita
┌──────────────────────────▼───────────────────────────────────────┐
│                  Laravel 11 (Backend)                            │
│                                                                  │
│  Controllers → Inertia::render('Module/Page', [props])           │
│  HandleInertiaRequests → shared props globais em toda página     │
│  Session Flash → ->with('success',...) lido por Toast.jsx        │
│  API /integrators/* → Bearer Token (Sanctum) para Java desktop   │
└──────────────────────────────────────────────────────────────────┘
```

#### FUTURO — Phase 5 (React Native / Expo)
```
┌───────────────────────┐   ┌───────────────────────┐   ┌──────────────────────────────┐
│   Web (React+Inertia) │   │  Doctor App (Expo)     │   │  Patient App (Expo)          │
│   Preclinic Template  │   │  React Native          │   │  React Native                │
│   Bootstrap 5         │   │  iPad / Desktop        │   │  iOS / Android               │
│   SPA-like (Inertia)  │   │  agenda + prontuário   │   │  receitas + laudos           │
└──────────┬────────────┘   └───────────┬────────────┘   └────────────┬─────────────────┘
           │ Inertia XHR               │ REST API                    │ REST API
           │ (sem CORS, com sessão)    │ Bearer Token                │ Bearer Token
           │                           │ @easyeye/api pkg            │ @easyeye/api pkg
┌──────────▼───────────────────────────▼─────────────────────────────▼─────────────────┐
│                              Laravel 11 (Backend)                                      │
│                                                                                        │
│   Controllers → Inertia::render()  |  JSON (API mobile)                               │
│   Services → Lógica de Negócios (intocada em todos os clientes)                       │
│   API /integrators/* → Equipamentos de diagnóstico (Java desktop)                     │
│   API /api/* (v2) → Futuros clientes mobile autenticados via Sanctum                  │
└────────────────────────────────────────────────────────────────────────────────────────┘
```

---

### 9.2 Entradas do Vite (Build)

| | ANTES (Phase 1–3) | DEPOIS (Phase 4) |
|---|---|---|
| **Total de entradas** | 23 arquivos | **2 arquivos** |
| **CSS** | `vendor.css`, `app.css`, `dashboard.css` | `resources/css/app.css` |
| **JS Principal** | `vendor.js`, `app.js` | `resources/js/app.jsx` |
| **JS por módulo** | `system/patients.js`, `system/doctors.js`, `system/users.js`, `system/schedules.js`, `system/setting.js`, `system/skintypes.js`, `system/iristypes.js`, `system/visittypes.js`, `system/additiontypes.js`, `system/colorvisiontypes.js`, `system/nearpointconvergences.js`, `system/covenants.js`, `system/lenses.js`, `system/surgerytypes.js`, `system/covertesttypes.js`, `system/visualacuitytypes.js` | — removidos |
| **JS Auth** | `auth/login.js`, `auth/register.js`, `auth/reset-password.js`, `auth/password-toggle.js` | — removidos |
| **Alias jQuery** | `jquery: ./resources/js/jquery-global.js` | — removido |

---

### 9.3 Pacotes npm — Removidos vs Mantidos

#### Removidos na Phase 4

| Pacote | Categoria | Substituto React |
|---|---|---|
| `jquery` | DOM / AJAX | React state + Inertia `router` |
| `jquery-asColorPicker` | UI Plugin | — (não utilizado no React) |
| `jquery-sparkline` | Gráficos inline | — (não utilizado no React) |
| `jquery-toast-plugin` | Notificações | `Toast.jsx` nativo (flash props) |
| `datatables.net` | Tabelas | `DataTable.jsx` customizado |
| `datatables.net-bs5` | Tabelas Bootstrap | `DataTable.jsx` customizado |
| `datatables.net-responsive` | Tabelas responsivas | `DataTable.jsx` customizado |
| `datatables.net-responsive-bs5` | Tabelas responsivas | `DataTable.jsx` customizado |
| `morris.js` | Gráficos SVG | — (não portado na Phase 4) |
| `raphael` | Dependência do morris | — (não portado na Phase 4) |
| `alpinejs` | Reatividade inline | React `useState` / `useEffect` |
| `gulp` + 5 plugins | Build legado | Vite (já era o padrão) |
| `browser-sync` | Dev server legado | Vite HMR nativo |
| `tailwindcss` | CSS utility | Bootstrap 5 (Preclinic template) |
| `@tailwindcss/forms` | CSS utility | Bootstrap 5 |
| `autoprefixer` | PostCSS plugin | — (Vite cobre nativamente) |
| `postcss` | CSS processor | — (Vite cobre nativamente) |
| `sass` | CSS preprocessor | — (Vite cobre nativamente) |

**Total removido: 18 pacotes** (10 dependencies + 8 devDependencies)

#### Mantidos (package.json atual)

| Pacote | Motivo |
|---|---|
| `react` + `react-dom` | Core do frontend |
| `@inertiajs/react` | Bridge Laravel ↔ React |
| `@vitejs/plugin-react` | Vite Fast Refresh |
| `vite` + `laravel-vite-plugin` | Build moderno |
| `bootstrap` | Preclinic template (Bootstrap 5) |
| `flatpickr` | Datepicker (usado em WorkSchedule e Agendamentos) |
| `sweetalert2` | Confirmações (substituível por `ConfirmDialog.jsx`) |
| `feather-icons` | Ícones no template |
| `@fortawesome/fontawesome-free` | Ícones (fa-check-circle, fa-times-circle) |
| `@tabler/icons-webfont` | Ícones do Preclinic |
| `inputmask` | Máscaras de telefone/CPF/CNPJ |
| `simplebar` | Scrollbar customizada na sidebar |
| `perfect-scrollbar` | Scrollbar do template |
| `qrcode` | Geração de QR Code (integradores) |
| `axios` | HTTP client para chamadas API |
| `concurrently` | Dev: rodar server + queue + vite em paralelo |

---

### 9.4 Controllers — Padrão de Retorno

| Módulo | ANTES | DEPOIS |
|---|---|---|
| Auth (Login, Register...) | `return view('auth.*')` | `return Inertia::render('Auth/*')` |
| Dashboard | `return view('system.dashboard')` | `return Inertia::render('Dashboard/Index')` |
| Settings (12 tipos) | `return view('system.setting.*')` | `return Inertia::render('Settings/*')` via `BaseSettingController` |
| Doctors Index/Show | `return view('system.doctors.*')` | `return Inertia::render('Doctors/*')` |
| Doctors WorkSchedule | `return view('system.doctors.work-schedule')` | `return Inertia::render('Doctors/WorkSchedule')` ← Phase 4 |
| Patients Index/Show | `return view('system.patients.*')` | `return Inertia::render('Patients/*')` |
| Users Index/Show | `return view('system.users.*')` | `return Inertia::render('Users/*')` |
| Schedules Index/Show | `return view('system.schedules.*')` | `return Inertia::render('Schedules/*')` |
| Medical Records | `return view('system.medical_records.*')` | `return Inertia::render('MedicalRecords/*')` |
| Manager (SaaS) | `return view('manager.*')` | `return Inertia::render('Manager/*')` |
| Reports (3 rotas) | `return view('system.reports.*')` | `return Inertia::render('Reports/*')` ← Phase 4 |
| Profile Edit | `return view('profile.edit')` | `return Inertia::render('Profile/Edit')` ← Phase 4 |
| Subscription Expired | `return view('subscription.expired')` | `return Inertia::render('Subscription/Expired')` ← Phase 4 |
| **Blade residual** | — | `app.blade.php` (root Inertia) + `welcome.blade.php` (landing pública) |

---

### 9.5 Páginas React — Inventário Completo (Phase 4 Final)

```
resources/js/Pages/
├── Auth/
│   ├── Login.jsx
│   ├── Register.jsx
│   ├── ForgotPassword.jsx
│   ├── ResetPassword.jsx
│   └── SelectEntity.jsx
├── Dashboard/
│   └── Index.jsx
├── Settings/
│   ├── SkinTypes.jsx          IrisTypes.jsx       VisitTypes.jsx
│   ├── AdditionTypes.jsx      ColorVisionTypes.jsx CoverTestTypes.jsx
│   ├── VisualAcuityTypes.jsx  SurgeryTypes.jsx     Lenses.jsx
│   ├── NearPointConvergences.jsx  Covenants.jsx    Resources.jsx
├── Doctors/
│   ├── Index.jsx
│   ├── Show.jsx
│   └── WorkSchedule.jsx       ← Phase 4
├── Patients/
│   ├── Index.jsx
│   └── Show.jsx
├── Users/
│   ├── Index.jsx
│   └── Show.jsx
├── Schedules/
│   ├── Index.jsx
│   └── Show.jsx
├── MedicalRecords/
│   ├── Index.jsx
│   └── Create.jsx
├── Reports/
│   ├── Index.jsx              ← Phase 4
│   ├── Schedules.jsx          ← Phase 4
│   └── Absenteeism.jsx        ← Phase 4
├── Profile/
│   └── Edit.jsx               ← Phase 4
├── Subscription/
│   └── Expired.jsx            ← Phase 4
└── Manager/
    ├── Dashboard.jsx
    ├── Entities/Index.jsx
    ├── Entities/Show.jsx
    ├── Subscriptions/Index.jsx
    └── Plans/Index.jsx

Total: 37 páginas React  |  0 Blade pages no painel autenticado
```

---

## 10. Riscos e Mitigações

| Risco | Impacto | Mitigação |
|---|---|---|
| Template Preclinic React desatualizado (React 16/17) | Médio | Atualizar imports para React 19 (`createRoot` ao invés de `ReactDOM.render`). Remover `class` por `className` se necessário. |
| DataTables migração complexa | Alto | Usar `@tanstack/react-table` — lib leve, moderna e sem jQuery. Migrar tabela por tabela. |
| Perder SEO na landing page | Baixo | Manter `welcome.blade.php` em Blade puro (público). Apenas o painel autenticado usa React. |
| Sistema parar durante migração | Crítico | **Modo híbrido:** Blade e React coexistem. Deploiar tela por tela. Nunca migrar tudo de uma vez. |
| Equipe sem experiência React | Médio | Começar pelas telas mais simples (Settings/CRUDs). Ganhar confiança antes de migrar Agendamentos e Prontuários. |

---

> **Nota Final:** Este plano foi desenhado para que o EasyEye nunca pare de funcionar durante toda a migração. Cada deploy entrega uma tela nova em React enquanto as outras continuam em Blade. Quando a última tela for migrada, você remove o Alpine, jQuery e os scripts legados de uma vez.
