# Triagem EE-02 — Dependências com avisos de segurança

Registro das decisões tomadas ao implementar EE-02 (RELATORIO.md) — formato
exigido pela própria auditoria para avisos não aplicáveis: ID, caminho de
uso, versão, responsável e revisão futura.

## Composer (PHP)

`composer update` escopado aos 16 pacotes apontados (execução + cadeia de
build), todos dentro das faixas SemVer já declaradas em `composer.json`
(nenhum bump de major) — `composer audit --format=json` resultante: **0
avisos**. Principais saltos: `laravel/framework` 12.56.0 → 12.69.3 (cobre o
CRLF de e-mail e a confusão de path em URL assinada citados no relatório),
`guzzlehttp/guzzle` 7.10.0 → 7.15.5, `symfony/*` 7.4.8 → 7.4.20/8.x,
`knplabs/knp-snappy` 1.6.0 → 1.7.3 (corrige o path do binário sem escape),
`league/commonmark` 2.8.2 → 2.10.3, `league/flysystem` 3.33.0 → 3.36.0,
`mtdowling/jmespath.php` 2.8.0 → 2.9.2 (via `aws/aws-sdk-php` 3.400.1).
Regressão completa (`tests/Feature` + `tests/Unit`): 5793 passaram, 1
ignorado (pré-existente, extensão Imagick ausente neste ambiente), 0
falhas, 709s.

## npm — removidos (comprovadamente sem uso)

- `gulp`, `gulp-clean-css`, `gulp-npm-dist`, `gulp-rename`, `gulp-sass`,
  `gulp-uglify`, `browser-sync`: nenhum `gulpfile.js` no repositório, nenhum
  script em `package.json` os invoca — pipeline Gulp/BrowserSync legado,
  substituído por Vite. Confirmado via busca textual (`grep`) por
  referência viva a qualquer um dos dois nomes fora do próprio
  `package.json`.
- `morris.js`, `raphael`: `morris.js` era importado em `resources/js/vendor.js`
  e `resources/css/vendor.css`, mas **nenhuma tela chama `Morris.*`**
  (busca por `Morris\.` em `resources/js` e `resources/views` não achou
  nada) — biblioteca de gráfico carregada no bundle do navegador sem
  nenhum gráfico real renderizado. `raphael` existia só como dependência do
  Morris (`window.Raphael` nunca referenciado por outro código). `morris.js`
  não tem correção disponível (`fixAvailable: no` no `npm audit`) —
  removê-lo também resolve o aviso sem substituto necessário. Imports
  removidos de `vendor.js`/`vendor.css`/`system/main.scss`; partial SCSS
  órfão (`plugins/_morris.scss`) excluído.

Resultado: `npm audit` caiu de 52 para 36 entradas só com essa remoção (461
pacotes a menos na árvore).

## npm — atualizados dentro do SemVer já declarado

`npm audit fix` (sem `--force`) + bumps explícitos pontuais quando o fix não
era aplicado automaticamente (`vue`, `@vue/server-renderer` → 3.5.43;
`vitest`, `@vitest/coverage-v8` → 4.1.11) resolveram o restante: `axios`,
`tinymce`, `@xmldom/xmldom`, `undici`, `ws`, `socket.io-*`, `svgo`,
`@tabler/icons-webfont` (e sua cadeia `svgtofont`/`nunjucks`), `postcss`,
`nanoid`, `lodash`/`lodash-es`, `immutable`, `ip-address`, `form-data`,
`http-cache-semantics`, `browserslist`, `concurrently` (via `shell-quote`),
`tar`, entre outros — nenhum major necessário. `npm run build` (client + SSR)
e `npx vitest run` (2506 testes) permaneceram verdes em cada etapa.

## npm — exceção documentada (não corrigida nesta rodada)

**Decisão do dono do projeto (2026-10-11): documentar e seguir sem migrar
agora; revisitar numa rodada própria.**

| Campo | Valor |
|---|---|
| Pacotes/IDs | `tailwindcss` (faixa afetada `0.5.0–3.4.19`), e sua cadeia interna `chokidar` [GHSA-vfj7-8cjw-p6xm via `braces`], `micromatch`, `fast-glob`, `postcss-nested`, `postcss-selector-parser` [GHSA-rj75-hqrm-r3gf] |
| Severidade | 5 alta, 2 moderada |
| Caminho de uso | CLI do Tailwind (`npm run dev` / etapa de build do `npm run build`) — observador de arquivos (`chokidar`/`micromatch`/`braces`) e parser de seletor CSS (`postcss-selector-parser`/`postcss-nested`) usados **só durante a geração do CSS** em máquina de desenvolvedor/CI. Confirmado: nenhum desses pacotes aparece no CSS gerado nem é importado por código da aplicação (`resources/js`, `resources/css` não referenciam `chokidar`/`micromatch`/`postcss-selector-parser` diretamente) — inacessível por requisição web a este produto. |
| Versão instalada | `tailwindcss@3.4.19` (dentro de `^3.4.3`, já o topo da faixa 3.x) |
| Correção real disponível | Só via `tailwindcss@4.3.3` — major, muda formato de config (`tailwind.config.js` → CSS-first), troca o plugin PostCSS, pode alterar classes/plugins em uso em todo o painel (Bootstrap 5 + Tailwind convivem hoje). `npm audit fix --force` aplicaria isso sem revisão visual — explicitamente não usado, por instrução do próprio relatório (EE-02) e por decisão deste registro. |
| Responsável | Higor Melo (dono do projeto) |
| Revisão futura | Antes da migração para Tailwind v4 (sem data fixa ainda) — reavaliar `npm audit` a cada rodada de dependências subsequente; se o Tailwind v3 parar de receber patch de segurança upstream, reabrir como item P1. |

Avisos novos publicados depois desta referência (2026-10-11) entram no
acompanhamento de segurança de rotina, não reabrem este item automaticamente.
