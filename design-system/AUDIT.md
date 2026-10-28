# EasyEye — inventário e plano de padronização do frontend

Data: 23/10/2026. Checkout inicial limpo, HEAD `88a03402`.

Entrega documental inicial: [padrão proposto](MASTER.md) e [inventário por módulo](inventory.csv), sem alteração de componente, estilo, dependência, banco ou fluxo. Em 23/10/2026 a fase 0 e o piloto Médicos/Pacientes foram implementados sobre o mesmo HEAD: ver "Estado da implementação" abaixo. As evidências da tabela de inconsistências descrevem o código **antes** dessa implementação.

## Escopo e limites

Varredura textual dos **502 arquivos Vue**, incluindo **361 em Pages/Panel**, e leitura dirigida dos estilos compartilhados, wrappers de campos/botões, modais e exemplos de Agenda, Prontuário, Pacientes, Médicos, Financeiro, Estoque, Imagens, Configurações e dashboards. Não houve inspeção visual autenticada nem medição de elementos no navegador.

O inventário CSV conta ocorrências de tokens/tags no template, removendo comentários HTML, não instâncias renderizadas nem problemas comprovados. Classes dinâmicas, condições, loops, slots, herança e regras externas limitam a contagem. Um style inline de geometria de imagem ou impressão não é defeito de padronização.

| Indicador nas fontes de Pages/Panel | Contagem |
| --- | --- |
| Arquivos Vue | 361 |
| Ocorrências de btn-sm | 663 |
| Ocorrências de btn-xs | 12 |
| Ocorrências de form-control-sm | 151 |
| Ocorrências de form-select-sm | 76 |
| Tags SearchSelect | 92 |
| Tags MoneyInput | 13 |
| Tags PageHeader | 78 |
| Tags ActionIconButton | 132 |
| Tags CenteredModal / OffcanvasPanel | 51 / 42 |
| Atributos style / :style | 688 |
| Arquivos candidatos com inputs simples e sm no mesmo template | 15 |

Esses números demonstram o alcance da migração; não são uma nota de qualidade. Nem todo arquivo é uma página completa e nem toda mistura é indevida.

Versões conferidas em package-lock.json: Vue 3.5.43, Inertia Vue 3.1.1, Bootstrap 5.3.8, Tailwind 3.4.19 e Vueform Multiselect 2.6.11. Preservar stack e lockfiles.

## Inconsistências comprovadas nas fontes

As medidas abaixo são declarações ou cálculos de referência com raiz de 16px. Cascade, Bootstrap, estado e conteúdo podem mudar o tamanho final. Medir getBoundingClientRect/computed styles no piloto antes de afirmar dimensões efetivamente exibidas.

