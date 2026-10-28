---
target: toda a landing page EasyEye
total_score: 25
max_score: 36
na_heuristics: 7
p0_count: 0
p1_count: 2
target_identity: "file:/Users/higormelo/Sites/MyStartup/easyeye/resources/js/Pages/Site/Home.vue"
target_fingerprint: "sha256:e15c758d94551e676c349f497769baca8d0a8026863d1f202b280e979bed9bf3"
target_path: /Users/higormelo/Sites/MyStartup/easyeye/resources/js/Pages/Site/Home.vue
timestamp: 2026-09-30T00-33-39Z
slug: resources-js-pages-site-home-vue
closed: true
---
Método: duas avaliações independentes — A: critique_design; B: critique_evidence.

A landing tem identidade consistente e boa hierarquia, mas precisa esclarecer melhor a oferta e encurtar o caminho até a decisão. Não vejo necessidade de redesign: navy/teal, tipografia e linguagem clínica formam uma base que vale preservar.

O conteúdo é específico da oftalmologia; a composição ainda é bastante convencional de SaaS. Títulos centralizados, pílulas e cartões se repetem, enquanto as telas reais do produto poderiam conduzir mais a narrativa.

Avaliação heurística: 25/36 — aceitável, próxima da faixa “boa”. É uma avaliação de usabilidade, não uma medição de conversão.

| Heurística | Nota | Leitura |
|---|---:|---|
| Visibilidade do estado | 3/4 | Abas e FAQ claros; envio, sucesso e erros previstos no formulário. |
| Linguagem do usuário | 3/4 | Boa especificidade clínica; “gateways” e “chat flutuante” são menos claros. |
| Controle e liberdade | 3/4 | Navegação reversível; demonstração automática sem botão explícito de pausa. |
| Consistência | 2/4 | Visual coerente; oferta e condições comerciais divergem entre fontes. |
| Prevenção de erros | 3/4 | Rótulos, limites, autocomplete e orientação sobre dados de pacientes. |
| Reconhecimento | 2/4 | Comparar planos empilhados exige lembrar o cartão anterior. |
| Eficiência operacional | n/a | Não é objetivo desta landing de apresentação e conversão. |
| Estética e minimalismo | 3/4 | Boa organização, com repetição de argumentos e estruturas. |
| Recuperação de erros | 3/4 | Código preserva dados e prevê mensagens específicas; sem envio real. |
| Ajuda | 3/4 | FAQ útil; condições de gratuidade pouco explícitas na landing. |
| Total | 25/36 | Sem P0 identificado; duas prioridades P1. |

O que funciona:
- A especialidade aparece cedo; o CTA principal se destaca e “Ver o sistema por dentro” oferece uma alternativa pertinente.
- Os grupos Recepção, Consultório e Faturamento ajudam os três públicos a reconhecer sua rotina.
- As capturas reais, as explicações concretas de conformidade e o FAQ sobre funcionamento offline fortalecem a confiança.

Prioridades:

1. [P1] Explicar a gratuidade e preservar a escolha do plano.
A landing repete “Começar grátis”, mas não mostra prazo de teste na configuração renderizada. Ao abrir o cadastro, encontrei “7 dias grátis”. A causa está em fontes diferentes: Home calcula o prazo pelos planos; cadastro usa SubscriptionSetting::trialDays(). Além disso, os três CTAs dos planos apontam para /register sem identificar a escolha, e o cadastro inicializa o primeiro plano.
Recomendação: usar a mesma condição comercial nas duas telas, explicar o teste junto do CTA e levar o plano escolhido ao cadastro. Isso resolve dúvidas antes do clique e evita refazer a decisão. Comandos: Impeccable Clarify e Harden.
Evidências: resources/js/Pages/Site/Home.vue:760; app/Http/Controllers/Auth/RegisteredUserController.php:41; resources/js/Pages/Auth/Register.vue:87.

2. [P1] Reconciliar o que está incluído em cada plano.
O Pro mostra “Módulo de estoque” após “Tudo do Básico, mais”, mas PRODUCT.md:48 registra estoque em todos os planos. A faixa de recursos comuns omite estoque. Também há destaque de TISS nas descrições dos planos superiores, embora TISS esteja na faixa comum.
Recomendação: confirmar qual fonte comercial está vigente e alinhar catálogo, descrições e recursos comuns. A divergência é verificável; não é possível concluir só pela landing qual regra comercial deve prevalecer. Comando: Impeccable Clarify.

