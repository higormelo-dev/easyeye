# Preparação local

Leia [AGENTS.md](../AGENTS.md) e as [invariantes do domínio](internal/engineering/domain-invariants.md) pertinente. O setup usa o ambiente existente; não cria contas, conexões externas ou bancos adicionais.

## Pré-requisitos e versões

PHP 8.4 com as extensões exigidas pelo Composer, Composer, Node/npm, RTK e PostgreSQL no fluxo local documentado. A CI usa Node 22/PHP 8.4; o diagnóstico de 2026-10-17 usou Node 24.18.0/npm 11.16.0/PHP 8.4.26. Valide compatibilidade antes de trocar versões. Redis, Reverb e workers são necessários apenas nos fluxos que dependem deles.

Lockfiles definem dependências. Verificações iniciais sem boot da aplicação:

```bash
rtk --version
rtk proxy php --version
rtk proxy node --version
rtk proxy npm --version
rtk proxy composer validate --no-check-publish
```

## Dependências e configuração

Reutilize `vendor/` e `node_modules/` existentes quando coerentes com os lockfiles. Antes de instalar, leia os scripts de [composer.json](../composer.json) e [package.json](../package.json): Composer executa descoberta de pacotes e pode publicar assets; npm executa cópia de TinyMCE e o `prepare` existente instala hooks via Lefthook. O setup não autoriza instalar novos hooks Git. Uma instalação nova deve tratar esses efeitos explicitamente; `--ignore-scripts`/`--no-scripts`, se escolhidos, deixam preparações pendentes. Dependências baixadas não comprovam ambiente pronto.

Configure o ambiente local pelo procedimento da equipe, sem publicar ou imprimir valores. Variáveis relevantes incluem `APP_ENV`, `APP_KEY`, `APP_URL`, `DB_CONNECTION`, `DB_HOST`, `DB_PORT`, `DB_DATABASE`, fila, cache, sessão e flags dos provedores. Não copie credenciais de produção. Uma instalação já configurada não precisa regenerar chave, aplicar migrations ou semear dados como preflight.

## Banco e testes PHP

O [phpunit.xml](../phpunit.xml) seleciona PostgreSQL e **`easyeye_test`**, único banco autorizado para testes. O fallback SQLite de `config/database.php` não é o destino dos testes. Antes de testes que sobem Laravel, confirme configuração efetiva, ambiente local/testing, host local, nome exato do banco, ausência de outro escritor e bloqueio de envios externos reais. Não registre senhas nessa verificação.

`RefreshDatabase` e testes em `tests/Integration/` podem recriar schema. Execute apenas quando esse efeito estiver autorizado, serializando o acesso ao banco. Nunca use PHP `--parallel`, crie bancos por sessão, altere o nome para contornar colisões ou burle o [guard existente](../.claude/hooks/guard-test-databases.py). O setup documental e os checks JS não dependem dessas ações.

## Startup controlado

Inspecione processos/portas existentes e efeitos de boot antes de iniciar serviços. Preserve sessões de outras pessoas; encerre somente processos criados pela tarefa. Depois de confirmar ambiente local e ausência de envios externos, os processos podem ser iniciados separadamente, em terminais próprios:

```bash
rtk proxy php artisan serve --host=127.0.0.1 --port=8085
rtk npm run dev -- --host 127.0.0.1
```

O Vite usa a porta 5173 e cria `public/hot`; preservar/restaurar esse arquivo é necessário quando já existe servidor consumidor. O servidor PHP pode acessar banco, cache e serviços durante requests; use dados de teste e revise o fluxo antes de navegar. Esse par de comandos não inicia worker ou Reverb.

`rtk proxy composer dev` inicia cinco processos: servidor 8085, `queue:listen`, Pail, Vite e Reverb. O worker pode processar jobs existentes e disparar integrações. Use somente quando esses efeitos forem necessários e estiverem isolados/autorizados.

O [Docker existente](../DOCKER.md) tem efeitos adicionais: o entrypoint instala Composer se necessário, gera chave quando ausente e aplica `migrate --force` com `RUN_MIGRATIONS=true`, atualmente configurado no serviço app. O serviço Node instala dependências e inicia Vite; outros serviços incluem worker/Reverb. `docker compose up` não é preflight passivo e não faz parte do setup sem migrations. Seeds também não são uma etapa automática autorizada.

## Verificação e agentes

[Qualidade](quality.md) lista checks locais, testes condicionais e a linha de base. `rtk npm run verify` executa lint, self-check, Vitest e build, com falhas propagadas. Pode parar no lint legado. O build substitui gerados do cliente, SSR e TinyMCE; preserve os artefatos antes ou use saídas temporárias verificadas e registre a diferença em relação ao comando canônico.

O [registro de setup](setup.md) distingue instalação, configuração, descoberta e uso real. Reutilize claude-mem e documentos versionados: recupere o assunto necessário, confira fonte/data/checkout e invalide notas antigas quando o código mudar. Uma leitura por agente em contexto limpo testa recuperação de contexto; validar descoberta após reiniciar o cliente é outro procedimento, que precisa ser observado.

Downloads públicos são permitidos; conectar novos serviços ou enviar código, dados, relatórios e manifests privados a terceiros permanece fora do escopo autorizado. Context7 já está configurado (anônimo, sem OAuth nem chave; consultas sem segredos, código privado ou dados de paciente). Não iniciar OAuth, Sentry MCP ou outro serviço por conveniência do onboarding.

### Integrações do Claude Code neste checkout

`.claude/settings.json` é rastreado mas marcado com skip-worktree: Superpowers e Ponytail ficam habilitados só no checkout onde foram instalados. Em outra worktree ou clone, use o escopo `local` (grava em `.claude/settings.local.json`, ignorado pelo git; o escopo `project` reescreveria o `.claude/settings.json` rastreado): `rtk proxy claude plugin install superpowers@claude-plugins-official --scope local` e `rtk proxy claude plugin marketplace add DietrichGebert/ponytail && rtk proxy claude plugin install ponytail@ponytail --scope local --config ledger=false`. No Codex: `/plugins` para o Superpowers e `codex plugin marketplace add DietrichGebert/ponytail && codex plugin add ponytail@ponytail` para o Ponytail, confiando os hooks em `/hooks`. O Context7 vem do `.mcp.json` versionado (o Claude pede aprovação do servidor do projeto na primeira vez). O Graphify é um CLI global (`uv tool install graphifyy`); gere o grafo com `GRAPHIFY_NO_AUTO_REFRESH=1 graphify extract . --code-only --no-cluster`, que não usa rede nem modelo e escreve em `graphify-out/` (ignorado pelo git).