| ID | Evidência | Situação atual | Melhoria |
| --- | --- | --- | --- |
| UI-01 | [_buttons.scss](../resources/css/system/components/_buttons.scss), base e btn-sm | Ambos usam fonte 13px, padding 5px 12px e line-height 1.5; caixa teórica de 31,5px com borda 1px | Densidades explícitas; não usar sm e base como nomes diferentes da mesma métrica |
| UI-02 | Mesmo arquivo, btn-xs e btn-icon | Variante de ícone pode chegar a 22 × 22px; base btn-icon 30 × 30px | Migrar alvos para 32/40/44 por contexto e validar espaçamento |
| UI-03 | [_forms.scss](../resources/css/system/components/_forms.scss):8 | Input regular tem fonte 14px e padding vertical 8px; caixa teórica de 39px com line-height 1.5 e borda 1px | Alinhar input e botão do mesmo formulário em 40px |
| UI-04 | Mesmo arquivo, form-control-sm e select.form-control-sm | Campo sm usa fonte 12px/padding vertical 5px; select sm declara altura 2rem | Unificar medidas externas e fonte por densidade, em vez de por tag |
| UI-05 | [SearchInput.vue](../resources/js/Components/Panel/SearchInput.vue) | Wrapper força input-group-sm | Busca precisa herdar densidade da toolbar |
| UI-06 | [SearchSelect.vue](../resources/js/Components/Panel/SearchSelect.vue), bloco de estilo | Base define fonte 1rem, padding e min-height próprios; sm fixa height/min-height em 31px | Usar a mesma métrica de input nativo e acertar wrapper interno |
| UI-07 | [MoneyInput.vue](../resources/js/Components/Panel/MoneyInput.vue):37 | size padrão sm; chamadores precisam passar size vazio para dimensão regular | API de densidade comum e compatível, preservando parse e moeda |
| UI-08 | [ActionIconButton.vue](../resources/js/Components/Panel/ActionIconButton.vue):104 | Ícone usa botão fixo de 32 × 32px | Herança de densidade; alvo maior em toque |
| UI-09 | [ActionIconGroup.vue](../resources/js/Components/Panel/ActionIconGroup.vue) | Gaps de 2, 6 e 12px, diferentes dos demais agrupamentos | Espaço padrão 8px, compacto só com alvos sem conflito |
| UI-10 | [system.scss](../resources/css/system.scss):122 | .schedule-card .btn-sm força largura e altura 2rem | Substituir regra contextual genérica por variante explícita de ação de ícone |
| UI-11 | [_medical-records.scss](../resources/css/system/_medical-records.scss):237 | Controles e botões de prontuário recebem min-height 30px/fonte .84rem, depois selects recebem outro ajuste na linha 1066 | Tornar compacto uma densidade única, sem correções acumuladas |
| UI-12 | Mesmo arquivo, .pmr-save-btn e .pmr-flow-btn | Ações de fluxo têm alturas próprias de 46 e 42px | Mesma escala; diferenciar importância por papel visual e texto |
| UI-13 | [AcuitySelect.vue](../resources/js/Pages/Panel/MedicalRecords/Components/AcuitySelect.vue):127 | Campo especializado possui min-height 30px e largura própria | Herdar altura; preservar largura clínica necessária e interação |
| UI-14 | [_tables.scss](../resources/css/system/components/_tables.scss) | Padding de td 12px 16px com important e link action-item de 22px | Densidade de tabela explícita, com controles alinhados e crescimento de linha |
| UI-15 | [_pagination.scss](../resources/css/system/components/_pagination.scss) | Alturas próprias de 35 e 31px | Paginação acompanha a densidade da região |
| UI-16 | [_header.scss](../resources/css/system/structure/_header.scss) | Ações do cabeçalho têm várias caixas próprias, incluindo 32px | Unificar alvos; permitir compactação visual sem reduzir toque |
| UI-17 | [_forms.scss](../resources/css/system/components/_forms.scss), [_buttons.scss](../resources/css/system/components/_buttons.scss), [_card.scss](../resources/css/system/components/_card.scss), ActionIconButton | Raios de 5, 7 e 8px, além dos ajustes locais | Tokens para controle e superfície |
| UI-18 | [PageHeader.vue](../resources/js/Components/Panel/PageHeader.vue):36 | Título padrão é h4; cada caller define suas ações | Evoluir para h1 semântico com aparência independente e medidas comuns |
| UI-19 | [ReportSettings/Form.vue](../resources/js/Pages/Panel/Settings/ReportSettings/Form.vue):303 | SearchSelect sem sm na mesma linha que form-control-sm | Região de layout com densidade comum e alinhamento |
| UI-20 | [EyeImages/Index.vue](../resources/js/Pages/Panel/EyeImages/Index.vue):2360 | Toolbar combina selects e campos de data sm; múltiplos estilos locais | Alinhar toolbar; preservar geometria de imagens, lupa e A4 |
| UI-21 | [ProductFormModal.vue](../resources/js/Pages/Panel/Stock/Products/ProductFormModal.vue):386 | Editor de lotes usa inputs sm dentro de formulário com controles regulares | Definir sub-região compacta explícita, ou adotar padrão em todo formulário |
| UI-22 | [DoctorFormModal.vue](../resources/js/Pages/Panel/Doctors/DoctorFormModal.vue):571 | Seletor de cor declara height 38px inline | Variante compartilhada que acompanha densidade |
| UI-23 | [ScheduleFormModal.vue](../resources/js/Pages/Panel/Schedules/ScheduleFormModal.vue):1145 | Formulário exige min-height 480px | Corpo com tamanho guiado pela viewport e conteúdo, validando teclado virtual |
| UI-24 | [CenteredModal.vue](../resources/js/Components/Panel/CenteredModal.vue), [OffcanvasPanel.vue](../resources/js/Components/Panel/OffcanvasPanel.vue) | Compartilham grande parte do CSS, mas larguras e chamadores podem divergir; sem tratamento local de reduced-motion | Consolidar tokens, escala de larguras e política de movimento; validar política efetiva global |
| UI-25 | [_medical-records.scss](../resources/css/system/_medical-records.scss), .pmr-doc-img-btn-label | Rótulos em .58rem e .54rem em mobile | Rever legibilidade e disponibilizar nomes inteiros; não aplicar limite arbitrário à imagem clínica |
| UI-26 | [DESIGN.md](../DESIGN.md):164 e estilos de dashboard/painel | Documento visual principal é restrito à landing; painel usa convenções dispersas | MASTER próprio para painel e exceções documentadas |

