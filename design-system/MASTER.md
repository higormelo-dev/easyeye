# EasyEye — proposta de padrão do painel

Data: 23/10/2026. Base inspecionada: `88a03402`.

**Status: fase 0 (fundação) e piloto Médicos/Pacientes implementados em 23/10/2026 e commitados em `64b373aa` e `de48095a`; adotado como contrato em 26/10/2026 (`AGENTS.md`); os demais módulos seguem no padrão legado e migram pelos lotes de [docs/internal/planos/design-system-adocao-plano.md](../docs/internal/planos/design-system-adocao-plano.md).** Os tokens de densidade e as regiões `data-ee-density` existem em `resources/css/system/_control-tokens.scss`, e fora de uma região nada mudou. Ainda não há gate automatizado de CI nem a página interna de referência (§8). O que está pronto, o que foi medido e o que falta está na seção "Estado da implementação" de [AUDIT.md](AUDIT.md).

O objetivo é que recepção, médico, financeiro e gestão encontrem controles com as mesmas medidas e comportamento. A densidade muda por contexto de trabalho e capacidade de interação; o módulo não inventa sua própria escala.

Escopo: painel autenticado, seus componentes compartilhados e novos recursos. Login e portais reutilizam fundamentos de legibilidade, estados e acessibilidade com layouts próprios. Landing pública continua seguindo [DESIGN.md](../DESIGN.md). PDFs, impressão A4, imagens clínicas, thumbnails e regiões de zoom mantêm métricas próprias.

Fontes: [auditoria e plano de adoção](AUDIT.md), [inventário estático](inventory.csv), skill local `ui-ux-pro-max` e referências W3C ao final. As recomendações da skill foram adaptadas à operação clínica; preservar Inter, identidade do painel e Vue/Inertia/Bootstrap/Tailwind. Não há necessidade demonstrada de trocar biblioteca ou instalar um framework de componentes.

## 1. Medidas dos controles

Valores em CSS px, com equivalentes rem calculados para raiz de 16px. Implementar com unidades relativas. As alturas são **mínimas**, considerando conteúdo, padding e borda (`border-box`); rótulos longos, tradução e zoom podem aumentar o controle.

| Papel | Compacto | Padrão | Confortável/toque |
| --- | --- | --- | --- |
| Input de uma linha, busca, select, data, dinheiro | 32px / 2rem | **40px / 2.5rem** | **44px / 2.75rem** |
| Botão com texto | 32px | **40px** | **44px** |
| Botão somente ícone | 32 × 32px | 40 × 40px | 44 × 44px |
| Paginação, alternância de visualização e botão limpar | 32 × 32px | 40 × 40px | 44 × 44px |
| Fonte do controle / entrelinha | 14 / 20px | 14 / 20px | 16 / 24px |
| Padding horizontal de campo ou botão | 8px | 12px | 12px |
| Padding vertical de referência, borda 1px | 5px | 9px | 9px |
| Ícone dentro de campo ou botão | 16px | 18px | 20px |
| Espaço entre ícone e texto | 8px | 8px | 8px |
| Espaço entre alvos independentes | 8px | 8px | 8px |
| Opção de dropdown de uma linha | mínimo 32px | mínimo 40px | mínimo 44px |

Escolher uma densidade para a região, como formulário ou toolbar. Input, SearchSelect, MoneyInput, datas, prefixos, limpar, dropdown e botão de ação dessa região devem se alinhar pela borda inferior e usar a mesma medida externa. Não alinhar pela parte superior do label quando houver campos com e sem rótulo.

**Padrão é 40px.** Usar em cadastros, filtros, configurações, financeiro, estoque e ações de página. Não diminuir o botão Salvar só porque está no rodapé de um modal.

**Compacto é uma exceção explícita.** Usar em grades clínicas densas e edição tabular que comprovadamente precisam mostrar mais dados, com mouse/teclado e viewport larga. Não aplicar a um módulo inteiro por tradição. Rótulos de paciente, instruções e texto clínico não ficam menores junto com os controles.

