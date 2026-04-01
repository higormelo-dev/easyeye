# Technical Specifications Document (SPECS) - EasyEye (Medicare)

**Linguagem & Framework:** PHP 8.4 / Laravel 13  
**Frontend:** Blade/Alpine.js (Legado) & Inertia.js/React (Novo)  
**Banco de Dados:** SQLite (Dev), PostgreSQL (Prod)  
**Gestão de Estado:** Multi-Tenancy baseada em Sessão

---

## 1. Arquitetura do Sistema

### 1.1 Multi-Tenancy
O sistema utiliza um modelo de **Shared Database, Shared Schema**, com isolamento via `entity_id`.
*   **Identificação:** Entidades utilizam prefixos legíveis (ex: `ENT-0000000001`).
*   **Middleware:** `EnsureEntitySelected` gerencia a entidade ativa na sessão do usuário.
*   **RBAC:** Controle de acesso baseado em papéis (`entity_users`) vinculados a permissões específicas (`EntityGate`).

### 1.2 Camada de Serviços (Service Layer)
Toda a lógica de negócios está encapsulada em `app/Services/`. Os Controllers são "thin", servindo apenas como ponto de entrada para as rotas.
*   Serviços principais: `MedicalRecordSignatureService`, `PatientService`, `SubscriptionService`, `FeatureGateService`.

### 1.3 Stack de Frontend (Híbrida)
O projeto está em fase de transição de tecnologia de interface:
*   **Painel Administrativo:** Blade + Alpine.js (migrando para React).
*   **Novos Módulos:** React 18+ via Inertia.js para uma experiência de SPA.
*   **CSS:** Tailwind CSS para layouts modernos e Bootstrap 5 para componentes legados.
*   **Assets:** Vite para build e HMR (Hot Module Replacement).

---

## 2. Integração com Equipamentos

### 2.1 API do Integrador (`/api/integrators/`)
*   **Autenticação:** Laravel Sanctum com tokens de longa duração e validação de `HWID` (Hardware ID).
*   **Upload de Exames:** Recebimento de arquivos via Multipart. O backend realiza o parsing do nome do arquivo para vinculação automática ao paciente e agendamento pendente.
*   **Quotas:** O `UsageMeterService` monitora o consumo de API (uploads de exames) conforme o plano da clínica.

### 2.2 Waiting Room TV (`/tv/`)
*   **Public Display:** Rotas públicas para exibição de fila de chamadas.
*   **Pareamento:** Utiliza sistema de QRCode para autenticar a Smart TV com a entidade da clínica sem necessidade de login manual complexo.

---

## 3. Compliance & Engenharia de Dados

### 3.1 Compliance CFM (Assinatura de Prontuário)
*   **Trait `Signable`:** Implementa lógica de hashing SHA-256 dos dados clínicos no momento da assinatura.
*   **Imutabilidade:** Uma vez assinado (`is_locked = true`), o registro não pode ser alterado ou excluído.
*   **Versionamento:** O Trait `Versionable` cria snapshots em `record_versions` antes de qualquer atualização em registros sensíveis.

### 3.2 Compliance LGPD (Privacidade)
*   **Auditoria:** Trait `Auditable` registra todas as ações de escrita (CUD) em `audit_logs`.
*   **Acesso a Dados:** Trait `LogsDataAccess` registra visualizações de dados sensíveis em `data_access_logs`.
*   **Consentimentos:** Tabelas `patient_consents` e `term_versions` gerenciam o histórico de aceites e revogações.

---

## 4. Infraestrutura e DevSecOps

### 4.1 Fila e Background Jobs
*   **Redis:** Utilizado para cache, sessões e driver de filas (Laravel Horizon).
*   **Jobs:** Processamento assíncrono para envio de e-mails, processamento de logs de auditoria e cálculo de comissões.

### 4.2 Testes e Qualidade
*   **Pest 4:** Framework de testes para Feature e Unit testing. Cobertura focada em Gates de Planos, Auditoria e Integridade Clinical.
*   **Laravel Pint:** Padronização de código conforme PSR-12.

### 4.3 Containerização
*   **Docker:** Configuração completa via `Dockerfile` (PHP 8.4-FPM) e `docker-compose.yml` (Nginx, Redis, PostgreSQL).

---

## 5. Próximos Desafios Técnicos
*   **S3 Integration:** Migração do armazenamento de exames (blobs gigantes) de local para Object Storage compatível com S3.
*   **WebSockets (Laravel Reverb):** Implementação de notificações em tempo real para o integrador e tela de TV.
*   **IA Pipelines:** Desenvolvimento de wrappers para integração com LLMs (OpenAI/Claude) para síntese de prontuários.
