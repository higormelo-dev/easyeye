---
target: landing page completa (Home.vue)
total_score: 22
max_score: 32
na_heuristics: 7,10
p0_count: 0
p1_count: 4
target_identity: "file:/Users/higormelo/Sites/MyStartup/easyeye/resources/js/Pages/Site/Home.vue"
target_fingerprint: "sha256:613cb08da4bcd21d72148c44ce69211a8d1bff4d1b14344a3c485062f0577568"
target_path: /Users/higormelo/Sites/MyStartup/easyeye/resources/js/Pages/Site/Home.vue
timestamp: 2026-09-27T20-44-41Z
slug: resources-js-pages-site-home-vue
---
# Critique — landing EasyEye (resources/js/Pages/Site/Home.vue)

## Design Health Score
| # | Heurística | Nota | Problema principal |
|---|---|---|---|
| 1 | Visibilidade do status | 3 | Formulário exemplar; página longa sem seção ativa na nav; "Como funciona" e demo trocam sozinhos sem indicar tempo/pausa. |
| 2 | Linguagem do usuário | 3 | Fala a língua da clínica; vazam "compliant", "LGPD Compliant", "Uptime", "EMR"; "Ver demonstração" com ícone de vídeo leva a prints. |
| 3 | Controle e liberdade | 3 | Passos de "Como funciona" giram sem pausa; demo pausa só com mouse/foco (sem hover no toque, risco WCAG 2.2.2); menu móvel não trava rolagem. |
| 4 | Consistência | 2 | "Plano Professional" inexistente no FAQ; "Novidade" (PT) × "Coming soon" (EN); "14 dias" fixo; 4 variantes de card; <title> "EasyEye — EasyEye". |
| 5 | Prevenção de erros | 3 | Formulário protegido; selects sem seta; "Começar grátis" sem prazo/cartão. |
| 6 | Reconhecer × lembrar | 3 | Comparar planos exige memorizar listas de 13 linhas; idioma só por bandeira no desktop. |
| 7 | Flexibilidade | n/a | Página de persuasão. |
| 8 | Estética e minimalismo | 2 | Mesmas ~6 capacidades em 5 blocos de cards; eyebrow em toda seção; gradiente; blobs; esqueleto no hero. |
| 9 | Recuperação de erros | 3 | Erros acessíveis e dados preservados; 419 manda copiar e recarregar. |
| 10 | Ajuda | n/a | Landing; FAQ trata objeções. |
| Total | | 22/32 (69%) | Aceitável (7 e 10 n/a) |

## Design Specificity Verdict
Forma intercambiável com qualquer SaaS de clínica; especificidade só na copy e nos prints reais (OCT, biometria, retinografia, refração OD/OE), enterrados em ~5.400px (desktop) / ~10.200px (celular). Hero é esqueleto de dashboard; 11 seções com o mesmo eyebrow; 5 grades de ícone em quadrado tingido. Detector: CLI 3 achados (side-tab Home.vue:93,133; overused-font Home.vue:54); navegador 163 (desktop) / 160 (celular): low-contrast 34 (branco sobre teal 2,5:1 nos CTAs), kicker-above-heading 12, side-tab 8, gradient-text 5, skipped-heading 2, bounce-easing 68 (um só token), ai-color-palette 24 e overused-font (identidade), line-length (nota de créditos ~167 car./linha), undersized-ui-text (selo "Novidade" 10px), oversized-h1 (72px, 4 linhas). Falsos positivos: buried-raster no logo SVG (troca intencional); text-occlusion (artefato do overlay).

