# Qualidade e verificações

Os comandos abaixo partem da raiz com [RTK](../RTK.md). [AGENTS.md](../AGENTS.md) define limites; [onboarding](onboarding.md) detalha banco, startup e efeitos de instalação/build. O [registro do setup](setup.md) guarda evidências finais e estado das ferramentas.

## Comandos e cobertura

| Comando | O que verifica / efeito |
| --- | --- |
| `rtk npm run lint` | ESLint do repositório, sem correção automática; falhas legadas permanecem visíveis. |
| `rtk npm run lint:quality` | Medição de adoção das regras adicionais; avisos não equivalem a correções. |
| `rtk npm run quality:rules` | Self-check local: `node scripts/verify-quality-rules.mjs ./eslint-rules/index.cjs`. |
| `rtk npm test` | Suite Vitest (`vitest run`); não demonstra comportamento PHP ou E2E. |
| `rtk npm run format:check` | Prettier no escopo frontend de `.prettierignore`; PHP pertence ao Pint. |
| `rtk proxy php vendor/bin/pint --test` | Estilo PHP, sem escrever correções. |
| `rtk proxy composer validate --no-check-publish` | Validade do manifesto Composer, sem instalar. |
| `rtk npm run build` | Copia TinyMCE e compila cliente + SSR; substitui `public/vendor/tinymce*`, `public/build` e `bootstrap/ssr`. |
| `rtk npm run verify` | Lint → self-check → Vitest → build; interrompe/propaga a primeira falha. Não executa PHP, banco, migrations ou deploy. |

Preserve consumidores e gerados antes do build. Compilar com `outDir` temporário é evidência distinta da execução do script canônico. Não rodar formatadores com escrita no repositório inteiro para resolver dívida fora da tarefa.

Pest 4, Laravel Pint e Cypress existentes permanecem ferramentas nativas. Testes PHP com DB dependem de preflight e autorização para o efeito em schema: somente PostgreSQL `easyeye_test`, sem PHP `--parallel`, bancos extras ou outro escritor. Não aplicar migrations como check. Cypress depende de aplicação local controlada, dados de teste e isolamento de notificações/gateways; inspecione `e2e/cypress.config.js` antes de selecionar um fluxo. Os checks JS não autorizam implicitamente esses processos.

## Linha de base anterior à adoção

Medição de **2026-10-17**, antes das alterações; não é resultado atualizado do checkout:

| Check | Resultado observado |
| --- | --- |
| Vitest | **3.244 aprovados**, zero falhas/pendentes. |
| ESLint | **123 erros, 55 avisos**, 916 arquivos; exit 1. |
| Pint `--test --format=json` | **44 arquivos** com diferenças; exit 1. |
| Composer validate | Aprovado; exit 0. |

Erros ESLint: 48 `no-unused-vars`, 39 `vue/no-mutating-props`, 17 `no-undef`, 11 `no-irregular-whitespace`, 5 `no-useless-escape`, 3 `vue/no-v-text-v-html-on-component`. Avisos: 42 `vue/prop-name-casing`, 12 `vue/no-v-html`, 1 `vue/no-template-shadow`. Saídas comparativas depois do setup ficam em [setup](setup.md); correções de escopo CommonJS devem ser medidas, sem presumir que todo `no-undef` é falso positivo.

Essas falhas impedem afirmar gate integral verde. Trate violações por regra, arquivo e risco. Mudanças em mutação de props ou HTML clínico precisam de revisão de comportamento; não aplique fixes mecânicos, ignores de ofensores, tetos maiores ou severidade menor para apagar dívida. Avisos novos de adoção devem continuar mensuráveis.

## Regras e stack

