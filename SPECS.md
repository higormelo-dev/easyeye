**Linguagem & Framework:** PHP 8.4 / Laravel 11  
**Frontend:** Blade Templates, Alpine.js, Tailwind CSS & Bootstrap 5
**Banco de Dados:** SQLite (Dev/Test), PostgreSQL (Prod)
**Middleware Stack:** `auth` → `verified` → `entity.selected` → `entity.role`

---

## 1. Arquitetura Multi-Tenancy (Session-Based)

O sistema opera sob um modelo de **Shared Database, Shared Schema**, onde o isolamento ocorre via `entity_id`.
*   **Identificadores:** Entidades e registros principais utilizam IDs sequenciais públicos com prefixos (ex: `ENT-0000000001`, `PAC-0000000001`).
*   **Gestão de Sessão:** A entidade ativa é armazenada na sessão após a seleção pelo usuário. O middleware `EnsureEntitySelected` protege todas as rotas do painel.
*   **RBAC Dinâmico:** Usuários podem pertencer a múltiplas entidades com papéis diferentes. A autorização é validada via `EntityGate` e `EnsureEntityRole`.

## 2. Camada de Serviços e Regras de Negócio

A lógica de alto nível é isolada em `app/Services/`. Controllers são apenas orquestradores.

### 2.1 Core Services
*   **SubscriptionService:** Ciclo de vida de assinaturas (Trial, Active, Expired).
*   **FeatureGateService:** Singleton que valida acesso a funcionalidades baseado nas configurações do plano.
*   **UsageMeterService:** Rastreia o consumo de API (uploads) para enforcement de quotas mensais.

### 2.2 Subsistema de Growth (CAC)
Implementado para otimizar o custo de aquisição de clientes:
*   **ActivationService:** Rastreia o progresso da clínica através de enums de `ActivationStep` com pesos específicos (score 0-100).
*   **ReferralService:** Gerencia o ciclo de indicações peer-to-peer (geração de códigos, eventos de conversão e recompensas).
*   **PartnerService:** Gerencia o programa de parceiros com atribuição via UTM/Tokens, rastreamento de leads e geração de comissões.

---

## 3. Padrões de Desenvolvimento (System Patterns)

### 3.1 Observers & Event-Driven Logic
O sistema automatiza comportamentos através de Observers em `app/Observers/`:
*   **EntityObserver:** Inicia automaticamente o trial ao criar uma entidade.
*   **ActivationObserver:** Registra marcos de ativação em tempo real ao interagir com modelos clínicos.
*   **SubscriptionObserver:** Dispara eventos de indicação e comissionamento de parceiros.

### 3.2 Traits Transversais
*   **Signable:** Hashing SHA-256 e lock de segurança para prontuários clínicos.
*   **Versionable:** Criação automática de snapshots em `record_versions` antes de mutações de dados.
*   **Auditable & HasAuditColumns:** Rastreamento completo de CUD e preenchimento de `created_by`/`updated_by`.
*   **LogsDataAccess:** Trait de controller para registrar leitura de dados sensíveis (compliance LGPD).

---

## 4. Integrações e API

### 4.1 EasyEye Integrator API
*   **Auth:** Laravel Sanctum com validação de HWID.
*   **Protocolo:** Upload multipart via `/api/integrators/upload`.
*   **Processamento:** Parsing assíncrono de metadados para vinculação direta ao agendamento vigente.

### 4.2 Waiting Room TV
*   **Display Público:** Rota `/tv/{slug}` otimizada para navegadores de smart TVs.
*   **Sincronização:** Polling/Websocket (planejado Laravel Reverb) para atualização de chamadas em tempo real.

---

## 5. Infraestrutura e Qualidade

*   **Processamento em Fila:** Redis + Laravel Horizon para jobs de auditoria e comissionamento.
*   **Qualidade de Código:** Laravel Pint (estilo) e Pest 4 (testes).
*   **Ambiente Docker:** PHP 8.4-FPM executando em instâncias isoladas com Nginx e Redis.

## 6. Governança de Dados (Compliance)
*   **Retention Policy:** Soft Deletes implementados em todos os recursos críticos com funcionalidade de `restore()`.
*   **Audit Context:** Singleton `AuditContext` que garante que o ID do usuário correto seja capturado em jobs de background.
