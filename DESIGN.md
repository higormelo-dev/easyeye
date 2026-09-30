---
name: "EasyEye — site institucional"
description: "Precisão clínica: uma identidade clara, confiável e organizada."
colors:
  navy: "#0F2551"
  navy-mid: "#1B3A6B"
  navy-dark: "#0A1628"
  blue: "#1E5EBF"
  teal: "#00B4D8"
  teal-lt: "#90E0EF"
  teal-ink: "#00708A"
  mint: "#06D6A0"
  bg: "#F4F7FB"
  bg-white: "#FFFFFF"
  text: "#1A202C"
  text-muted: "#5A6A80"
  border: "#E2E8F0"
typography:
  display:
    fontFamily: "Inter, 'Inter Fallback', system-ui, -apple-system, sans-serif"
    fontSize: "clamp(2.375rem, calc(1.5rem + 3vw), 4rem)"
    fontWeight: 900
    lineHeight: 1.08
    letterSpacing: "-0.02em"
  headline:
    fontFamily: "Inter, 'Inter Fallback', system-ui, -apple-system, sans-serif"
    fontSize: "clamp(1.75rem, calc(1.25rem + 2vw), 2.75rem)"
    fontWeight: 800
    lineHeight: 1.16
    letterSpacing: "-0.015em"
  title-panel:
    fontFamily: "Inter, 'Inter Fallback', system-ui, -apple-system, sans-serif"
    fontSize: "clamp(1.25rem, calc(1rem + 1vw), 1.5rem)"
    fontWeight: 800
    lineHeight: 1.25
    letterSpacing: "-0.015em"
  title-card:
    fontFamily: "Inter, 'Inter Fallback', system-ui, -apple-system, sans-serif"
    fontSize: "1.25rem"
    fontWeight: 800
    lineHeight: 1.25
    letterSpacing: "-0.015em"
  title-item:
    fontFamily: "Inter, 'Inter Fallback', system-ui, -apple-system, sans-serif"
    fontSize: "1.125rem"
    fontWeight: 700
    lineHeight: 1.35
    letterSpacing: "-0.01em"
  lead:
    fontFamily: "Inter, 'Inter Fallback', system-ui, -apple-system, sans-serif"
    fontSize: "1.125rem"
    fontWeight: 400
    lineHeight: 1.6
  body:
    fontFamily: "Inter, 'Inter Fallback', system-ui, -apple-system, sans-serif"
    fontSize: "1rem"
    fontWeight: 400
    lineHeight: 1.65
  body-card:
    fontFamily: "Inter, 'Inter Fallback', system-ui, -apple-system, sans-serif"
    fontSize: "1rem"
    fontWeight: 400
    lineHeight: 1.6
  label:
    fontFamily: "Inter, 'Inter Fallback', system-ui, -apple-system, sans-serif"
    fontSize: "0.9375rem"
    fontWeight: 600
    lineHeight: 1.6
  meta:
    fontFamily: "Inter, 'Inter Fallback', system-ui, -apple-system, sans-serif"
    fontSize: "0.875rem"
    fontWeight: 400
    lineHeight: 1.55
  micro:
    fontFamily: "Inter, 'Inter Fallback', system-ui, -apple-system, sans-serif"
    fontSize: "0.8125rem"
    fontWeight: 400
    lineHeight: 1.5
  numeral:
    fontFamily: "Inter, 'Inter Fallback', system-ui, -apple-system, sans-serif"
    fontWeight: 900
    letterSpacing: "-0.01em"
    fontFeature: "\"tnum\""
  price:
    fontFamily: "Inter, 'Inter Fallback', system-ui, -apple-system, sans-serif"
    fontSize: "clamp(2.25rem, calc(1.75rem + 1vw), 2.75rem)"
    fontWeight: 900
    lineHeight: 1.1
    letterSpacing: "-0.01em"
    fontFeature: "\"tnum\""
rounded:
  control: "8px"
  surface: "12px"
  card: "16px"
  surface-large: "20px"
  pill: "999px"
