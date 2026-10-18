import { defineConfig } from 'eslint/config';
import base from './eslint.config.js';
import quality from './eslint-rules/index.cjs';

// Adoption inventory only: retain every existing gate and measure legacy size debt.
export default defineConfig([
    base,
    {
        name: 'easyeye/quality-adoption',
        files: ['resources/js/**/*.{js,vue}', 'tests/JavaScript/**/*.js', 'e2e/cypress/**/*.js'],
        plugins: { quality },
        rules: {
            'quality/max-lines': ['warn', { max: 350, includeTests: true }],
        },
    },
]);
