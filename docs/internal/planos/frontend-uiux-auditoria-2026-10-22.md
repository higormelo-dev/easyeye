<!-- Origem: auditoria estática com a skill ui-ux-pro-max, 22/10/2026, encaminhada pelo dono para execução em lotes (Lote 1 = itens 1–13). Plano de implementação: frontend-uiux-lote1-plano.md. -->

# EasyEye — revisão de frontend com UI/UX Pro Max

Data: 22/10/2026. Escopo: análise estática, somente leitura da implementação atual, incluindo alterações locais ainda sem commit. Nenhum arquivo do projeto foi modificado. Relatório privado em /tmp.

## Método e limites

Inventário: 502 componentes/páginas Vue, sendo 361 arquivos em Pages/Panel. Foi feita uma revisão transversal dos componentes compartilhados e uma amostra de autenticação, dashboard da clínica, dashboard do gestor, agenda, cadastro de pacientes/médicos, timeline do prontuário e portal do paciente. Não foi feita leitura integral dos 502 arquivos, nem inspeção visual autenticada, teste com paciente real, auditoria completa de acessibilidade ou medição de desempenho. Esta lista reúne os problemas e oportunidades identificados, sem prometer exaustividade de todas as telas.

Skill executada localmente: consulta --design-system para healthcare/clinic SaaS, consulta --stack vue e consulta --domain ux. O gerador sugeriu dashboard denso, mas também sugeriu uma landing e fontes voltadas a código: essas partes não são adequadas para aplicar automaticamente ao painel clínico. Preservar Vue/Inertia, Inter e identidade atual; adaptar recomendações ao contexto de recepção, médico e financeiro. DESIGN.md:164 limita o design documentado à landing pública.

Prioridades: alta = risco de perder trabalho, interpretar informação errada ou não conseguir operar; média = fricção, legibilidade e consistência; investigação = depende de teste ou medição. Evidência estática indica o que o código faz; não substitui validação do CSS final e do DOM renderizado.

## Melhorias identificadas