## Melhorias transversais a exigir na migração

São critérios de revisão, não uma declaração de falha em todas as telas.

| ID | Prioridade | Item |
| --- | --- | --- |
| UX-01 | Alta | Foco visível, nome de botões de ícone, labels associados e ordem de Tab em todas as variantes |
| UX-02 | Alta | Salvar/Cancelar/X/Escape/backdrop com proteção coerente contra perda de edição |
| UX-03 | Alta | Loading, erro de rede, retry, vazio e sem permissão distintos; último resultado válido preservado |
| UX-04 | Alta | Sem duplo envio; não alterar payload, parsing de dinheiro/data ou v-model em migração visual |
| UX-05 | Alta | Contraste de texto e estados em claro/escuro; cores arbitrárias da agenda exigem pares texto/fundo válidos |
| UX-06 | Alta | Contexto de clínica, paciente, lateralidade e alvo de ação em lote legíveis e preservados |
| UX-07 | Média | Erro abre aba correta, aponta campo e oferece explicação acionável |
| UX-08 | Média | Hierarquia de ação principal/secundária/perigosa e texto consistente por tarefa |
| UX-09 | Média | Filtros, URL, paginação e scroll preservados conforme fluxo; retorno previsível |
| UX-10 | Média | Menus, tooltips e editor sem clipping ou disputa de foco/z-index |
| UX-11 | Média | Textos pt_BR/en, nomes longos, zoom e formulários com duas linhas de label |
| UX-12 | Média | Gráficos com valores acessíveis e unidades/locale corretos; estado de dados desatualizados explícito |
| UX-13 | Média | Movimento reduzido, animações de estado sem mudar layout e sem transition: all |
| UX-14 | Medir | Desempenho real de tabelas, agenda, imagens e bundles; não aplicar virtualização indiscriminadamente |

### Revisão anterior: não repetir achados já corrigidos

No checkout atual, _reboot.scss já oferece foco compartilhado e não contém a antiga supressão global de outline. SearchSelect já usa latestRequest, estados de erro/carregamento e retry. ActionDropdown já implementa suporte de teclado. O novo padrão deve preservar essas entregas. O relatório de 22/10 em /tmp é histórico, não uma lista atual de pendências.

## Estado da implementação (23/10/2026)

Fase 0 (tokens e densidade herdada) e o piloto de cadastro de Médicos e Pacientes foram implementados sobre o HEAD `88a03402` e commitados depois (`64b373aa` em 24/10, testes em `de48095a` em 26/10). O que existe:

