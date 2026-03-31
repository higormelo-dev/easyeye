# Technical Specifications Document (SPECS)
**Produto:** EasyEye (Medicare)
**Arquitetura:** Laravel 11 (PHP 8.4) + Alpine.js + Integrador Java Local

---

## 1. Stack Tecnológico e Infraestrutura
- **Backend Framework:** Laravel 11.x
- **Linguagem:** PHP 8.4
- **Banco de Dados (Dev):** SQLite (Suporte robusto a MySQL/PostgreSQL para Prod).
- **Cache, Sessões e Filas:** Redis
- **Frontend (Novo - Em Migração):** React 18, Inertia.js (v3), Vite, baseado no template premium Preclinic.
- **Frontend (Legado):** Blade Templates, Alpine.js, Bootstrap 5, jQuery (sendo gradualmente substituídos).
- **Serviço de Background:** Laravel Queue Workers nativos para assincronicidade (envio de e-mails, comissões de parceiros, webhooks).

## 2. Arquitetura de Multi-Tenancy
O sistema opera através de **Session-based Multi-tenancy** garantindo total isolamento visual e operacional.
1. O banco guarda todos os dados numa mesma estrutura, porém cada tabela-chave (Patients, Schedules, Exams) possui a coluna `entity_id`.
2. A entidade controladora do SaaS em si (`ENT-0000000001`) tem regras de bypass (Manager routes).
3. O Middleware `EnsureEntitySelected` intercepta o request, injeta a entidade logada na sessão e obriga que as buscas via repositório/models (nos Services) venham com escopo (Tenant Guard).

## 3. Padrões de Design de Código
- **Service Layer Pattern:** Nenhum controlador ("Thin Controller") detém lógica de negócios. Todos os fluxos pesados ficam dentro de `app/Services/` (Ex: `PatientExamService::createFromScheduleIdentifier()`).
- **Repositories (via Model queries):** O Eloquent ORM é manipulado estritamente pelas classes de Service para acesso e persistência.
- **Observer Pattern Silencioso:** Acoplamento fraco para efeitos colaterais. Auditorias (AuditContext), Criação de Trial Automático e Tracking de Ativação (CAC/Growth) operam via Observers do Eloquent (`creating/created`). Exceções não críticas nos observers são controladas (Logs de erro silenciados) para não quebrar a transação de negócio primária.
- **Action/Job Pattern:** Ações assíncronas encapsuladas sob Jobs quando necessário.
- **Frontend Inertia Pattern:** Novo padrão em transição onde os Controllers retornam `Inertia::render()` ao invés de views Blade. Componentes encapsulados de React substituem lógicas extensas antes dependentes de jQuery + DataTables (ex.: uso de um `SettingsCrud` unificado em React conectando as API calls base para configurações).
- **Presenters:** Utilização da biblioteca `laracasts/presenter` para separar lógicas visuais (formatações financeiras, horários amigáveis) diretamente da camada do Model (usado largamente no legado, agora as formatações também migram para responsabilidade do Front de forma nativa).

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

## 7. Próximos Passos de Arquitetura (Engenharia V2)
- **Object Storage (S3/R2):** Atualizar o Filesystem do `PatientExamService` para garantir que o blob de Imagens gigantescas (ex: OCT de retina) vá para provedores focados em *Cold Storage* sem estourar discos do servidor Web.
- **WebSocket (Pusher/Reverb):** Emissão de eventos em tempo real para as estações da recepção (Broadcasting) avisando quando o integrador fez o push de um exame pronto com sucesso, ou na atualização tática de "Próxima Senha de Fila".
- **LLM API Clients:** Classes dedicadas para fazer o wrap das Requests aos endpoints do OpenAI (ou Claude) nas features de resumo e speech-to-text.
