# Local quality rules

Source: [vibe-coding-toolkit](https://github.com/soumatheusgomes/vibe-coding-toolkit/tree/de66fe371a49f5a4d3b521759e5c401f390b6424/templates/eslint), revision `de66fe371a49f5a4d3b521759e5c401f390b6424` (MIT, copyright 2026 Matheus Gomes; full notice in [LICENSE](LICENSE)).

Copied files: `templates/eslint/eslint-rules/{index,core-rules,utils}.cjs` and `templates/eslint/verify.mjs` (now `scripts/verify-quality-rules.mjs`). Changes: local formatting/provenance, Vue full-file counting, Vue parser regressions, and local usage instructions. No Next presets were copied.

`rtk npm run quality:rules` checks the three copied rules and the local `no-local-control-sizing` (smoke; the full matrix is `tests/JavaScript/eslint-rules/no-local-control-sizing.test.js`, run by `rtk npm test`). Only `quality/max-lines` is enabled for adoption, at **350 lines**, as warnings in `eslint.quality.config.js`. It counts the complete Vue SFC, including template-only/import-only components, and includes JavaScript tests. The upstream source/test/generated/config/barrel exclusions remain; no offender baseline is supplied. Existing ESLint global ignores remain effective. This measures authored JavaScript/Vue in `resources/js`, `tests/JavaScript`, and `e2e/cypress`, not PHP or every repository file.

`rtk npm run lint:quality` extends the full existing lint config, retaining its severities and failure status. The normal `lint` gate is unchanged except for valid Cypress CommonJS globals and linting of the added Node modules. `no-direct-console` and `no-direct-data-access` are available and tested but inactive: this frontend has no project logging adapter or JavaScript database client contract to enforce.

To update, compare the pinned upstream files against these adaptations, preserve the MIT notice, rerun the self-check (including Vue cases), and compare both full lint reports before changing adoption policy. Do not introduce ignore entries or raise the budget to erase debt.

## `quality/no-local-control-sizing` (local, not from upstream)

Source: `eslint-rules/no-local-control-sizing.cjs`; contract in `design-system/MASTER.md` (section 6) and `docs/internal/planos/design-system-adocao-plano.md` (D3). Unlike the copied rules it is **active as `error`** in the normal `rtk npm run lint`, in the `easyeye/design-system-density` block of `eslint.config.js`, and only for the files listed in that block (pages already inside a `data-ee-density` region). The list grows batch by batch; legacy debt stays outside it, visible, and never gets an `eslint-disable`.

It reads the Vue **template** (through `vue-eslint-parser`; script blocks are ignored) and reports, per element:

- `sizeClass`: `btn-sm`, `btn-lg`, `btn-md`, `form-control-sm`, `form-select-sm` or `input-group-sm` in `class`, or as a literal inside `:class` / `v-bind:class` (object keys, array items, conditional branches, `&&`/`||` literals, `+` concatenation, template literals). Any element: the class alone is already a local measure.
- `fontClass`: `fs-11` or `fs-13` on a **control** (`button`, `input`, `select`, `textarea`, `SearchSelect`, `SearchInput`, `MoneyInput`, or any element carrying `.btn`, `.form-control` or `.form-select`).
- `style`: `height`, `min-height` or `font-size` in `style` / `:style` (declarations in strings, template literals, and object keys in camelCase or kebab-case) on a control.

Not reported, on purpose: elements carrying `.btn-xs`, `.btn-link` or `.btn-close` (the region leaves them alone; the size classes above are still reported on them); badges and icons (`fs-*` stays); geometry of `img`, `canvas`, `svg`, colour dots and document pages; `style` without height or font size.

Known gaps (a false positive costs more than a gap): classes or styles coming from a variable, a computed value or a function call (`:class="cls"`, `:style="styleObj"`, `cx(...)`) cannot be read; `size="sm"` / `sm` props of `SearchSelect`, `SearchInput` and `MoneyInput` are inert inside a region and are not reported; `fs-*` other than 11 and 13, `*-lg` variants of `form-control`/`form-select`/`input-group`, `btn-group-sm`/`btn-group-lg`, and `<style>` blocks are outside the plan's D3 list.