spacing:
  inline-small: "8px"
  inline: "12px"
  related: "16px"
  content-small: "20px"
  gutter: "24px"
  card-inset: "28px"
  panel: "32px"
  section-heading: "40px"
  section-mobile: "64px"
  section-tablet: "80px"
  section: "100px"
components:
  button-primary:
    backgroundColor: "{colors.teal}"
    textColor: "{colors.navy}"
    typography: "{typography.label}"
    rounded: "{rounded.control}"
    padding: "12px 24px"
  button-outline:
    backgroundColor: "transparent"
    textColor: "{colors.navy}"
    typography: "{typography.label}"
    rounded: "{rounded.control}"
    padding: "12px 24px"
  button-featured:
    backgroundColor: "{colors.teal}"
    textColor: "{colors.navy}"
    typography: "{typography.label}"
    rounded: "{rounded.control}"
    padding: "12px 24px"
  input:
    backgroundColor: "{colors.bg}"
    textColor: "{colors.text}"
    rounded: "{rounded.control}"
    padding: "11px 14px"
  nav-link:
    textColor: "#3A4A6B"
    padding: "8px 10px"
    rounded: "6px"
  plan-badge:
    backgroundColor: "{colors.teal}"
    textColor: "{colors.navy}"
    rounded: "{rounded.pill}"
    padding: "4px 10px"
  audience-card:
    backgroundColor: "{colors.bg}"
    textColor: "{colors.navy}"
    rounded: "{rounded.card}"
    padding: "{spacing.card-inset}"
  pricing-card:
    backgroundColor: "{colors.bg-white}"
    textColor: "{colors.navy}"
    rounded: "{rounded.card}"
    padding: "{spacing.card-inset}"
---

# Design System: EasyEye

## Overview

**Creative North Star: "Precisão clínica"**

Precisão clínica traduz uma interface clara, confiável e organizada. A Inter estabelece uma voz única; o azul-marinho dá estrutura, o teal orienta as ações e os neutros frios oferecem superfícies tranquilas para a leitura. A identidade foi confirmada pelo responsável pelo produto.

Os componentes são claros, organizados e têm cantos suavemente arredondados. Superfícies claras com bordas discretas formam a base; sombras suaves distinguem capturas do produto e áreas de destaque. A densidade se adapta à largura disponível, preservando o conteúdo, a leitura e os controles.

**Escopo:** site institucional e landing pública do EasyEye. Este registro descreve o código existente; não define o visual do painel autenticado nem do aplicativo integrador. A revisão tipográfica preserva a identidade e distingue leitura, controles, títulos e números por função.

**Key Characteristics:**

- Uma única família tipográfica: Inter.
- Azul-marinho, teal e neutros frios com papéis distintos.
- Grades alinhadas e espaçamento consistente entre conteúdos relacionados.
- Cantos suaves, bordas discretas e profundidade seletiva.
- Capturas reais do produto e ícones consistentes, sem substituir conteúdo por decoração.

