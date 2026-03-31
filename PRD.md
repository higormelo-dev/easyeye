# Product Requirements Document (PRD)
**Produto:** EasyEye (Medicare)
**Data:** Março de 2026
**Visão:** Ser o Sistema Operacional definitivo (SaaS B2B) para clínicas e consultórios de oftalmologia no Brasil, focado em alta produtividade clínica, compliance rigoroso e integração perfeita com equipamentos de diagnóstico.

---

## 1. Sumário Executivo
O **EasyEye** é uma plataforma SaaS multi-tenant focada na vertical de oftalmologia. Ao contrário de sistemas de gestão horizontal genéricos, o EasyEye resolve o maior gargalo técnico da especialidade: a fragmentação entre o prontuário eletrônico e os equipamentos robóticos de diagnóstico (paquímetros, topógrafos, auto-refratores). Através de um integrador local (desktop) e uma API robusta, a plataforma unifica a jornada do paciente, desde o agendamento até a assinatura digital do prontuário, garantindo conformidade com o CFM e a LGPD.

## 2. Declaração do Problema
* **Desconexão de Hardware/Software:** Médicos oftalmologistas perdem, em média, de 2 a 5 minutos por consulta exportando imagens de máquinas locais via pen-drive ou rede mal configurada para anexar manualmente em prontuários web.
* **Riscos Legais e Compliance:** Sistemas menores falham em prover a imutabilidade exigida pela Resolução CFM 2.227/2018 para prontuários eletrônicos sem papel, além de não controlarem adequadamente os consentimentos exigidos pela LGPD.
* **Falta de Foco Especializado:** Sistemas como iClinic ou Feegow oferecem interfaces amplas demais, não otimizadas para o workflow tático do oftalmologista e suas especificidades (ex: lateralidade de exames - OD/OE).

## 3. Público-Alvo e Personas
1. **O Médico Oftalmologista (Dr. Roberto):** Focado em ver mais pacientes por hora sem perder qualidade. Odeia trabalho administrativo e burocracia de software.
2. **A Secretária/Recepção (Ana):** Focada em velocidade de agendamento, recepção de pacientes e baixa taxa de no-show (faltas).
3. **O Gestor/Dono da Clínica (Carlos):** Foca no faturamento, controle de glosas, aquisição de novos pacientes e cumprimento de regras para evitar multas.

## 4. Funcionalidades Atuais (Core V1)

### 4.1 Gestão Clínica e Agendamentos
- **Prontuário Eletrônico:** Focado na oftalmologia, com suporte a histórico e evolução tática.
- **Painel de Agendamentos:** Controle de status da consulta (Agendado, Em Andamento, Concluído, Cancelado).
- **Multi-Tenancy por Entidade:** Clínicas são Entities isoladas (`ENT-XXX`). Usuários podem ter múltiplos papéis (Roles) dentro de uma entidade.

### 4.2 EasyEye Integrator (Hardware-to-Cloud)
- **Sincronização Automática:** Aplicativo desktop em Java (EasyEye Integrator) que monitora as pastas de saída dos equipamentos médicos.
- **Resolução Inteligente de Entidades:** A API (`POST /api/integrators/v1/exams`) identifica o paciente (`PAC-XXX`) através do nome do arquivo (ex: `PAC-000002-od.jpeg`) e vincula automaticamente o exame da máquina ao agendamento do dia, salvando no prontuário sem cliques.

### 4.3 Compliance Enterprise (CFM & LGPD)
- **Assinatura Eletrônica (CFM):** Congelamento (lock) do prontuário ao assinar, gerando um hash SHA-256 (imutabilidade).
- **Gestão de Consentimentos:** Painel LGPD para coletar, versionar e revogar permissões de uso de dados sensíveis e imagens.
- **Log de Acesso Total:** Registro estrito de quem leu ou modificou cada dado médico (Audit Logs).

### 4.4 Growth e Monetização (PLG)
- **Billing por Uso (Meters):** Planos controlam limites de envios de API (exames) e uso de créditos mensais de IA.
- **Sistema de Parcerias e Indicações:** Clínicas geram códigos de indicação para descontos. Revendedores recebem comissionamento nativo mensurado por marcos de Ativação (`ActivationSteps`).

### 4.5 Modernização de Interface (Phase 4 Completa — 2026-03-31)

**Status atual:** Frontend 100% React 19 + Inertia.js. Nenhuma Blade page no painel autenticado. **18 pacotes legados removidos** (jQuery ×4, DataTables ×4, Alpine.js, morris.js, raphael, Gulp ×5, Tailwind CSS pipeline ×4). Entradas Vite reduzidas de 23 para 2. Blade residual: `app.blade.php` (root Inertia) + `welcome.blade.php` (landing pública). Documentação do mapa de dependências antes/depois em `MIGRATION_PLAN.md` §9.

#### Arquitetura implementada
- **React 19** via **Inertia.js (v3)** — navegação SPA-like sem full-page reload
- **Template Preclinic** — UI premium com Bootstrap 5, sidebar responsiva, dark/light mode
- **Vite** — build reduzido de 23 entradas para 2: `app.css` + `app.jsx`
- **`AuthenticatedLayout.jsx`** — wrapper universal do painel (sidebar + header + menu condicional por `isClient`)
- **`GuestLayout.jsx`** — wrapper para páginas de autenticação
- **`HandleInertiaRequests`** — shared props globais: `auth`, `entity`, `flash`, `isClient`, `userRule`, `locale`, `appName`

#### Telas migradas — mapa completo (37 páginas React)