3. [P2] Melhorar o ritmo e a comparação no mobile.
Em 390×844, a página mede aproximadamente 17,4 mil px; os planos começam perto de 10 mil px de rolagem. As âncoras ajudam, mas funcionalidades e diferenciais repetem especialidade, integração e “tudo num lugar”. Pro e Premium acumulam oito/nove itens, e “Tudo do plano anterior” exige voltar na página.
Recomendação: reduzir repetição antes de reduzir espaçamento; agrupar capacidade, IA e integrações; apresentar os critérios decisivos de forma comparável e deixar detalhes expansíveis. Preservar o conteúdo útil e a identidade. Comandos: Impeccable Distill e Layout.

4. [P2] Usar uma imagem principal que comprove melhor a promessa.
O hero promete gestão completa, mas a captura do dashboard contém vários indicadores com “Em breve”. Os cartões falam de imagens por olho e prontuário assinado, enquanto a imagem mostra um painel administrativo. No celular, os detalhes da interface ficam pequenos.
Recomendação: manter moldura, paleta e composição, escolhendo um recorte real de uma tarefa clínica já disponível, diretamente relacionado aos destaques. Não preencher artificialmente recursos futuros. Comando: Impeccable Layout.
Evidência: public/site/images/hero-dashboard.webp.

5. [P2] Facilitar o primeiro contato.
Há sete campos mais consentimento; três seletores opcionais têm peso visual semelhante aos obrigatórios. No mobile, o formulário ocupa cerca de 1.057 px e aparece antes do WhatsApp comercial.
Recomendação: apresentar o contato direto primeiro no mobile, explicitar os campos opcionais e separar a qualificação secundária da primeira mensagem. “Falar com um especialista” também deveria indicar o canal: hoje o CTA final abre e-mail, enquanto a central de vendas usa WhatsApp. Comandos: Impeccable Distill e Clarify.
Evidência: resources/js/Components/Site/ContactForm.vue:18; resources/js/Pages/Site/Home.vue:544.

Tipografia e espaçamentos:
A escala de títulos está bem resolvida; não recomendo trocar a fonte nem aumentar os pesos. Textos de planos com 14 px e rótulos de 13 px merecem atenção no mobile. No desktop, o selo do Pro desloca preço e descrição, e a equalização de altura deixa grande vazio no Básico. Alinhar as faixas de comparação ajudaria mais que comprimir toda a página. O espaço entre os cartões laterais do contato também é excessivamente distribuído pela altura do formulário.

Observações menores:
- “Dúvidas frequentes” acima de “Perguntas frequentes” é redundante.
- O CTA fixo mobile aparece junto do CTA de alguns planos; pode recolher quando a ação equivalente está visível.
- A coluna “Empresa” do rodapé contém apenas “Contato”, desequilibrando a densidade.
- As métricas e os depoimentos têm uma nota de contexto; um caso detalhado ou metodologia acessível reforçaria a prova sem adicionar promessas.

Carga cognitiva moderada: grupos extensos nos planos, seis destinos no menu desktop além das ações de conta, e esforço de memória na comparação mobile. A jornada começa clara, ganha força na demonstração e perde ritmo na repetição de argumentos; oferta e formulário são os pontos de maior atrito.

Personas:
- Gestor criterioso: precisa entender custo, prazo gratuito e diferenças reais entre planos.
- Oftalmologista conhecendo o produto: precisa reconhecer uma tarefa clínica comprovada pela imagem principal.
- Visitante no celular e com pressa: encontra bons alvos de toque, mas enfrenta comparação longa e contato direto tardio.

Detector:
CLI: zero achados nos três componentes Vue analisados. No navegador: 21 alvos anotados, com 26 associações em sete regras. Predominam gradientes/paleta, nove rótulos acima de títulos, Inter, molduras com borda/sombra e uma linha longa sem localização confirmada.
O alerta de logo oculto é falso positivo: são as versões clara/escura alternadas. Fonte, cor e gradientes pertencem à identidade existente; não justificam mudanças isoladamente. A repetição de rótulos converge com a avaliação visual. A varredura estática tem cobertura limitada sobre traduções, loops Vue e SCSS externos.

Perguntas para orientar uma próxima etapa:
1. Prioridade: clareza da oferta e CTAs, ou ritmo visual e comparação mobile?
2. Escopo: corrigir apenas os dois P1, ou trabalhar os cinco pontos preservando a identidade?