Fontes do registro: [tokens](resources/css/site/_variables.scss), [base](resources/css/site/_base.scss), [layout](resources/css/site/_layout.scss), [seções](resources/css/site/_sections.scss), [planos](resources/js/Components/Site/PricingPlans.vue), [formulário](resources/js/Components/Site/ContactForm.vue), [animações](resources/js/site-animations.js), [comportamento da landing](resources/js/Pages/Site/Home.vue) e [carregamento de fontes](resources/views/app.blade.php). Valores representativos foram conferidos na página local em desktop e mobile. A estrutura segue a [especificação DESIGN.md](https://github.com/google-labs-code/design.md/blob/main/docs/spec.md); o frontmatter contém os valores normativos e o texto explica sua aplicação.

## Colors

Azul-marinho e teal conduzem a identidade; neutros frios organizam o conteúdo sem competir com os destaques.

### Primary

- **Azul-marinho (`navy`):** títulos, contornos, superfícies de destaque e texto nos botões teal.
- **Azul-marinho intermediário (`navy-mid`):** variação de superfície, contornos e foco em fundos claros.
- **Azul-marinho profundo (`navy-dark`):** rodapé e fundo de maior contraste.
- **Teal (`teal`):** preenchimento de ações primárias, destaques e indicadores.
- **Teal claro (`teal-lt`):** texto, ícones e foco sobre superfícies escuras.
- **Teal de leitura (`teal-ink`):** links e rótulos sobre fundos claros.

### Secondary

- **Azul de apoio (`blue`):** ícones, avatares e marcadores secundários.
- **Verde de confirmação (`mint`):** indicadores e ícones de confirmação. O significado continua disponível em texto.

### Neutral

- **Fundo frio (`bg`):** seções alternadas e áreas internas de conteúdo.
- **Branco (`bg-white`):** superfície principal, formulários e cartões de planos.
- **Texto principal (`text`):** parágrafos e conteúdo de leitura.
- **Texto secundário (`text-muted`):** descrições, legendas e metadados.
- **Divisória fria (`border`):** contornos e separação entre áreas.

**The Contrast Rule.** Nas ações teal da landing, usar texto azul-marinho. Sobre superfícies claras, links usam teal escuro; o teal de preenchimento não substitui a cor de texto.

Gradientes navy existentes contextualizam hero, fechamento e áreas de destaque. A ênfase do hero e alguns números usam um gradiente teal já implementado. Esses tratamentos são registros do visual atual, não uma orientação para aplicar gradiente em todos os títulos. Cores pontuais de ícones e erros permanecem nos componentes que as utilizam, sem ampliar a paleta principal.

## Typography

**Display Font:** Inter.
**Body Font:** Inter.
**Fallback:** Inter Fallback, system-ui, -apple-system e sans-serif.

A mesma família sustenta títulos fortes, leitura regular e controles semibold. A hierarquia usa pesos, medidas, entrelinhas e espaço; não há família adicional para simular linguagem técnica.

### Hierarchy

| Papel no frontmatter | Aplicação atual |
| --- | --- |
| `display` | Título principal do hero; maior peso e escala fluida. |
| `headline` | Títulos das seções, contato e fechamento. |
| `title-panel` | Títulos de painéis internos, incluindo integrador, créditos de IA e conformidade, com a mesma escala fluida. |
| `title-card` | Títulos de públicos, planos e formulário. |
| `title-item` | Passos, diferenciais e itens de contato. |
| `lead` | Introduções de seção. O hero possui uma variação fluida própria. |
| `body` | Respostas do FAQ e textos de leitura. |
| `body-card` | Descrições em cartões, recursos, depoimentos e nota de créditos. |
| `label` | Botões e rótulos de formulário. |
| `meta` | Legendas e informações secundárias. |
| `micro` | Anotações breves e elementos densos. |
| `numeral` | Peso e números tabulares dos indicadores; tamanho definido pelo componente. |
| `price` | Preços dos planos com escala fluida própria; a variante sob consulta usa o tamanho de título de painel e peso 800. |

Os títulos usam quebras equilibradas; introduções e parágrafos selecionados evitam linhas finais muito curtas. Os títulos de seção têm medida de até 30ch, as introduções de seção até 60ch e a prosa do FAQ e dos créditos até 68ch. A descrição do integrador mantém sua medida de 48ch. As medidas acompanham a largura disponível, sem altura fixa ou truncamento de traduções.

A Inter variável carrega no HTML inicial do site, com pesos de 400 a 900, eixo óptico de 14 a 32 e `display=swap`. A substituta local usa Arial com ajuste de tamanho e métricas para reduzir mudanças de quebra. A composição não exige fonte itálica adicional.

**The Inter Rule.** Preservar a Inter e sua hierarquia por função; não introduzir outra família sem uma mudança de identidade explicitamente solicitada.

**Aplicações por função:** campos do formulário usam 16px com entrelinha 1.5 em todos os tamanhos; os rótulos mantêm 15px e peso 600. A prosa dos cartões e dos planos usa 16px, enquanto linhas compactas de comparação, controles e notas de teste mantêm 15px. Metadados e notas breves preservam seus papéis menores. Os títulos do integrador e dos créditos usam o mesmo papel de painel. Preços usam o papel `price`; a variante sob consulta acompanha a escala de painel com peso 800 e entrelinha 1.25.

Ícones dimensionados em pixels e monogramas de avatar a 11px são exceções gráficas existentes. Eles não definem novos papéis de prosa nem justificam reduzir o texto de leitura.

## Layout

O site combina seções amplas, grades e colunas de leitura. O container central tem largura máxima de 1200px, recuos laterais de 24px e recuos de 16px abaixo de 480px.

- Seções principais usam respiro vertical de 100px, reduzido a 80px até 900px e a 64px até 640px. O hero possui sua própria reserva para a navegação fixa.
- Grades de cartões usam intervalos recorrentes de 24px. Recuos internos observados variam entre 20px, 24px, 28px, 32px e 40px conforme o componente.
- Os três cartões de funcionalidades compartilham linhas de conteúdo no desktop com subgrid e fallback flex. Abaixo de 961px, formam uma coluna de até 640px. O fluxo TISS ocupa uma faixa separada abaixo dos cartões.
- Planos usam três colunas no desktop e uma coluna de até 520px abaixo de 960px. Os painéis do integrador e dos créditos acompanham a mesma largura e os mesmos recuos nessa composição.
- O par de painéis usa intervalo de 32px em desktop e 24px quando empilhado. O conteúdo de créditos também passa a uma coluna nessa faixa.
- A navegação abre o menu compacto até 1200px; ações laterais cedem espaço no celular. Grades e formulários usam limites próprios de conteúdo, incluindo container query no formulário.
- O rodapé reorganiza marca e listas em colunas menores; links mantêm alcance confortável no toque.

Os breakpoints e exceções estão descritos no arquivo complementar. Preservar diferenças comprovadas dos componentes; não impor um único breakpoint a toda a página sem verificar o resultado.

## Elevation & Depth

Superfícies claras com bordas discretas sustentam o conteúdo. Sombras suaves identificam capturas do produto e áreas de destaque, como formulário e navegação após rolagem. A composição usa profundidade seletiva, sem elevar todos os cartões da mesma maneira.

### Shadow Vocabulary

- **Sombra de superfície:** `0 4px 24px rgba(15, 37, 81, .08)`; presente em cartões elevados e formulário.
- **Sombra ampla:** `0 12px 48px rgba(15, 37, 81, .14)`; usada em molduras e camadas destacadas.
- **Navegação após rolagem:** `0 2px 20px rgba(15, 37, 81, .08)`; separa o cabeçalho do conteúdo.

**The Selective Depth Rule.** Usar a profundidade existente para distinguir camadas e estados; cartões de planos permanecem estáveis ao passar o mouse.

**The Purposeful Motion Rule.** Usar movimento breve para orientar a entrada e confirmar ações; o conteúdo e os controles permanecem disponíveis sem animação, sem loops decorativos e com respeito à preferência por movimento reduzido.

O hero faz uma única entrada de até 620ms, sem animar os CTAs; no celular, as notas abaixo da captura ficam estáticas. Métricas positivas contam uma vez em 700ms e recuperam o texto final original. Apenas os cartões de funcionalidades recebem entrada ligada à rolagem, com opacidade inicial de .94 e deslocamento de 8px; sem suporte, continuam visíveis.

Botões respondem à pressão em 90ms; cores, detalhes e feedback do formulário usam transições breves de 160ms. FAQ e painéis da demonstração transitam em 260ms. A preferência por movimento reduzido remove deslocamentos animados, spinner, rolagem suave e reprodução automática pertinente, mantendo conteúdo, cores e estados disponíveis. A página reage à mudança dessa preferência durante o uso e pausa as animações JavaScript e a demonstração quando a aba fica oculta. Os easings e valores por componente estão no arquivo complementar.

## Shapes

Cantos suavemente arredondados distinguem função e escala: controles compactos, superfícies intermediárias e cartões maiores têm raios próprios no frontmatter. Pílulas ficam reservadas a selos e etapas breves. Avatares são circulares.

Bordas finas delimitam planos, campos e áreas de conteúdo; o contorno de botões secundários é mais forte. Capturas do produto respeitam a moldura arredondada. O arredondamento não substitui o alinhamento entre blocos.

## Components

### Buttons

Ações claras, com peso semibold e rótulo legível. O primário combina teal e texto azul-marinho. O secundário usa fundo transparente e contorno azul-marinho; em superfícies escuras existe uma versão de contorno claro.

O alvo mínimo é 44px; botões dos planos usam 52px e o envio do formulário usa 48px. O estado hover muda a cor; com ponteiro preciso, desloca o botão 1px para cima e aplica uma sombra discreta. Pressionar reduz a escala para .985 em 90ms, com precedência sobre o hover; movimento reduzido preserva apenas o feedback sem deslocamento. O foco visível muda de tom conforme o fundo. No celular, CTAs longos podem ocupar a largura disponível sem cortar texto.

### Chips

Selos curtos identificam estados como plano em destaque, integrador e disponibilidade. O selo principal de plano usa teal e texto azul-marinho; o selo do integrador usa uma superfície teal clara. Um selo não deve parecer um controle se não houver ação.

### Cards / Containers

Cartões de funcionalidades usam fundo frio, borda discreta e linhas alinhadas entre colunas. Cartões de planos usam fundo branco; o plano em destaque inverte a superfície para azul-marinho com texto claro.

Comparações, listas de recursos e ações mantêm sua organização. Detalhes nativos expandem conteúdo sem substituí-lo por texto truncado. Integrador e créditos usam painéis de mesma largura com conteúdo próprio e títulos do mesmo papel, sem alongar apenas um plano. Em cartões de plano com largura interna de até 14rem, moeda e valor passam a linhas separadas e o número pode quebrar, preservando a escala do preço durante ampliação de texto.

### Inputs / Fields

Campos usam fundo frio, contorno discreto, fonte herdada e rótulos explícitos. O foco acentua o contorno, clareia o fundo e mantém um indicador visível. Erros combinam texto, cor e associação ao campo; o envio possui estado desabilitado.

Os campos lado a lado dependem da largura interna do formulário. O texto mantém o mesmo tamanho em desktop e mobile; no toque, a altura mínima preserva o alcance do controle. Campos opcionais podem estar em detalhes expansíveis, com seus rótulos preservados.

### Navigation

Navegação fixa com duas versões do logo: clara sobre o hero e escura sobre a superfície clara após rolagem. A mudança é uma variação de contexto da mesma marca.

Links desktop são compactos e têm alvo de 44px. O menu móvel permite rolagem própria, mantém foco visível e evita rolagem acidental da página ao fundo. Rodapé e links auxiliares usam texto claro sobre azul-marinho profundo.

### Capturas e fluxos do produto

Capturas reais aparecem em molduras de navegador; abas e controles mantêm rótulos acessíveis. Até 640px, a captura do hero vem antes de duas notas agrupadas em uma única legenda. A demonstração oferece pausa, interrompe a troca automática durante leitura com mouse ou foco, fora da tela e com a aba oculta, e respeita mudanças na preferência por movimento reduzido.

Fluxos de etapas usam listas ordenadas e indicadores direcionais consistentes. O fluxo TISS mantém suas etapas legíveis mesmo antes da animação. O integrador mostra a relação entre aparelhos, integração e exames, com o mesmo vocabulário visual da página.

## Do's and Don'ts

### Do:

- **Do** preservar Inter, logos, azul-marinho, teal e neutros frios ao refinar a landing.
- **Do** usar os papéis tipográficos existentes conforme a função do texto, com unidades relativas e quebras naturais.
- **Do** manter os alinhamentos dos cartões e dos painéis nos respectivos breakpoints.
- **Do** preservar foco visível, rótulos, estados de erro, alvos de toque e a preferência por movimento reduzido.
- **Do** conferir a versão em português e inglês com textos reais e ampliação de texto.

### Don't:

- **Don't** apresentar o visual do site público como se fosse a especificação do painel autenticado.
- **Don't** usar texto branco sobre o teal dos botões primários da landing.
- **Don't** fixar a altura de blocos de texto para disfarçar diferenças de conteúdo ou cortar traduções.
- **Don't** trocar a fonte ou redesenhar a identidade durante um refinamento.
- **Don't** incorporar uma melhoria apenas planejada como se já estivesse implementada.