| Módulo | Páginas React | Fase |
|--------|--------------|------|
| Auth | Login, Register, ForgotPassword, ResetPassword, SelectEntity | Phase 1–2 |
| Dashboard | Dashboard clínico + Manager SaaS | Phase 3 |
| Settings (12 módulos) | SkinTypes, IrisTypes, VisitTypes, AdditionTypes, ColorVisionTypes, CoverTestTypes, VisualAcuityTypes, SurgeryTypes, Lenses, NearPointConvergences, Covenants, Resources | Phase 3 |
| Médicos | Index (cards + busca AJAX), Show (perfil), WorkSchedule (escala + bloqueios) | Phase 3–4 |
| Pacientes | Index (cards + busca AJAX), Show (perfil + histórico de prontuários) | Phase 3 |
| Usuários/ACL | Index, Show | Phase 3 |
| Agendamentos | Index (lista diária com situação inline + bulk-actions), Show | Phase 3 |
| Prontuário Eletrônico | Index (timeline), Create (formulário clínico) | Phase 3 |
| Manager (SaaS Admin) | Dashboard, Entities/Index, Entities/Show, Subscriptions/Index, Plans/Index | Phase 3 |
| Relatórios | Index (hub), Schedules (produção + filtros), Absenteeism (faltas + filtros) | Phase 4 |
| Perfil do Usuário | Edit (foto + dados pessoais + troca de senha) | Phase 4 |
| Assinatura Expirada | Expired (banner de aviso + cards de upgrade de plano) | Phase 4 |

#### Componentes globais implementados

| Componente | Localização | Função |
|---|---|---|
| `AuthenticatedLayout.jsx` | `Layouts/` | Sidebar + header, dark mode toggle, menu por role |
| `GuestLayout.jsx` | `Layouts/` | Wrapper centralizado para auth pages |
| `SettingsCrud.jsx` | `Components/UI/` | CRUD genérico (name + active) com modal inline |
| `Toast.jsx` | `Components/UI/` | Notificações flash automáticas (`flash.success/error/warning`) |
| `FlashMessages.jsx` | `Components/UI/` | Exibição de flash inline em páginas |
| `ConfirmDialog.jsx` | `Components/UI/` | Confirmação de exclusão (substitui `window.confirm()`) |
| `CardGrid.jsx` | `Components/UI/` | Listagem em cards com paginação AJAX |
| `DataTable.jsx` | `Components/UI/` | Tabela server-side para Manager |
| `Pagination.jsx`, `Badge.jsx`, `PageHeader.jsx`, `FormInput.jsx`, `PersonForm.jsx`, `Modal.jsx` | `Components/UI/` | Primitivos reutilizáveis |

#### Infraestrutura de controllers
- **`BaseSettingController`** — abstrato; subclasses declaram apenas `$inertiaPage` e `$resourceClass`; cobre os 12 tipos de configuração
- **`ProfileController`** — upload de foto via method-spoofing PATCH com FormData
- **`PasswordController`** — validação simples sem error bags; redireciona para `profile.edit` com `flash.success`
- **`ReportsController`** — serializa dados com eager loading `doctor.entityUser.user`; `results: null` quando sem filtros
- **`SubscriptionExpiredController`** — serializa `FeatureKey::cases()` com `label()` e `isBoolean()` para o frontend

---

## 5. Roadmap e Funcionalidades Futuras (V2 e V3)

### 5.1 Phase 5 — Apps Mobile (React Native / Expo)
- **App do Médico:** Agenda do dia, visualização de prontuários e exames no iPad/tablet via Expo.
- **App do Paciente:** Receitas de óculos, laudos, histórico de consultas — iOS/Android.
- **Monorepo Turborepo:** Pacotes `@easyeye/api` e `@easyeye/shared` reutilizados entre web e mobile.
- **Arquitetura multi-cliente:** Web (React+Inertia via sessão) + Doctor App (Expo via Bearer Token) + Patient App (Expo via Bearer Token) — todos consumindo o mesmo Laravel 11. Detalhes em `MIGRATION_PLAN.md` §9.1.

### 5.2 Foco em Produtividade com Inteligência Artificial (V2)
- **Transcrição de Evolução por Voz (Voice-to-Text):** O médico dita a evolução e a IA resume e preenche os campos do prontuário de forma estruturada. (Consumindo a cota `AiMonthlyCredits`).
- **Pré-Análise de Imagens Diagnósticas:** IA capaz de identificar anomalias primárias em fotos de retina ou topografias, agindo como segunda opinião.
- **Agendamento via WhatsApp Bot AI:** Integração direta com a agenda do EasyEye, permitindo que o paciente marque, reagende e confirme presença 24/7 de forma conversacional.

### 5.3 Faturamento e Financeiro (V3)
- **Faturamento TISS (Integração de Convênios):** Geração de guias TISS/TUSS em lote para minimizar glosas com planos de saúde.
- **Conciliação Bancária e Split de Pagamentos:** Divisão automática dos honorários entre a clínica e os oftalmologistas associados nas consultas particulares.

### 5.4 Portal do Paciente (V4)
- **Portal do Paciente:** Acesso web/mobile simples para o paciente acessar suas receitas de óculos, declarações e laudos.

## 6. Métricas de Sucesso (KPIs)
- **Ativação (Activation Rate):** Tempo médio desde a criação do Trial até o envio do 1º exame via Integrador.
- **Engajamento:** Quantidade média de exames enviados via API por clínica mensalmente.
- **CAC vs LTV:** Manter o Custo de Aquisição baixo graças ao motor de *Referral/Partners* embutido, frente a uma retenção (LTV) altíssima causada pela "aderência" do integrador de equipamentos.
- **Churn Rate:** Meta audaciosa de manter abaixo de 1% a.m., já que desplugar equipamentos de hardware da nuvem gera enorme atrito.
