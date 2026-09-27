# Product

<!-- impeccable:product-schema 1 -->

## Platform

web

## Users

Três públicos decidem juntos a assinatura, com o mesmo peso (confirmado em 2026-09-27):

- **Dono ou gestor da clínica** (médico-proprietário ou gestor): decide a compra; pesa custo, risco regulatório (CFM/LGPD) e ganho de operação.
- **Oftalmologista**: usa prontuário, laudos, exames e imagens durante a consulta; influencia a escolha.
- **Gestão administrativa e financeira**: recepção, agenda, faturamento de convênio (TISS), glosas e caixa.

Perfis dentro do produto: admin, financeiro, médico, secretária e usuário comum (clínica); parceiro comercial (portal de leads e comissões); integradores externos (API com token).

## Product Purpose

EasyEye é um SaaS multi-clínica para clínicas de oftalmologia no Brasil. Reúne a operação clínica do dia a dia (pacientes, médicos, agenda, prontuário, documentos, exames e imagens), a conformidade regulatória (CFM/LGPD), o financeiro e o faturamento TISS num só sistema.

Sucesso para a clínica: sair do papel, das planilhas e das ferramentas soltas; reduzir no-show e glosa; ter o histórico do paciente num só lugar e trilha de auditoria de tudo.

## Positioning

Quatro diferenciais confirmados, que um sistema genérico não afirma com verdade:

1. **Feito para oftalmologia**: campos, laudos, imagens organizadas por olho e integração com aparelhos oftalmológicos, em vez de prontuário genérico adaptado.
2. **TISS integrado**: guias, lotes XML (TISS 3.06), envio, processamento de retorno e pré-validação para reduzir glosa.
3. **Tudo num só sistema**: agenda, prontuário, exames, documentos e financeiro sem ferramentas soltas.
4. **Conformidade CFM/LGPD desde a arquitetura**: trilha de auditoria, versionamento e assinatura de prontuário.

## Operating Context

- Rotina de recepção, consultório e faturamento; vários médicos, salas e equipamentos; atendimento por convênio (TISS) e particular.
- Exames chegam dos aparelhos pelo integrador desktop local e pela API de integradores.
- Uma conta pode operar várias unidades com um único login (multi-clínica).
- Idiomas: português do Brasil (principal) e inglês.
- Planos e preços vêm do banco (Básico, Pro, Premium na data deste registro); dias de teste grátis definidos por plano.

## Capabilities and Constraints

- Isolamento por clínica (tenant); autorização por perfil; dados de paciente nunca em logs, formulários de marketing ou materiais públicos.
- Todo texto de interface vem dos arquivos de tradução (`lang/{pt_BR,en}`); a copy da landing fica em `lang/{pt_BR,en}/site.php`.
- Assistente de IA existe como apoio (créditos por plano), com conduta final sempre do médico; nunca apresentado como substituto do julgamento clínico.
- Programa de optotipos ainda não existe no produto: só aparece marcado como "Em breve", apenas no card do plano Premium, e nunca como já incluído.
- Módulo de estoque está incluído em todos os planos; não é exclusividade do Premium.
- Stack atual: Laravel 12 (PHP 8.4), Vue 3 com Inertia, PostgreSQL; a landing tem renderização no servidor (SSR).

## Brand Commitments

- Nome: **EasyEye**. Logos: `resources/img/system/logo.svg`, `logo-white.svg` e `logo-small.svg`.
- A identidade visual atual da landing é mantida em refinamentos (pedido do responsável em 2026-09-27). Redesign só com pedido explícito.

## Evidence on Hand

Confirmado como real e verificável pelo responsável em 2026-09-27 (textos em `lang/{pt_BR,en}/site.php`):

- Métricas: 500+ clínicas ativas, 50k+ consultas por mês, 99,9% de uptime, 97% de satisfação; R$ 0 de taxa de implantação.
- Depoimentos nominais: Dr. Ricardo Mendes (oftalmologista, Clínica Visão SP), Dra. Ana Carvalho (diretora, Instituto Ocular BH), Paulo Souza (gestor, Rede OftalmoClin RJ), Dra. Mariana Costa (Clínica Visão SP).
- TISS 3.06 homologado pela ANS.
- Capturas reais do produto: `public/site/images/how-it-works.png` e `demo-{agenda,imagens,laudos,prontuario}.png`.

Ausências: não há estudos de caso nem imprensa registrados. Qualquer número, depoimento ou selo novo precisa de fonte antes de ir para a landing.

## Product Principles

1. **Especialidade antes de genérico**: funcionalidade e copy partem da rotina real de uma clínica oftalmológica.
2. **Conformidade não é adendo**: CFM, LGPD, auditoria e privacidade do paciente valem em todo fluxo, inclusive no marketing.
3. **Uma fonte da verdade para a clínica**: integrar (aparelhos, TISS, financeiro) em vez de somar ferramentas.
4. **Prova antes de promessa**: só números e depoimentos verificados; o que ainda não existe aparece como novidade, nunca como incluído.
5. **Falar com os três públicos**: custo e risco para quem decide, prática clínica para o médico, operação e faturamento para a gestão.

## Accessibility & Inclusion

- Requisito do responsável: acessibilidade, responsividade e modo escuro no painel; alvo WCAG 2.2 AA.
- Landing: alvos de toque de 44px em telas de toque, animações respeitam `prefers-reduced-motion` e nunca condicionam a visibilidade do conteúdo.
