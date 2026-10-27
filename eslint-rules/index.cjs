// Adapted from soumatheusgomes/vibe-coding-toolkit, de66fe371a49f5a4d3b521759e5c401f390b6424.
// MIT; copyright (c) 2026 Matheus Gomes. See eslint-rules/LICENSE and README.md.
'use strict';

const { maxLines, noDirectConsole, noDirectDataAccess } = require('./core-rules.cjs');
const { noLocalControlSizing } = require('./no-local-control-sizing.cjs');

module.exports = {
    rules: {
        'max-lines': maxLines,
        'no-direct-console': noDirectConsole,
        'no-direct-data-access': noDirectDataAccess,
        'no-local-control-sizing': noLocalControlSizing,
    },
};