**Confortável é obrigatório para toque no padrão proposto.** Aplicar em telas pequenas, dispositivos com ponteiro impreciso e portais orientados ao paciente. Um tablet largo também precisa de alvos confortáveis. A presença de touch em dispositivos híbridos deve ser considerada: não usar apenas breakpoint para decidir. Se uma grade não comportar os alvos, reorganizar o layout ou oferecer edição em painel; não sobrepor áreas de clique invisíveis.

Os 44px são uma escolha de usabilidade deste projeto e correspondem à referência mais exigente de tamanho de alvo. O mínimo WCAG 2.2 AA é 24px com exceções; não declarar que um controle de 32px viola automaticamente esse critério.

### Controles que precisam crescer

- Textarea: mínimo 96px, três linhas legíveis, `resize: vertical`; notas curtas podem começar com duas linhas mediante contexto documentado.
- Multi-select com chips: mínimo igual à densidade; crescer ao quebrar linha. Alternativa resumida precisa permitir consultar toda a seleção sem depender de hover.
- Checkbox/radio: indicador visual de 18–20px e label associado. A região clicável do conjunto segue a densidade, chegando a pelo menos 44px no toque.
- Switch: tamanho visual próprio, label clicável e estado anunciado; preservar alvo confortável e significado sem depender só de cor.
- Upload: texto de formato/limite, estado de envio e recuperação de falha; dropzone acessível por teclado.
- Conteúdo rico e ditado: não limitar à altura de input; preservar TinyMCE, seleção, teclado e contratos de dados existentes.

## 2. Tipografia e espaçamento

| Papel | Proposta |
| --- | --- |
| Família | Inter, preservando fallback sans-serif |
| Leitura geral do painel | 14px / 21px |
| Prosa longa, orientação ao paciente e conteúdo clínico de leitura | 16px / 24px |
| Label de campo | 14px / 20px, peso 500 ou 600 |
| Ajuda, erro e metadado secundário | 12px / 18px; nunca reduzir informação clínica essencial a esse papel |
| Título da página | h1, 24px / 32px, peso 600 |
| Título de seção | h2, 20px / 28px, peso 600 |
| Título de card/modal | 18px / 24px, nível semântico adequado ao contexto |
| Números financeiros, horários e medidas alinhadas | Algarismos tabulares e formato do locale |
| Ícones estruturais novos | Família Tabler já existente; alternativas só para significado especializado documentado |

Não diminuir a fonte para fazer um botão caber. Em largura curta, mover ações secundárias para menu, reorganizar colunas ou quebrar o texto de forma legível. Altura mínima não pode virar corte de texto em zoom ou inglês.

Escala de espaços: **4, 8, 12, 16, 24 e 32px**. Exceção intencional: 6px entre label e campo.

| Relação | Medida |
| --- | --- |
| Label → controle | 6px |
| Controle → ajuda/erro | 4px |
| Entre campos na grade | 16px horizontal e vertical |
| Entre grupos/seções de formulário | 24px |
| Entre controles de uma toolbar | 8px |
| Header → filtros; filtros → resultados | 16px |
| Padding interno de card/modal | 20px padrão; 16px compacto ou mobile |
| Entre cards/seções da página | 24px desktop; 16px mobile |
| Margem de conteúdo da página | 24px desktop; 16px mobile |
| Raio de controle / card ou diálogo | 8px / 12px |

Os 20px de padding preservam a proximidade com os cards atuais. Pills ficam para badges e estados; botões comuns não mudam de raio por página.

## 3. Páginas, filtros e tabelas

Ordem de listagem: breadcrumb quando necessário → cabeçalho com h1 e ação principal → filtros → seleção em lote, quando ativa → resultados → paginação. Estados vazio, carregando, sem permissão e erro são distintos.

Reutilizar [PageHeader](../resources/js/Components/Panel/PageHeader.vue), evoluindo sua semântica antes da migração. Não substituir os cabeçalhos especializados do dashboard por um genérico se perderem função. Uma ação primária por contexto; Salvar no modal tem seu próprio contexto, diferente da página ao fundo inerte.

Filtros com labels visíveis, mesma densidade e largura definida pelo conteúdo. Intervalo de datas alinha início/fim; opção de limpar e filtro ativo ficam claros. Em mobile, filtros podem ser recolhidos com resumo/contagem, sem esconder a existência do filtro. Preservar URL, filtros e scroll quando fizer sentido.