## Priority Issues
1. [P1] Prova de especialidade enterrada e primeira dobra genérica; prints de ambiente de teste ("CLÍNICA TESTE INTEGRADOR", "teste E2E", e-mail @example.org, KPIs "Em breve" em TISS/financeiro); prints ilegíveis no celular. Fix: recorte real OD/OE ou refração no hero (mantendo moldura/paleta/cartões com rótulos verdadeiros), recapturar prints em ambiente de demo com "Dados fictícios", recortes de detalhe no celular, demo logo após Problemas. Comando: $impeccable bolder.
2. [P1] Parede de cards redundante (6+8+6+6+6+4 repetindo as mesmas capacidades; ~7.200px no celular antes de "Como funciona"); ~15.700px sem CTA de cadastro no celular. Fix: 3 blocos por público com 2–3 resultados e 1 recorte de tela; Problemas com 3–4 dores; Diferenciais = os 4 do PRODUCT.md; alternar fundos; "Começar grátis" persistente no celular. Comando: $impeccable distill.
3. [P1] Contraste abaixo de WCAG AA no caminho de conversão: branco sobre teal 2,46:1 em todos os CTAs primários e no selo "Mais popular"; links teal 2,46:1; eyebrows ~3:1; rodapé legal 2,68:1; "não incluído" 1,97:1; anel de foco teal 2,46:1 sobre branco. Fix: texto navy #0F2551 no botão teal (6,1:1) ou fundo #007A93 (5:1); links #00708A ou #1E5EBF; rodapé legal opacidade ≥0,6; "não incluído" 4,5:1 com traço; foco navy nas seções claras. Comando: $impeccable polish.
4. [P1] Callout Premium promete optotipos (inexistente) com "Novidade" em PT ("Coming soon" em EN), "tem acesso a ferramentas de gestão", bloco mais pesado do meio da página; Compliance real com só 422px de chips. Fix: "Em breve" + verbo no futuro; reduzir a uma linha no card Premium ou retirar; peso visual para conformidade com evidência real (auditoria, prontuário travado). Comandos: $impeccable clarify + $impeccable quieter.
5. [P2] Preços não ajudam a decidir: CTAs desalinhados até 106px (align-items:start); "✓ Sem créditos de IA"; "Até 1 médico(s)", "10000"; sem linha TISS/financeiro por plano; exclusivos do Premium mostrados como gerais; teste nulo nos planos × "14 dias" fixo; estoque ✗ no Básico contra PRODUCT.md (dado do banco). Fix: CTAs na base do card; "Tudo do Básico, mais:"; linha TISS/financeiro; microcopy de teste do banco; marcar "no Premium"; "1 médico", "10.000", traço para ausências. Comando: $impeccable layout.

## Persona Red Flags
- Jordan: título diz categoria e não o porquê; "Ver demonstração" promete vídeo; CTA sem prazo/cartão até ~12.700px; jargão ("EMR", "Uptime", "compliant", "Revisão inteligente de consistência"); "Agenda" × "Agenda Inteligente"; FAQ sem "instalar?", "teste?", "cancelar e levar dados?".
- Riley: "plano Professional"; "Novidade" × "Coming soon"; "Uptime garantido" com nota de monitoramento; passos de "Como funciona" com cursor de clique sem efeito nem foco; avatar "DR"; "Falar com um especialista" é mailto; "teste E2E" nos prints.
- Casey: ~27 telas; ~15.700px sem CTA de cadastro; prints ilegíveis; 5 PNGs (1,77 MB) sem lazy/srcset, 2 pacotes de ícones (~780 KB), Inter em 7 pesos; WhatsApp só após formulário de 8 campos.
- Dra. Helena (dona de clínica): "Compliance garantido"/"Resolução CFM" sem número nem página de segurança; rodapé sem razão social/CNPJ/DPO e link LGPD oculto; prints com nome de paciente sem "dados fictícios"; Premium com item inexistente.
- Márcia (faturamento): demo sem aba TISS/Financeiro, nenhuma guia ou glosa na página; "Guias aguardando"/"A receber" como "Em breve"; planos não dizem se o Básico fatura TISS; FAQ sem convênios, glosa/recurso, migração de agenda e financeiro.

## Minor Observations
Subtítulos descentralizados (Funcionalidades, Depoimentos, Planos; `.text-center .section-sub` exige pai .text-center); `.cta-note` sai com 18px (`.cta-final p` vence); h2 do CTA final com line-height 1,6; "Gestão" sozinha no h1 e viúva "isso?"; sem text-wrap: balance; faixas vazias ~200px entre seções de mesmo fundo; selects sem seta; sem <main>/skip link; h2→h4 no aside do contato; dois pacotes de ícones; avatares "JA MC RS PL" genéricos; Title Case esporádico; coluna "Empresa" com um link; "Conhecer o Plano Premium" leva ao topo de #precos; cinza #64748b sobre #f4f7fb 4,4:1 (15 textos) e placeholders 4,3:1 (detector).

## Questions to Consider
1. Com uma retinografia OD/OE no hero, ainda seriam necessários 20 cards para provar a especialidade?
2. Para cada público, o caminho principal é teste self-service ou conversa com vendas?
3. O que convence de "conformidade desde a arquitetura": chips ou uma tela da trilha de auditoria?
4. Por que a gestora de faturamento não vê nenhuma guia TISS numa página que abre com "TISS 3.06 homologado"?
5. Se o optotipos saísse até existir, o que a venda do Premium perderia?
