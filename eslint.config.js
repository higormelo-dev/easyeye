import js from '@eslint/js';
import { defineConfig, globalIgnores } from 'eslint/config';
import prettier from 'eslint-config-prettier/flat';
import pluginVue from 'eslint-plugin-vue';
import globals from 'globals';

/**
 * ESLint (flat config, v10) do frontend: app Vue/Inertia, testes Vitest,
 * e2e Cypress e configs da raiz. Formatação é do Prettier — o
 * eslint-config-prettier (último item) desliga as regras de estilo que
 * brigariam com ele. PHP fica com o Pint; Blade fora.
 */
export default defineConfig([
    globalIgnores([
        'vendor/',
        'public/',
        'storage/',
        'bootstrap/',
        'coverage/',
        'resources/js/ziggy.js', // gerado pelo Ziggy
        'resources/js/preclinic-script.js', // script do tema Preclinic (terceiro)
        '**/*.jsx', // sobras do starter React (React não é dependência)
    ]),

    {
        name: 'easyeye/javascript',
        files: ['**/*.{js,vue}'],
        extends: [js.configs.recommended],
    },
    pluginVue.configs['flat/recommended'],

    // App no navegador. `route()` vem do Ziggy (@routes nos entry-points Blade).
    {
        name: 'easyeye/app',
        files: ['resources/js/**/*.{js,vue}'],
        languageOptions: {
            globals: {
                ...globals.browser,
                route: 'readonly',
            },
        },
    },

    // Páginas Inertia (resources/js/Pages/**) são nomeadas pela AÇÃO da rota
    // (Index/Edit/Show/Create — convenção do próprio Inertia, usada em toda a
    // documentação oficial), nunca renderizadas como <Componente/> genérico
    // em outro template — vue/multi-word-component-names é uma regra pra
    // COMPONENTE reutilizável, não pra página de rota. Reusable components
    // (resources/js/Components/**) continuam com a regra ligada (achado via
    // auditoria de encerramento, EE-13 — "diferenças entre convenções de
    // páginas Inertia e regras genéricas").
    {
        name: 'easyeye/inertia-pages',
        files: ['resources/js/Pages/**/*.vue'],
        rules: {
            'vue/multi-word-component-names': 'off',
        },
    },

    // Vitest roda com `globals: true` (vitest.config.js).
    {
        name: 'easyeye/vitest',
        files: ['tests/JavaScript/**/*.js'],
        languageOptions: {
            globals: {
                ...globals.browser,
                ...globals.node,
                ...globals.vitest,
            },
        },
    },

    // Cypress (e2e/, package.json próprio).
    {
        name: 'easyeye/cypress',
        files: ['e2e/cypress/**/*.js'],
        languageOptions: {
            globals: {
                ...globals.browser,
                ...globals.mocha,
                cy: 'readonly',
                Cypress: 'readonly',
                expect: 'readonly',
                assert: 'readonly',
            },
        },
    },

    // The e2e package has no type:module; these files use bundled CommonJS.
    // Keep this precise: other specs declare their own globals inline.
    {
        name: 'easyeye/cypress-commonjs',
        files: [
            'e2e/cypress/support/e2e.js',
            'e2e/cypress/support/commands.js',
            'e2e/cypress/support/billing.js',
            'e2e/cypress/e2e/docs/billing-manual.cy.js',
        ],
        languageOptions: {
            globals: globals.node,
        },
    },

    // Local Node rule modules and their self-check use the same recommended gate.
    {
        name: 'easyeye/quality-tooling',
        files: ['eslint-rules/**/*.cjs', 'scripts/verify-quality-rules.mjs'],
        extends: [js.configs.recommended],
        languageOptions: {
            globals: globals.node,
        },
    },

    // Node: configs e scripts.
    {
        name: 'easyeye/node',
        files: ['*.config.js', 'e2e/*.config.js', 'e2e/scripts/**/*.js'],
        languageOptions: {
            globals: globals.node,
        },
    },

    prettier,
]);