| Tabela | Compacta | Padrão | Toque |
| --- | --- | --- | --- |
| Altura de referência de linha de uma linha | 44px | 52px | 56px |
| Padding horizontal de célula | 12px | 16px | 16px |
| Controle embutido | 32px | 40px | 44px |

Alturas de linha são referências de composição, não `height` rígido: nomes, erros e conteúdo em múltiplas linhas podem crescer. Centralizar controles verticalmente. Cabeçalho ordenável usa botão, estado anunciado e nome descritivo. Dinheiro à direita; texto à esquerda; status usa texto além da cor.

Rolagem horizontal pode existir numa tabela ou calendário que precisa de duas dimensões, sem arrastar a página inteira. Não esconder dados essenciais só para evitar scroll. Em mobile, preferir cards para listagens simples e detalhe acessível para colunas secundárias. Ações perigosas ficam separadas das rotineiras.

## 4. Modais, painéis e sobreposições

Reutilizar CenteredModal e OffcanvasPanel com useModalA11y. Preservar foco, retorno ao acionador, Escape, fundo inerte e overlays aninhados; não implementar novos modais à mão.

Proposta de larguras máximas externas: **400px** confirmação simples, **640px** formulário comum, **800px** formulário amplo e **1120px** composição complexa. Mobile usa largura disponível com margem de 8px, viewport dinâmica e safe areas. Esses valores substituem gradualmente as larguras atuais; não trocar globalmente antes do piloto.

Cabeçalho e rodapé crescem pelo conteúdo; corpo tem rolagem própria quando necessário. Evitar `min-height: 480px` para estabilizar todas as abas. Usar conteúdo e limite pela viewport. Ações precisam continuar visíveis em telas baixas e com teclado virtual.

Rodapé: Cancelar secundário e Salvar primário, ambos na mesma densidade; 8px entre ações. No toque podem ocupar largura disponível. Cancelar, X, Escape e backdrop passam pela mesma proteção de alterações não salvas. Durante envio, bloquear confirmação duplicada e garantir recuperação após falha.

Camadas: nomear os níveis de backdrop, diálogo, dropdown teleportado, tooltip, alertas e menus do editor. Preservar inicialmente os valores existentes, inclusive backdrop 1054 e diálogo 1055, validando overlays externos antes de mudar. Não resolver conflitos inventando z-index crescente em cada tela.

## 5. Estados e acessibilidade

Cada componente deve cobrir: normal, hover, foco, pressionado/selecionado, desabilitado, somente leitura, carregando, inválido e sucesso quando aplicável.

- Reutilizar tokens de foco `--ee-focus-*` já entregues. Não reintroduzir supressão global de outline.
- Tokens de cor devem representar papel: ação principal, superfície, texto, texto secundário, borda, perigo, sucesso, aviso e informação. Mapear temas claro/escuro preservando identidade. A migração não exige mudar a cor primária.
- Texto normal: contraste mínimo 4,5:1; texto grande: 3:1; limites e indicadores necessários à operação devem ser perceptíveis, com verificação própria. Texto secundário de 12–14px continua sujeito a 4,5:1 quando ativo.
- Cada campo tem id único, label associado e ajuda/erro ligados por aria-describedby. Obrigatoriedade não depende só do asterisco.
- Botões de ícone têm nome acessível localizado e tooltip complementar; tooltip não é a única forma de entender ação crítica.
- Após submit inválido, abrir aba do primeiro erro e focar o campo; formulário grande tem resumo navegável. Não mostrar erros prematuros enquanto a pessoa ainda preenche um campo novo.
- Erro de rede oferece retry e preserva o trabalho. Nunca representar erro como lista vazia ou sucesso. Reutilizar latestRequest para buscas com respostas concorrentes.
- Estado de envio mantém tamanho e rótulo compreensível; spinner não substitui a descrição da ação. Anunciar progresso e conclusão sem roubar foco.
- Confirmar saída com mudanças; rascunho clínico exige armazenamento e autorização adequados, sem persistência indiscriminada em localStorage.
- Animação de feedback entre 150 e 200ms, cobrindo prefers-reduced-motion e mantendo resposta visual estática. Não aplicar transition: all por padrão.
- Gráfico tem nome, resumo e acesso aos valores. Imagem clínica mantém controles de zoom/comparação e identificação, sem reduzir conteúdo por regra estética.

