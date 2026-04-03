**Versão:** 2.5
**Data:** Abril de 2026
**Status:** Madura (Stack Blade + Alpine.js + Tailwind CSS)

---

## 1. Visão do Produto
O **EasyEye** é uma plataforma SaaS (Software as a Service) multi-tenant projetada como o "Sistema Operacional" para clínicas de oftalmologia. O sistema integra a jornada completa do paciente — do agendamento à conformidade legal — com um diferencial técnico único: a automação da captura de exames diretamente do hardware diagnóstico para a nuvem.

## 2. O Problema & Soluções Core
1.  **Gargalo de Hardware:** Médicos gastam tempo movendo exames manualmente.
    *   *Solução:* **EasyEye Integrator**, um bridge que automatiza o upload e vinculação de exames via IA de reconhecimento de arquivos.
2.  **Risco Jurídico (CFM/LGPD):** Falta de imutabilidade em prontuários e auditoria de dados sensíveis.
    *   *Solução:* Assinatura eletrônica com hash SHA-256, versionamento de prontuários (snapshots) e logs automáticos de acesso a dados.
3.  **Complexidade Operacional:** Sistemas legados são lentos e confusos.
    *   *Solução:* Interface premium baseada em templates modernos, focada na experiência do usuário (UX) e rapidez de resposta.

## 3. Personas
*   **Dr. Roberto (Oftalmologista):** Focado em agilidade clínica e segurança jurídica.
*   **Ana (Recepção/Secretária):** Gerencia o fluxo de pacientes e faturamento.
*   **Carlos (Gestor da Clínica):** Focado em indicadores de crescimento (CAC/LTV) e compliance total.

## 4. Funcionalidades de Grande Porte (Implementadas)

### 4.1 Ecossistema de Crescimento (CAC/Growth)
Uma camada estratégica para reduzir o custo de aquisição e aumentar a retenção:
*   **Programa de Indicação (Referral):** Sistema peer-to-peer onde clínicas indicam outras e recebem recompensas (descontos ou extensões de trial).
*   **Portal de Parceiros/Revendedores:** Dashboard para distribuidores de equipamentos e consultores gerenciarem leads e comissões automáticas na conversão.
*   **Ativação de Trial (Activation Score):** Monitoramento de 7 marcos críticos (ex: 1º médico adicionado, 1º exame subido) para identificar clínicas com baixo engajamento.

### 4.2 Gestão Clínica & PEP
*   **Prontuário Oftalmológico Especializado:** Campos táticos para refração, biometria e exames complementares.
*   **Agendamento Multi-Unidade:** Controle centralizado de agendas com status em tempo real.
*   **Waiting Room TV:** Display para sala de espera que sincroniza com a recepção via QRCode.

### 4.3 Compliance "Enterprise Ready"
*   **CFM Res. 2.227/2018:** Assinatura digital que congela o registro clínico; qualquer alteração subsequente cria uma nova versão imutável.
*   **LGPD Nativa:** Gestão de consentimentos do paciente por finalidade (Art. 7/11) e canal de atendimento para solicitações de direitos do titular (Art. 18).

## 5. Arquitetura Multi-Tenant
*   **Isolamento de Dados:** Cada clínica (Entidade) possui seus dados totalmente isolados logicamente.
*   **RBAC (Role-Based Access Control):** Papéis granulares (Dono, Administrador, Médico, Recepcionista) que herdam permissões específicas por entidade.

---

## 6. Roadmap e Futuro (V3+)

### 6.1 IA & Expansão Clínica
*   **Assistente de Redação:** IA para síntese de histórias clínicas a partir de tópicos.
*   **Integração TISS/TUSS:** Faturamento eletrônico para convênios conforme padrão ANS.

### 6.2 Mobile & Engajamento
*   **Portal do Paciente:** Visualização de laudos e receitas em ambiente seguro.
*   **App do Médico:** Notificações de agenda e visualização rápida de prontuários.

## 7. Métricas de Sucesso (KPIs)
*   **Activation Score (P90):** Tempo médio para atingir 100% de ativação nos primeiros 15 dias.
*   **Referral Conversion Rate:** Percentual de trials iniciados via indicação que se convertem em planos pagos.
*   **Time-to-Sign:** Velocidade entre a consulta e a assinatura definitiva do prontuário.
*   **Churn Preventivo:** Identificação de clínicas com queda de uso do Integrador (sinal de churn).
*   **Retention (LTV):** Baixa taxa de churn devido à dependência tecnológica da integração com hardware.