Os módulos compatíveis derivam do [toolkit na revisão fixa de66fe3](https://github.com/soumatheusgomes/vibe-coding-toolkit/tree/de66fe371a49f5a4d3b521759e5c401f390b6424). Origem/adaptações e cobertura efetiva estão nos arquivos das regras, self-check e registro de setup. Perfil Next/React/Drizzle e lint tipado não são contratos desta aplicação JavaScript/Vue/Eloquent.

A referência de 350 linhas serve para inventário e revisão proporcional ao comportamento, sem impor refatoração indiscriminada. Cobertura de lógica em Vue precisa estar comprovada pelo parser/self-check; contar linhas não demonstra cobertura semântica de template. Regras de logging/acesso a dados só devem ser ativadas onde existirem fronteiras reais e contrato de logging adequado. ESLint/Prettier/Pint existentes dispensam adicionar outro formatador por checklist.

## Estratégia de testes e revisão

Selecione testes por decisões, falhas e regressões do comportamento afetado. Reutilize suites existentes e referências em [invariantes](internal/engineering/domain-invariants.md). Registre comando, isolamento, resultado e limites; lint, build e testes unitários não comprovam segurança, performance, acessibilidade completa ou conformidade regulatória.

A CI existente em `.github/workflows/integrator-contracts.yml` seleciona testes/checks por impacto; consulte workflow e scripts atuais antes de alterar seu contrato. Execução local não prova rodada remota de CI aprovada. Mudanças não triviais pedem revisão independente quando disponível, com diff exato, chamadores/controles e testes pertinentes; achados confirmados são corrigidos no escopo, com verificação afetada. Limites de revisão devem constar no retorno.

Alterações de instruções/memória exigem checks do harness pertinentes em cópia descartável, com cliente/modelo/esforço efetivamente observados. Recuperação por agente em contexto limpo e descoberta após reinício real de cliente são estados separados. Mera instalação/configuração não comprova nenhum deles.

## Revisão de segurança reutilizável

Fonte adotada: [prompt 13 — revisão de segurança, revisão imutável](https://github.com/soumatheusgomes/vibe-coding-toolkit/blob/de66fe371a49f5a4d3b521759e5c401f390b6424/docs/prompts/13-security-review.md). Consulte essa revisão e execute o bloco Prompt dentro dos limites do projeto, sem copiar o toolkit inteiro. Atualizações exigem revisar o diff e registrar a revisão nova; não usar `main` como referência reprodutível.

Invocação para revisão futura do checkout, em conversa com o agente:

```text
Leia o prompt 13 na revisão de66fe371a49f5a4d3b521759e5c401f390b6424 do toolkit,
cujo link está neste documento. Execute o bloco Prompt em modo repository,
somente leitura, preservando cliente/modelo/autenticação. Inclua alterações locais
relevantes e registre o commit atual, estado dirty e exclusões. Não conecte novos
serviços, não envie código/dados/manifests/relatórios externamente nem aplique fixes.
Salve relatório sem segredos fora do repositório, em diretório privado com
permissões restritas e nome único; não sobrescreva relatórios anteriores.
```

Para revisar mudança, forneça base/head resolvidos, comparação endpoint ou merge-base e inclusão/exclusão de dirty. Ao incluir dirty, head deve ser o HEAD atual. O relatório privado identifica escopo, fontes, cobertura, testes, severidade/confiança, fluxo alcançável, contraprovas, hipóteses e limitações. Revisão local não autoriza probing de produção, uploads ou exploração de serviços de terceiros. Uma skill opcional não amplia autoridade.

## Dependências e manutenção

Auditorias locais com dados já disponíveis podem complementar a análise. Auditorias remotas podem enviar inventário/manifests; a restrição atual não autoriza esse envio. Não iniciar `npm audit`/`composer audit` pela rede sem autorização específica para a divulgação. Não usar `audit fix`, `--force` ou upgrades indiscriminados para produzir checklist verde.

Atualize este documento junto com scripts/regras. Compare checks equivalentes antes/depois e preserve customizações na repetição do setup. Hooks Git, automações, bloqueios de push e requisitos de branch não são efeitos implícitos da adoção.
