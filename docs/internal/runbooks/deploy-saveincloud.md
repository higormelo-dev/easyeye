# Runbook — deploy nos nós SaveInCloud

Origem: levantamento do servidor de produção de 08/11/2026. O nó tinha `.env` com permissão 644, `config/sentry.php` copiado à mão (o `git pull` seguinte recusaria sobrescrevê-lo), token do GitHub na URL do remote e arquivos soltos na raiz (`build`, `vite`, `11.12.0`).

**Hipótese de invalidação:** refazer se o deploy deixar de ser `git` no nó (ex.: pipeline de CI), se o repositório deixar de ser público ou se mudar o checklist da seção 3 de `docs/internal/infra/reverb-ambiente-teste.md`.

## Uso

No nó, como o dono dos arquivos (`nginx`), em `/var/www/webroot/ROOT`:

```bash
scripts/deploy-saveincloud.sh --dry-run   # só confere e mostra o que faria
scripts/deploy-saveincloud.sh             # deploy de origin/<branch atual>
scripts/deploy-saveincloud.sh --build     # força o build do front
```

Vale para produção (`main`) e teste (`desenv`): o script usa o branch do checkout.

## O que ele faz, em ordem

1. `.env` e cópias `.env.*` (menos `.env.example`) passam a 600.
2. Remote com credencial na URL: se o repositório abre sem ela (é público), a credencial sai da URL. Revogue o token no GitHub. Se não abrir, ele só avisa: troque por *deploy key* SSH somente leitura.
3. `git fetch` do branch atual. Para, sem mudar o código, se o servidor tiver commit que não está no origin ou arquivo rastreado alterado.
4. Arquivo não rastreado no caminho de um arquivo novo do commit (o merge recusaria): igual ao que vem, sai; diferente, vai para `.easyeye-deploy-backups/<data>/`.
5. Lista os arquivos soltos que não são do repositório. Avalie e remova à mão.
6. `git merge --ff-only`.
7. `composer install` (`--no-dev` em produção), `config:clear`, `migrate --force`, build do front quando mudou `resources/`, `package*.json` ou a config do Vite, Tailwind ou PostCSS, `optimize`, `schedule:clear-cache`, `queue:restart` e `reverb:restart`.

`optimize` refaz os caches de config, eventos, rotas e views. O nó tinha `bootstrap/cache/events.php`: só com `config:cache` e `route:cache`, um listener novo descoberto automaticamente ficaria de fora. O script não usa `optimize:clear`, que apagaria o cache da aplicação.

O script não mexe na crontab (seção 2.3 de `reverb-ambiente-teste.md`), não cria chave nova no `.env` e não põe o site em manutenção.

## Primeira vez (o script ainda não está no nó)

```bash
cd /var/www/webroot/ROOT
git fetch origin main
git show origin/main:scripts/deploy-saveincloud.sh > /tmp/deploy-saveincloud.sh
bash /tmp/deploy-saveincloud.sh --dry-run
bash /tmp/deploy-saveincloud.sh
```

## O que continua manual

- Revogar no GitHub o token que estava na URL do remote.
- Remover os arquivos soltos que o script lista.

Teste: `tests/Unit/Deploy/DeploySaveInCloudScriptTest.php` (repositórios git temporários e php/composer/npm falsos).