- Tokens e regiões de densidade em `resources/css/system/_control-tokens.scss`, encaminhado por `main.scss`. Só valem dentro de `data-ee-density`; fora dela as medidas legadas seguem iguais.
- Prop `density` em `CenteredModal` e `OffcanvasPanel`, porque modais teleportados para o `<body>` não herdam a região da página. `ActionIconButton`, `ActionIconGroup` e os dois modais leem os tokens com fallback legado, e o botão de limpar do `SearchInput` virou quadrado dentro de região.
- Piloto em `Doctors/Index`, `DoctorFormModal`, `DoctorTable`, `DoctorCards`, `DoctorInvitationsPending`, `Patients/Index`, `PatientFormModal` e `PatientTable`. Saíram as medidas locais: `fs-13 btn-md` (a classe `btn-md` não existe no CSS), `btn-sm`, `input-group-sm`, a altura inline de 38px do seletor de cor e o chip "Colunas" feito à mão (agora `btn btn-white`). O `min-height: 480px` do formulário de paciente virou `min(480px, 55dvh)`.
- Defeito achado e corrigido: o botão de buscar CEP passava `label`, prop que o `ActionIconButton` não declara, e ficava sem nome acessível, em Médicos e em Pacientes. A correção em `PatientFormSections` vale também na Agenda, que usa o mesmo componente e continua fora de região.
- Segundo defeito achado e corrigido: a tabela de convites pendentes de Médicos alargava a página inteira em telas estreitas (479px de conteúdo a 360px no HEAD). O `<span class="visually-hidden">` do cabeçalho "Ações" é absoluto e, sem pai posicionado, fica no x real da coluna. O `th` agora é `position-relative`.

### Resultado medido

Chrome 156 headless com os componentes reais e o mesmo CSS do build (`vendor.css` e `system.scss`), dados sintéticos. Telas reais de Médicos e Pacientes (lista e cada aba do modal de cadastro) a 1.440px com mouse; "antes" é o HEAD, "depois" é a árvore de trabalho.

| Controle | Antes | Depois |
| --- | --- | --- |
| Campo de texto, data, cor | 39px | 40px |
| Busca da lista | 31px | 40px |
| Select com busca | 40px, fonte 16px | 40px, fonte 14px |
| Botão com texto | 25, 31,5 e 39px | 40px |
| Botão de ícone (ação, CEP) | 32px | 40px |
| Espaço entre ícones de ação | 2px | 8px |
| Paginação | 28px | 40px |
| Alternar tabela/cards | 24px | 40px |
| Abas do modal | 37,5px | 40px |
| Textarea de 3 linhas | 81px | 96px |

Em toque (768 e 375px) toda região vira 44px, e a janela estreita com mouse (até 575,98px) também. Nas telas reais foram 167 controles por combinação, em 10 combinações (1.440, 720, 768, 375 e 360px CSS, em tema claro e escuro; 720 e 360px equivalem a zoom de 200% e 400% numa janela de 1.440px), e nenhum ficou fora da altura esperada: 40px em 1.440 e 720px, 44px nas demais. Depois da mudança não há rolagem lateral em nenhuma combinação; no HEAD, a lista de Médicos rolava 119px a 360px (ver segundo defeito acima). No harness de componentes (35 controles de referência, 6 viewports, 3 densidades, 2 temas) a diferença entre controles da mesma barra é de 0,00px.

Fora de região nada mudou. O CSS global compilado de `system.scss` ganhou 170 linhas num único bloco e não perdeu nenhuma; todo seletor novo começa por `[data-ee-density`, sem `:root` nem `!important`. No harness, 540 medições com todos os componentes compartilhados (6 viewports, 2 temas) saíram idênticas às da base, e o modal sem região mantém raio de 8px e espaço de 12px no rodapé.

### Revisão independente

Uma revisão somente leitura do diff, em 23/10, achou 1 achado P2 e 10 P3. Cada um foi conferido no navegador antes de corrigir:

- **Campo inválido ou válido:** o padding da região (12px) substituía os 33px que o Bootstrap reserva para o ícone de erro, e o texto passava por baixo dele. Corrigido; medido de novo em 33px no padrão e 29px no compacto.
- **`btn-group`:** os cantos internos ficavam arredondados em 8px dentro de região. O raio do botão passou para uma regra com `:where()`, de especificidade menor que a do Bootstrap; medido de novo com os cantos internos em 0px.
- **Botão do input de arquivo:** não acompanhava o padding da região (folga de 1px no padrão, estouro no compacto). Agora acompanha.
- **`btn-lg`:** media 39px, 1px abaixo dos 40px da região. Passou a seguir a região, como `form-control-lg` e `pagination-lg` já seguiam. Seguem como estão dentro de região: `.btn-xs`, `.btn-link` e `.btn-close`.
- **Altura do formulário de paciente:** usava `dvh` sem reserva. Agora há reserva de 480px e `svh`, que não oscila com a barra do navegador.
- **Comentários e testes:** os comentários de `ActionIconButton` e `ActionIconGroup` só descreviam o comportamento fora de região. Os testes ganharam um contrato que exige o escopo de região em todo seletor compilado, casos para o campo inválido e para o raio do botão, e a asserção do botão de repetir do alerta de erro de carga.
- **Não corrigidos de propósito:** `input-icon-start` e `input-icon-end` (CSS legado que nenhuma tela usa) e o ícone de erro do seletor de cor, que o swatch cobre; a borda vermelha e a mensagem continuam.

### Inconsistências da tabela acima

- **Resolvidas dentro de uma região:** UI-01, UI-03 a UI-09, UI-15, UI-17 (raio de controle de 8px; raio de superfície de 12px só nos modais em região) e UI-22. UI-02 vale para `.btn-icon` e `ActionIconButton`; `.btn-xs` continua com 22px por desenho, como exceção para ação em chip.
- **Parciais:** UI-14 (controles embutidos seguem a densidade; o padding de `td` e o `.action-item > a` de 22px não), UI-23 (só o `PatientFormModal`; o `ScheduleFormModal` fica para a Agenda) e UI-24 (prop `density` e tokens de raio e espaço; escala de larguras e movimento reduzido pendentes).
- **Pendentes:** UI-10 a UI-13, UI-16, UI-18 a UI-21 e UI-25. UI-26 é o próprio `MASTER.md`.

### O que falta

- Módulos da tabela de adoção a partir da Agenda; Import e WorkSchedule de Médicos e Importar de Pacientes ficaram de fora do piloto.
- Checkbox, radio e switch, ícone dentro de campo e botão, padding de tabela e botão somente com ícone que não use `.btn-icon` nem `.ee-btn-square`.
- Itens de menus teleportados: o `ActionDropdown` leva o menu para o `<body>`, então os itens (inclusive o menu "Colunas" das tabelas) não herdam a região, e o `MASTER.md` §1 pede 32, 40 e 44px por opção. Caminho previsto: o menu copiar o `data-ee-density` do ancestral do botão ao abrir.
- Medições não feitas: inglês, paisagem, teclado virtual, contraste renderizado e leitor de tela. O zoom foi medido só por equivalência de largura CSS (720 e 360px), não com o zoom real do navegador. Texto longo foi medido apenas no select com busca (rótulo de 80 caracteres: altura mantida em 40 e 44px, sem rolagem lateral); nomes longos em tabelas e cards não foram medidos.
- Verificação automática de medidas ad hoc em PR e a página interna de referência (itens 2 e 7 do trabalho necessário).
- Decisão do time: adotar o `MASTER.md` como contrato para novos módulos. As instruções de agentes e a CI não foram alteradas.
- O `inventory.csv` reflete o HEAD `88a03402`; as linhas Panel/Doctors e Panel/Patients ficaram desatualizadas pelo piloto.

## Adoção por módulo