| # | Prioridade | Evidência local | Problema e ação recomendada |
|---|---|---|---|
| 1 | Alta | resources/css/system/components/_reboot.scss:30; resources/js/Components/Panel/ActionIconButton.vue:140 | `button,a { outline: none !important }` anula outlines locais sem important, inclusive focus-visible do ActionIconButton. Retirar supressão global e definir foco visível consistente em links, botões, menus e ações. Alguns controles podem ter box-shadow alternativo; conferir resultado renderizado. |
| 2 | Alta | resources/js/Pages/Auth/Login.vue:90; resources/js/Pages/Panel/Doctors/DoctorFormModal.vue:254 | Labels de e-mail/senha e vários campos do médico não têm associação for/id, nem envolvem o input. Associar rótulos a controles reais, incluindo o input interno de SearchSelect. |
| 3 | Alta | resources/js/Layouts/AppLayout.vue:344–405 | Menu móvel, recolher sidebar, idioma e tema dependem de ícone sem nome acessível explícito. Adicionar rótulos traduzidos; tema deve anunciar estado. |
| 4 | Alta | resources/js/Pages/Auth/Login.vue:118; resources/js/Pages/Panel/Doctors/DoctorFormModal.vue:531 | Mostrar senha usa tabindex=-1 e ícone sem rótulo. Permitir Tab, nomear Mostrar/Ocultar senha e comunicar estado. |
| 5 | Alta | resources/js/Pages/Panel/Patients/PatientFormModal.vue:94; resources/js/Pages/Panel/Doctors/DoctorFormModal.vue:169; resources/js/Components/Panel/OffcanvasPanel.vue:53–85 | Cadastro fecha por backdrop/Escape/X/cancelar; os consumidores examinados não verificam alterações antes de fechar e resetam ao reabrir. Interceptar todas as saídas quando sujo. Guard de navegação já existe no projeto, mas não substitui confirmação ao fechar modal. Não salvar dados clínicos indiscriminadamente em localStorage. |
| 6 | Alta | resources/js/Pages/Panel/Doctors/DoctorFormModal.vue:109,137,254; resources/js/Pages/Panel/Patients/PatientFormModal.vue:62 | Erros em abas recebem indicação visual, mas submit não conduz à primeira aba/campo inválido; faltam associações acessíveis em campos examinados. Abrir aba correspondente, focar campo, associar erro e exibir resumo quando houver vários. |
| 7 | Alta | resources/js/Pages/Panel/Doctors/DoctorFormModal.vue:72 | loadEditData não verifica res.ok e só tem finally. Falha pode encerrar spinner sem mostrar recuperação. Exibir estado de erro com tentar novamente e impedir edição de dados não carregados. |
| 8 | Alta | resources/js/Components/Panel/SearchSelect.vue:109–137 | Debounce não impede resposta de uma busca antiga de substituir a nova. Usar mecanismo existente de última requisição válida/cancelamento e limpar trabalho pendente ao desmontar. |
| 9 | Alta | resources/js/Components/Panel/SearchSelect.vue:91–100,119–137 | Falha remota é silenciosa; resposta vazia volta às opções iniciais. Separar carregando, resultados vazios, falha e seed; permitir nova tentativa sem apresentar opções antigas como resultado atual. |
| 10 | Alta | resources/js/Pages/Panel/MedicalRecords/Index.vue:37–60 | Timeline usa fetch sem res.ok e sem estado de erro/catch local. Falha inicial pode terminar em aparência vazia; falha de paginação não tem recuperação própria. Preservar itens já carregados e mostrar falha com retry, distinta de ausência de prontuários. |
| 11 | Alta | resources/js/Pages/Panel/Schedules/CalendarView.vue:46–79 | Calendário força texto branco sobre todas as cores. Contrastes calculados das cores declaradas: #0aa2c0 3,02:1; #ea580c 3,56:1; #b7791f 3,64:1 contra branco. Insuficientes para texto pequeno normal se essas cores forem as efetivas. Definir pares semânticos de fundo/texto e conferir CSS final. |
| 12 | Alta | resources/js/Pages/Panel/Schedules/CalendarView.vue:74 | Todos os agendamentos recebem bloco visual de 20 minutos. Se procedimentos têm durações diferentes, a ocupação desenhada engana. Usar duração real; se não existir, comunicar a duração estimada e validar contrato com backend. |
| 13 | Alta | resources/js/Pages/Panel/Schedules/CalendarView.vue:191 | Grade fixa 07h–20h30 pode ocultar agendamentos fora da faixa. Derivar faixa do expediente e ampliar para incluir eventos existentes; testar clínicas que operam cedo ou à noite. |
| 14 | Média | resources/js/Layouts/AppLayout.vue:599–646 | Grupos da sidebar usam link # como botão, sem aria-expanded/controls; rota ativa só por classe. Usar controle de expansão semântico e aria-current nos destinos ativos. O grupo ativo também é forçado aberto por isMenuOpen:179; permitir recolhimento previsível. |
| 15 | Média | resources/js/Layouts/AppLayout.vue:693; resources/js/Pages/Panel/Schedules/Index.vue:638 | Painel não oferece skip link nem região main explícita no layout; agenda começa por h4. Padronizar landmark principal, título h1 por página e hierarquia subsequente. Validar foco após navegação. A landing já tem skip link. |
| 16 | Média | resources/js/Layouts/AppLayout.vue:620,634; resources/js/panel.js:25 | Links comuns da sidebar usam a[href] e provocam navegação completa apesar do Inertia. Usar Link nas rotas internas apropriadas; preservar filtros, scroll e guardas existentes. Rotas que exigem recarga devem continuar explícitas. |
| 17 | Média | resources/js/Layouts/AppLayout.vue:131 | safeRoute transforma rota inválida em #. O fallback protege contra tela quebrada, mas deixa ação que parece disponível e não leva ao destino. Representar indisponibilidade com mensagem e sem navegação enganosa, preservando diagnóstico existente. |
| 18 | Média | resources/js/Components/Panel/ActionIconButton.vue:104; resources/js/Components/Panel/SearchSelect.vue:273 | Ações de 32px e selects de 31px são apertados para toque. Criar densidade compacta para mouse e confortável para toque, visando 44px de alvo. Isso não implica que 32px viole o mínimo WCAG AA de 24px. |
| 19 | Média | resources/css/system/components/_reboot.scss:17; resources/css/system/components/_forms.scss:24; resources/js/Pages/Panel/Schedules/EventCard.vue:29–74 | Base 14px, campos pequenos 12px e metadados da agenda próximos de 11px dificultam leitura prolongada. Definir papéis tipográficos, manter informação clínica essencial maior e inputs mobile em 16px; testar zoom e nomes longos. |
| 20 | Média | resources/js/Pages/Panel/Patients/PatientFormModal.vue:158 | Formulário exige min-height de 480px independentemente da viewport. Adaptar a telas baixas/landscape, preservar rodapé de ações e permitir crescimento de traduções/texto ampliado. Validar quebra real antes de escolher altura nova. |
| 21 | Média | DESIGN.md:164; resources/css/system.scss:13; resources/js/Pages/Panel/Schedules/EventCard.vue; resources/js/Components/Panel/SearchSelect.vue | Documentação visual cobre a landing, enquanto painel usa tokens Preclinic e vários valores locais; EventCard mistura Tabler/Font Awesome. Documentar sistema do painel: cores semânticas, tipografia, espaçamentos, densidades, ícones, raios e camadas. Migrar gradualmente preservando Bootstrap + Tailwind. |
| 22 | Média | resources/js/Layouts/AppLayout.vue; resources/js/Pages/Panel/Patients/PatientFormModal.vue:90; resources/js/Pages/Panel/Doctors/DoctorFormModal.vue:23 | UI tem seletor de idioma, mas títulos, abas e ações de cadastros continuam fixos em português. Completar traduções pt_BR/en, inclusive textos assistivos, falhas e confirmações; testar expansão de texto. |
| 23 | Média | resources/js/Pages/Panel/ManagerDashboard/MrrTrendChart.vue:126 | Gráfico canvas não tem nome, resumo ou tabela de valores no componente. Adicionar identificação, resumo da tendência e alternativa tabular. Se tooltip trouxer informação exclusiva, disponibilizá-la também por teclado. |
| 24 | Média | resources/js/Pages/Panel/ManagerDashboard/MrrTrendChart.vue:79,93,121 | Gráfico do gestor fixa pt-BR, R$, /mês e abreviações. Reutilizar formatador de locale e moeda; traduzir unidade temporal e verificar eixos em telas estreitas. |
| 25 | Média | resources/js/Components/Panel/CenteredModal.vue:206; resources/js/Components/Panel/OffcanvasPanel.vue:208; resources/js/Pages/Auth/Login.vue:172 | Componentes examinados animam sem tratamento local de movimento reduzido; não foi encontrada política global do painel que cubra esses casos nas fontes examinadas. Cobrir modais, spinner customizado e Chart.js respeitando preferência, sem remover feedback de atividade. |
| 26 | Média | resources/js/Layouts/AppLayout.vue:244–282 | Flash de erro desaparece automaticamente em seis segundos. Manter falhas que exigem ação até dispensar/corrigir; sucessos simples podem ser temporários. Vincular recuperação à operação e evitar excesso de anúncios assertivos. |
| 27 | Média | resources/js/composables/useDashboardPolling.js:27–45; resources/js/Pages/Panel/Dashboard/DashboardHeader.vue:154 | Polling preserva último horário bem-sucedido, o que é bom, mas não retorna estado explícito de falha; cabeçalho mantém linguagem de ao vivo. Expor indicador de conexão/dados desatualizados e retry após falha, distinguindo métricas de gestão que só atualizam manualmente. |
| 28 | Média | resources/js/Layouts/PatientPortalLayout.vue:50–65,91–100 | Nome Minha conta fica oculto no mobile, deixando botão só com ícones sem aria-label; dropdown não informa expansão nem trata Escape no componente. Fechar alertas também não tem rótulo. Corrigir nomes, estado, teclado e anúncios dos alertas, especialmente para pacientes com baixa visão. |