## 6. Contrato técnico para adoção

**Arquitetura (implementada na fase 0):** tokens em um único arquivo SCSS do painel, `resources/css/system/_control-tokens.scss`, encaminhado por `main.scss`; nenhuma terceira biblioteca visual. Uma **região** é qualquer elemento com `data-ee-density="compact|default|comfortable"` (página, barra de filtros, modal); valor desconhecido cai em `default`. Os tokens existem só dentro de regiões, nunca em `:root`. Dentro da região, `-sm`, `-lg` e `input-group-sm` deixam de decidir o tamanho, e quem decide é a região; ficam como estão `.btn-xs` (ação mínima em chip), `.btn-link` e `.btn-close`. Fora da região nada muda, o que permite migrar módulo a módulo. Toque (`pointer: coarse`) ou tela até 575,98px força confortável em toda região, inclusive a compacta.

Tokens:

| Família | Nomes e finalidade |
| --- | --- |
| Densidade (implementados) | `--ee-control-min-height`, `--ee-control-font-size`, `--ee-control-line-height`, `--ee-control-padding-x`, `--ee-control-padding-y`, `--ee-icon-size` |
| Forma/espaço (implementados) | `--ee-control-radius`, `--ee-surface-radius`, `--ee-control-gap` |
| Forma/espaço (pendente) | `--ee-space-*`: sem consumidor ainda, será criado quando uma migração precisar |
| Cor (pendente) | `--ee-color-action`, `--ee-color-on-action`, `--ee-color-surface`, `--ee-color-text`, `--ee-color-muted`, `--ee-color-border`, `--ee-color-danger` e equivalentes de estado: **não implementados** |
| Já existente | Reutilizar `--ee-focus-color`, `--ee-focus-width`, `--ee-focus-offset` |

Componentes com CSS próprio leem `var(--ee-token, <valor legado>)`: dentro de uma região seguem o padrão e fora dela mantêm o que sempre tiveram (feito em `ActionIconButton`, `ActionIconGroup`, `OffcanvasPanel` e `CenteredModal`).

**A região é a API.** `CenteredModal` e `OffcanvasPanel` recebem a prop `density`, porque são teleportados para o `<body>` e não herdam a região da página. `SearchInput`, `SearchSelect` (`sm`) e `MoneyInput` (`size`) mantêm as props atuais, que só valem fora de região; dentro dela herdam a densidade sem mudar os chamadores. Uma prop `density` por componente só se justifica se um caso exigir densidade diferente dentro da mesma região, e nesse caso a saída é uma sub-região `data-ee-density="compact"` (como no editor de lotes do Estoque, UI-21).

Ainda fora das regiões: checkbox, radio e switch, ícone dentro de campo ou botão (`--ee-icon-size` só vale no `ActionIconButton`), padding de tabela, botão somente com ícone que não use `.btn-icon` nem `.ee-btn-square` e os itens de menus teleportados para o `<body>` (como o do `ActionDropdown`), que não herdam a região da página.

Estilizar input, select nativo, multiselect e seu wrapper interno, input-group, MoneyInput, botão e dropdown pelo mesmo conjunto. Evitar borda dupla e soma de altura do wrapper do multiselect. Não mudar parsing, v-model, formato monetário, debounce ou payload ao adaptar medidas.

Reutilizar componentes existentes e evoluir suas APIs antes de criar outros. Um wrapper Button ou FormField só se justifica se centralizar comportamento ou semântica que as classes atuais não atendem; não duplicar o ecossistema por estética. Adaptadores devem preservar attrs, refs de foco, slots, eventos e estado desabilitado.

## 7. Regra para módulos e recursos novos

Ao adotar esta proposta, o fluxo de entrega de cada recurso deve exigir:

1. Ler este arquivo e procurar exceções do contexto. Hoje não há overrides de página definidos. Criar `design-system/pages/<context>.md` apenas com justificativa, papel afetado, medidas, acessibilidade, responsável e critério de revisão; fundamento de acessibilidade não pode ser dispensado.
2. Escolher composição de página e densidade por região. Default 40px; justificar compactação.
3. Reutilizar componentes compartilhados; não copiar markup/CSS de outro módulo sem revisar suas exceções.
4. Não introduzir altura, fonte, raio ou cor funcional ad hoc em campos e botões. Geometria de imagem, gráfico e documento é uma exceção legítima, não um erro de lint automático.
5. Cobrir estados, teclado, pt_BR/en, temas, proteção de edição e mensagens de recuperação.
6. Validar a página com dados sintéticos longos e medir controles renderizados. Registrar comando, resultado e limitações.