| Ordem | Área | Prioridade da migração |
| --- | --- | --- |
| 0 | Componentes e estilos compartilhados | **Feito em 23/10 (fase 0).** Tokens e densidade em região, testados numa composição real. Falta checkbox/radio/switch e padding de tabela |
| 1 | Médicos/Pacientes | **Feito em 23/10 no cadastro:** lista, tabela, cards, convites e modal. Import e WorkSchedule de Médicos e Importar de Pacientes seguem no legado |
| 2 | Agenda | Barra de filtros, ações em lote, cards, calendário e edição sem alterar regras de série/encaixe |
| 3 | Financeiro | Dinheiro, datas, tabelas, regras e repasses; preservar comportamento e contratos financeiros |
| 4 | Estoque | Edição de produtos/lotes, quantidades, tabelas e importação |
| 5 | Configurações/Laudos | Layout de relatório, inputs sm e selects; não modificar medidas do documento impresso |
| 6 | Imagens | Filtros/ações/metadados; preservar lupa, comparação, zoom, thumbnails e impressão |
| 7 | Prontuário | Densidade compacta explícita, acuidade e toolbar; piloto com conteúdo clínico sintético |
| 8 | Cirurgia, Pronto atendimento, Retornos, Relatórios | Revisar componentes de domínio além das páginas; o código de cirurgia fica também em Components/Surgery |
| 9 | Manager e dashboards | Reutilização de tokens, preservando composição por função e personalização existente |
| 10 | Login, Portal e Portal do Paciente | Fundamentos e alvo confortável; layout específico por público |

Não converter todos os sm em regular por substituição textual. Primeiro adaptar componente/API com compatibilidade, depois migrar cada região e remover apenas os overrides que se tornarem redundantes.

## Trabalho necessário para manter o padrão

1. Adotar MASTER como contrato da equipe para novos módulos; hoje é proposta, sem mudança nas instruções dos agentes ou CI.
2. Criar página interna de referência com variantes reais e dados sintéticos.
3. Implementar tokens e densidade herdada, incluindo wrappers de bibliotecas.
4. Pilotar cadastro de médico/paciente e toolbar de agenda.
5. Medir tamanhos renderizados e validar teclado/zoom/temas antes de propagar.
6. Migrar módulos conforme a tabela, registrando exceções de domínio.
7. Adicionar verificação dirigida para novas medidas ad hoc em controles e regressões visuais de composições representativas. Não bloquear toda geometria inline nem esconder dívida legada com ignores.
8. Aplicar checklist de MASTER em cada PR de nova funcionalidade; incluir referências visuais com dados sintéticos e cenários verificados.

## Validação da entrega documental (antes da implementação)

- Skill UI/UX Pro Max: design-system, stack Vue e domínio UX executados localmente.
- Inventário textual de todos os Vue e revisão manual dos exemplos listados.
- Fontes públicas W3C consultadas sem envio de código privado.
- Sem banco, servidor, build, dependências novas ou suites de aplicação: essa entrega alterou somente documentação e inventário.
- Não foram validados DOM, layout calculado, contraste final renderizado ou conclusão de tarefas por usuários. Critérios de teste da implementação estão em MASTER.

## Validação da implementação (23/10/2026)

- Vitest completo: 323 arquivos e 3.428 testes passando. Novos: `tests/JavaScript/Components/ControlDensity.test.js` (17 testes: compila o SCSS e confere altura = linha + 2 × padding + borda, a regra de toque, a ausência de tokens em `:root` e de `!important`, o escopo de região em todo seletor emitido, o espaço do ícone de erro, o raio do botão e o marcador de região nos dois modais) e asserções de região, de medidas locais removidas, do nome do botão de CEP, do botão de repetir do alerta de erro e do pai posicionado do `.visually-hidden` em 5 arquivos de teste do piloto.
- Mutação: 24 mutantes entre tokens, modais e piloto; 23 mortos pelos testes e 1 sobrevivente, um `btn-sm` dentro do alerta de convite, que o teste não renderiza (a asserção vazia foi retirada).
- ESLint nos 20 arquivos tocados: 0 erros, exceto 36 `vue/no-mutating-props` já existentes em `PatientFormSections.vue`. Prettier sem desvios nas linhas alteradas; `DoctorFormModal.vue` tem 2 desvios já presentes no HEAD, que não foram reformatados.
- Medição em navegador real: harness temporário (Vite + puppeteer-core + Chrome 156) fora do repositório, resultados acima.
- Não executado: `npm run build` (substitui `public/build` e `bootstrap/ssr`), E2E Cypress e validação com leitor de tela.

Invalidar ou atualizar este inventário quando controles compartilhados, estilos, dependências ou composição dos módulos mudarem. Não usar contagens como prova de conformidade.
