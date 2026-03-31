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

### ANTES (Stack Atual)
```
┌─────────────────────────────────┐
│        Browser (Web)            │
│  Blade + Alpine + jQuery        │
│  DataTables + Bootstrap 5       │
│  ┌───────────────────────────┐  │
│  │ JavaScript Vanilla (.js)  │  │
│  │ 18 arquivos em /system/   │  │
│  └───────────────────────────┘  │
└──────────────┬──────────────────┘
               │ Full Page Reload
┌──────────────▼──────────────────┐
│     Laravel 11 (Backend)        │
│  Controllers → return view()    │
│  Blade Templates (HTML)         │
└─────────────────────────────────┘
```

### DEPOIS (Stack Migrada)
```
┌─────────────────────┐  ┌──────────────────────┐  ┌───────────────┐
│  Web (React+Inertia) │  │  Doctor App (Expo)   │  │ Patient App   │
│  Preclinic Template  │  │  React Native        │  │ (Expo)        │
│  SPA-like experience │  │  iPad/Desktop        │  │ iOS/Android   │
└──────────┬───────────┘  └──────────┬───────────┘  └───────┬───────┘
           │ Inertia XHR             │ REST API              │ REST API
           │ (sem CORS, com sessão)  │ (Bearer Token)        │ (Bearer)
┌──────────▼─────────────────────────▼──────────────────────▼───────┐
│                    Laravel 11 (Backend)                            │
│              Controllers → Inertia::render() ou JSON              │
│              Services → Lógica de Negócios (intocada)             │
│              API /integrators → Integração Equipamentos           │
└───────────────────────────────────────────────────────────────────┘
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
