# Product Requirements Document (PRD) - EasyEye (Medicare)

**Versão:** 2.0  
**Data:** Março de 2026  
**Status:** Em Evolução (Migração React/Inertia em curso)

---

## 1. Visão do Produto
O **EasyEye** (codinome interno *Medicare*) é o "Sistema Operacional" definitivo para clínicas de oftalmologia. Ele resolve a fragmentação entre o atendimento clínico e os equipamentos de diagnóstico, unificando agendamento, prontuário eletrônico (PEP), captura automática de exames e gestão de conformidade (CFM/LGPD) em uma única plataforma SaaS premium.

## 2. O Problema
1.  **Gargalo de Hardware:** Oftalmologistas perdem tempo precioso movendo arquivos de máquinas de exames (Topógrafos, OCTs) para o prontuário via pen-drives ou redes locais instáveis.
2.  **Risco Jurídico:** A maioria dos sistemas não garante a imutabilidade do prontuário exigida pelo CFM (Res. 2.227/2018) nem o registro rigoroso de acesso a dados sensíveis (LGPD).
3.  **Experiência Datada:** Interfaces de sistemas médicos costumam ser complexas, lentas e não otimizadas para o fluxo rápido de uma clínica oftalmológica.

## 3. Personas
*   **Dr. Roberto (Oftalmologista):** Precisa de agilidade. Quer ditar a evolução, ver os exames automaticamente no prontuário e assinar digitalmente com um clique.
*   **Ana (Recepção/Secretária):** Gerencia agendas lotadas, confirmações de pacientes e o fluxo na sala de espera.
*   **Carlos (Gestor da Clínica):** Focado em métricas de faturamento, retenção de pacientes (LTV) e conformidade legal total para evitar multas.

## 4. Funcionalidades de Core (V1 - Estável)

### 4.1 Gestão Clínica 360°
*   **Agendamento Inteligente:** Controle de status (Agendado, Em Atendimento, Concluído, Cancelado) com visão por médico e unidade.
*   **Prontuário Eletrônico Oftalmológico:** Campos específicos para lateralidade (OD/OE), histórico clínico e evolução tática.
*   **Multi-Tenancy (Entidades):** Isolamento total de dados entre clínicas, com suporte a múltiplos papéis (Roles) por usuário (Médico, Recepcionista, Gestor).

### 4.2 EasyEye Integrator (Hardware-to-Cloud)
*   **Automação de Exames:** Aplicativo desktop que monitora pastas de saída de equipamentos e faz upload automático para a nuvem.
*   **Match Inteligente:** A API identifica o paciente e o agendamento vigente através do nome do arquivo, vinculando o exame ao prontuário sem intervenção manual.

### 4.3 Compliance & Segurança (Enterprise Ready)
*   **Assinatura Digital (CFM):** Sistema de congelamento de prontuário com hash SHA-256 para garantir imutabilidade.
*   **Arquitetura LGPD Native:** Logs de acesso a dados sensíveis (`data_access_logs`), gestão de consentimentos versionados e solicitações de direitos do titular.
*   **Snapshot de Versões:** Histórico completo de alterações em registros clínicos.

### 4.4 Experiência do Paciente & Sala de Espera
*   **Waiting Room TV:** Display público para sala de espera que exibe a fila de chamadas em tempo real, pareado via QRCode.

---

## 5. Funcionalidades em Implementação (V2 - Modernização)

### 5.1 Nova Experiência Frontend (React + Inertia.js)
*   **Migração de Blade para React:** Refatoração completa da interface para uma experiência de SPA (Single Page Application) fluida, utilizando o template premium *Preclinic*.
*   **Componentização:** Criação de biblioteca de componentes reutilizáveis para garantir consistência visual e velocidade de desenvolvimento.

### 5.2 IA e Produtividade Clínica
*   **Voice-to-Text (Ditado Médico):** Transcrição e estruturação automática de dados da consulta via IA, otimizando o preenchimento do PEP.
*   **IA de Triagem de Imagens:** Pré-análise de exames para detecção de anomalias comuns (ex: Catarata, Glaucoma) como apoio à decisão.

---

## 6. Roadmap e Futuro (V3+)

### 6.1 Ecossistema Mobile
*   **App do Médico:** Acesso rápido à agenda e prontuários pelo celular (reutilizando a lógica do frontend React).
*   **Portal do Paciente:** App/Web para o paciente visualizar receitas de óculos, laudos de exames e agendar consultas.

### 6.2 Finanças e Expansão
*   **Módulo Financeiro TISS:** Faturamento eletrônico de guias para convênios conforme padrão ANS.
*   **Telemedicina Integrada:** Consultas remotas com integração nativa ao prontuário e assinatura digital de receitas.
*   **Marketplace de Insumos:** Integração com fornecedores de lentes e medicamentos para clínicas.

## 7. Métricas de Sucesso (KPIs)
*   **Activation Score:** Percentual de clínicas que realizam o primeiro upload de exame via Integrador nos primeiros 7 dias.
*   **Time-to-Chart:** Tempo médio gasto pelo médico para concluir o preenchimento e assinatura de um prontuário.
*   **Retention (LTV):** Baixa taxa de churn devido à dependência tecnológica da integração com hardware.