## Pontos que precisam de validação visual e funcional

Não classificar os itens abaixo como bugs comprovados sem executar os cenários.

1. Agenda em 375px, 768px, 1024px e 1440px, incluindo landscape: filtros, seleção em lote, nomes longos, lista/semana/mês e ações simultâneas.
2. Zoom de 200%/400%, ampliação de texto e baixo contraste: reflow, clipping, rodapés fixos, texto clínico e dropdowns.
3. Tema claro/escuro: contraste efetivo de badges, disabled, placeholder, bordas, foco, gráficos e menus teleportados. Tema já possui correções existentes; não presumir falha global.
4. Teclado e leitor de tela: login, clínica ativa, cadastrar paciente, agendar, reagendar, cancelar, atendimento e cobrança. Testar foco com overlays aninhados/TinyMCE.
5. Estado de navegação: voltar/avançar deve restaurar filtros, paginação e scroll sem trocar clínica ou paciente silenciosamente.
6. Hierarquia visual: validar uma ação principal por contexto; reduzir competição entre toolbar da agenda, avisos, IA flutuante e suporte em telas pequenas.
7. Segurança operacional da interface: clínica e paciente ativos claramente identificados; ação em lote deve dizer quantidade/escopo e confirmação deve mostrar alvo e consequência. Não foi auditada autorização backend.
8. Estados vazios: distinguir primeira utilização, ausência de resultados por filtro, acesso indisponível e erro de rede; oferecer próxima ação adequada.
9. Desempenho: medir carregamento real e interação de agenda/prontuário/imagens antes de otimizar. vendor.js carrega plugins globais; investigar uso por rota e redução gradual, preservando dependências e ordem. Não há evidência medida para afirmar lentidão ou economia de bytes.
10. Imagens de exames: validar carregamento progressivo, reserva de espaço, zoom, comparação, lateralidade, mensagem de falha e retry sem expor conteúdo em logs/capturas externas.
11. Operação com rede lenta/offline: mostrar processamento e resultado; impedir duplo envio nos fluxos que alteram estado; timeout recuperável sem duplicar operação.
12. Teste de usabilidade por função: recepção agenda e remarca; médico identifica paciente e conclui atendimento; financeiro recebe/concilia; paciente encontra e abre documento. Medir conclusão, erros e necessidade de ajuda antes/depois.

