# Local quality rules

Source: [vibe-coding-toolkit](https://github.com/soumatheusgomes/vibe-coding-toolkit/tree/de66fe371a49f5a4d3b521759e5c401f390b6424/templates/eslint), revision `de66fe371a49f5a4d3b521759e5c401f390b6424` (MIT, copyright 2026 Matheus Gomes; full notice in [LICENSE](LICENSE)).

Copied files: `templates/eslint/eslint-rules/{index,core-rules,utils}.cjs` and `templates/eslint/verify.mjs` (now `scripts/verify-quality-rules.mjs`). Changes: local formatting/provenance, Vue full-file counting, Vue parser regressions, and local usage instructions. No Next presets were copied.

`rtk npm run quality:rules` checks all three copied rules. Only `quality/max-lines` is enabled for adoption, at **350 lines**, as warnings in `eslint.quality.config.js`. It counts the complete Vue SFC, including template-only/import-only components, and includes JavaScript tests. The upstream source/test/generated/config/barrel exclusions remain; no offender baseline is supplied. Existing ESLint global ignores remain effective. This measures authored JavaScript/Vue in `resources/js`, `tests/JavaScript`, and `e2e/cypress`, not PHP or every repository file.

`rtk npm run lint:quality` extends the full existing lint config, retaining its severities and failure status. The normal `lint` gate is unchanged except for valid Cypress CommonJS globals and linting of the added Node modules. `no-direct-console` and `no-direct-data-access` are available and tested but inactive: this frontend has no project logging adapter or JavaScript database client contract to enforce.

To update, compare the pinned upstream files against these adaptations, preserve the MIT notice, rerun the self-check (including Vue cases), and compare both full lint reports before changing adoption policy. Do not introduce ignore entries or raise the budget to erase debt.
