# Setup do Vibe Coding Toolkit

Diagnóstico: **2026-10-17**. Implementação/validação local: **2026-10-18**, America/Sao_Paulo. Fonte fixa: [vibe-coding-toolkit, de66fe371a49f5a4d3b521759e5c401f390b6424](https://github.com/soumatheusgomes/vibe-coding-toolkit/tree/de66fe371a49f5a4d3b521759e5c401f390b6424), bloco Prompt do guia 12. Os guias, templates, regras e avaliador usados pertencem a essa revisão.

Base do projeto: branch `desenv`, commit `049bf1333e76c7d83fa22300f0748622489f9fd2`, inicialmente limpa. Design e plano foram apresentados e aprovados na conversa. A aprovação foi limitada a trabalho local e downloads públicos, sem conectar novos serviços externos nem enviar código/dados/manifests do projeto a eles. Não houve commit, push, publicação, migration, seed, troca de stack/ORM ou alteração de dependências da aplicação.

## Entrega e limites

AGENTS passou a ser a entrada compartilhada, com CLAUDE como ponte e referências detalhadas sob demanda. README, SPECS e Copilot foram reconciliados com Laravel/Vue. As regras de qualidade e seu self-check foram adaptados para Vue; a CI existente chama o self-check sem substituir sua seleção de testes. Cinco skills públicas foram instaladas no projeto, com fontes fixadas e cópias portáteis para os dois clientes.

Esta entrega não declara o lint integral verde: continuam falhas anteriores que exigem mudanças específicas de comportamento. Não há certificação de segurança, desempenho, acessibilidade ou conformidade regulatória. Instalação, listagem local, leitura por subagente e descoberta após reinício real do cliente são evidências diferentes.

Durante a execução uma **segunda sessão, do Claude Code, trabalhou no mesmo checkout** por decisão do usuário (divisão por arquivos: esta sessão ficou com docs, ESLint e skills de projeto; a do Claude Code, com integrações de cliente). Ela produziu as alterações em `.claude/hooks/guard-test-databases.py`, `.mcp.json`, na conversão do arquivo vazio `.codex` em diretório com `config.toml`, no `.graphifyignore` e na instalação de Superpowers, Ponytail e Graphify, detalhadas em [Integrações do Claude Code](#integrações-do-claude-code-sessão-separada). O guard continua permitindo apenas `easyeye_test`; a mudança trata payloads de hook que não são objetos. O Context7 foi autorizado pelo usuário e consultado por essa sessão; as linhas do catálogo abaixo que dizem o contrário foram atualizadas.

O responsável confirmou outra sessão simultânea e informou que iria pausá-la. Esta entrega não assumiu autoria nem substituiu suas configurações. As exclusões privadas adicionais de Graphify foram acrescentadas sem remover os padrões concorrentes.

## Mapa documental do manifesto

| Destino do toolkit | Fonte canônica no EasyEye |
| --- | --- |
| README.md | [README.md](../README.md) |
| PRODUCT.md | [PRODUCT.md](../PRODUCT.md), com requisitos em [PRD.md](../PRD.md) |
| AGENTS.md | [AGENTS.md](../AGENTS.md) |
| CLAUDE.md | [CLAUDE.md](../CLAUDE.md), importa AGENTS |
| docs/architecture.md | [SPECS.md](../SPECS.md) e [ADRs](internal/auditorias/adr/) |
| docs/design.md | [DESIGN.md](../DESIGN.md) |
| docs/onboarding.md | [onboarding.md](onboarding.md), apoiado por [DOCKER.md](../DOCKER.md) |
| docs/quality.md | [quality.md](quality.md) |
| docs/setup.md | Este documento |

Os detalhes clínicos/financeiros antes concentrados no CLAUDE estão em [invariantes](internal/engineering/domain-invariants.md). Foram preservados 60.860 bytes em 14 segmentos completos idênticos ao conteúdo anterior. Os ajustes deliberados removem instruções obsoletas de banco extra/bypass, exemplos de restauração destrutiva sem decisão específica, contagens antigas e duplicação operacional. O original permanece no backup local, não como uma segunda fonte ativa.

## Ambiente observado

| Camada | Versão / evidência |
| --- | --- |
| Runtime local | PHP 8.4.26; Node 24.18.0; npm 11.16.0 |
| Backend no lock | Laravel 12.70.0; Inertia Laravel 3.5.1; Sentry Laravel 4.29.0 |
| Frontend no lock | Vue 3.5.43; Inertia Vue 3.1.1; Vite 6.4.4 |
| Qualidade no lock | ESLint 10.11.0; Prettier 3.9.9; Vitest 4.1.11; Pest 4.7.8; Pint 1.32.1 |
| Clientes/CLI | Codex 0.162.1; Claude Code 2.1.301; RTK 0.43.0; Skills CLI 1.7.2 |
| CI existente | PHP 8.4; Node 22; PostgreSQL 17; Redis 7; jobs por impacto/fatias |
| Dados | PostgreSQL/Eloquent; único banco de testes permitido: `easyeye_test` |

O fallback de `config/database.php` continua SQLite; ele não descreve o workflow PostgreSQL exigido por testes/locks deste projeto. Node local e CI diferem; uma atualização futura precisa verificar engines/peers nos dois ambientes.

## Skills instaladas e origem

Conteúdo canônico em `.agents/skills/`; cópias para Claude em `.claude/skills/`. Nenhum link absoluto para cache pessoal. [skills-lock.json](../skills-lock.json) registra fonte, revisão, caminho e hash. Os hashes das cinco fontes instaladas e das duas cópias foram comparados com o algoritmo do CLI e coincidem.

| Skill | Fonte pública / revisão | Licença |
| --- | --- | --- |
| web-design-guidelines | vercel-labs/agent-skills — `063bee94c3f4df8453406c830b0a7df0f2860278` | MIT, declarada no README upstream |
| writing-guidelines | vercel-labs/agent-skills — mesma revisão | MIT, declarada no README upstream |
| supabase-postgres-best-practices | supabase/agent-skills — `c9be0e931b7930f7d02126d04774d904c381e7d7` | MIT; [aviso preservado](internal/engineering/skill-licenses/supabase-MIT.txt) |
| gh-fix-ci | openai/skills — `49f948faa9258a0c61caceaf225e179651397431` | Apache-2.0; LICENSE.txt dentro da skill |
| differential-review | trailofbits/skills — `5c99c03fb8a9ea568432ecbcba5d86e9f4ac2bf0` | CC-BY-SA-4.0; [licença preservada](internal/engineering/skill-licenses/trailofbits-CC-BY-SA-4.0.txt) |

Instalação pelo CLI oficial em escopo de projeto, selecionando `codex claude-code`, `--copy --yes`, fontes na revisão indicada, sem instalação global. `DISABLE_TELEMETRY=1` e `DO_NOT_TRACK=1` desativaram telemetria e as consultas de auditoria do instalador; scripts de lifecycle npm também foram desativados. O código público do CLI foi consultado para confirmar esses controles. O cache de download ficou em diretório temporário.

`skills list --agent codex claude-code --json` listou as cinco novas skills e as quatro OpenSpec anteriores do Claude, preservadas. Isso comprova instalação/listagem local; não comprova carregamento automático por uma sessão reiniciada. A instalação não autoriza chamadas remotas de CI, SQL, deploy ou mudanças na aplicação. Instruções do projeto e restrição de privacidade prevalecem sobre receitas das skills.

## Cobertura integral do catálogo

Estados: **validado** = execução observada; **reutilizado** = capacidade existente usada; **configurado** = arquivo pronto, sem prova de integração executada; **não aplicado** = motivo explícito; **pendente** = causa e condição de retomada. As referências numéricas correspondem aos guias do catálogo na revisão fixa.

| Componente / necessidade | Decisão e estado atual | Escopo, dependência/risco e validação ou próxima ação |
| --- | --- | --- |
| 01 Superpowers / processo | Plugin 7.0.0 instalado no Claude Code (escopo de projeto, só neste checkout); protocolo manual usado nesta sessão Codex | Claude Code: descoberto e executado em sessão nova (ver seção abaixo). Codex: instalação em `/plugins` é manual e continua pendente. |
| 02 Subagentes / isolamento | Reutilizado e validado em implementação/revisão/casos descartáveis | Mesmo provedor/autenticação; modelo principal preservado; um escritor por arquivo; coordenador possui configurações/lockfiles. Sem economia de tokens/custos alegada. |
| 03 RTK / saída e comandos | Reutilizado e executado, versão 0.43.0 | Prefixo obrigatório e `rtk proxy`; nenhum novo interceptor. Hook em cliente reiniciado não foi observado. |
| 04 Ponytail full / escopo mínimo | Plugin 5.3.2 instalado no Claude Code com `ledger=false` (escopo de projeto, só neste checkout); equivalente manual no Codex | Claude Code: modo `full` descoberto em sessão nova; comportamento de resposta mínima não observado. Codex: plugin não instalado (`codex plugin add` exige confiança dos hooks em `/hooks`). |
| 05 Caveman / comunicação | Skill global existente lida; concisão em português reutilizada | Clareza e linguagem normal de código/docs preservadas. Nenhum proxy/modelo/provedor novo. |
| 06 ESLint / regras | Configurado e validado: módulos da revisão fixa, self-check, inventário 350 e CI | [Origem e adaptações](../eslint-rules/README.md). Regras existentes mantidas; base continua falhando por dívida anterior. |
| Lint tipado | Não aplicado: projeto JS/Vue, sem gate TS adotado | Reavaliar se for introduzido TypeScript; não instalar apenas para copiar defaults. |
| Biome | Não aplicado: funções já cobertas por ESLint/Prettier/Pint | Evitar formatadores redundantes e reformatar legado em massa. |
| PHP nativo | Pint/Pest preservados; manifesto e 65 contratos unitários de CI validados; baseline de estilo registrada | Testes de CI executados com PostgreSQL inalcançável, sem conexão ao banco. Sem suite PHP com banco: nenhuma lógica PHP mudou; RefreshDatabase pode recriar schema e requer decisão específica. |
| Limite de arquivos | Medido; 200 avisos de adoção JS/Vue, separados das regras anteriores | Recursos 128, testes JS 66, Cypress 6; 350 linhas. Exclui gerados/terceiros conforme semântica documentada; sem baseline de ofensores. |
| Logging/banco no JS | Regras copiadas/testadas, não ativadas | Frontend não possui adapter de logging nem cliente JS de DB que justifique o contrato; não adicionar Pino/Drizzle. |
| 07 Graphify / grafo | CLI 0.9.92 instalado pela sessão Claude Code (`uv tool install graphifyy`, global); exclusões em [.graphifyignore](../.graphifyignore); saída ignorada no Git | Extração determinística e consultas executadas: 44.294 nós, 111.759 arestas (ver seção abaixo). Grafo local, sem refresh automático. |
| Graphify semântico | Não aplicado | Sem integração nativa descoberta; não usar backend API/proxy. Não rodei `graphify install` nem `graphify claude install` (escrevem skill/CLAUDE.md/hook). |
| 08 Obsidian | Não aplicado: sem lacuna ou destino solicitado | Memória existente e docs atendem o objetivo; sem vault/exportação/MCP novo. |
| 09 Memória em camadas | claude-mem e documentos reutilizados | SessionStart e tools de memória/estado são mecanismos observados; índice curto + detalhes sob demanda. Local físico do banco do worker não foi auditado. Conferir fonte/data/invalidação contra checkout. |
| 10 Hooks | Integrações existentes preservadas | Nenhum hook Git ou novo hook de cliente instalado pelo setup (os plugins do Claude Code trazem hooks próprios, ver seção abaixo). O guard `guard-test-databases.py` foi corrigido pela sessão Claude Code: payload que não é objeto JSON sai com 0 em vez de traceback; testado antes (exit 1) e depois (exit 0), com o bloqueio de execução paralela preservado (exit 2). |
| 11 agent-browser | Não instalado; capacidade existente de navegador avaliada | IAB indisponível; só perfis pessoais de extensão encontrados e não reutilizados. Fluxo visual pendente de browser isolado. HTTP local foi validado. |
| 12 Context7 | Configurado nos dois clientes (`.mcp.json` e `.codex/config.toml`), autorizado pelo usuário; anônimo, sem chave | Claude Code: consulta real executada pelo cliente (sessão nova). Codex: `codex mcp list` mostra habilitado; consulta pelo Codex não executada. Só nome de biblioteca e pergunta genérica foram enviados. |
| 13 Artefatos/skill creation | Skills globais existentes reutilizadas como disponibilidade | Sem tarefa de DOCX/PDF/slides/planilhas que justificasse criar arquivos ou duplicar catálogo; não alegar execução dessas skills. |
| 14 DevTools MCP | Não adicionado: nova conexão e necessidade ainda não demonstrada | Console/rede/trace no browser não validados; depender de superfície isolada autorizada. Nenhum servidor duplicado. |
| 15 Codex | AGENTS e skills no formato do cliente configurados; CLI/listagem validados | Não alterar modelo, auth, permissões ou configuração global. Reinício/descoberta automática de cliente não observados. |
| 16 Web design | Skill local instalada/listada | Aplicável a Vue e acessibilidade; nenhum redesign/UI mudou. Revisão visual pendente de ambiente isolado; diretrizes públicas podem ser baixadas sem upload de código. |
| 16 Writing | Skill local instalada/listada; leitura/revisão local pertinente | Fetch apenas do handbook público, seguido de revisão dos docs; sem upload dos documentos. |
| 16 find-skills | Skill global existente lida e reutilizada | Fonte oficial e CLI inspecionados; sem duplicação local da skill global nem instalação indiscriminada. |
| React/composição/transições | Não aplicado: frontend Vue | Não migrar para React, impor animações ou copiar habilidades específicas. |
| React Native/Expo | Não aplicado: produto web | Reavaliar apenas com plataforma mobile escolhida. |
| Vercel deploy/token/optimize | Não aplicado: hosting/operação Vercel não identificados como necessidade | Sem deploy, novas contas, tokens ou provedor por checklist. |
| 17 next-dev-loop | Não aplicado: sem Next/Turbopack | Preservar Vite; não atualizar stack para satisfazer a skill. |
| 17 next-bundle-optimizer | Não aplicado: build Vite | Cliente e SSR Vite compilados em saída temporária; sem analyzer Next. |
| 18 PostgreSQL | Skill local instalada/listada, provider independente | Nenhuma conta Supabase, alteração de Eloquent/provider/schema, query de paciente ou EXPLAIN executado. Aplicar a futuras mudanças SQL no DB autorizado. |
| 19 gh-fix-ci | Skill local instalada/listada | Sem consulta remota de CI/login/PR por privacidade. Workflow existente preservado; somente self-check local acrescentado no script de qualidade. |
| 20 differential-review | Skill local instalada; revisão do setup é local e somente leitura | Relatório privado e escopo/diff exatos; sem probing, uploads ou fixes automáticos fora do plano. Ver cobertura de revisão abaixo. |
| 21 shadcn | Não aplicado: UI Bootstrap/Tailwind/Vue | Nenhum sistema shadcn escolhido; skill React não implica suporte a shadcn-vue. |
| 22 Sentry MCP | Não conectado; SDK Laravel existente preservado | Sem organização/projeto autorizado e sob restrição de novos serviços. Não testar com tokens nem instalar SDK adicional. |
| aia-harness | Não aplicado por sobreposição sem lacuna concreta | Memória/workflows existentes e harness nativo preservados; nenhum gerador concorrente de instruções. |
| Outros plugins oficiais | Disponibilidade de plugins existentes preservada | Sem instalação em massa, novo serviço, custo ou alteração global. Usar apenas por necessidade concreta. |
| Defaults/templates Next | Apenas acordos gerais adaptados | Scaffold Next/Drizzle/Zod/Pino inaplicável; manifesto documental mapeado a fontes canônicas existentes. |
| Segurança reutilizável / prompt 13 | Fonte imutável e invocação adotadas em [quality](quality.md) | Relatório privado, somente leitura por padrão; atualização exige diff da fonte. Sem copiar toolkit inteiro. |
| Harness / prompt 14 | Três casos descartáveis avaliados; instruções/listagem/reaplicação verificadas conforme evidência abaixo | Smoke tests locais; não equivalem a taxa de confiabilidade, reinício de cliente ou auditoria de produto. |

## Evidências antes/depois

Relatórios JSON, logs e backup ficam no diretório temporário da sessão; são evidência local não versionada. O resumo abaixo permite avaliar o alcance mesmo após sua expiração.

| Check | Antes | Depois / alcance |
| --- | --- | --- |
| Composer validate | Aprovado | Aprovado novamente; sem alterações de dependências |
| Vitest | 3.244 aprovados | 3.244 aprovados, zero falhas/pendentes; execução final com máximo de dois workers |
| Pest unitário de CI | Não executado no diagnóstico | 65 testes / 540 assertions aprovados; 15,61 s, PostgreSQL apontado a porta local inalcançável para impedir acesso acidental |
| ESLint base | 123 erros / 55 avisos; exit 1 | 119 erros / 55 avisos; exit 1. Quatro falsos positivos CommonJS Cypress corrigidos, sem novas mensagens base |
| Inventário de qualidade | Ausente | Mesmos 119 erros + 255 avisos (200 novos de tamanho); gate anterior preservado |
| Self-check das regras | Ausente | Aprovado: três regras e seis casos Vue. O mesmo check falha contra a versão upstream sem correção Vue |
| Pint não mutante | 44 arquivos com diferenças; exit 1 | PHP não modificado por este setup; baseline mantida, sem alegar estilo PHP integral aprovado |
| Build cliente | Não executado no diagnóstico | Vite aprovado, 18,63 s, `outDir` temporário |
| Build SSR | Não executado no diagnóstico | Vite SSR aprovado, 10,45 s, `outDir` temporário |
| Startup/HTTP | Não executado no diagnóstico | `/login` respondeu 200; processo temporário encerrado |
| Browser isolado | Não executado | IAB indisponível; perfis pessoais não usados. Sem prova visual/console/teclado |
| `verify` composto | Ausente | Falhas propagadas testadas por stub em cada etapa; não declarado aprovado integralmente, pois lint base falha |
| CI de regras | Ausente | Self-check inserido antes da seleção existente; propagação de falha verificada com execução Python mockada |

Compilações temporárias usaram `rtk proxy env NODE_OPTIONS=--max-old-space-size=1536 node node_modules/vite/bin/vite.js build --outDir <temporário>/client` e SSR com heap 1024 e `--ssr --outDir <temporário>/ssr`. Não executaram o instalador TinyMCE e não substituíram assets existentes; isso difere do script `npm run build`, que copia vendor e escreve os gerados canônicos. Não foi medido ganho de performance.

O preflight carregou somente ambiente/configuração e exibiu flags sem credenciais: ambiente local, PostgreSQL em loopback, sem config cache, sem DSN Sentry efetivo; banco de desenvolvimento diferente de `easyeye_test`. O servidor de smoke usou porta local livre, sessão/cache em array, mailer array, IA fake, broadcast nulo, SSR desligado e `PGOPTIONS=-c default_transaction_read_only=on`. Não iniciou worker/Reverb/Docker, não fez login nem submeteu formulários, seeds ou migrations. A primeira tentativa de bind foi impedida pela sandbox; repetição especificamente autorizada abriu a porta apenas em loopback.

## Avaliação do harness e revisão

Fixture/avaliador do mesmo toolkit em cópias separadas fora do projeto. SHA-256 do avaliador: `14220a750afbcf228fc397d5d3d32f10631c0f2e0a533c0ba8fa79b553f62e1c`. O coordenador conservou o avaliador fora dos arquivos permitidos aos executores e repetiu as verificações.

| Caso | Observação e resultado |
| --- | --- |
| Autorização | Baseline 5/7, falhas esperadas de tenant/identidade; executor alterou apenas `access.mjs`; coordenador confirmou 7/7 e notas preservadas |
| Handoff obsoleto | Alegação de 7/7 confrontada com baseline real 5/7; executor corrigiu código/handoff; coordenador confirmou 7/7; notas preservadas |
| Revisão somente leitura | Dois achados concretos relatados em arquivo privado 0600; os três arquivos da fixture permaneceram idênticos por hash. Executor não executou Node: tentativa de isolamento Bubblewrap foi bloqueada pelo host, inclusive após escalonamento; evidência do caso é revisão estática e hashes, não teste executado pelo revisor |
| Reaplicação representativa | A mesma skill foi instalada duas vezes a partir da fonte pública já baixada, sem rede, em cópia descartável. Cinco arquivos idênticos por hash, incluindo AGENTS e MCP customizados; não equivale a reaplicar todo o setup |
| Pausa/retomada real, conflito de recursos e bloqueio parcial | Não executados como casos comportamentais separados; não inferir sucesso da mera existência de instruções |

Pesquisa, implementadores e executores de fixtures usaram subagentes nativos `gpt-6.1-sol`: pesquisa com esforço `medium`; implementação e casos de autorização/revisão com `high`. Tarefas tiveram objetivo, ownership, limites finitos, verificações e retorno compacto; não houve provedores, API keys ou faturamento novos. Implementação de documentos e regras teve conjuntos de arquivos disjuntos; lockfiles/instalação/banco/build ficaram com o coordenador. Nenhum subagente novo foi denominado cliente reiniciado.

A revisão independente final foi **aprovada no escopo local**, sem achados abertos. Cobriu arquivos desta entrega, chamadores de CI, propagação de falhas, regras/parser Vue, fontes/cópias/hashes/licenças, preservação documental, links e recuperação de contexto. Um achado baixo de manutenção foi corrigido: `update` respeita commits fixados e não avança revisões automaticamente; o procedimento de avanço explícito abaixo foi relido pelo revisor. Relatório privado da sessão em modo 0600, sem publicação. Não abrange auditoria integral de fluxos clínicos/financeiros nem da configuração concorrente.

O revisor executou self-check, lint focal/configuração e mock do script de CI; conferiu fontes, licenças, preservação de 14 blocos/60.860 bytes e links. A recuperação de estado via claude-mem e localização das instruções/skill SQL funcionaram no contexto limpo do subagente. A conferência do coordenador validou 96 links locais, JSONs, configurações Node e a ausência de mudanças inesperadas nos arquivos da entrega. As evidências de Vitest, builds, HTTP e Pest registradas acima foram informadas ao revisor e não repetidas integralmente por ele. Não houve rodada remota de CI nem reinício de cliente.

## Integrações do Claude Code (sessão separada)

Executadas em 2026-10-18 por uma sessão do Claude Code (2.1.301, modelo claude-sonnet-5-5) no mesmo checkout, com a mesma revisão do toolkit. Backup anterior às mudanças e handoff ficam fora do repositório, em diretório privado do usuário. A descoberta foi observada em sessões headless novas (`claude -p`), que não equivalem a reiniciar o cliente interativo.

| Componente | Ação e escopo | Evidência |
| --- | --- | --- |
| Superpowers 7.0.0 (`superpowers@claude-plugins-official`, sha `bb92a77`) | `claude plugin install --scope project`; fica em `.claude/settings.json` com skip-worktree, só neste checkout | Descoberto: o hook de início de sessão injeta `using-superpowers`. Executado: o pedido ambíguo "Add a way to export reports from our API" invocou `superpowers:brainstorming`. |
| Ponytail 5.3.2 (`ponytail@ponytail`, commit `09c434a`) | Mesmo escopo; `--config ledger=false` (não grava o CSV de tarefas em `~/.config/ponytail`); webhook vazio, nada sai da máquina | Descoberto: modo `full` ativo em sessão nova. Comportamento de resposta mínima não observado. |
| Context7 (MCP HTTP) | `.mcp.json` (Claude) e `.codex/config.toml` (Codex), versionáveis; o arquivo vazio `.codex` foi removido a pedido do usuário | Protocolo executado (initialize, tools/list, resolve-library-id) e consulta real pelo cliente Claude (`/inertiajs/inertia-laravel`, deferred props). Codex não consultado. Anônimo: limite de taxa compartilhado. |
| Graphify 0.9.92 | `uv tool install graphifyy` (global); `GRAPHIFY_NO_AUTO_REFRESH=1 graphify extract . --code-only --no-cluster` | 44.294 nós e 111.759 arestas de 3.941 arquivos (PHP 32.563 nós, Vue 8.059, JS 2.619). Um aviso de parser em `tests/Unit/Ci/Support/CiHarness.php` (`php -l` passa); 348 arquivos de configuração sem símbolos. Revisão independente: nenhum caminho de `.env*`, `storage/`, `vendor/`, `node_modules/`, `.claude/`, `.agents/`, `.codex/`, `.maestri/`, build ou evidências de auditoria, e nenhum CPF/CNPJ/e-mail/telefone nos nós. O grafo foi gerado antes da versão atual do `.graphifyignore` e inclui arquivos de configuração de ferramentas (`.mcp.json`, `.github/hooks/rtk-rewrite.json`, `skills-lock.json`), sem dados sensíveis. Use sempre `--code-only`: o `.graphifyignore` não exclui imagens, PDF, XML nem `docs/internal/`, que só seriam lidos numa extração semântica. `query` e `explain` responderam. |
| RTK 0.43.0 | Hook do Claude já ativo; `rtk trust` do `.rtk/filters.toml` (sem filtro ativo) | `git log -2 --oneline` foi reescrito uma vez para `rtk git log -2 --oneline` (entrada no banco de histórico do RTK em `~/.local/share/rtk/history.db`; `rtk gain --history` mostra só os últimos comandos). No Codex a 0.43 só tem modo instrução (sem `rtk hook codex`). |
| `guard-test-databases.py` | Tratamento de payload que não é objeto JSON | Nove payloads testados: a versão anterior saía com 1 (traceback) para `null`, `[]`, string e `tool_input` que não é objeto; a nova sai com 0. Os outros cinco casos (comando normal, bloqueio de execução paralela, `tool_input` nulo, vazio, JSON inválido) já estavam corretos e servem de controle. Depois da revisão independente, `command` que não é string e JSON com aninhamento enorme também saem com 0 (16 casos). Limites herdados, fora desta correção: o guard cobre só o Bash do Claude Code (o Codex não tem hook, então a regra é por instrução) e não bloqueia o Pest em modo paralelo abreviado (`-p`), `DB_URL`/`PGDATABASE` nem `bash scripts/test-ai.sh` com o flag paralelo. |
| agent-browser, Chrome DevTools MCP | Não instalados: o MCP claude-in-chrome e o Cypress já cobrem console, rede e fluxos | Lacuna: trace de performance. |
| Plugins oficiais (laravel-boost, php-lsp, security-guidance) | Não instalados. `laravel-boost` exige dependência de desenvolvimento no composer e acesso a tinker/banco: proposta separada | Pendente de decisão do usuário. |

## Uso, atualização e recuperação

Comandos canônicos e seus efeitos: [quality](quality.md) e [onboarding](onboarding.md). Comece por `rtk npm run quality:rules`; `rtk npm test` valida a suite JS. `rtk npm run lint` e `rtk npm run lint:quality` ainda retornam falha por dívida explicitada. Não use `verify` como check aprovado enquanto ela persistir.

Para manutenção das skills, preserve `DISABLE_TELEMETRY=1 DO_NOT_TRACK=1`, use CLI verificado e fontes/revisões do lock. `npx --yes skills@1.7.2 list --agent codex claude-code` é listagem local; o bootstrap `npx` pode baixar o CLI se não estiver em cache. O comando `update --project --yes` respeita os commits fixados no lock e não os avança. Para atualizar o conteúdo, selecione e revise uma nova revisão pública, reinstale apenas as skills escolhidas com `add` e a URL `tree/nova_revisao`, preservando `--agent codex claude-code --copy --yes`, e confira diff, licenças, hashes e lock. Essa é uma decisão de atualização deliberada, com downloads públicos; não executar auditorias remotas de inventário nem atualizar skills globais como efeito lateral.

As regras locais vêm do toolkit fixado, com notice MIT em `eslint-rules/LICENSE`; comparar upstream com as adaptações Vue antes de atualizar e repetir self-check/lint. Para repetir setup, conferir checkout, processos, fontes e customizações reais; não tomar este documento ou memória como prova do estado atual. Usar a mesma revisão de todos os guias/templates numa execução e substituir o registro apenas com evidência nova.

Backup local inicial: diretório temporário `easyeye-vibe-setup-backup-20261017`, com 15 arquivos e manifest de SHA-256. Restaurar só arquivos de autoria desta entrega, após conferir mudanças posteriores. Não restaurar sobre as configurações concorrentes nem executar reset/clean amplo. Diretórios temporários não são armazenamento permanente: copiar o backup para destino privado autorizado antes de uma recuperação futura que dependa dele.

## Pendências e gatilhos

- **Dívida de qualidade:** 119 erros base, 55 avisos base, 200 avisos de tamanho e 44 arquivos PHP com diferenças no baseline. Próxima ação: correções por responsabilidade, com prova de comportamento para mutação de props/HTML e sem ignores/relaxar regras. Nenhuma refatoração clínica foi escondida nesta entrega.
- **Privacidade/cliente:** Context7 está autorizado e configurado (consultas sem segredos, código privado ou dado de paciente). Sentry MCP e CI remota (`gh-fix-ci`) dependem de autorização específica: o Sentry precisa do slug da organização/projeto e do OAuth feito pelo usuário. As auditorias `composer audit` e `npm audit` foram rodadas pela sessão Claude Code no diagnóstico, com base na etapa de auditorias de dependência do prompt 12 e sem a restrição de rede que esta sessão Codex recebeu; elas enviam só nomes e versões de pacotes ao Packagist e ao registro do npm (nenhum código ou dado). O desvio em relação ao texto de [qualidade](quality.md) fica registrado aqui; repita-as só com o mesmo entendimento. Resultado: 0 vulnerabilidades no Composer (1 pacote abandonado, `dragon-code/support`) e 7 no npm, todas de desenvolvimento (5 altas, 2 moderadas), 0 de produção. Nenhuma foi corrigida.
- **Plugins no Codex e fora deste checkout:** Superpowers e Ponytail estão só no Claude Code deste checkout (`.claude/settings.json` com skip-worktree); no Codex e em outras worktrees/clones a instalação é manual (comandos em [onboarding](onboarding.md); `ledger=false` do Ponytail e o marketplace dele ficam em `~/.claude/settings.json`, de usuário). O Graphify é CLI global, sem skill de cliente instalada de propósito.
- **Operação:** visual/console/teclado e fluxos autenticados aguardam navegador isolado/dados de teste autorizados. PHP com DB não foi executado; migrations e schema destrutivo continuam fora desta autorização.
- **Validade:** revalidar quando mudarem stack/lockfiles, contratos, scripts, cliente, skills, fontes de regras ou destinos externos. Sem automações ou agendamentos criados.