Trecho reutilizável para tarefas de desenvolvimento:

> Siga design-system/MASTER.md e os overrides documentados do contexto. Use controles de 40px no padrão, 32px apenas nas regiões densas justificadas e 44px no toque. Alinhe campos, selects, datas, dinheiro e botões da mesma região. Preserve Vue/Inertia, comportamento, isolamento, traduções e componentes existentes. Não invente medidas locais. Entregue estados de erro/carregamento, foco e validação visual nas larguras e temas exigidos; relate qualquer exceção.

## 8. Critérios de aceite da futura implementação

Estado em 23/10/2026, medido no piloto Médicos/Pacientes (detalhes e método em [AUDIT.md](AUDIT.md)). Item marcado = provado por medição; item aberto traz o que falta.

- [x] Campos, prefixos, selects e botões de uma linha alinhados; diferença máxima de 1 CSS px no cenário de referência sem zoom. Diferença de 0,00 px em toda a matriz do harness e 0 de 167 controles fora da altura esperada em cada combinação das telas reais.
- [ ] Base 32/40/44px conforme contexto, com crescimento permitido por conteúdo e zoom. Alturas de 32, 40 e 44px medidas; crescimento com zoom e texto longo ainda não medido (os tokens estão em rem).
- [ ] Alvos independentes de toque de pelo menos 44 × 44 CSS px sem sobreposição. Altura de 44px medida em toque; largura de botão só com ícone fora de `.btn-icon`/`.ee-btn-square`, checkbox, radio e switch ainda não cobertos.
- [ ] Teclado alcança todos os controles; foco visível; modal e dropdown devolvem foco corretamente. Não reverificado; nenhum componente de foco foi alterado e as suítes de acessibilidade dos modais passam.
- [ ] Labels/erros anunciados; falha abre a aba correta e permite recuperação sem perda de trabalho. Fora do escopo da fase 0; só o nome acessível do botão de CEP foi corrigido (Médicos e Pacientes).
- [ ] Verificado em 375, 768, 1024 e 1440px, landscape e largura equivalente a 320 CSS px para reflow. Medido em 1440, 1024, 768 (toque), 375 (toque e mouse), 360 e 320, sem rolagem lateral depois da mudança; paisagem não medida.
- [ ] Verificado com 200%/400% de zoom, nomes/textos longos e pt_BR/en. Zoom medido por larguras CSS equivalentes (720 e 360px) nas telas reais: alturas corretas e sem rolagem lateral; texto longo medido só no select com busca (altura mantida); inglês e nomes longos em tabelas e cards não medidos.
- [ ] Temas claro/escuro e movimento reduzido conferidos separadamente. Claro e escuro medidos (mesmas alturas); movimento reduzido não se aplica a esta fase.
- [ ] Tabelas/calendário com scroll localizado; menu, rodapé e ações não escondem conteúdo. Não avaliado.
- [ ] Gates adequados passaram, ou falhas e limites foram registrados sem mascaramento. Ver "Validação" em [AUDIT.md](AUDIT.md).

Criar futuramente uma página interna de referência com todas as variantes usando componentes reais e dados sintéticos. Ela serve para revisão visual e testes de regressão; não foi criada nem disponibilizada por esta análise.

## Referências

- [WCAG 2.2 — tamanho mínimo de alvo, AA](https://www.w3.org/WAI/WCAG22/Understanding/target-size-minimum.html).
- [WCAG — tamanho ampliado de alvo, AAA](https://www.w3.org/WAI/WCAG22/Understanding/target-size-enhanced.html).
- [WCAG — reflow e exceções para conteúdo bidimensional](https://www.w3.org/WAI/WCAG22/Understanding/reflow.html).
- [WCAG — contraste mínimo](https://www.w3.org/WAI/WCAG22/Understanding/contrast-minimum.html).
