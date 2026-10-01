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