## O que preservar

- Dashboard da clínica já é por função e permite reordenar/ocultar seções; não recomendar refazer isso como se faltasse.
- Páginas Vue têm import.meta.glob lazy; agenda já usa defineAsyncComponent. Não afirmar que tudo está num bundle único.
- CenteredModal/OffcanvasPanel já usam useModalA11y com entrada/retorno de foco, Escape, isolamento do fundo e pilha de modais.
- Há useUnsavedChangesGuard e latestRequest reutilizáveis; aplicar onde houver lacuna em vez de criar alternativas.
- ActionIconButton já fornece aria-label e semântica de disabled em variantes; o problema destacado é tamanho em toque e conflito com reset global de foco.
- Agenda já oferece lista alternativa, labels completos para agendamentos do calendário e indicação textual de encaixe/série. Melhorar cobertura sem apagar essas soluções.
- Dashboard pausa polling com aba oculta e só atualiza lastUpdated após sucesso.
- Campos dark mode, tooltip e tradução já têm infraestrutura; completar adoção em vez de trocar stack.

## Ordem recomendada

1. Corrigir componentes de maior alcance: foco, nomes acessíveis, labels, erros e proteção de fechamento.
2. Corrigir estados de rede e respostas antigas em busca/timeline/cadastros.
3. Validar fidelidade da agenda: duração, faixa horária e contraste.
4. Padronizar densidade, tipografia, navegação, traduções e gráficos.
5. Testar no navegador por função, dispositivos, temas e acessibilidade; medir desempenho e depois otimizar.

Critérios de aceite: tarefa concluída por teclado; edição não perdida por saída acidental; falha não apresentada como vazio/sucesso; resultado antigo não substitui busca atual; agenda representa horários corretos; texto pequeno >=4,5:1; alvos confortáveis no toque; rota de retorno e contexto preservados.

## Verificação realizada

- Consultas locais da skill (--design-system, --stack vue, --domain ux).
- Leitura de componentes, layouts, estilos compartilhados, entrypoints e configuração de build.
- Inventário de Vue e cálculo de contraste sRGB para três pares declarados no calendário.
- Consulta às fontes públicas W3C abaixo, sem envio de código ou dados privados.
- Nenhum build, teste PHP, migration, servidor ou fluxo de escrita foi executado. Não há mudança de código a validar por suíte nesta revisão.

Referências públicas:

- Foco visível: https://www.w3.org/WAI/WCAG22/Understanding/focus-visible.html
- Tamanho mínimo de alvo WCAG 2.2 AA (24 CSS px e exceções; 44px é recomendação de conforto mais rigorosa): https://www.w3.org/WAI/WCAG22/Understanding/target-size-minimum.html
- Feedback e erros em formulários: https://www.w3.org/WAI/tutorials/forms/notifications/
